-- EBSMI Screening Database Schema
-- Run this file once to initialize the database.
-- All tables and indexes are created in a single transaction.

BEGIN;

-- ------------------------------------------------------------
-- USERS
-- HR staff and admin accounts.
-- ------------------------------------------------------------
CREATE TABLE users (
    user_id               SERIAL PRIMARY KEY,
    role                  VARCHAR(20)   NOT NULL CHECK (role IN ('hr', 'admin')),
    name                  VARCHAR(255)  NOT NULL,
    email                 VARCHAR(255)  NOT NULL UNIQUE,
    email_normalized      VARCHAR(255)  NOT NULL DEFAULT '', -- lowercase email, used for lookups
    password_hash         VARCHAR(255)  NOT NULL,
    status                VARCHAR(20)   NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
    deleted_at            TIMESTAMP,
    created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    avatar_url            VARCHAR(512),           -- Cloudinary secure_url for the HR staff avatar
    avatar_public_id      VARCHAR(255),           -- Cloudinary public_id for removal

    reset_token           VARCHAR(64),            -- password reset token
    reset_token_expires_at TIMESTAMP,

    remember_token        VARCHAR(64),            -- "Remember me" persistent login token
    remember_token_expires_at TIMESTAMP
);

CREATE INDEX idx_users_reset_token ON users(reset_token) WHERE reset_token IS NOT NULL;
CREATE INDEX idx_users_remember_token ON users(remember_token) WHERE remember_token IS NOT NULL;
CREATE INDEX idx_users_status ON users(status);

-- ------------------------------------------------------------
-- JOB POSITIONS
-- One row per job position; each position has its own job
-- description PDF stored in Cloudinary (jd_url). HR adds
-- positions via the "+ Add job position" control in Add Applicant.
-- ------------------------------------------------------------
CREATE TABLE job_positions (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    jd_url        VARCHAR(512),         -- Cloudinary secure_url
    jd_public_id  VARCHAR(255),         -- Cloudinary public_id (for deletion)
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at   TIMESTAMP
);

CREATE INDEX idx_job_positions_archived_at ON job_positions(archived_at);

-- ------------------------------------------------------------
-- APPLICANT
-- One row per invited applicant.
-- ------------------------------------------------------------
CREATE TABLE applicant (
    applicant_id         SERIAL PRIMARY KEY,
    reference_code       VARCHAR(50)  NOT NULL UNIQUE,
    secure_token         VARCHAR(64)  NOT NULL UNIQUE,
    encoded_by_user_id   INT REFERENCES users(user_id),
    position_id          INT REFERENCES job_positions(id), -- the job position this applicant was added under

    name                 VARCHAR(255) NOT NULL,   -- full name = first + middle + last (kept for the rest of the app)
    first_name           VARCHAR(100) NOT NULL,
    middle_name          VARCHAR(100) NOT NULL DEFAULT '',
    last_name            VARCHAR(100) NOT NULL,
    name_normalized      VARCHAR(255) NOT NULL,   -- lowercase, no dots/commas, single spaces -> one person, one applicant

    email                VARCHAR(255) NOT NULL,
    email_normalized     VARCHAR(255) NOT NULL,   -- lowercase; Gmail dots/+tags removed for consistent matching
    phone_number         VARCHAR(20),

    cv_file_url          VARCHAR(512),
    cv_hash              CHAR(64),                -- SHA-256 of the CV file -> one CV, one applicant

    verified_at          TIMESTAMP,               -- NULL = invited only; set when the OTP is verified
    status               VARCHAR(20)  NOT NULL DEFAULT 'pending'
                         CHECK (status IN ('pending', 'in_progress', 'completed', 'submitted', 'reviewed', 'rejected', 'accepted')),
    result               VARCHAR(20),

    invited_at           TIMESTAMP,               -- when the invitation was sent
    submitted_at         TIMESTAMP,               -- when the screening was completed
    passed_at            TIMESTAMP,               -- when the result became Passed
    deleted_at           TIMESTAMP,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rejected_at          TIMESTAMP,               -- when the result became Rejected
    rejection_reason     TEXT,                    -- HR's reason/note for rejecting the applicant
    restored_at          TIMESTAMP                -- reset point for terminal auto-archive after restore
);

