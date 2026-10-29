"""Exercise real response headers and unchanged legacy fallbacks on loopback only."""
import http.client
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest


class HttpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="lavka-redirect-test-")
        cls.root = Path(cls.temp.name)
        for name in ("bootstrap.php", "router.php"):
            shutil.copyfile(Path(__file__).parent / name, cls.root / name)
        (cls.root / "index.php").write_text("<?php require __DIR__.'/bootstrap.php'; echo 'legacy-fallback';")
        cls.mapping = {"version": 1, "home": "https://kreul.com.ua/",
            "products": {"123": "https://kreul.com.ua/p/paint/"}, "categories": {}, "aliases": {}}
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            cls.port = sock.getsockname()[1]
        cls.server = subprocess.Popen(["php", "-S", "127.0.0.1:" + str(cls.port), "-t", str(cls.root)],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", cls.port), timeout=.1):
                    break
            except OSError:
                if cls.server.poll() is not None:
                    raise RuntimeError("PHP test server could not start")
                time.sleep(.02)
        else:
            cls.server.terminate()
            raise RuntimeError("PHP test server did not become ready")

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.temp.cleanup()

    def setUp(self):
        (self.root / "mapping.json").write_text(json.dumps(self.mapping))

    def request(self, path, method="GET", host="1lavka.com", cookie=None):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        headers = {"Host": host}
        if cookie:
            headers["Cookie"] = cookie
        conn.request(method, path, headers=headers)
        response = conn.getresponse()
        result = response.status, dict(response.getheaders()), response.read().decode()
        conn.close()
        return result

    def test_permanent_redirect_exits_before_legacy_code(self):
        status, headers, body = self.request("/index.php?route=product/product&product_id=123")
        self.assertEqual(status, 301)
        self.assertEqual(headers["Location"], "https://kreul.com.ua/p/paint/")
        self.assertEqual(headers["Cache-Control"], "private, no-store")
        self.assertEqual(body, "")

    def test_head(self):
        status, headers, body = self.request("/", "HEAD")
        self.assertEqual((status, headers["Location"], body), (301, "https://kreul.com.ua/", ""))

    def test_legacy_workflows_and_post_are_untouched(self):
        for path, method in [("/index.php?route=checkout/cart", "GET"),
                             ("/index.php?route=account/order", "GET"),
                             ("/index.php?route=product/product&product_id=123", "POST"),
                             ("/index.php?route=extension/payment/callback", "GET")]:
            status, headers, body = self.request(path, method)
            self.assertEqual((status, body), (200, "legacy-fallback"))
            self.assertNotIn("Location", headers)

    def test_corrupt_or_missing_map_falls_back(self):
        (self.root / "mapping.json").write_text("invalid JSON")
        self.assertEqual(self.request("/")[2], "legacy-fallback")
        (self.root / "mapping.json").unlink()
        self.assertEqual(self.request("/")[2], "legacy-fallback")

    def test_other_domain_falls_back(self):
        self.assertEqual(self.request("/", host="paint.dn.ua")[2], "legacy-fallback")

    def test_existing_sessions_fall_back(self):
        for cookie in ("PHPSESSID=existing-session", "default=existing-session"):
            self.assertEqual(self.request("/", cookie=cookie)[2], "legacy-fallback")


if __name__ == "__main__":
    unittest.main()
