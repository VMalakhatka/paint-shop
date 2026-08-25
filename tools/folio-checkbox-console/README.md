# Folio Checkbox Console — experimental foundation

A standalone manager console for reviewing Folio expense documents and preparing
idempotent Checkbox fiscalization operations. It does not load WordPress or
WooCommerce.

Version `0.1.0` is deliberately safe:

- mock Folio documents by default;
- mock Checkbox cashier and receipt by default;
- real Checkbox cashier authentication can be enabled explicitly;
- the real Checkbox receipt-creation call is not implemented;
- no writes to Folio;
- no PIN, access token, license key, full command, or customer contact is stored;
- every preview is reserved in SQLite under unique `operation_key` and
  `receipt_uuid` values;
- `PROCESSING` and `UNCERTAIN` states block blind retries.

## Requirements

- Python 3.9 or later;
- no third-party Python packages;
- a modern browser;
- for future Folio data: the existing Java API plus the read-only endpoints in
  [docs/JAVA_FOLIO_READ_CONTRACT.md](docs/JAVA_FOLIO_READ_CONTRACT.md);
- for real cashier authentication: a configured Checkbox cash register license
  key and the cashier entering their PIN in the browser.

## First local launch

From this directory:

```bash
python3 -m folio_checkbox_console.password_tool
```

The command asks for the manager password without echoing it and prints only a
PBKDF2 hash. Then:

```bash
cp .local.env.example .local.env
chmod 600 .local.env
```

Put the generated hash after `FISCAL_MANAGER_PASSWORD_HASH=` in `.local.env`.
Do not put the original password there.

Start the console:

```bash
./run-local.sh
```

Open [http://127.0.0.1:8765](http://127.0.0.1:8765).

In mock mode:

1. sign in with the manager username and password configured locally;
2. enter any 4–12 digit mock cashier PIN;
3. open an eligible test invoice;
4. select the payment type and confirm that payment was received;
5. create preview;
6. explicitly confirm the simulated fiscalization;
7. repeat the last action to see the stored successful operation instead of a
   second receipt.

The local journal is created at `runtime/fiscalization.sqlite3` and is ignored by
Git. Delete it only when intentionally resetting mock experiments; never use
that reset procedure for a real integration journal.

## Simulating failures

Set one of these values in `.local.env` and restart:

```text
FISCAL_MOCK_CHECKBOX_OUTCOME=success
FISCAL_MOCK_CHECKBOX_OUTCOME=uncertain
FISCAL_MOCK_CHECKBOX_OUTCOME=failed
```

`uncertain` demonstrates the important rule: after an unknown result the same
operation is not sent again.

## Real Checkbox cashier authentication, still without receipts

Store the license key in the ignored `.local.env`, not in Git or the browser:

```text
FISCAL_CHECKBOX_MODE=api
FISCAL_CHECKBOX_NETWORK_ENABLED=true
FISCAL_CHECKBOX_LICENSE_KEY=environment-specific-secret
```

The cashier enters their PIN in the UI. The server exchanges it for an access
token, reads the cashier profile and current shift, and keeps the token only in
the in-memory manager session. Restarting the app clears all such sessions.

Real Checkbox authentication should be tested first against a test cashier and
test register. A live cashier may authenticate, but v0.1 still cannot create a
receipt.

## Future Folio connection

The console never connects to legacy MSSQL directly. Set:

```text
FISCAL_FOLIO_SOURCE=http
FISCAL_FOLIO_API_BASE_URL=https://java-api-host
FISCAL_FOLIO_API_TOKEN=environment-specific-secret
```

The Java application remains the owner of SQL Server 2000, jTDS, CP1251,
`SCL_NAKL`, `SCL_MOVE`, and payment mappings. The missing read-only endpoint is
documented separately and must be authenticated before it is published.

## How production would run later

The intended deployment is one server process behind HTTPS:

```text
manager browser
  -> HTTPS reverse proxy / authenticated network
  -> this console
      -> Java/Folio read-only API
      -> durable operation database
      -> Checkbox API
```

For production, replace the experimental launcher with a `systemd` service or a
container, use an external durable database instead of a local SQLite file when
multiple instances are required, enable secure cookies, add backup/monitoring,
and perform a separate approved rollout of the real receipt gateway.

## Tests

```bash
python3 -m unittest discover -s tests -v
```

## Kill switch and rollback

The v0.1 kill switch is structural: `ApiCheckboxGateway.create_receipt()` always
rejects real apply. Stopping the process removes access to the console. Rolling
back consists of restoring the previous code and preserving the SQLite journal;
do not discard the journal if any real Checkbox operation has ever been enabled.