CREATE UNIQUE INDEX uq_applicant_name_normalized  ON applicant(name_normalized);
CREATE UNIQUE INDEX uq_applicant_email_normalized ON applicant(email_normalized);
CREATE UNIQUE INDEX uq_applicant_cv_hash          ON applicant(cv_hash) WHERE cv_hash IS NOT NULL;
CREATE INDEX idx_applicant_unverified      ON applicant(created_at) WHERE verified_at IS NULL;
CREATE INDEX idx_applicant_status          ON applicant(status);
CREATE INDEX idx_applicant_deleted_at      ON applicant(deleted_at);

-- ------------------------------------------------------------
-- OTP
-- One row per applicant; reused on every resend (request_count / attempt_count
-- increment in place).
-- ------------------------------------------------------------
CREATE TABLE otp (
    otp_id          SERIAL PRIMARY KEY,
    applicant_id    INT          NOT NULL UNIQUE REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    otp_code_hash   VARCHAR(255) NOT NULL,
    request_count   INT          NOT NULL DEFAULT 0 CHECK (request_count >= 0),
    attempt_count   INT          NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    expires_at      TIMESTAMP    NOT NULL,
    locked_until    TIMESTAMP,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_otp_applicant_id ON otp(applicant_id);
CREATE INDEX idx_otp_expires_at   ON otp(expires_at);

-- ------------------------------------------------------------
-- OTP REQUEST LOG
-- One row per OTP send; used to count requests within a 10-minute window for
-- the risk scoring rubric.
-- ------------------------------------------------------------
CREATE TABLE otp_request_log (
    log_id          SERIAL PRIMARY KEY,
    applicant_id    INT       NOT NULL REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    requested_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_otp_request_log_applicant    ON otp_request_log(applicant_id);
CREATE INDEX idx_otp_request_log_requested_at ON otp_request_log(requested_at);

-- ------------------------------------------------------------
-- RATE LIMIT LOG
-- Database-backed rate limiting (used by rate_limit() in security.php).
-- One row per rate-limited request.
-- ------------------------------------------------------------
CREATE TABLE rate_limit_log (
    id          SERIAL PRIMARY KEY,
    key_hash    CHAR(64)   NOT NULL,
    created_at  TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_rate_limit_key_time ON rate_limit_log(key_hash, created_at);

-- ------------------------------------------------------------
-- QUESTION CONFIG
-- The screening questionnaire managed by the Admin.
-- question_type: explicit type stored so the applicant page never has to guess
--   from the shape of answer_config.
--   values: yesno | yesno_select | scale | date | text | consent
-- answer_config: all type-specific settings as JSONB.
--   yesno        { "no_note": "...", "favorable": "Yes"|"No",
--                  "link": { "text": "...", "url": "..." } }
--   yesno_select { "followup_when": "Yes"|"No", "followup_label": "...",
--                  "followup_options": ["choice1", ...] }
--   scale        { "max": 5, "labels": ["left label", "right label"] }
--   date         { "hint": "..." }
--   text         { "hint": "...", "placeholder": "...",
--                  "min_chars": 0, "max_chars": 500 }
--   consent      { "body": ["paragraph1", ...], "checkbox": "I agree" }
-- passing_answer: knockout rule as JSONB (NULL when not a knockout question).
--   yesno / yesno_select  { "answer": "Yes"|"No",
--                           "pass_follow": ["choice1", ...] }
--   scale                 { "min": 3 }
-- No UNIQUE (section_no, section): many questions share one section, so a
--   unique pair would allow only one question per section. Keeping one name
--   per section_no is the application's job (renaming a section updates every
--   question in that section).
-- ------------------------------------------------------------
CREATE TABLE question_config (
    question_id         SERIAL PRIMARY KEY,
    section_no          INT          NOT NULL,
    display_order       INT          NOT NULL DEFAULT 0,
    section             VARCHAR(50)  NOT NULL,
    question_text       VARCHAR(400) NOT NULL,
    question_type       VARCHAR(20)  NOT NULL
                        CHECK (question_type IN ('yesno', 'yesno_select', 'scale', 'date', 'text', 'consent')),
    answer_config       JSONB,
    passing_answer      JSONB,
    is_knockout         BOOLEAN      NOT NULL DEFAULT FALSE,
    is_consent          BOOLEAN      NOT NULL DEFAULT FALSE,
    is_data_protection  BOOLEAN      NOT NULL DEFAULT FALSE,
    reminder_text       VARCHAR(255),
    is_active           BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by_user_id  INT REFERENCES users(user_id),
    deleted_at          TIMESTAMP,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_question_config_section_order
    ON question_config (section_no, display_order, question_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_question_config_active ON question_config(is_active);

-- ------------------------------------------------------------
-- RESPONSE
-- One row per applicant; updated in place as they progress.
-- answers_json: { "<question_id>": <value>, "<question_id>:follow": "..." }
-- timing_json:  per-question millisecond timings from the questionnaire JS
--               { "<question_id>": 4200, ... }
-- ------------------------------------------------------------
CREATE TABLE response (
    response_id                SERIAL PRIMARY KEY,
    applicant_id               INT          NOT NULL UNIQUE REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    answers_json               JSONB        NOT NULL DEFAULT '{}'::jsonb,
    timing_json                JSONB        NOT NULL DEFAULT '{}'::jsonb,
    video_file_url             VARCHAR(512),
    video_file_hash            VARCHAR(255),
    ip_address                 VARCHAR(45),
    device_fingerprint         VARCHAR(255),
    data_protection_agreed_at  TIMESTAMP,
    started_at                 TIMESTAMP,
    submitted_at               TIMESTAMP
);

CREATE INDEX idx_response_answers_json ON response USING GIN (answers_json);

-- ------------------------------------------------------------
-- SCHEDULE
-- Interview scheduling; one or more rows per applicant (rescheduling adds rows).
-- ------------------------------------------------------------
CREATE TABLE schedule (
    schedule_id     SERIAL PRIMARY KEY,
    applicant_id    INT         NOT NULL REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    preferred_date  DATE,
    preferred_time  TIME,
    final_date      DATE,
    final_time      TIME,
    venue           VARCHAR(255),
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'confirmed', 'rescheduled', 'completed', 'cancelled')),
    email_sent_at   TIMESTAMP,
    answered_at     TIMESTAMP,               -- when HR confirmed/rescheduled the interview
    created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_schedule_applicant_id ON schedule(applicant_id);
CREATE INDEX idx_schedule_final_date   ON schedule(final_date);

-- ------------------------------------------------------------
-- SCORE
-- Risk scoring result for each submitted response.
-- risk_flags_json:  { band, auto_high, factors, knockouts, outcome }
-- narrative_json:   Gemini-generated HR narrative
--                   { title, summary, favor[], watch[] }
-- ------------------------------------------------------------
CREATE TABLE score (
    score_id               SERIAL PRIMARY KEY,
    response_id            INT          NOT NULL UNIQUE REFERENCES response(response_id) ON DELETE CASCADE,
    reviewed_by_user_id    INT REFERENCES users(user_id),
    questionnaire_result   VARCHAR(20),
    risk_score             NUMERIC(6,2) CHECK (risk_score IS NULL OR risk_score >= 0),
    risk_flags_json        JSONB        NOT NULL DEFAULT '[]'::jsonb,
    narrative_json         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    final_decision         VARCHAR(20)
                            CHECK (final_decision IS NULL OR final_decision IN ('pending', 'accepted', 'rejected', 'manual_review')),
    reviewed_at            TIMESTAMP
);

CREATE INDEX idx_score_risk_score ON score(risk_score);

-- Retain only file fingerprints after permanent applicant deletion so future
-- duplicate checks can still work without retaining applicant-identifying rows.
CREATE TABLE deleted_applicant_fingerprints (
    fingerprint_id    SERIAL PRIMARY KEY,
    fingerprint_type VARCHAR(10)  NOT NULL CHECK (fingerprint_type IN ('cv', 'video')),
    fingerprint_hash VARCHAR(255) NOT NULL,
    retained_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (fingerprint_type, fingerprint_hash)
);

-- ------------------------------------------------------------
-- ACTIVITY LOG
-- ------------------------------------------------------------
CREATE TABLE activity_log (
    log_id       SERIAL PRIMARY KEY,
    actor        VARCHAR(20) NOT NULL,
    actor_name   VARCHAR(255) NOT NULL DEFAULT '',
    activity     VARCHAR(100) NOT NULL,
    details      TEXT NOT NULL DEFAULT '-',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;

-- ------------------------------------------------------------
-- CREATE DATABASE ebsmi_screening;   -- run once on its own if the database doesn't exist yet, then connect to it
-- ------------------------------------------------------------
