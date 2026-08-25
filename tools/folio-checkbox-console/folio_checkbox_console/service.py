from __future__ import annotations

import hashlib
import json
import re
from datetime import date
from typing import Optional

from .checkbox import (
    CheckboxApplyLocked,
    CheckboxDefiniteFailure,
    CheckboxGateway,
    CheckboxUncertain,
)
from .folio import FolioSource, FolioSourceError
from .models import CashierContext, Invoice
from .storage import IdempotencyConflict, OperationNotFound, OperationRepository


class ConsoleError(RuntimeError):
    code = "CONSOLE_ERROR"
    http_status = 400

    def __init__(self, message: str, *, details: Optional[dict] = None) -> None:
        super().__init__(message)
        self.details = details or {}


class ValidationError(ConsoleError):
    code = "VALIDATION_ERROR"
    http_status = 422


class NotFoundError(ConsoleError):
    code = "NOT_FOUND"
    http_status = 404


class ConflictError(ConsoleError):
    code = "CONFLICT"
    http_status = 409


class ApplyLockedError(ConsoleError):
    code = "APPLY_LOCKED"
    http_status = 403


class UpstreamError(ConsoleError):
    code = "UPSTREAM_ERROR"
    http_status = 502


class FiscalizationService:
    def __init__(
        self,
        folio: FolioSource,
        checkbox: CheckboxGateway,
        operations: OperationRepository,
    ) -> None:
        self.folio = folio
        self.checkbox = checkbox
        self.operations = operations

    def list_invoices(
        self,
        *,
        date_from: Optional[date] = None,
        date_to: Optional[date] = None,
    ) -> list[dict]:
        try:
            return [invoice.public_dict() for invoice in self.folio.list_expenses(date_from, date_to)]
        except FolioSourceError as error:
            raise UpstreamError(str(error)) from error

    def get_invoice(self, source_id: str) -> dict:
        return self._invoice(source_id).public_dict(include_items=True)

    def preview(
        self,
        *,
        manager_id: str,
        cashier: CashierContext,
        source_id: str,
        payment_type: str,
        payment_confirmed: bool,
        revision: int = 1,
    ) -> dict:
        invoice = self._invoice(source_id)
        reasons = invoice.eligibility_reasons()
        if reasons:
            raise ValidationError(
                "Накладна не пройшла обов'язкові перевірки",
                details={"eligibility_reasons": reasons},
            )
        if not payment_confirmed:
            raise ValidationError("Менеджер має підтвердити фактичне отримання оплати")
        payment_type = payment_type.strip().upper()
        if payment_type not in {"CASH", "CASHLESS"}:
            raise ValidationError("Форма оплати має бути CASH або CASHLESS")
        if revision < 1 or revision > 999:
            raise ValidationError("Revision must be between 1 and 999")
        operation_key = self._operation_key(cashier, invoice, revision)
        canonical = self._canonical(invoice, payment_type)
        request_hash = _hash(canonical)
        try:
            operation = self.operations.reserve_preview(
                operation_key=operation_key,
                source_id=invoice.source_id,
                revision=revision,
                request_hash=request_hash,
                manager_id=manager_id,
                cashier=cashier,
                payment_type=payment_type,
                total_cents=invoice.total_cents,
            )
        except IdempotencyConflict as error:
            raise ConflictError(str(error), details={"next_revision": revision + 1}) from error
        self.operations.append_event(
            "PREVIEW_CONFIRMED",
            manager_id,
            cashier.cashier_id,
            operation_key=operation_key,
            safe_metadata={
                "source_id": invoice.source_id,
                "payment_type": payment_type,
                "total_cents": invoice.total_cents,
                "revision": revision,
            },
        )
        return {
            "operation": _safe_operation(operation),
            "preview": {
                "invoice": invoice.public_dict(include_items=True),
                "payment_type": payment_type,
                "payment_confirmed": True,
                "request_hash": request_hash,
                "cashier": cashier.public_dict(),
            },
            "apply_available": self.checkbox.mode == "mock",
        }

    def fiscalize(
        self,
        *,
        manager_id: str,
        cashier: CashierContext,
        operation_key: str,
        request_hash: str,
        confirmed: bool,
    ) -> dict:
        if not confirmed:
            raise ValidationError("Потрібне явне підтвердження саме цієї операції")
        try:
            operation = self.operations.get(operation_key)
        except OperationNotFound as error:
            raise NotFoundError(str(error)) from error
        if operation["request_hash"] != request_hash:
            raise ConflictError("Preview hash does not match the reserved operation")
        if operation["status"] in {"SUCCEEDED", "FISCALIZED"}:
            return {"operation": _safe_operation(operation), "replayed": True}
        if operation["status"] in {"PROCESSING", "UNCERTAIN"}:
            raise ConflictError(
                "Операція має невідомий або незавершений результат; повтор заборонено",
                details={"operation": _safe_operation(operation)},
            )
        if cashier.shift_status != "OPENED" or cashier.shift_id == "":
            raise ConflictError("Для фіскалізації потрібна відкрита зміна Checkbox")
        if cashier.organization_id != operation["organization_id"]:
            raise ConflictError("Організація касира відрізняється від preview")
        if cashier.cash_register_id != operation["cash_register_id"]:
            raise ConflictError("Каса відрізняється від preview")

        invoice = self._invoice(str(operation["source_id"]))
        canonical = self._canonical(invoice, str(operation["payment_type"]))
        current_hash = _hash(canonical)
        if current_hash != operation["request_hash"]:
            raise ConflictError(
                "Накладна або фіскальні дані змінилися після preview; потрібна нова revision"
            )
        if self.checkbox.mode != "mock":
            raise ApplyLockedError(
                "Реальна фіскалізація відсутня у v0.1; цей запуск перевіряє лише авторизацію касира"
            )

        try:
            processing = self.operations.mark_processing(operation_key)
        except IdempotencyConflict as error:
            raise ConflictError(str(error)) from error
        if processing["status"] in {"SUCCEEDED", "FISCALIZED"}:
            return {"operation": _safe_operation(processing), "replayed": True}
        command = dict(canonical)
        command["id"] = operation["receipt_uuid"]
        try:
            response = self.checkbox.create_receipt(cashier, command)
            stored = self.operations.mark_success(operation_key, response)
            self.operations.append_event(
                "MOCK_FISCALIZATION_SUCCEEDED",
                manager_id,
                cashier.cashier_id,
                operation_key=operation_key,
                safe_metadata={
                    "source_id": operation["source_id"],
                    "total_cents": operation["total_cents"],
                },
            )
            return {"operation": _safe_operation(stored), "replayed": False}
        except CheckboxUncertain as error:
            stored = self.operations.mark_uncertain(operation_key, error.code, str(error))
            self.operations.append_event(
                "MOCK_FISCALIZATION_UNCERTAIN",
                manager_id,
                cashier.cashier_id,
                operation_key=operation_key,
            )
            raise ConflictError(str(error), details={"operation": _safe_operation(stored)}) from error
        except CheckboxDefiniteFailure as error:
            stored = self.operations.mark_failure(operation_key, error.code, str(error))
            raise UpstreamError(str(error), details={"operation": _safe_operation(stored)}) from error
        except CheckboxApplyLocked as error:
            stored = self.operations.mark_failure(operation_key, error.code, str(error))
            raise ApplyLockedError(str(error), details={"operation": _safe_operation(stored)}) from error

    def recent_operations(self, limit: int = 50) -> list[dict]:
        return [_safe_operation(operation) for operation in self.operations.recent(limit)]

    def _invoice(self, source_id: str) -> Invoice:
        try:
            return self.folio.get_expense(source_id)
        except FolioSourceError as error:
            if "not found" in str(error).lower():
                raise NotFoundError(str(error)) from error
            raise UpstreamError(str(error)) from error

    @staticmethod
    def _operation_key(cashier: CashierContext, invoice: Invoice, revision: int) -> str:
        components = [
            "folio",
            _key(cashier.environment),
            _key(cashier.organization_id),
            _key(cashier.cash_register_id),
            "expense",
            _key(invoice.source_id),
            "sell",
            f"v{revision}",
        ]
        value = ":".join(components)
        if len(value) > 191:
            digest = hashlib.sha256(value.encode("utf-8")).hexdigest()[:32]
            value = ":".join(["folio", "expense", _key(invoice.source_id)[:80], digest, f"v{revision}"])
        return value

    @staticmethod
    def _canonical(invoice: Invoice, payment_type: str) -> dict:
        return {
            "schema_version": "1.0",
            "operation_type": "SELL",
            "source": {
                "system": "folio",
                "entity_type": "EXPENSE",
                "entity_id": invoice.source_id,
                "document_number": invoice.document_number,
                "occurred_at": invoice.document_date.isoformat(),
            },
            "currency": "UAH",
            "expected_total_cents": invoice.total_cents,
            "goods": [
                {
                    "code": item.sku,
                    "name": item.name,
                    "price_cents": item.price_cents,
                    "quantity_thousandths": item.quantity_thousandths,
                    "line_total_cents": item.line_total_cents,
                    "tax_codes": list(item.tax_codes),
                    "discounts": [],
                    "is_return": False,
                }
                for item in invoice.items
            ],
            "payments": [
                {
                    "type": payment_type,
                    "value_cents": invoice.total_cents,
                    "label": "Готівка" if payment_type == "CASH" else "Безготівкова",
                }
            ],
            "discounts": [],
        }


def _hash(payload: dict) -> str:
    encoded = json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def _key(value: str) -> str:
    normalized = re.sub(r"[^A-Za-z0-9._-]+", "-", str(value)).strip("-").lower()
    return normalized or "unknown"


def _safe_operation(operation: dict) -> dict:
    allowed = {
        "operation_key",
        "receipt_uuid",
        "source_system",
        "source_type",
        "source_id",
        "operation_type",
        "revision",
        "request_hash",
        "status",
        "mode",
        "manager_id",
        "cashier_id",
        "cashier_name",
        "organization_id",
        "cash_register_id",
        "shift_id",
        "environment",
        "payment_type",
        "total_cents",
        "attempts",
        "checkbox_receipt_id",
        "fiscal_code",
        "error_code",
        "error_message",
        "created_at",
        "updated_at",
    }
    return {key: operation.get(key) for key in allowed}


def parse_optional_date(value: Optional[str]) -> Optional[date]:
    if value is None or value.strip() == "":
        return None
    try:
        return date.fromisoformat(value)
    except ValueError as error:
        raise ValidationError("Date must use YYYY-MM-DD format") from error
