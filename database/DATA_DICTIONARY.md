# Data Dictionary — EBSMI Expertise Management Portal

This document lists every table, column, data type, constraint, and description for the screening system database.

## 1. users

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| user_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing user identifier |
| role | VARCHAR(20) | NO | CHECK (role IN ('hr','admin')) | User role: hr or admin |
| name | VARCHAR(255) | NO | | Full name of the user |
| email | VARCHAR(255) | NO | UNIQUE | Email address |
| email_normalized | VARCHAR(255) | NO | DEFAULT '' | Lowercased email for lookups |
| password_hash | VARCHAR(255) | NO | | Bcrypt password hash |
| status | VARCHAR(20) | NO | DEFAULT 'Active', CHECK (status IN ('Active','Inactive')) | Account status |
| deleted_at | TIMESTAMP | YES | | Soft-delete timestamp |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Account creation time |
| avatar_url | VARCHAR(512) | YES | | Cloudinary secure_url for avatar |
| avatar_public_id | VARCHAR(255) | YES | | Cloudinary public_id for avatar deletion |
| reset_token | VARCHAR(64) | YES | | Password reset token |
| reset_token_expires_at | TIMESTAMP | YES | | Reset token expiration |
| remember_token | VARCHAR(64) | YES | | "Remember me" persistent login token |
| remember_token_expires_at | TIMESTAMP | YES | | Remember token expiration |

## 2. admin_mfa_challenges

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| challenge_id | BIGSERIAL | NO | PRIMARY KEY | Auto-incrementing challenge identifier |
| admin_user_id | INTEGER | NO | FK → users(user_id) ON DELETE CASCADE | Admin user who owns the challenge |
| purpose | VARCHAR(40) | NO | | Purpose of the MFA challenge |
| session_hash | CHAR(64) | NO | | Hashed session identifier |
| token_hash | CHAR(64) | NO | UNIQUE | Hashed verification token |
| code_hash | VARCHAR(255) | NO | | Hashed MFA code |
| operation_hash | CHAR(64) | NO | | Hashed operation being protected |
| attempt_count | SMALLINT | NO | DEFAULT 0 | Number of failed attempts |
| expires_at | TIMESTAMPTZ | NO | | Challenge expiration time |
| consumed_at | TIMESTAMPTZ | YES | | Time when challenge was consumed |
| created_at | TIMESTAMPTZ | NO | DEFAULT CURRENT_TIMESTAMP | Creation time |

## 3. job_positions

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| job_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing job position identifier |
| job_name | VARCHAR(100) | NO | | Name of the job position |
| jd_url | VARCHAR(512) | YES | | Cloudinary URL for the job description PDF |
| jd_public_id | VARCHAR(255) | YES | | Cloudinary public_id for the PDF |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Position creation time |
| archived_at | TIMESTAMP | YES | | Soft-archive timestamp |

## 4. applicant

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| applicant_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing applicant identifier |
| reference_code | VARCHAR(50) | NO | UNIQUE | Human-readable application reference code |
| secure_token | VARCHAR(64) | NO | UNIQUE | Secure token for applicant invite link |
| encoded_by_user_id | INTEGER | YES | FK → users(user_id) | HR user who created this applicant |
| position_id | INTEGER | YES | FK → job_positions(job_id) | Job position the applicant applied for |
| name | VARCHAR(255) | NO | | Full name (first + middle + last) |
| first_name | VARCHAR(100) | NO | | First name |
| middle_name | VARCHAR(100) | NO | DEFAULT '' | Middle name |
| last_name | VARCHAR(100) | NO | | Last name |
| name_normalized | VARCHAR(255) | NO | | Normalized name for deduplication |
| email | VARCHAR(255) | NO | | Email address |
| email_normalized | VARCHAR(255) | NO | | Normalized email for deduplication |
| phone_number | VARCHAR(20) | YES | | Contact phone number |
| cv_file_url | VARCHAR(512) | YES | | Cloudinary URL for uploaded CV |
| cv_hash | CHAR(64) | YES | | SHA-256 hash of CV file for duplicate detection |
| verified_at | TIMESTAMP | YES | | Timestamp when OTP was verified |
| status | VARCHAR(20) | NO | DEFAULT 'pending', CHECK (status IN ('pending','in_progress','completed','submitted','reviewed','rejected','accepted')) | Screening status |
| result | VARCHAR(20) | YES | | Screening result: passed or rejected |
| invited_at | TIMESTAMP | YES | | When invitation was sent |
| submitted_at | TIMESTAMP | YES | | When screening was completed |
| passed_at | TIMESTAMP | YES | | When result became passed |
| deleted_at | TIMESTAMP | YES | | Soft-delete timestamp |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Record creation time |
| rejected_at | TIMESTAMP | YES | | When result became rejected |
| rejection_reason | TEXT | YES | | HR's reason for rejection |
| restored_at | TIMESTAMP | YES | | Timestamp when archived applicant was restored |

