CREATE TABLE IF NOT EXISTS admin_mfa_challenges (
    challenge_id BIGSERIAL PRIMARY KEY,
    admin_user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    purpose VARCHAR(40) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    code_hash VARCHAR(255) NOT NULL,
    operation_hash CHAR(64) NOT NULL,
    attempt_count SMALLINT NOT NULL DEFAULT 0,
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_admin_mfa_challenges_owner
    ON admin_mfa_challenges (admin_user_id, purpose, created_at DESC);