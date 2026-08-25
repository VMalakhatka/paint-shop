import http.client
import json
import tempfile
import threading
import unittest
from pathlib import Path

from app import build_application
from folio_checkbox_console.config import Config
from folio_checkbox_console.security import hash_password
from folio_checkbox_console.web import ThreadingHTTPServer


class HttpFlowTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        config = Config(
            host="127.0.0.1",
            port=8765,
            database_path=Path(self.temp_dir.name) / "http-test.sqlite3",
            manager_username="manager",
            manager_password_hash=hash_password("test-manager-password"),
            session_ttl_seconds=3600,
            secure_cookie=False,
            folio_source="mock",
            folio_api_base_url="",
            folio_api_token="",
            checkbox_mode="mock",
            checkbox_api_base_url="https://api.checkbox.ua",
            checkbox_license_key="",
            checkbox_access_key="",
            checkbox_network_enabled=False,
            checkbox_timeout_seconds=10,
            mock_checkbox_outcome="success",
        )
        application = build_application(config)
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), application.handler())
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.connection = http.client.HTTPConnection("127.0.0.1", self.server.server_port, timeout=5)
        self.cookie = ""
        self.csrf = ""

    def tearDown(self):
        self.connection.close()
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)
        self.temp_dir.cleanup()

    def request(self, method, path, body=None, *, csrf=False):
        headers = {"Accept": "application/json"}
        if body is not None:
            headers["Content-Type"] = "application/json"
        if self.cookie:
            headers["Cookie"] = self.cookie
        if csrf:
            headers["X-CSRF-Token"] = self.csrf
        encoded = json.dumps(body).encode("utf-8") if body is not None else None
        self.connection.request(method, path, body=encoded, headers=headers)
        response = self.connection.getresponse()
        payload = json.loads(response.read().decode("utf-8"))
        set_cookie = response.getheader("Set-Cookie")
        if set_cookie:
            self.cookie = set_cookie.split(";", 1)[0]
        return response.status, payload

    def test_complete_mock_operator_flow(self):
        status, session = self.request("GET", "/api/session")
        self.assertEqual(200, status)
        self.assertFalse(session["authenticated"])

        status, session = self.request(
            "POST",
            "/api/login",
            {"username": "manager", "password": "test-manager-password"},
        )
        self.assertEqual(200, status)
        self.assertTrue(session["authenticated"])
        self.csrf = session["csrf_token"]

        status, session = self.request(
            "POST",
            "/api/cashier/login",
            {"pin": "1234"},
            csrf=True,
        )
        self.assertEqual(200, status)
        self.assertEqual("OPENED", session["cashier"]["shift_status"])

        status, invoices = self.request("GET", "/api/invoices")
        self.assertEqual(200, status)
        self.assertGreaterEqual(invoices["count"], 3)

        status, preview = self.request(
            "POST",
            "/api/invoices/751193/preview",
            {"payment_type": "CASHLESS", "payment_confirmed": True, "revision": 1},
            csrf=True,
        )
        self.assertEqual(200, status)
        self.assertEqual("PREVIEW", preview["operation"]["status"])

        status, applied = self.request(
            "POST",
            "/api/fiscalize",
            {
                "operation_key": preview["operation"]["operation_key"],
                "request_hash": preview["preview"]["request_hash"],
                "confirmed": True,
            },
            csrf=True,
        )
        self.assertEqual(200, status)
        self.assertEqual("SUCCEEDED", applied["operation"]["status"])
        self.assertEqual(1, applied["operation"]["attempts"])


if __name__ == "__main__":
    unittest.main()

