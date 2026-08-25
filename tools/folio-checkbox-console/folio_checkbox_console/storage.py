from __future__ import annotations

import json
import sqlite3
import threading
import uuid
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterator, Optional

from .models import CashierContext


class IdempotencyConflict(RuntimeError):
    pass


class OperationNotFound(RuntimeError):
    pass


class OperationRepository:
    def __init__(self, database_path: Path) -> None:
        self.database_path = Path(database_path)
        self.database_path.parent.mkdir(parents=True, exist_ok=True)
        self._migration_lock = threading.Lock()

    def migrate(self) -> None:
        with self._migration_lock, self._connect() as connection:
            connection.executescript(
                """
                CREATE TABLE IF NOT EXISTS operations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    operation_key TEXT NOT NULL UNIQUE,
                    receipt_uuid TEXT NOT NULL UNIQUE,
                    source_system TEXT NOT NULL,
                    source_type TEXT NOT NULL,
                    source_id TEXT NOT NULL,
                    operation_type TEXT NOT NULL,
                    revision INTEGER NOT NULL,
                    request_hash TEXT NOT NULL,
                    status TEXT NOT NULL,
                    mode TEXT NOT NULL,
                    manager_id TEXT NOT NULL,
                    cashier_id TEXT NOT NULL,
                    cashier_name TEXT NOT NULL,
                    organization_id TEXT NOT NULL,
                    cash_register_id TEXT NOT NULL,
                    shift_id TEXT NOT NULL,
                    environment TEXT NOT NULL,
                    payment_type TEXT NOT NULL,
                    total_cents INTEGER NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    checkbox_receipt_id TEXT,
                    fiscal_code TEXT,
                    http_code INTEGER,
                    error_code TEXT,
                    error_message TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS operations_status_updated
                    ON operations(status, updated_at);

                CREATE TABLE IF NOT EXISTS audit_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    operation_key TEXT,
                    event_type TEXT NOT NULL,
                    manager_id TEXT NOT NULL,
                    cashier_id TEXT,
                    safe_metadata TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS audit_events_operation
                    ON audit_events(operation_key, created_at);
                """
            )

    def reserve_preview(
        self,
        *,
        operation_key: str,
        source_id: str,
        revision: int,
        request_hash: str,
        manager_id: str,
        cashier: CashierContext,
        payment_type: str,
        total_cents: int,
    ) -> dict:
        now = _now()
        with self._connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            existing = connection.execute(
                "SELECT * FROM operations WHERE operation_key = ? LIMIT 1",
                (operation_key,),
            ).fetchone()
            if existing is not None:
                if existing["request_hash"] != request_hash:
                    raise IdempotencyConflict(
                        "Operation key already exists with different fiscal data; use a new revision"
                    )
                connection.commit()
                return _row(existing)
            receipt_uuid = str(uuid.uuid4())
            connection.execute(
                """
                INSERT INTO operations (
                    operation_key, receipt_uuid, source_system, source_type, source_id,
                    operation_type, revision, request_hash, status, mode, manager_id,
                    cashier_id, cashier_name, organization_id, cash_register_id,
                    shift_id, environment, payment_type, total_cents, attempts,
                    created_at, updated_at
                ) VALUES (?, ?, 'folio', 'EXPENSE', ?, 'SELL', ?, ?, 'PREVIEW',
                          'preview', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
                """,
                (
                    operation_key,
                    receipt_uuid,
                    source_id,
                    revision,
                    request_hash,
                    manager_id,
                    cashier.cashier_id,
                    cashier.cashier_name,
                    cashier.organization_id,
                    cashier.cash_register_id,
                    cashier.shift_id,
                    cashier.environment,
                    payment_type,
                    total_cents,
                    now,
                    now,
                ),
            )
            connection.commit()
        return self.get(operation_key)

    def get(self, operation_key: str) -> dict:
        with self._connect() as connection:
            row = connection.execute(
                "SELECT * FROM operations WHERE operation_key = ? LIMIT 1",
                (operation_key,),
            ).fetchone()
        if row is None:
            raise OperationNotFound("Fiscalization operation was not found")
        return _row(row)

    def mark_processing(self, operation_key: str) -> dict:
        with self._connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute(
                "SELECT status FROM operations WHERE operation_key = ? LIMIT 1",
                (operation_key,),
            ).fetchone()
            if row is None:
                raise OperationNotFound("Fiscalization operation was not found")
            status = str(row["status"])
            if status in {"SUCCEEDED", "FISCALIZED"}:
                connection.commit()
                return self.get(operation_key)
            if status in {"PROCESSING", "UNCERTAIN"}:
                raise IdempotencyConflict(
                    "Operation is already processing or has an uncertain result; retry is blocked"
                )
            connection.execute(
                """
                UPDATE operations
                   SET status = 'PROCESSING', mode = 'fiscalize', attempts = attempts + 1,
                       error_code = NULL, error_message = NULL, updated_at = ?
                 WHERE operation_key = ?
                """,
                (_now(), operation_key),
            )
            connection.commit()
        return self.get(operation_key)

    def mark_success(self, operation_key: str, response: dict) -> dict:
        return self._update_result(
            operation_key,
            status="SUCCEEDED",
            checkbox_receipt_id=str(response.get("id") or ""),
            fiscal_code=str(response.get("fiscal_code") or ""),
            error_code=None,
            error_message=None,
        )

    def mark_uncertain(self, operation_key: str, code: str, message: str) -> dict:
        return self._update_result(
            operation_key,
            status="UNCERTAIN",
            error_code=code,
            error_message=_safe_error(message),
        )

    def mark_failure(self, operation_key: str, code: str, message: str) -> dict:
        return self._update_result(
            operation_key,
            status="FAILED",
            error_code=code,
            error_message=_safe_error(message),
        )

    def _update_result(self, operation_key: str, *, status: str, **fields: object) -> dict:
        allowed = {"checkbox_receipt_id", "fiscal_code", "error_code", "error_message"}
        values = {key: value for key, value in fields.items() if key in allowed}
        assignments = ["status = ?", "updated_at = ?"]
        parameters: list[object] = [status, _now()]
        for key, value in values.items():
            assignments.append(f"{key} = ?")
            parameters.append(value)
        parameters.append(operation_key)
        with self._connect() as connection:
            changed = connection.execute(
                f"UPDATE operations SET {', '.join(assignments)} WHERE operation_key = ?",
                parameters,
            ).rowcount
            if changed != 1:
                raise OperationNotFound("Fiscalization operation was not found")
        return self.get(operation_key)

    def recent(self, limit: int = 50) -> list[dict]:
        limit = max(1, min(100, int(limit)))
        with self._connect() as connection:
            rows = connection.execute(
                """
                SELECT * FROM operations
                 ORDER BY id DESC
                 LIMIT ?
                """,
                (limit,),
            ).fetchall()
        return [_row(row) for row in rows]

    def append_event(
        self,
        event_type: str,
        manager_id: str,
        cashier_id: Optional[str],
        *,
        operation_key: Optional[str] = None,
        safe_metadata: Optional[dict] = None,
    ) -> None:
        metadata = json.dumps(safe_metadata or {}, ensure_ascii=False, sort_keys=True)
        with self._connect() as connection:
            connection.execute(
                """
                INSERT INTO audit_events (
                    operation_key, event_type, manager_id, cashier_id,
                    safe_metadata, created_at
                ) VALUES (?, ?, ?, ?, ?, ?)
                """,
                (operation_key, event_type, manager_id, cashier_id, metadata, _now()),
            )

    @contextmanager
    def _connect(self) -> Iterator[sqlite3.Connection]:
        connection = sqlite3.connect(self.database_path, timeout=10)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys = ON")
        connection.execute("PRAGMA journal_mode = WAL")
        connection.execute("PRAGMA busy_timeout = 10000")
        try:
            yield connection
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()


def _row(row: sqlite3.Row) -> dict:
    result = dict(row)
    result.pop("id", None)
    return result


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _safe_error(message: str) -> str:
    return " ".join(str(message).split())[:500]
