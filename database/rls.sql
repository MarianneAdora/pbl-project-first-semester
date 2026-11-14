-- database/rls.sql — Row Level Security policies for PostgreSQL.
-- Apply after schema.sql. Requires the app to set a DB role per request
-- (e.g. via SET ROLE) matching the policy checks below.
--
-- Roles used by the app: hr, admin, applicant

-- ------------------------------------------------------------
-- USERS
-- ------------------------------------------------------------
ALTER TABLE users ENABLE ROW LEVEL SECURITY;

-- Admins can see all users
CREATE POLICY users_admin_all ON users
    FOR ALL TO admin
    USING (true);

-- HR can see active HR users only (not admins)
CREATE POLICY users_hr_read ON users
    FOR SELECT TO hr
    USING (role = 'hr' AND deleted_at IS NULL);

-- Users can read their own row
CREATE POLICY users_own_read ON users
    FOR SELECT TO PUBLIC
    USING (user_id = current_setting('app.user_id', true)::int);

-- ------------------------------------------------------------
-- APPLICANT
-- ------------------------------------------------------------
ALTER TABLE applicant ENABLE ROW LEVEL SECURITY;

-- Admin/HR can see all non-deleted applicants
CREATE POLICY applicant_staff_read ON applicant
    FOR SELECT TO admin, hr
    USING (deleted_at IS NULL);

-- Applicant can read their own row
CREATE POLICY applicant_own_read ON applicant
    FOR SELECT TO applicant
    USING (applicant_id = current_setting('app.applicant_id', true)::int);

-- ------------------------------------------------------------
-- RESPONSE
-- ------------------------------------------------------------
ALTER TABLE response ENABLE ROW LEVEL SECURITY;

-- Admin/HR can see all responses
CREATE POLICY response_staff_read ON response
    FOR SELECT TO admin, hr
    USING (true);

-- Applicant can read their own response
CREATE POLICY response_own_read ON response
    FOR SELECT TO applicant
    USING (applicant_id = current_setting('app.applicant_id', true)::int);

-- ------------------------------------------------------------
-- SCORE
-- ------------------------------------------------------------
ALTER TABLE score ENABLE ROW LEVEL SECURITY;

CREATE POLICY score_staff_read ON score
    FOR SELECT TO admin, hr
    USING (true);

CREATE POLICY score_own_read ON score
    FOR SELECT TO applicant
    USING (
        EXISTS (
            SELECT 1 FROM response r
            WHERE r.response_id = score.response_id
              AND r.applicant_id = current_setting('app.applicant_id', true)::int
        )
    );

-- ------------------------------------------------------------
-- OTP
-- ------------------------------------------------------------
ALTER TABLE otp ENABLE ROW LEVEL SECURITY;

CREATE POLICY otp_staff_read ON otp
    FOR SELECT TO admin, hr
    USING (true);

CREATE POLICY otp_own_read ON otp
    FOR SELECT TO applicant
    USING (
        applicant_id = current_setting('app.applicant_id', true)::int
    );

-- ------------------------------------------------------------
-- QUESTION_CONFIG
-- ------------------------------------------------------------
ALTER TABLE question_config ENABLE ROW LEVEL SECURITY;

CREATE POLICY question_staff_all ON question_config
    FOR ALL TO admin, hr
    USING (true);

CREATE POLICY question_active_read ON question_config
    FOR SELECT TO applicant
    USING (is_active = true AND deleted_at IS NULL);
