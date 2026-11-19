# pbl-project-first-semester

**EBSMI Expertise Management Portal** — a PHP + PostgreSQL applicant screening system for HR and admin staff. Handles invitations, OTP verification, questionnaires, video submissions, AI risk scoring, interview scheduling, and archival workflows.

## Features

- **Authentication**: Database-backed login for `hr` and `admin` roles with bcrypt password hashing, CSRF protection, rate limiting, session hardening, and optional "remember me" tokens.
- **Applicant invitations**: HR invites applicants per job position; applicants verify via OTP (email through Brevo).
- **Questionnaire**: Configurable question types (yes/no, yes/no select, scale, date, text, consent) with knockout rules and conditional follow-ups.
- **File uploads**: CV (PDF) and chunked video uploads to Cloudinary, with SHA-256 duplicate detection.
- **AI scoring**: Gemini-generated risk scores and HR narratives, triggerable on demand or via cron.
- **Interview scheduling**: HR confirms/reschedules interviews; auto-archiving of terminal applicants (rejected after 3 days, passed after 3 days from latest confirmed/completed/rescheduled interview).
- **Archival & deletion**: Soft archival with 30-day retention; permanent deletion retains only file-hash fingerprints for duplicate checks.
- **Security**: Field-level encryption at rest, CSP nonces, security headers, SQL injection prevention via prepared statements, and PostgreSQL row-level security (`database/rls.sql`).

## Tech stack

- PHP (native, no framework)
- PostgreSQL
- Composer (smalot/pdfparser for CV parsing)
- Brevo (transactional email)
- Cloudinary (file storage)
- Google Gemini (AI scoring/narratives)

## Project structure

```
pbl-project-first-semester/
├── index.php                 # Login portal entry point
├── auth/                     # Password reset flows
├── admin/                    # Admin dashboards and management pages
├── hr/                       # HR dashboards and applicant management
├── applicant/                # Applicant-facing flows (OTP, questionnaire, upload)
├── api/                      # JSON API endpoints used by the frontend
├── cron/                     # Scheduled jobs (AI scoring)
├── config/                   # Risk scoring configuration
├── database/                 # schema.sql, seeds.sql, rls.sql
├── includes/                 # Email templates
├── scripts/                  # Maintenance scripts (dependency audit)
├── src/                      # Core library (Helpers, Models, Repositories, Services)
│   └── Helpers/
│       ├── db.php            # PDO connection helper (reads .env)
│       ├── security.php      # Session, CSRF, rate limiting, headers, guards
│       ├── env.php           # .env loader
│       ├── encrypt.php       # Field-level encryption
│       ├── functions.php     # Legacy helpers (delegates to db.php)
│       └── display.php       # View helpers
├── assets/                   # CSS, JS, images
├── vendor/                   # Composer dependencies (gitignored)
├── .env                      # Runtime secrets (gitignored, create from .env.example)
├── .env.example              # Environment variable template
├── .user.ini                 # PHP upload limits (50 MB video)
├── composer.json
└── README.md
```

## Environment setup

1. Clone the repository and install PHP dependencies:

   ```bash
   composer install
   ```

2. Copy the environment template and fill in your values:

   ```bash
   cp .env.example .env
   ```

   Required variables (see `.env.example`):

   | Variable | Description |
   |---|---|
   | `DB_HOST` | PostgreSQL host (default `localhost`) |
   | `DB_PORT` | PostgreSQL port (default `5432`) |
   | `DB_NAME` | Database name (default `ebsmi_screening`) |
   | `DB_USER` | Database user (default `postgres`) |
   | `DB_PASS` | Database password |
   | `BREVO_API_KEY` | Brevo API key for email |
   | `CLOUDINARY_CLOUD_NAME` | Cloudinary cloud name |
   | `CLOUDINARY_API_KEY` | Cloudinary API key |
   | `CLOUDINARY_API_SECRET` | Cloudinary API secret |
   | `GEMINI_API_KEY` | Google Gemini API key |
   | `ENCRYPTION_KEY` | ≥32 random chars for field encryption (`php -r "echo bin2hex(random_bytes(32));"`) |

   `.env` is the single place for database credentials and secrets. The shared `db()` helper in `src/Helpers/db.php` reads these values; the legacy `getDbConnection()` in `src/Helpers/functions.php` delegates to `db()`.

3. Create the database and run the schema:

   ```sql
   CREATE DATABASE ebsmi_screening;
   \c ebsmi_screening
   \i database/schema.sql
   ```

   Optionally load seed data (includes default admin/HR accounts and 13 questionnaire questions):

   ```sql
   \i database/seeds.sql
   ```

   Seed credentials (passwords are bcrypt hashes):

   | Role | Email | Password |
   |---|---|---|
   | admin | admin@expertise.com | `admin123` |
   | hr | hr@expertise.com | `hr123` |

   Apply row-level security policies:

   ```sql
   \i database/rls.sql
   ```

4. Configure PHP upload limits. The root `.user.ini` allows video uploads up to 50 MB. On Azure App Service these PHP-FPM settings apply automatically; allow a few minutes after deployment for PHP to reload them.

## Running the app

Serve the project root with any PHP-capable web server (Apache/Nginx via XAMPP, or PHP's built-in server for local development):

```bash
php -S localhost:8000 -t .
```

Then open `http://localhost:8000` and log in with an admin or HR account.

## Database updates

For databases created before activity actor names were added, run once:

```sql
ALTER TABLE activity_log
    ADD COLUMN IF NOT EXISTS actor_name VARCHAR(255) NOT NULL DEFAULT '';
```

New databases from `database/schema.sql` already include the column. Older activity rows show the role instead of the actor name.

## Cron / scheduled jobs

`cron/process-ai-scoring.php` processes pending AI scoring. Schedule it as needed (e.g. every few minutes) on your server.

## Security

- CSRF tokens on all state-changing requests
- Login rate limiting (50 attempts per 15 minutes per IP)
- Secure session cookies (HttpOnly, SameSite, strict mode)
- CSP nonces for inline scripts
- Prepared statements for all SQL queries
- Field-level encryption for sensitive data at rest (`ENCRYPTION_KEY`)
- PostgreSQL row-level security policies in `database/rls.sql`
- File-hash fingerprinting for CV/video duplicate detection

## Scripts

- `composer run audit-deps` — dependency vulnerability audit (`scripts/audit-deps.php`)

## License

This is a first-semester PBL project. No license specified.