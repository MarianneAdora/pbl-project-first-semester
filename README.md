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

Other secrets (Brevo, Cloudinary, Gemini) are also configured in `.env`.

The root `.user.ini` configures PHP to accept video uploads up to the 50 MB
limit shown to applicants. Azure App Service applies these PHP-FPM settings
automatically; allow a few minutes after deployment for PHP to reload them.
The browser sends video in 900 KiB chunks so each request stays below Azure's
Nginx request-body limit; the upload endpoint reassembles the chunks before
forwarding the video to Cloudinary.

## Existing database updates

For databases created before activity actor names were added, run this once:

```sql
ALTER TABLE activity_log
    ADD COLUMN IF NOT EXISTS actor_name VARCHAR(255) NOT NULL DEFAULT '';
```

New databases created from `database/schema.sql` already include the column.
Older activity rows do not contain the actor's name and will continue to show the role.

HR dashboard, screening, and archive requests check for terminal applicants to
archive: rejected applicants after three days from rejection, and passed
applicants three days after their latest confirmed, rescheduled, or completed
interview time. Restoring a terminal applicant starts a fresh three-day
window. The check runs on the next HR request after the deadline, rather than
at an exact scheduled time. Archived files are retained until permanent
deletion after the 30-day archive period.

Permanent applicant deletion retains only their CV and video file hashes in
`deleted_applicant_fingerprints` for future duplicate checks. These fingerprints
are kept indefinitely without applicant IDs or names; hashes may still be
linkable to known files. Parsed CV content is not retained.
