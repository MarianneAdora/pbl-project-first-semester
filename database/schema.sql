CREATE DATABASE ebsmi_screening; 

BEGIN;

CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    role VARCHAR(20) NOT NULL CHECK (role IN ('hr', 'admin')),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    deleted_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE applicant (
    applicant_id SERIAL PRIMARY KEY,
    reference_code VARCHAR(50) NOT NULL UNIQUE,
    secure_token VARCHAR(64) NOT NULL UNIQUE,
    encoded_by_user_id INT REFERENCES users(user_id),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20),
    cv_file_url VARCHAR(512),
    device_fingerprint VARCHAR(255),
    ip_address VARCHAR(45),
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'in_progress', 'submitted', 'reviewed', 'rejected', 'accepted')),
    result VARCHAR(20),
    deleted_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE otp (
    otp_id SERIAL PRIMARY KEY,
    applicant_id INT NOT NULL UNIQUE REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    otp_code_hash VARCHAR(255) NOT NULL,
    request_count INT NOT NULL DEFAULT 0 CHECK (request_count >= 0),
    attempt_count INT NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    expires_at TIMESTAMP NOT NULL,
    locked_until TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE question_config (
    question_id SERIAL PRIMARY KEY,
    section_no INT NOT NULL,
    section VARCHAR(50) NOT NULL,
    question_text VARCHAR(400) NOT NULL,
    answer_config JSONB,
    -- type-specific settings: scale max/labels, yesno_select choices,
    -- text hint/placeholder/min/max, consent statement/checkbox label, {link} word/url
    passing_answer VARCHAR(255),
    is_knockout BOOLEAN NOT NULL DEFAULT FALSE,
    -- explicit flag for consent questions (data protection statement)
    is_consent BOOLEAN NOT NULL DEFAULT FALSE,
    -- explicit flag for data protection questions
    is_data_protection BOOLEAN NOT NULL DEFAULT FALSE,
    reminder_text VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by_user_id INT REFERENCES users(user_id),
    deleted_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE response (
    response_id SERIAL PRIMARY KEY,
    applicant_id INT NOT NULL UNIQUE REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    answers_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    video_file_url VARCHAR(512),
    video_file_hash VARCHAR(255),
    ip_address VARCHAR(45),
    device_fingerprint VARCHAR(255),
    data_protection_agreed_at TIMESTAMP,
    started_at TIMESTAMP,
    submitted_at TIMESTAMP
);

CREATE TABLE schedule (
    schedule_id SERIAL PRIMARY KEY,
    applicant_id INT NOT NULL REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    preferred_date DATE,
    preferred_time TIME,
    final_date DATE,
    final_time TIME,
    venue VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'confirmed', 'rescheduled', 'completed', 'cancelled')),
    email_sent_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE score (
    score_id SERIAL PRIMARY KEY,
    response_id INT NOT NULL UNIQUE REFERENCES response(response_id) ON DELETE CASCADE,
    reviewed_by_user_id INT REFERENCES users(user_id),
    questionnaire_result VARCHAR(20),
    risk_score NUMERIC(6,2) CHECK (risk_score IS NULL OR risk_score >= 0),
    risk_flags_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    final_decision VARCHAR(20)
        CHECK (final_decision IS NULL OR final_decision IN ('pending', 'accepted', 'rejected', 'manual_review')),
    reviewed_at TIMESTAMP
);

-- Risk scoring additions
-- invite_link_clicked_at: when the applicant first opened their invite link
-- (recorded only; not scored — email scanners open links within seconds)
ALTER TABLE applicant ADD COLUMN IF NOT EXISTS invite_link_clicked_at TIMESTAMP;

-- timing_json: per-question millisecond timings sent by the questionnaire JS
-- { "1": 4200, "2": 1100, ... }  (question_id -> ms)
ALTER TABLE response ADD COLUMN IF NOT EXISTS timing_json JSONB NOT NULL DEFAULT '{}'::jsonb;

-- otp_request_log: one row per OTP send so the 10-minute window can be computed
CREATE TABLE IF NOT EXISTS otp_request_log (
    log_id       SERIAL PRIMARY KEY,
    applicant_id INT NOT NULL REFERENCES applicant(applicant_id) ON DELETE CASCADE,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_otp_request_log_applicant ON otp_request_log(applicant_id);
CREATE INDEX IF NOT EXISTS idx_otp_request_log_requested_at ON otp_request_log(requested_at);

-- score.narrative_json: Gemini-generated HR narrative stored alongside the rubric numbers
ALTER TABLE score ADD COLUMN IF NOT EXISTS narrative_json JSONB NOT NULL DEFAULT '{}'::jsonb;

-- Indexes for common lookups and soft-delete filtering
CREATE INDEX idx_applicant_email ON applicant(email);
CREATE INDEX idx_applicant_status ON applicant(status);
CREATE INDEX idx_applicant_deleted_at ON applicant(deleted_at);
CREATE INDEX idx_otp_applicant_id ON otp(applicant_id);
CREATE INDEX idx_otp_expires_at ON otp(expires_at);
CREATE INDEX idx_question_config_active ON question_config(is_active);
CREATE INDEX idx_response_answers_json ON response USING GIN (answers_json);
CREATE INDEX idx_schedule_applicant_id ON schedule(applicant_id);
CREATE INDEX idx_schedule_final_date ON schedule(final_date);
CREATE INDEX idx_score_risk_score ON score(risk_score);

COMMIT;
