from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime
from typing import Optional


@dataclass(frozen=True)
class InvoiceItem:
    sku: str
    name: str
    price_cents: int
    quantity_thousandths: int
    line_total_cents: int
    tax_codes: tuple[int, ...]

    def public_dict(self) -> dict:
        return {
            "sku": self.sku,
            "name": self.name,
            "price_cents": self.price_cents,
            "quantity_thousandths": self.quantity_thousandths,
            "line_total_cents": self.line_total_cents,
            "tax_codes": list(self.tax_codes),
        }


@dataclass(frozen=True)
class Invoice:
    source_id: str
    document_number: str
    document_date: datetime
    customer_display: str
    warehouse_display: str
    total_cents: int
    accounted: bool
    return_document: bool
    active: bool
    operation_kind: Optional[str]
    suggested_payment_type: str
    items: tuple[InvoiceItem, ...] = field(default_factory=tuple)
    items_loaded: bool = True
    line_count_hint: int = 0

    def eligibility_reasons(self) -> list[str]:
        reasons: list[str] = []
        if not self.active:
            reasons.append("DOCUMENT_NOT_ACTIVE")
        if not self.accounted:
            reasons.append("DOCUMENT_NOT_ACCOUNTED")
        if self.return_document:
            reasons.append("RETURN_REQUIRES_SEPARATE_FLOW")
        if self.total_cents <= 0:
            reasons.append("DOCUMENT_TOTAL_NOT_POSITIVE")
        if self.items_loaded:
            if not self.items:
                reasons.append("DOCUMENT_HAS_NO_ITEMS")
            if sum(item.line_total_cents for item in self.items) != self.total_cents:
                reasons.append("ITEM_TOTAL_MISMATCH")
            if any(not item.tax_codes for item in self.items):
                reasons.append("TAX_MAPPING_MISSING")
            if any(
                item.price_cents * item.quantity_thousandths != item.line_total_cents * 1000
                for item in self.items
            ):
                reasons.append("LINE_CALCULATION_MISMATCH")
        return reasons

    def public_dict(self, *, include_items: bool = False) -> dict:
        reasons = self.eligibility_reasons()
        result = {
            "source_id": self.source_id,
            "document_number": self.document_number,
            "document_date": self.document_date.isoformat(),
            "customer_display": self.customer_display,
            "warehouse_display": self.warehouse_display,
            "total_cents": self.total_cents,
            "accounted": self.accounted,
            "return_document": self.return_document,
            "active": self.active,
            "operation_kind": self.operation_kind,
            "suggested_payment_type": self.suggested_payment_type,
            "eligible": not reasons,
            "eligibility_reasons": reasons,
            "line_count": len(self.items) if self.items_loaded else self.line_count_hint,
        }
        if include_items:
            result["items"] = [item.public_dict() for item in self.items]
        return result


@dataclass
class CashierContext:
    cashier_id: str
    cashier_name: str
    organization_id: str
    organization_name: str
    cash_register_id: str
    cash_register_label: str
    shift_id: str
    shift_status: str
    environment: str
    access_token: str = field(repr=False, default="")

    def public_dict(self) -> dict:
        return {
            "cashier_id": self.cashier_id,
            "cashier_name": self.cashier_name,
            "organization_id": self.organization_id,
            "organization_name": self.organization_name,
            "cash_register_id": self.cash_register_id,
            "cash_register_label": self.cash_register_label,
            "shift_id": self.shift_id,
            "shift_status": self.shift_status,
            "environment": self.environment,
        }