## 5. otp

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| otp_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing OTP identifier |
| applicant_id | INTEGER | NO | UNIQUE, FK → applicant(applicant_id) ON DELETE CASCADE | Applicant this OTP belongs to |
| otp_code_hash | VARCHAR(255) | NO | | Hashed OTP code |
| request_count | INTEGER | NO | DEFAULT 0, CHECK (request_count >= 0) | Number of OTP requests |
| attempt_count | INTEGER | NO | DEFAULT 0, CHECK (attempt_count >= 0) | Number of verification attempts |
| expires_at | TIMESTAMP | NO | | OTP expiration time |
| locked_until | TIMESTAMP | YES | | Account lockout expiration |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Record creation time |

## 6. otp_request_log

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| log_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing log identifier |
| applicant_id | INTEGER | NO | FK → applicant(applicant_id) ON DELETE CASCADE | Applicant who requested the OTP |
| requested_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Time of OTP request |

## 7. rate_limit_log

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| rate_limit_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing rate limit log identifier |
| key_hash | CHAR(64) | NO | | Hashed rate-limit key (identifier + action) |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Time of the rate-limited request |

## 8. question_config

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| question_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing question identifier |
| section_no | INTEGER | NO | | Section number for ordering |
| display_order | INTEGER | NO | DEFAULT 0 | Display order within section |
| section | VARCHAR(50) | NO | | Section name |
| question_text | VARCHAR(400) | NO | | The question text |
| question_type | VARCHAR(20) | NO | CHECK (question_type IN ('yesno','yesno_select','scale','date','text','consent')) | Type of question |
| answer_config | JSONB | YES | | Type-specific settings as JSON |
| passing_answer | JSONB | YES | | Knockout passing rule as JSON |
| is_knockout | BOOLEAN | NO | DEFAULT FALSE | Whether this is a knockout question |
| is_consent | BOOLEAN | NO | DEFAULT FALSE | Whether this is a consent question |
| is_data_protection | BOOLEAN | NO | DEFAULT FALSE | Whether this is a data protection question |
| reminder_text | VARCHAR(255) | YES | | Reminder text for the question |
| is_active | BOOLEAN | NO | DEFAULT TRUE | Whether the question is active |
| created_by_user_id | INTEGER | YES | FK → users(user_id) | User who created the question |
| deleted_at | TIMESTAMP | YES | | Soft-delete timestamp |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Record creation time |

## 9. response

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| response_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing response identifier |
| applicant_id | INTEGER | NO | UNIQUE, FK → applicant(applicant_id) ON DELETE CASCADE | Applicant who submitted this response |
| answers_json | JSONB | NO | DEFAULT '{}'::jsonb | Questionnaire answers as JSON |
| timing_json | JSONB | NO | DEFAULT '{}'::jsonb | Per-question timing as JSON |
| video_file_url | VARCHAR(512) | YES | | Cloudinary URL for uploaded video |
| video_file_hash | VARCHAR(255) | YES | | Hash of video file for duplicate detection |
| ip_address | VARCHAR(45) | YES | | Applicant's IP address |
| device_fingerprint | VARCHAR(255) | YES | | Device fingerprint for fraud detection |
| data_protection_agreed_at | TIMESTAMP | YES | | When applicant agreed to data protection |
| started_at | TIMESTAMP | YES | | When applicant started the questionnaire |
| submitted_at | TIMESTAMP | YES | | When applicant submitted the questionnaire |

