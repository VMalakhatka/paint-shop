from __future__ import annotations

import json
import re
import urllib.error
import urllib.request
import uuid
from abc import ABC, abstractmethod
from typing import Optional

from .models import CashierContext


class CheckboxError(RuntimeError):
    code = "CHECKBOX_ERROR"


class CheckboxAuthenticationError(CheckboxError):
    code = "CHECKBOX_AUTH_FAILED"


class CheckboxApplyLocked(CheckboxError):
    code = "CHECKBOX_APPLY_LOCKED"


class CheckboxUncertain(CheckboxError):
    code = "CHECKBOX_RESULT_UNCERTAIN"


class CheckboxDefiniteFailure(CheckboxError):
    code = "CHECKBOX_DEFINITE_FAILURE"


class CheckboxGateway(ABC):
    mode: str

    @abstractmethod
    def authenticate(self, pin: str) -> CashierContext:
        raise NotImplementedError

    @abstractmethod
    def create_receipt(self, cashier: CashierContext, command: dict) -> dict:
        raise NotImplementedError


class MockCheckboxGateway(CheckboxGateway):
    mode = "mock"

    def __init__(self, outcome: str = "success") -> None:
        self.outcome = outcome

    def authenticate(self, pin: str) -> CashierContext:
        if not re.fullmatch(r"\d{4,12}", pin):
            raise CheckboxAuthenticationError("У тестовому режимі введіть від 4 до 12 цифр")
        return CashierContext(
            cashier_id="mock-cashier-1",
            cashier_name="Тестовий касир",
            organization_id="mock-org-1",
            organization_name="Тестова організація",
            cash_register_id="mock-register-1",
            cash_register_label="TEST-Каса",
            shift_id="mock-shift-opened",
            shift_status="OPENED",
            environment="test",
            access_token="mock-session-token",
        )

    def create_receipt(self, cashier: CashierContext, command: dict) -> dict:
        if cashier.environment != "test":
            raise CheckboxApplyLocked("Mock gateway accepts only a test cashier context")
        if self.outcome == "uncertain":
            raise CheckboxUncertain("Симуляція невідомого результату; повтор заборонений")
        if self.outcome == "failed":
            raise CheckboxDefiniteFailure("Симуляція визначеної помилки Checkbox")
        receipt_id = str(command["id"])
        return {
            "status": "DONE",
            "id": receipt_id,
            "fiscal_code": "MOCK-" + receipt_id.split("-", 1)[0].upper(),
            "shift_id": cashier.shift_id,
            "is_sent_dps": True,
        }


class ApiCheckboxGateway(CheckboxGateway):
    """Real cashier authentication; fiscal receipt creation is intentionally absent in v0.1."""

    mode = "api"

    def __init__(
        self,
        base_url: str,
        license_key: str,
        access_key: str = "",
        timeout: int = 20,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.license_key = license_key
        self.access_key = access_key
        self.timeout = timeout

    def authenticate(self, pin: str) -> CashierContext:
        if not (1 <= len(pin) <= 64) or pin.strip() != pin or any(ord(char) < 32 for char in pin):
            raise CheckboxAuthenticationError("PIN касира має некоректний формат")
        auth = self._request(
            "POST",
            "/api/v1/cashier/signinPinCode",
            {"pin_code": pin},
            token="",
            include_license=True,
        )
        token = str(auth.get("access_token") or "")
        if token == "":
            raise CheckboxAuthenticationError("Checkbox не повернув access token")
        profile = self._request("GET", "/api/v1/cashier/me", None, token=token)
        shift = self._request("GET", "/api/v1/cashier/shift", None, token=token)
        active_shift = _active_shift(shift)
        register = active_shift.get("cash_register") if isinstance(active_shift, dict) else {}
        register = register if isinstance(register, dict) else {}
        organization = profile.get("organization") if isinstance(profile.get("organization"), dict) else {}
        environment = "test" if bool(profile.get("is_test")) or bool(active_shift.get("is_test")) else "live"
        return CashierContext(
            cashier_id=str(profile.get("id") or ""),
            cashier_name=str(profile.get("full_name") or profile.get("name") or "Касир Checkbox"),
            organization_id=str(organization.get("id") or "unknown-org"),
            organization_name=str(organization.get("title") or organization.get("name") or "Організація Checkbox"),
            cash_register_id=str(register.get("id") or "unknown-register"),
            cash_register_label=str(
                register.get("fiscal_number") or register.get("title") or "Каса Checkbox"
            ),
            shift_id=str(active_shift.get("id") or ""),
            shift_status=str(active_shift.get("status") or "CLOSED").upper(),
            environment=environment,
            access_token=token,
        )

    def create_receipt(self, cashier: CashierContext, command: dict) -> dict:
        raise CheckboxApplyLocked(
            "Реальна фіскалізація навмисно не реалізована у v0.1; доступна лише симуляція"
        )

    def _request(
        self,
        method: str,
        path: str,
        body: Optional[dict],
        *,
        token: str,
        include_license: bool = False,
    ) -> dict:
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-Client-Name": "Folio Checkbox Console",
            "X-Client-Version": "0.1.0",
        }
        if include_license:
            headers["X-License-Key"] = self.license_key
        if self.access_key:
            headers["X-Access-Key"] = self.access_key
        if token:
            headers["Authorization"] = "Bearer " + token
        data = json.dumps(body, ensure_ascii=False).encode("utf-8") if body is not None else None
        request = urllib.request.Request(
            self.base_url + path,
            data=data,
            headers=headers,
            method=method,
        )
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                raw = response.read(1_048_577)
                if len(raw) > 1_048_576:
                    raise CheckboxError("Checkbox response is too large")
                payload = json.loads(raw.decode("utf-8"))
                if not isinstance(payload, dict):
                    raise CheckboxError("Checkbox returned an unexpected response")
                return payload
        except urllib.error.HTTPError as error:
            if error.code in {401, 403}:
                raise CheckboxAuthenticationError("Checkbox відхилив авторизацію касира") from error
            raise CheckboxError(f"Checkbox API returned HTTP {error.code}") from error
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as error:
            raise CheckboxError("Checkbox API is unavailable or returned invalid JSON") from error


def _active_shift(payload: dict) -> dict:
    if isinstance(payload.get("results"), list):
        for candidate in payload["results"]:
            if isinstance(candidate, dict) and str(candidate.get("status") or "").upper() == "OPENED":
                return candidate
        return {}
    if str(payload.get("status") or "").upper() == "OPENED":
        return payload
    return {}
