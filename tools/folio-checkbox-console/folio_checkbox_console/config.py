from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse


BASE_DIR = Path(__file__).resolve().parents[1]


def _bool(name: str, default: bool = False) -> bool:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _int(name: str, default: int, minimum: int, maximum: int) -> int:
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    value = int(raw)
    return max(minimum, min(maximum, value))


def _safe_http_url(value: str, *, allow_empty: bool = False) -> str:
    value = value.strip().rstrip("/")
    if value == "" and allow_empty:
        return ""
    parsed = urlparse(value)
    host = (parsed.hostname or "").lower()
    is_local = host in {"localhost", "127.0.0.1", "::1"}
    if not parsed.scheme or not parsed.hostname:
        raise ValueError("URL must include scheme and host")
    if parsed.scheme != "https" and not (parsed.scheme == "http" and is_local):
        raise ValueError("URL must use HTTPS; HTTP is allowed only for localhost")
    return value


@dataclass(frozen=True)
class Config:
    host: str
    port: int
    database_path: Path
    manager_username: str
    manager_password_hash: str
    session_ttl_seconds: int
    secure_cookie: bool
    folio_source: str
    folio_api_base_url: str
    folio_api_token: str
    checkbox_mode: str
    checkbox_api_base_url: str
    checkbox_license_key: str
    checkbox_access_key: str
    checkbox_network_enabled: bool
    checkbox_timeout_seconds: int
    mock_checkbox_outcome: str

    @classmethod
    def from_env(cls) -> "Config":
        database_raw = os.getenv(
            "FISCAL_DATABASE_PATH",
            str(BASE_DIR / "runtime" / "fiscalization.sqlite3"),
        )
        config = cls(
            host=os.getenv("FISCAL_HOST", "127.0.0.1").strip(),
            port=_int("FISCAL_PORT", 8765, 1024, 65535),
            database_path=Path(database_raw).expanduser().resolve(),
            manager_username=os.getenv("FISCAL_MANAGER_USERNAME", "").strip(),
            manager_password_hash=os.getenv("FISCAL_MANAGER_PASSWORD_HASH", "").strip(),
            session_ttl_seconds=_int("FISCAL_SESSION_TTL_SECONDS", 28_800, 300, 86_400),
            secure_cookie=_bool("FISCAL_SECURE_COOKIE", False),
            folio_source=os.getenv("FISCAL_FOLIO_SOURCE", "mock").strip().lower(),
            folio_api_base_url=_safe_http_url(
                os.getenv("FISCAL_FOLIO_API_BASE_URL", ""), allow_empty=True
            ),
            folio_api_token=os.getenv("FISCAL_FOLIO_API_TOKEN", "").strip(),
            checkbox_mode=os.getenv("FISCAL_CHECKBOX_MODE", "mock").strip().lower(),
            checkbox_api_base_url=_safe_http_url(
                os.getenv("FISCAL_CHECKBOX_API_BASE_URL", "https://api.checkbox.ua")
            ),
            checkbox_license_key=os.getenv("FISCAL_CHECKBOX_LICENSE_KEY", "").strip(),
            checkbox_access_key=os.getenv("FISCAL_CHECKBOX_ACCESS_KEY", "").strip(),
            checkbox_network_enabled=_bool("FISCAL_CHECKBOX_NETWORK_ENABLED", False),
            checkbox_timeout_seconds=_int("FISCAL_CHECKBOX_TIMEOUT_SECONDS", 20, 5, 60),
            mock_checkbox_outcome=os.getenv("FISCAL_MOCK_CHECKBOX_OUTCOME", "success").strip().lower(),
        )
        config.validate()
        return config

    def validate(self) -> None:
        if self.manager_username == "":
            raise ValueError("FISCAL_MANAGER_USERNAME is required")
        if not self.manager_password_hash.startswith("pbkdf2_sha256$"):
            raise ValueError(
                "FISCAL_MANAGER_PASSWORD_HASH is required; generate it with "
                "python3 -m folio_checkbox_console.password_tool"
            )
        if self.folio_source not in {"mock", "http"}:
            raise ValueError("FISCAL_FOLIO_SOURCE must be mock or http")
        if self.folio_source == "http" and self.folio_api_base_url == "":
            raise ValueError("FISCAL_FOLIO_API_BASE_URL is required for the HTTP Folio source")
        if self.checkbox_mode not in {"mock", "api"}:
            raise ValueError("FISCAL_CHECKBOX_MODE must be mock or api")
        if self.checkbox_mode == "api":
            if not self.checkbox_network_enabled:
                raise ValueError(
                    "FISCAL_CHECKBOX_NETWORK_ENABLED must be true before API cashier authentication"
                )
            if self.checkbox_license_key == "":
                raise ValueError("FISCAL_CHECKBOX_LICENSE_KEY is required for API mode")
        if self.mock_checkbox_outcome not in {"success", "uncertain", "failed"}:
            raise ValueError("FISCAL_MOCK_CHECKBOX_OUTCOME must be success, uncertain, or failed")

    def public_summary(self) -> dict:
        return {
            "version": "0.1.0",
            "folio_source": self.folio_source,
            "checkbox_mode": self.checkbox_mode,
            "checkbox_network_enabled": self.checkbox_network_enabled,
            "real_fiscalization_available": False,
            "database": "sqlite",
        }

