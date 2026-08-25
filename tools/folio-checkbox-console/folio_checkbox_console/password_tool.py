from __future__ import annotations

import getpass

from .security import hash_password


def main() -> None:
    first = getpass.getpass("New manager password (12+ characters): ")
    second = getpass.getpass("Repeat password: ")
    if first != second:
        raise SystemExit("Passwords do not match")
    try:
        print(hash_password(first))
    except ValueError as error:
        raise SystemExit(str(error)) from error


if __name__ == "__main__":
    main()

