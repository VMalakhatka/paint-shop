import unittest

from folio_checkbox_console.security import hash_password, verify_password


class PasswordTest(unittest.TestCase):
    def test_hash_and_verify(self):
        encoded = hash_password("a-long-local-manager-password")
        self.assertTrue(verify_password("a-long-local-manager-password", encoded))
        self.assertFalse(verify_password("wrong-password", encoded))
        self.assertNotIn("a-long-local-manager-password", encoded)

    def test_short_password_is_rejected(self):
        with self.assertRaises(ValueError):
            hash_password("short")


if __name__ == "__main__":
    unittest.main()

