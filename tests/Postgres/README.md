# PostgreSQL concurrency acceptance tests

These tests use two forked PHP processes and separate PostgreSQL connections. They are intentionally outside the default SQLite suite.

Create and migrate a disposable, dedicated test database that can be discarded after the run, then set `KOSTKITA_PG_TEST_DATABASE` and `DB_DATABASE` to the same database name. Supply the PostgreSQL connection settings (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD`) for that dedicated database. Run:

```sh
KOSTKITA_PG_TEST_DATABASE=kostkita_acceptance DB_CONNECTION=pgsql DB_DATABASE=kostkita_acceptance vendor/bin/phpunit -c phpunit.postgres.xml
```

The suite refuses to start unless `DB_CONNECTION=pgsql` and the configured database name exactly matches `KOSTKITA_PG_TEST_DATABASE`. It requires the KostKita migrations to be applied to that dedicated database and leaves its fixture rows in place because audit logs are append-only. Destroy the disposable database after testing; never point the suite at production or a shared database.
