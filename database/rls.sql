-- database/rls.sql — Row Level Security policies for PostgreSQL.
-- Apply after schema.sql. Requires the app to set a DB role per request
-- (e.g. via SET ROLE) matching the policy checks below.
--
-- Roles used by the app: hr, admin

-- Create application roles if they don't already exist
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'admin') THEN
        CREATE ROLE admin LOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'hr') THEN
        CREATE ROLE hr LOGIN;
    END IF;
END $$;

-- ------------------------------------------------------------
-- Table privileges
-- ------------------------------------------------------------

-- Admin: full access to all application tables
GRANT SELECT, INSERT, UPDATE, DELETE ON users, applicant, response, score, otp, question_config, job_positions, schedule, activity_log, password_requests, otp_request_log TO admin;

-- HR: read + limited write on applicant-facing tables
GRANT SELECT, INSERT, UPDATE, DELETE ON applicant, response, score, schedule, activity_log TO hr;
GRANT SELECT ON users, otp, question_config, job_positions TO hr;
-- HR can update their own user row (name/email settings)
GRANT UPDATE (name, email, email_normalized) ON users TO hr;
GRANT SELECT, INSERT ON otp_request_log TO hr;

-- ------------------------------------------------------------
-- USERS
-- ------------------------------------------------------------
ALTER TABLE users ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS users_admin_all ON users;
CREATE POLICY users_admin_all ON users
    FOR ALL TO admin
    USING (true);

DROP POLICY IF EXISTS users_hr_read ON users;
CREATE POLICY users_hr_read ON users
    FOR SELECT TO hr
    USING (role = 'hr' AND deleted_at IS NULL);

DROP POLICY IF EXISTS users_own_read ON users;
CREATE POLICY users_own_read ON users
    FOR SELECT TO PUBLIC
    USING (user_id = current_setting('app.user_id', true)::int);

-- ------------------------------------------------------------
-- APPLICANT
-- ------------------------------------------------------------
ALTER TABLE applicant ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS applicant_staff_read ON applicant;
CREATE POLICY applicant_staff_read ON applicant
    FOR SELECT TO admin, hr
    USING (deleted_at IS NULL);

-- ------------------------------------------------------------
-- RESPONSE
-- ------------------------------------------------------------
ALTER TABLE response ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS response_staff_read ON response;
CREATE POLICY response_staff_read ON response
    FOR SELECT TO admin, hr
    USING (true);

-- ------------------------------------------------------------
-- SCORE
-- ------------------------------------------------------------
ALTER TABLE score ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS score_staff_read ON score;
CREATE POLICY score_staff_read ON score
    FOR SELECT TO admin, hr
    USING (true);

-- ------------------------------------------------------------
-- OTP
-- ------------------------------------------------------------
ALTER TABLE otp ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS otp_staff_read ON otp;
CREATE POLICY otp_staff_read ON otp
    FOR SELECT TO admin, hr
    USING (true);

DROP POLICY IF EXISTS otp_staff_insert ON otp;
CREATE POLICY otp_staff_insert ON otp
    FOR INSERT TO admin, hr
    WITH CHECK (true);

DROP POLICY IF EXISTS otp_staff_update ON otp;
CREATE POLICY otp_staff_update ON otp
    FOR UPDATE TO admin, hr
    USING (true);

-- ------------------------------------------------------------
-- QUESTION_CONFIG
-- ------------------------------------------------------------
ALTER TABLE question_config ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS question_admin_all ON question_config;
CREATE POLICY question_admin_all ON question_config
    FOR ALL TO admin
    USING (true);

DROP POLICY IF EXISTS question_hr_read ON question_config;
CREATE POLICY question_hr_read ON question_config
    FOR SELECT TO hr
    USING (is_active = true AND deleted_at IS NULL);

-- ------------------------------------------------------------
-- JOB_POSITIONS
-- ------------------------------------------------------------
ALTER TABLE job_positions ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS job_positions_admin_all ON job_positions;
CREATE POLICY job_positions_admin_all ON job_positions
    FOR ALL TO admin
    USING (true);

DROP POLICY IF EXISTS job_positions_hr_read ON job_positions;
CREATE POLICY job_positions_hr_read ON job_positions
    FOR SELECT TO hr
    USING (archived_at IS NULL);

-- ------------------------------------------------------------
-- PASSWORD_REQUESTS
-- ------------------------------------------------------------
ALTER TABLE password_requests ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS password_requests_admin_all ON password_requests;
CREATE POLICY password_requests_admin_all ON password_requests
    FOR ALL TO admin
    USING (true);

DROP POLICY IF EXISTS password_requests_hr_own ON password_requests;
CREATE POLICY password_requests_hr_own ON password_requests
    FOR SELECT TO hr
    USING (account_id = current_setting('app.user_id', true)::int);
