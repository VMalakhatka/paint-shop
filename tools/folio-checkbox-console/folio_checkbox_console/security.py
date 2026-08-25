from __future__ import annotations

import base64
import hashlib
import hmac
import secrets
import threading
import time
from dataclasses import dataclass
from typing import Optional

from .models import CashierContext


PASSWORD_ITERATIONS = 310_000


def hash_password(password: str, *, iterations: int = PASSWORD_ITERATIONS) -> str:
    if len(password) < 12:
        raise ValueError("Password must contain at least 12 characters")
    salt = secrets.token_bytes(18)
    digest = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, iterations)
    return "pbkdf2_sha256${}${}${}".format(
        iterations,
        base64.urlsafe_b64encode(salt).decode("ascii").rstrip("="),
        base64.urlsafe_b64encode(digest).decode("ascii").rstrip("="),
    )


def verify_password(password: str, encoded: str) -> bool:
    try:
        algorithm, iterations_raw, salt_raw, digest_raw = encoded.split("$", 3)
        if algorithm != "pbkdf2_sha256":
            return False
        iterations = int(iterations_raw)
        salt = _decode_base64(salt_raw)
        expected = _decode_base64(digest_raw)
        actual = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, iterations)
        return hmac.compare_digest(actual, expected)
    except (TypeError, ValueError):
        return False


def _decode_base64(value: str) -> bytes:
    return base64.urlsafe_b64decode(value + "=" * (-len(value) % 4))


@dataclass
class SessionRecord:
    session_id: str
    manager_username: str
    csrf_token: str
    expires_at: float
    cashier: Optional[CashierContext] = None


class SessionStore:
    def __init__(self, ttl_seconds: int):
        self.ttl_seconds = ttl_seconds
        self._sessions: dict[str, SessionRecord] = {}
        self._lock = threading.RLock()

    def create(self, manager_username: str) -> SessionRecord:
        now = time.time()
        session = SessionRecord(
            session_id=secrets.token_urlsafe(32),
            manager_username=manager_username,
            csrf_token=secrets.token_urlsafe(24),
            expires_at=now + self.ttl_seconds,
        )
        with self._lock:
            self._purge(now)
            self._sessions[session.session_id] = session
        return session

    def get(self, session_id: str) -> Optional[SessionRecord]:
        now = time.time()
        with self._lock:
            self._purge(now)
            session = self._sessions.get(session_id)
            if session is None:
                return None
            session.expires_at = now + self.ttl_seconds
            return session

    def set_cashier(self, session_id: str, cashier: CashierContext) -> None:
        with self._lock:
            session = self._sessions.get(session_id)
            if session is None:
                raise KeyError("Session not found")
            session.cashier = cashier

    def clear_cashier(self, session_id: str) -> None:
        with self._lock:
            session = self._sessions.get(session_id)
            if session is not None:
                session.cashier = None

    def delete(self, session_id: str) -> None:
        with self._lock:
            self._sessions.pop(session_id, None)

    def _purge(self, now: float) -> None:
        expired = [key for key, value in self._sessions.items() if value.expires_at <= now]
        for key in expired:
            self._sessions.pop(key, None)