## 10. schedule

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| schedule_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing schedule identifier |
| applicant_id | INTEGER | NO | FK → applicant(applicant_id) ON DELETE CASCADE | Applicant being scheduled |
| preferred_date | DATE | YES | | Applicant's preferred interview date |
| preferred_time | TIME | YES | | Applicant's preferred interview time |
| final_date | DATE | YES | | Confirmed interview date |
| final_time | TIME | YES | | Confirmed interview time |
| venue | VARCHAR(255) | YES | | Interview venue |
| status | VARCHAR(20) | NO | DEFAULT 'pending', CHECK (status IN ('pending','confirmed','rescheduled','completed','cancelled')) | Schedule status |
| email_sent_at | TIMESTAMP | YES | | When interview email was sent |
| answered_at | TIMESTAMP | YES | | When HR confirmed/rescheduled |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Record creation time |
| updated_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Last update time |

## 11. score

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| score_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing score identifier |
| response_id | INTEGER | NO | UNIQUE, FK → response(response_id) ON DELETE CASCADE | Response being scored |
| reviewed_by_user_id | INTEGER | YES | FK → users(user_id) | User who reviewed the score |
| questionnaire_result | VARCHAR(20) | YES | | Questionnaire pass/fail result |
| risk_score | NUMERIC(6,2) | YES | CHECK (risk_score IS NULL OR risk_score >= 0) | AI-generated risk score |
| risk_flags_json | JSONB | NO | DEFAULT '[]'::jsonb | Risk factors as JSON |
| narrative_json | JSONB | NO | DEFAULT '{}'::jsonb | HR narrative as JSON |
| final_decision | VARCHAR(20) | YES | CHECK (final_decision IS NULL OR final_decision IN ('pending','accepted','rejected','manual_review')) | Final hiring decision |
| reviewed_at | TIMESTAMP | YES | | When the score was reviewed |

## 12. deleted_applicant_fingerprints

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| fingerprint_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing fingerprint identifier |
| fingerprint_type | VARCHAR(10) | NO | CHECK (fingerprint_type IN ('cv','video')) | Type of fingerprint |
| fingerprint_hash | VARCHAR(255) | NO | | Hash of the file for duplicate detection |
| retained_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | When fingerprint was retained |
| UNIQUE (fingerprint_type, fingerprint_hash) | | | | Prevents duplicate fingerprints |

## 13. activity_log

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| log_id | SERIAL | NO | PRIMARY KEY | Auto-incrementing log identifier |
| actor | VARCHAR(20) | NO | | Who performed the activity (HR/Admin/system) |
| actor_name | VARCHAR(255) | NO | DEFAULT '' | Name of the actor |
| activity | VARCHAR(100) | NO | | Description of the activity |
| details | TEXT | NO | DEFAULT '-' | Additional details |
| created_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | Time of activity |

## 14. notification_reads

| Column | Type | Nullable | Constraint | Description |
|--------|------|----------|------------|-------------|
| user_id | INTEGER | NO | FK → users(user_id) ON DELETE CASCADE | User who read the notification |
| notification_key | VARCHAR(255) | NO | | Unique key for the notification |
| read_at | TIMESTAMP | NO | DEFAULT CURRENT_TIMESTAMP | When the notification was read |
| PRIMARY KEY (user_id, notification_key) | | | | Composite primary key |

## Database Objects

| Object | Type | Description |
|--------|------|-------------|
| v_applicant_screening_summary | View | Joins applicant, job_positions, response, score for screening summaries |
| v_weekly_funnel_stats | View | Weekly aggregates of invited, submitted, passed, rejected counts and pass rate |
| fn_auto_archive_terminal_applicants() | PL/pgSQL Function | Auto-archives terminal applicants (rejected/passed after 3 days) |
| trg_applicant_status_audit | Trigger | Logs applicant status changes to activity_log |

## Application Roles

| Role | Privileges | Used By |
|------|-----------|---------|
| app_user | SELECT, INSERT, UPDATE, DELETE on all tables; USAGE on sequences | PHP application (replaces postgres) |
| read_only | SELECT on all tables and views; USAGE on sequences | Reporting / dashboards |
