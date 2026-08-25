from __future__ import annotations

import json
import mimetypes
import re
from datetime import datetime, timezone
from http import HTTPStatus
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Optional
from urllib.parse import parse_qs, urlparse

from .checkbox import CheckboxAuthenticationError, CheckboxError, CheckboxGateway
from .config import Config
from .security import SessionRecord, SessionStore, verify_password
from .service import ConsoleError, FiscalizationService, ValidationError, parse_optional_date
from .storage import OperationRepository


MAX_REQUEST_BYTES = 64 * 1024
SESSION_COOKIE = "folio_fiscal_session"


class ConsoleApplication:
    def __init__(
        self,
        config: Config,
        sessions: SessionStore,
        checkbox: CheckboxGateway,
        service: FiscalizationService,
        operations: OperationRepository,
        static_root: Path,
    ) -> None:
        self.config = config
        self.sessions = sessions
        self.checkbox = checkbox
        self.service = service
        self.operations = operations
        self.static_root = static_root.resolve()

    def handler(self):
        application = self

        class Handler(ConsoleRequestHandler):
            app = application

        return Handler


class ConsoleRequestHandler(BaseHTTPRequestHandler):
    app: ConsoleApplication
    server_version = "FolioCheckboxConsole/0.1"
    sys_version = ""

    def do_GET(self) -> None:  # noqa: N802
        parsed = urlparse(self.path)
        try:
            if parsed.path == "/health":
                self._json(
                    HTTPStatus.OK,
                    {
                        "ok": True,
                        "service": "folio-checkbox-console",
                        "time": datetime.now(timezone.utc).isoformat(),
                        "config": self.app.config.public_summary(),
                    },
                )
                return
            if parsed.path == "/api/session":
                session = self._session(required=False)
                self._json(HTTPStatus.OK, self._session_payload(session))
                return
            if parsed.path == "/api/invoices":
                session = self._authorized_cashier_session()
                query = parse_qs(parsed.query)
                rows = self.app.service.list_invoices(
                    date_from=parse_optional_date(_query_one(query, "date_from")),
                    date_to=parse_optional_date(_query_one(query, "date_to")),
                )
                self._json(HTTPStatus.OK, {"documents": rows, "count": len(rows)})
                return
            invoice_match = re.fullmatch(r"/api/invoices/([^/]+)", parsed.path)
            if invoice_match:
                self._authorized_cashier_session()
                source_id = _path_id(invoice_match.group(1))
                self._json(HTTPStatus.OK, {"document": self.app.service.get_invoice(source_id)})
                return
            if parsed.path == "/api/operations":
                self._authorized_cashier_session()
                self._json(
                    HTTPStatus.OK,
                    {"operations": self.app.service.recent_operations(50)},
                )
                return
            self._static(parsed.path)
        except ConsoleError as error:
            self._console_error(error)
        except Exception:
            self._json_error(HTTPStatus.INTERNAL_SERVER_ERROR, "INTERNAL_ERROR", "Unexpected server error")

    def do_POST(self) -> None:  # noqa: N802
        parsed = urlparse(self.path)
        try:
            payload = self._read_json()
            if parsed.path == "/api/login":
                self._login(payload)
                return
            session = self._session(required=True)
            self._require_csrf(session)
            if parsed.path == "/api/logout":
                self.app.sessions.delete(session.session_id)
                self._clear_cookie()
                self._json(HTTPStatus.OK, {"ok": True})
                return
            if parsed.path == "/api/cashier/login":
                self._cashier_login(session, payload)
                return
            if parsed.path == "/api/cashier/logout":
                cashier_id = session.cashier.cashier_id if session.cashier else None
                self.app.operations.append_event(
                    "CASHIER_SESSION_CLEARED",
                    session.manager_username,
                    cashier_id,
                )
                self.app.sessions.clear_cashier(session.session_id)
                self._json(HTTPStatus.OK, self._session_payload(session))
                return
            if session.cashier is None:
                self._json_error(
                    HTTPStatus.UNAUTHORIZED,
                    "CASHIER_AUTH_REQUIRED",
                    "Checkbox cashier authorization is required",
                )
                return
            preview_match = re.fullmatch(r"/api/invoices/([^/]+)/preview", parsed.path)
            if preview_match:
                source_id = _path_id(preview_match.group(1))
                result = self.app.service.preview(
                    manager_id=session.manager_username,
                    cashier=session.cashier,
                    source_id=source_id,
                    payment_type=str(payload.get("payment_type") or ""),
                    payment_confirmed=payload.get("payment_confirmed") is True,
                    revision=int(payload.get("revision") or 1),
                )
                self._json(HTTPStatus.OK, result)
                return
            if parsed.path == "/api/fiscalize":
                result = self.app.service.fiscalize(
                    manager_id=session.manager_username,
                    cashier=session.cashier,
                    operation_key=str(payload.get("operation_key") or ""),
                    request_hash=str(payload.get("request_hash") or ""),
                    confirmed=payload.get("confirmed") is True,
                )
                self._json(HTTPStatus.OK, result)
                return
            self._json_error(HTTPStatus.NOT_FOUND, "NOT_FOUND", "Endpoint not found")
        except ConsoleError as error:
            self._console_error(error)
        except (TypeError, ValueError):
            self._json_error(HTTPStatus.UNPROCESSABLE_ENTITY, "INVALID_REQUEST", "Request fields are invalid")
        except Exception:
            self._json_error(HTTPStatus.INTERNAL_SERVER_ERROR, "INTERNAL_ERROR", "Unexpected server error")

    def _login(self, payload: dict) -> None:
        username = str(payload.get("username") or "").strip()
        password = str(payload.get("password") or "")
        valid_user = username == self.app.config.manager_username
        valid_password = verify_password(password, self.app.config.manager_password_hash)
        if not (valid_user and valid_password):
            self._json_error(HTTPStatus.UNAUTHORIZED, "LOGIN_FAILED", "Invalid manager credentials")
            return
        session = self.app.sessions.create(username)
        self.app.operations.append_event("MANAGER_LOGIN", username, None)
        self._set_cookie(session)
        self._json(HTTPStatus.OK, self._session_payload(session))

    def _cashier_login(self, session: SessionRecord, payload: dict) -> None:
        pin = str(payload.get("pin") or "")
        try:
            cashier = self.app.checkbox.authenticate(pin)
        except CheckboxAuthenticationError as error:
            self._json_error(HTTPStatus.UNAUTHORIZED, error.code, str(error))
            return
        except CheckboxError as error:
            self._json_error(HTTPStatus.BAD_GATEWAY, error.code, str(error))
            return
        finally:
            pin = ""
        self.app.sessions.set_cashier(session.session_id, cashier)
        session.cashier = cashier
        self.app.operations.append_event(
            "CASHIER_AUTHENTICATED",
            session.manager_username,
            cashier.cashier_id,
            safe_metadata={
                "organization_id": cashier.organization_id,
                "cash_register_id": cashier.cash_register_id,
                "shift_id": cashier.shift_id,
                "shift_status": cashier.shift_status,
                "environment": cashier.environment,
            },
        )
        self._json(HTTPStatus.OK, self._session_payload(session))

    def _authorized_cashier_session(self) -> SessionRecord:
        session = self._session(required=True)
        if session.cashier is None:
            raise UnauthorizedRequest("Checkbox cashier authorization is required")
        return session

    def _session(self, *, required: bool) -> Optional[SessionRecord]:
        raw_cookie = self.headers.get("Cookie", "")
        cookie = SimpleCookie()
        try:
            cookie.load(raw_cookie)
        except Exception:
            cookie = SimpleCookie()
        morsel = cookie.get(SESSION_COOKIE)
        session = self.app.sessions.get(morsel.value) if morsel is not None else None
        if required and session is None:
            raise UnauthorizedRequest("Manager login is required")
        return session

    def _require_csrf(self, session: Optional[SessionRecord]) -> None:
        if session is None:
            raise UnauthorizedRequest("Manager login is required")
        provided = self.headers.get("X-CSRF-Token", "")
        if not provided or provided != session.csrf_token:
            raise ForbiddenRequest("CSRF token is invalid")

    def _session_payload(self, session: Optional[SessionRecord]) -> dict:
        return {
            "authenticated": session is not None,
            "manager": session.manager_username if session else None,
            "csrf_token": session.csrf_token if session else None,
            "cashier": session.cashier.public_dict() if session and session.cashier else None,
            "config": self.app.config.public_summary(),
        }

    def _read_json(self) -> dict:
        raw_length = self.headers.get("Content-Length", "0")
        try:
            length = int(raw_length)
        except ValueError as error:
            raise ValidationError("Content-Length is invalid") from error
        if length < 0 or length > MAX_REQUEST_BYTES:
            raise ValidationError("Request body is too large")
        raw = self.rfile.read(length) if length else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise ValidationError("Request body must be valid UTF-8 JSON") from error
        if not isinstance(payload, dict):
            raise ValidationError("Request body must be a JSON object")
        return payload

    def _static(self, request_path: str) -> None:
        relative = "index.html" if request_path in {"", "/"} else request_path.lstrip("/")
        candidate = (self.app.static_root / relative).resolve()
        if self.app.static_root not in candidate.parents and candidate != self.app.static_root:
            self._json_error(HTTPStatus.NOT_FOUND, "NOT_FOUND", "File not found")
            return
        if not candidate.is_file():
            self._json_error(HTTPStatus.NOT_FOUND, "NOT_FOUND", "File not found")
            return
        content = candidate.read_bytes()
        content_type, _ = mimetypes.guess_type(str(candidate))
        self.send_response(HTTPStatus.OK)
        self._security_headers(api=False)
        self.send_header("Content-Type", (content_type or "application/octet-stream") + ("; charset=utf-8" if candidate.suffix in {".html", ".css", ".js"} else ""))
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        self.wfile.write(content)

    def _console_error(self, error: ConsoleError) -> None:
        self._json_error(error.http_status, error.code, str(error), details=error.details)

    def _json_error(
        self,
        status: int,
        code: str,
        message: str,
        *,
        details: Optional[dict] = None,
    ) -> None:
        self._json(status, {"error": {"code": code, "message": message, "details": details or {}}})

    def _json(self, status: int, payload: dict) -> None:
        content = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self._security_headers(api=True)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(content)

    def _security_headers(self, *, api: bool) -> None:
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Permissions-Policy", "camera=(), microphone=(), geolocation=()")
        self.send_header(
            "Content-Security-Policy",
            "default-src 'self'; script-src 'self'; style-src 'self'; "
            "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'",
        )

    def _set_cookie(self, session: SessionRecord) -> None:
        parts = [
            f"{SESSION_COOKIE}={session.session_id}",
            "Path=/",
            "HttpOnly",
            "SameSite=Strict",
            f"Max-Age={self.app.config.session_ttl_seconds}",
        ]
        if self.app.config.secure_cookie:
            parts.append("Secure")
        self.send_cookie = "; ".join(parts)

    def _clear_cookie(self) -> None:
        self.send_cookie = f"{SESSION_COOKIE}=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0"

    def send_response(self, code: int, message: Optional[str] = None) -> None:
        super().send_response(code, message)
        cookie = getattr(self, "send_cookie", None)
        if cookie:
            self.send_header("Set-Cookie", cookie)
            self.send_cookie = None

    def log_message(self, format: str, *args) -> None:
        sanitized_path = self.path.split("?", 1)[0]
        print(f"{self.client_address[0]} {self.command} {sanitized_path} {args[1] if len(args) > 1 else ''}")


class UnauthorizedRequest(ConsoleError):
    code = "UNAUTHORIZED"
    http_status = 401


class ForbiddenRequest(ConsoleError):
    code = "FORBIDDEN"
    http_status = 403


def serve(application: ConsoleApplication) -> None:
    server = ThreadingHTTPServer((application.config.host, application.config.port), application.handler())
    print(
        f"Folio Checkbox Console 0.1 listening on "
        f"http://{application.config.host}:{application.config.port} "
        f"(Folio={application.config.folio_source}, Checkbox={application.config.checkbox_mode})"
    )
    server.serve_forever()


def _query_one(query: dict[str, list[str]], key: str) -> Optional[str]:
    values = query.get(key)
    return values[0] if values else None


def _path_id(value: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9._:-]{1,191}", value):
        raise ValidationError("Source identifier has an invalid format")
    return value

