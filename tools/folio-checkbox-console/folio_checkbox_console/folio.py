from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from abc import ABC, abstractmethod
from datetime import date, datetime, timezone
from typing import Iterable, Optional

from .models import Invoice, InvoiceItem


class FolioSourceError(RuntimeError):
    pass


class FolioSource(ABC):
    @abstractmethod
    def list_expenses(self, date_from: Optional[date], date_to: Optional[date]) -> list[Invoice]:
        raise NotImplementedError

    @abstractmethod
    def get_expense(self, source_id: str) -> Invoice:
        raise NotImplementedError


class MockFolioSource(FolioSource):
    def __init__(self) -> None:
        self._invoices = {invoice.source_id: invoice for invoice in _mock_invoices()}

    def list_expenses(self, date_from: Optional[date], date_to: Optional[date]) -> list[Invoice]:
        rows = list(self._invoices.values())
        if date_from is not None:
            rows = [row for row in rows if row.document_date.date() >= date_from]
        if date_to is not None:
            rows = [row for row in rows if row.document_date.date() <= date_to]
        return sorted(rows, key=lambda row: (row.document_date, row.source_id), reverse=True)

    def get_expense(self, source_id: str) -> Invoice:
        invoice = self._invoices.get(str(source_id))
        if invoice is None:
            raise FolioSourceError("Expense document was not found")
        return invoice


class HttpFolioSource(FolioSource):
    """Read-only adapter for the future Java/Folio fiscalization endpoint."""

    def __init__(self, base_url: str, token: str = "", timeout: int = 20) -> None:
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout

    def list_expenses(self, date_from: Optional[date], date_to: Optional[date]) -> list[Invoice]:
        query: dict[str, str] = {}
        if date_from is not None:
            query["dateFrom"] = date_from.isoformat()
        if date_to is not None:
            query["dateTo"] = date_to.isoformat()
        payload = self._get("/admin/folio/fiscalization/expenses", query)
        raw_rows = payload.get("documents", payload) if isinstance(payload, dict) else payload
        if not isinstance(raw_rows, list):
            raise FolioSourceError("Folio list response has an unexpected shape")
        return [self._invoice(row, include_items=False) for row in raw_rows]

    def get_expense(self, source_id: str) -> Invoice:
        quoted = urllib.parse.quote(str(source_id), safe="")
        payload = self._get(f"/admin/folio/fiscalization/expenses/{quoted}", {})
        raw = payload.get("document", payload) if isinstance(payload, dict) else payload
        if not isinstance(raw, dict):
            raise FolioSourceError("Folio detail response has an unexpected shape")
        return self._invoice(raw, include_items=True)

    def _get(self, path: str, query: dict[str, str]) -> object:
        url = self.base_url + path
        if query:
            url += "?" + urllib.parse.urlencode(query)
        headers = {"Accept": "application/json"}
        if self.token:
            headers["X-Auth-Token"] = self.token
        request = urllib.request.Request(url, headers=headers, method="GET")
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                raw = response.read(1_048_577)
                if len(raw) > 1_048_576:
                    raise FolioSourceError("Folio response is too large")
                return json.loads(raw.decode("utf-8"))
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as error:
            raise FolioSourceError("Folio read-only API is unavailable or returned invalid JSON") from error

    def _invoice(self, raw: dict, *, include_items: bool) -> Invoice:
        try:
            items_raw: Iterable[dict] = raw.get("items", []) if include_items else []
            items = tuple(
                InvoiceItem(
                    sku=str(item["sku"]),
                    name=str(item["name"]),
                    price_cents=int(item["price_cents"]),
                    quantity_thousandths=int(item["quantity_thousandths"]),
                    line_total_cents=int(item["line_total_cents"]),
                    tax_codes=tuple(int(code) for code in item["tax_codes"]),
                )
                for item in items_raw
            )
            return Invoice(
                source_id=str(raw["source_id"]),
                document_number=str(raw["document_number"]),
                document_date=_parse_datetime(str(raw["document_date"])),
                customer_display=str(raw.get("customer_display") or "—"),
                warehouse_display=str(raw.get("warehouse_display") or "—"),
                total_cents=int(raw["total_cents"]),
                accounted=bool(raw["accounted"]),
                return_document=bool(raw["return_document"]),
                active=bool(raw["active"]),
                operation_kind=(str(raw["operation_kind"]) if raw.get("operation_kind") else None),
                suggested_payment_type=str(raw.get("suggested_payment_type") or "CASHLESS"),
                items=items,
                items_loaded=include_items,
                line_count_hint=int(raw.get("line_count") or 0),
            )
        except (KeyError, TypeError, ValueError) as error:
            raise FolioSourceError("Folio document is missing a required fiscalization field") from error


def _parse_datetime(value: str) -> datetime:
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed


def _mock_invoices() -> tuple[Invoice, ...]:
    tz = timezone.utc
    return (
        Invoice(
            source_id="751193",
            document_number="64471/0626",
            document_date=datetime(2026, 8, 25, 9, 35, tzinfo=tz),
            customer_display="Тестовий клієнт A",
            warehouse_display="Склад 7",
            total_cents=129_050,
            accounted=True,
            return_document=False,
            active=True,
            operation_kind="*РОЗНИЦА",
            suggested_payment_type="CASHLESS",
            items=(
                InvoiceItem("TEST-1001", "Фарба тестова, біла", 41_250, 2_000, 82_500, (8,)),
                InvoiceItem("TEST-1002", "Пензель тестовий", 23_275, 2_000, 46_550, (8,)),
            ),
        ),
        Invoice(
            source_id="751194",
            document_number="64472/0626",
            document_date=datetime(2026, 8, 25, 10, 10, tzinfo=tz),
            customer_display="Тестовий клієнт B",
            warehouse_display="Склад 2",
            total_cents=25_000,
            accounted=True,
            return_document=False,
            active=True,
            operation_kind="*ПРЕДОПЛАТА",
            suggested_payment_type="CASH",
            items=(
                InvoiceItem("TEST-2001", "Набір тестовий", 25_000, 1_000, 25_000, (8,)),
            ),
        ),
        Invoice(
            source_id="751195",
            document_number="64473/0626",
            document_date=datetime(2026, 8, 24, 16, 5, tzinfo=tz),
            customer_display="Тестове повернення",
            warehouse_display="Склад 7",
            total_cents=10_000,
            accounted=True,
            return_document=True,
            active=True,
            operation_kind="*ВОЗВРАТ",
            suggested_payment_type="CASHLESS",
            items=(
                InvoiceItem("TEST-3001", "Тестова позиція повернення", 10_000, 1_000, 10_000, (8,)),
            ),
        ),
    )
