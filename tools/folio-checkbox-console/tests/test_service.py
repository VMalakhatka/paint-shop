import sqlite3
import tempfile
import unittest
from pathlib import Path

from folio_checkbox_console.checkbox import MockCheckboxGateway
from folio_checkbox_console.folio import MockFolioSource
from folio_checkbox_console.models import CashierContext
from folio_checkbox_console.service import ConflictError, FiscalizationService, ValidationError
from folio_checkbox_console.storage import IdempotencyConflict, OperationRepository


class FiscalizationServiceTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.database = Path(self.temp_dir.name) / "operations.sqlite3"
        self.operations = OperationRepository(self.database)
        self.operations.migrate()
        self.cashier = CashierContext(
            cashier_id="cashier-test",
            cashier_name="Test cashier",
            organization_id="org-test",
            organization_name="Test org",
            cash_register_id="register-test",
            cash_register_label="TEST register",
            shift_id="shift-open",
            shift_status="OPENED",
            environment="test",
            access_token="must-not-be-persisted",
        )

    def tearDown(self):
        self.temp_dir.cleanup()

    def service(self, outcome="success"):
        return FiscalizationService(
            MockFolioSource(),
            MockCheckboxGateway(outcome),
            self.operations,
        )

    def test_payment_confirmation_is_required(self):
        with self.assertRaises(ValidationError):
            self.service().preview(
                manager_id="manager",
                cashier=self.cashier,
                source_id="751193",
                payment_type="CASHLESS",
                payment_confirmed=False,
            )

    def test_return_document_is_not_eligible_for_sale(self):
        with self.assertRaises(ValidationError):
            self.service().preview(
                manager_id="manager",
                cashier=self.cashier,
                source_id="751195",
                payment_type="CASHLESS",
                payment_confirmed=True,
            )

    def test_preview_success_and_replay_do_not_create_second_attempt(self):
        service = self.service()
        preview = service.preview(
            manager_id="manager",
            cashier=self.cashier,
            source_id="751193",
            payment_type="CASHLESS",
            payment_confirmed=True,
        )
        first = service.fiscalize(
            manager_id="manager",
            cashier=self.cashier,
            operation_key=preview["operation"]["operation_key"],
            request_hash=preview["preview"]["request_hash"],
            confirmed=True,
        )
        second = service.fiscalize(
            manager_id="manager",
            cashier=self.cashier,
            operation_key=preview["operation"]["operation_key"],
            request_hash=preview["preview"]["request_hash"],
            confirmed=True,
        )
        self.assertEqual("SUCCEEDED", first["operation"]["status"])
        self.assertFalse(first["replayed"])
        self.assertTrue(second["replayed"])
        self.assertEqual(1, second["operation"]["attempts"])
        self.assertEqual(
            first["operation"]["checkbox_receipt_id"],
            second["operation"]["checkbox_receipt_id"],
        )

    def test_uncertain_result_blocks_retry(self):
        service = self.service("uncertain")
        preview = service.preview(
            manager_id="manager",
            cashier=self.cashier,
            source_id="751194",
            payment_type="CASH",
            payment_confirmed=True,
        )
        with self.assertRaises(ConflictError):
            service.fiscalize(
                manager_id="manager",
                cashier=self.cashier,
                operation_key=preview["operation"]["operation_key"],
                request_hash=preview["preview"]["request_hash"],
                confirmed=True,
            )
        with self.assertRaises(ConflictError):
            service.fiscalize(
                manager_id="manager",
                cashier=self.cashier,
                operation_key=preview["operation"]["operation_key"],
                request_hash=preview["preview"]["request_hash"],
                confirmed=True,
            )
        stored = self.operations.get(preview["operation"]["operation_key"])
        self.assertEqual("UNCERTAIN", stored["status"])
        self.assertEqual(1, stored["attempts"])

    def test_processing_operation_cannot_be_acquired_twice(self):
        service = self.service()
        preview = service.preview(
            manager_id="manager",
            cashier=self.cashier,
            source_id="751193",
            payment_type="CASHLESS",
            payment_confirmed=True,
        )
        operation_key = preview["operation"]["operation_key"]

        first = self.operations.mark_processing(operation_key)
        self.assertEqual("PROCESSING", first["status"])

        with self.assertRaises(IdempotencyConflict):
            self.operations.mark_processing(operation_key)
        self.assertEqual(1, self.operations.get(operation_key)["attempts"])

    def test_pin_and_access_token_are_not_persisted(self):
        service = self.service()
        service.preview(
            manager_id="manager",
            cashier=self.cashier,
            source_id="751193",
            payment_type="CASHLESS",
            payment_confirmed=True,
        )
        connection = sqlite3.connect(self.database)
        dump = "\n".join(connection.iterdump())
        connection.close()
        self.assertNotIn("must-not-be-persisted", dump)
        self.assertNotIn("1234", dump)


if __name__ == "__main__":
    unittest.main()
