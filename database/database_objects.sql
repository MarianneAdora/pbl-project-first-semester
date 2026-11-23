-- database/database_objects.sql
-- Views, functions, and triggers for the screening system.
-- Run this AFTER database/schema.sql and BEFORE database/rls.sql.

-- ============================================================
-- VIEW 1: Applicant screening summary
-- Joins applicant, job_positions, response, and score.
-- ============================================================
CREATE OR REPLACE VIEW v_applicant_screening_summary AS
SELECT
    a.applicant_id,
    a.reference_code,
    a.name,
    a.email,
    a.phone_number,
    a.status,
    a.result,
    a.verified_at,
    a.submitted_at,
    jp.job_name,
    r.video_file_url,
    r.ip_address,
    r.started_at,
    s.score_id,
    s.risk_score,
    s.final_decision,
    s.reviewed_at
FROM applicant a
LEFT JOIN job_positions jp ON a.position_id = jp.job_id
LEFT JOIN response r ON r.applicant_id = a.applicant_id
LEFT JOIN score s ON s.response_id = r.response_id
WHERE a.deleted_at IS NULL;

-- ============================================================
-- VIEW 2: Weekly hiring funnel aggregates
-- GROUP BY week, counts invited/submitted/passed/rejected, pass rate.
-- ============================================================
CREATE OR REPLACE VIEW v_weekly_funnel_stats AS
SELECT
    date_trunc('week', a.created_at) AS week_start,
    COUNT(*) AS total_invited,
    COUNT(*) FILTER (WHERE a.status = 'submitted') AS submitted_count,
    COUNT(*) FILTER (WHERE a.result = 'passed') AS passed_count,
    COUNT(*) FILTER (WHERE a.result = 'rejected') AS rejected_count,
    ROUND(100.0 * COUNT(*) FILTER (WHERE a.result = 'passed') / NULLIF(COUNT(*), 0), 2) AS pass_rate_pct
FROM applicant a
WHERE a.deleted_at IS NULL
GROUP BY date_trunc('week', a.created_at)
ORDER BY week_start DESC;

-- ============================================================
-- PL/pgSQL FUNCTION: Auto-archive terminal applicants
-- Archives rejected applicants 3 days after rejection, and passed
-- applicants 3 days after their latest confirmed/rescheduled interview.
-- ============================================================
CREATE OR REPLACE FUNCTION fn_auto_archive_terminal_applicants()
RETURNS INTEGER AS $$
DECLARE
    archived_count INTEGER := 0;
    second_count   INTEGER := 0;
BEGIN
    -- Archive rejected applicants 3 days after rejection
    UPDATE applicant
    SET deleted_at = NOW() AT TIME ZONE 'Asia/Manila'
    WHERE deleted_at IS NULL
      AND LOWER(COALESCE(result, '')) = 'rejected'
      AND rejected_at IS NOT NULL
      AND rejected_at <= (NOW() AT TIME ZONE 'Asia/Manila') - INTERVAL '3 days';

    GET DIAGNOSTICS archived_count = ROW_COUNT;

    -- Archive passed applicants 3 days after latest confirmed/rescheduled/completed interview
    UPDATE applicant a
    SET deleted_at = NOW() AT TIME ZONE 'Asia/Manila'
    WHERE a.deleted_at IS NULL
      AND LOWER(a.status) = 'completed'
      AND LOWER(COALESCE(a.result, '')) = 'passed'
      AND EXISTS (
          SELECT 1 FROM schedule s
          WHERE s.applicant_id = a.applicant_id
            AND s.status IN ('confirmed', 'rescheduled', 'completed')
            AND s.final_date IS NOT NULL
            AND s.final_time IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM schedule newer
                WHERE newer.applicant_id = s.applicant_id
                  AND newer.schedule_id > s.schedule_id
            )
          AND (s.final_date + s.final_time) + INTERVAL '3 days' <= (NOW() AT TIME ZONE 'Asia/Manila')
    );

    GET DIAGNOSTICS second_count = ROW_COUNT;

    archived_count := archived_count + second_count;

    RETURN archived_count;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- TRIGGER: Log applicant status changes to activity_log
-- ============================================================
CREATE OR REPLACE FUNCTION trg_log_applicant_status_change()
RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status IS DISTINCT FROM NEW.status THEN
        INSERT INTO activity_log (actor, actor_name, activity, details, created_at)
        VALUES (
            'system',
            'System',
            'Applicant status changed',
            format('Applicant %s (%s) status changed from %s to %s', NEW.name, NEW.reference_code, OLD.status, NEW.status),
            NOW() AT TIME ZONE 'UTC'
        );
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgname = 'trg_applicant_status_audit'
          AND tgrelid = 'applicant'::regclass
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER trg_applicant_status_audit
        AFTER UPDATE OF status ON applicant
        FOR EACH ROW
        EXECUTE FUNCTION trg_log_applicant_status_change();
    END IF;
END $$;
