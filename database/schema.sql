CREATE DATABASE ebsmi_screening;

BEGIN;

-- ------------------------------------------------------------
-- USERS
-- HR staff and admin accounts.
-- ------------------------------------------------------------
CREATE TABLE users (
    user_id         SERIAL PRIMARY KEY,
    role            VARCHAR(20)  NOT NULL CHECK (role IN ('hr', 'admin')),
    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    deleted_at      TIMESTAMP,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- APPLICANT
-- One row per invited applicant.
-- invite_link_clicked_at: recorded when the applicant first opens their invite
--   link; not scored because email scanners also open links within seconds.
-- ------------------------------------------------------------
CREATE TABLE applicant (
    applicant_id            SERIAL PRIMARY KEY,
    reference_code          VARCHAR(50)  NOT NULL UNIQUE,
    secure_token            VARCHAR(64)  NOT NULL UNIQUE,
    encoded_by_user_id      INT REFERENCES users(user_id),
    name                    VARCHAR(255) NOT NULL,
    email                   VARCHAR(255) NOT NULL,
    phone_number            VARCHAR(20),
    cv_file_url             VARCHAR(512),
    device_fingerprint      VARCHAR(255),
    ip_address              VARCHAR(45),
    status                  VARCHAR(20)  NOT NULL DEFAULT 'pending'
                                CHECK (status IN ('pending', 'in_progress', 'submitted', 'reviewed', 'rejected', 'accepted')),
    result                  VARCHAR(20),
    invite_link_clicked_at  TIMESTAMP,
    deleted_at              TIMESTAMP,
    created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

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
-- UNIQUE (section_no, section): one name per number; prevents rename drift.
-- ------------------------------------------------------------
CREATE TABLE question_config (
    question_id         SERIAL PRIMARY KEY,
    section_no          INT          NOT NULL,
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
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_question_config_section UNIQUE (section_no, section)
);

-- ------------------------------------------------------------
-- RESPONSE
-- One row per applicant; updated in place as they progress.
-- answers_json: { "<question_id>": <value>, "<question_id>:follow": "..." }
-- timing_json:  per-question millisecond timings from the questionnaire JS
--               { "<question_id>": 4200, ... }
-- ------------------------------------------------------------
CREATE TABLE response (
    response_id                 SERIAL PRIMARY KEY,
    applicant_id                INT          NOT NULL UNIQUE REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    answers_json                JSONB        NOT NULL DEFAULT '{}'::jsonb,
    timing_json                 JSONB        NOT NULL DEFAULT '{}'::jsonb,
    video_file_url              VARCHAR(512),
    video_file_hash             VARCHAR(255),
    ip_address                  VARCHAR(45),
    device_fingerprint          VARCHAR(255),
    data_protection_agreed_at   TIMESTAMP,
    started_at                  TIMESTAMP,
    submitted_at                TIMESTAMP
);

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
    created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- SCORE
-- Risk scoring result for each submitted response.
-- risk_flags_json:  { band, auto_high, factors, knockouts, outcome }
-- narrative_json:   Gemini-generated HR narrative
--                   { title, summary, favor[], watch[] }
-- ------------------------------------------------------------
CREATE TABLE score (
    score_id                SERIAL PRIMARY KEY,
    response_id             INT          NOT NULL UNIQUE REFERENCES response(response_id) ON DELETE CASCADE,
    reviewed_by_user_id     INT REFERENCES users(user_id),
    questionnaire_result    VARCHAR(20),
    risk_score              NUMERIC(6,2) CHECK (risk_score IS NULL OR risk_score >= 0),
    risk_flags_json         JSONB        NOT NULL DEFAULT '[]'::jsonb,
    narrative_json          JSONB        NOT NULL DEFAULT '{}'::jsonb,
    final_decision          VARCHAR(20)
                                CHECK (final_decision IS NULL OR final_decision IN ('pending', 'accepted', 'rejected', 'manual_review')),
    reviewed_at             TIMESTAMP
);

-- ------------------------------------------------------------
-- INDEXES
-- ------------------------------------------------------------
CREATE INDEX idx_applicant_email           ON applicant(email);
CREATE INDEX idx_applicant_status          ON applicant(status);
CREATE INDEX idx_applicant_deleted_at      ON applicant(deleted_at);

CREATE INDEX idx_otp_applicant_id          ON otp(applicant_id);
CREATE INDEX idx_otp_expires_at            ON otp(expires_at);

CREATE INDEX idx_otp_request_log_applicant    ON otp_request_log(applicant_id);
CREATE INDEX idx_otp_request_log_requested_at ON otp_request_log(requested_at);

CREATE INDEX idx_question_config_active    ON question_config(is_active);

CREATE INDEX idx_response_answers_json     ON response USING GIN (answers_json);

CREATE INDEX idx_schedule_applicant_id     ON schedule(applicant_id);
CREATE INDEX idx_schedule_final_date       ON schedule(final_date);

CREATE INDEX idx_score_risk_score          ON score(risk_score);

COMMIT;
