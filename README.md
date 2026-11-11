# pbl-project-first-semester

## Database credentials

All database credentials are configured in a single place: the `.env` file at the
project root. A template is provided in `.env.example`.

To set up:

1. Copy `.env.example` to `.env`:
   ```
   cp .env.example .env
   ```
2. Edit `.env` and fill in your PostgreSQL details:
   - `DB_HOST` (default `localhost`)
   - `DB_PORT` (default `5432`)
   - `DB_NAME` (default `ebsmi_screening`)
   - `DB_USER` (default `postgres`)
   - `DB_PASS` (your PostgreSQL password)

The shared connection helper `db()` in `src/Helpers/db.php` reads these values.
The legacy `getDbConnection()` in `src/Helpers/functions.php` now delegates to
`db()`, so there is only one source of truth.

Other secrets (Brevo, Cloudinary, Gemini, Sentry) are also configured in `.env`.

## Database updates

When updating an existing database, run the SQL migration files in
`database/migrations/` against the configured PostgreSQL database before
deploying code that depends on them. For example:

```powershell
psql -h localhost -p 5432 -U postgres -d ebsmi_screening -f database/migrations/2026-11-11-question-display-order.sql
```
