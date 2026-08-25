from __future__ import annotations

from pathlib import Path

from folio_checkbox_console.checkbox import ApiCheckboxGateway, MockCheckboxGateway
from folio_checkbox_console.config import BASE_DIR, Config
from folio_checkbox_console.folio import HttpFolioSource, MockFolioSource
from folio_checkbox_console.security import SessionStore
from folio_checkbox_console.service import FiscalizationService
from folio_checkbox_console.storage import OperationRepository
from folio_checkbox_console.web import ConsoleApplication, serve


def build_application(config: Config) -> ConsoleApplication:
    operations = OperationRepository(config.database_path)
    operations.migrate()

    if config.folio_source == "http":
        folio = HttpFolioSource(
            config.folio_api_base_url,
            token=config.folio_api_token,
            timeout=config.checkbox_timeout_seconds,
        )
    else:
        folio = MockFolioSource()

    if config.checkbox_mode == "api":
        checkbox = ApiCheckboxGateway(
            config.checkbox_api_base_url,
            config.checkbox_license_key,
            access_key=config.checkbox_access_key,
            timeout=config.checkbox_timeout_seconds,
        )
    else:
        checkbox = MockCheckboxGateway(config.mock_checkbox_outcome)

    service = FiscalizationService(folio, checkbox, operations)
    sessions = SessionStore(config.session_ttl_seconds)
    return ConsoleApplication(
        config=config,
        sessions=sessions,
        checkbox=checkbox,
        service=service,
        operations=operations,
        static_root=Path(BASE_DIR / "static"),
    )


def main() -> None:
    config = Config.from_env()
    serve(build_application(config))


if __name__ == "__main__":
    main()

