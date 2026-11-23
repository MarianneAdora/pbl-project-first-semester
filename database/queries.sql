-- database/queries.sql
-- 10 standalone SQL queries for the EBSMI Screening System
-- Satisfies FUNDB Rubric Section 3: joins, GROUP BY/aggregates, subqueries, ORDER BY/filters

-- Q1. Count applicants by status (GROUP BY, ORDER BY)
SELECT status, COUNT(*) AS total
FROM applicant
WHERE deleted_at IS NULL
GROUP BY status
ORDER BY total DESC;

-- Q2. List all HR staff accounts (ORDER BY)
SELECT user_id, name, email, status, created_at
FROM users
WHERE role = 'hr' AND deleted_at IS NULL
ORDER BY created_at;

-- Q3. List all active screening questions (ORDER BY)
SELECT question_id, section, question_text, question_type, is_knockout
FROM question_config
WHERE deleted_at IS NULL AND is_active = TRUE
ORDER BY section_no, display_order;

-- Q4. Applicants who have not yet verified their OTP (WHERE filter, ORDER BY)
SELECT applicant_id, name, email, invited_at
FROM applicant
WHERE deleted_at IS NULL AND verified_at IS NULL
ORDER BY invited_at;

-- Q5. Interview schedules by status (GROUP BY, ORDER BY)
SELECT status, COUNT(*) AS total
FROM schedule
GROUP BY status
ORDER BY total DESC;

-- Q6. HR staff pass rate ranking (JOIN, GROUP BY, aggregate, ORDER BY)
SELECT
    u.name AS hr_name,
    COUNT(a.applicant_id) AS total_applicants,
    COUNT(*) FILTER (WHERE a.result = 'passed') AS passed_count,
    ROUND(100.0 * COUNT(*) FILTER (WHERE a.result = 'passed') / NULLIF(COUNT(*), 0), 2) AS pass_rate_pct
FROM applicant a
JOIN users u ON a.encoded_by_user_id = u.user_id
WHERE a.deleted_at IS NULL AND u.role = 'hr'
GROUP BY u.user_id, u.name
ORDER BY pass_rate_pct DESC;

-- Q7. Average days to submit per job position (JOIN, GROUP BY, aggregate, ORDER BY)
SELECT
    jp.job_name,
    COUNT(a.applicant_id) AS total_applicants,
    ROUND(AVG(EXTRACT(EPOCH FROM (a.submitted_at - a.invited_at)) / 86400), 2) AS avg_days_to_submit
FROM applicant a
JOIN job_positions jp ON a.position_id = jp.job_id
WHERE a.deleted_at IS NULL AND a.submitted_at IS NOT NULL
GROUP BY jp.job_id, jp.job_name
ORDER BY avg_days_to_submit;

-- Q8. Weekly applicant volume and interview turnaround (JOIN, GROUP BY, aggregate, ORDER BY)
SELECT
    date_trunc('week', a.created_at) AS week_start,
    COUNT(a.applicant_id) AS applicants_invited,
    COUNT(s.schedule_id) AS interviews_scheduled,
    ROUND(AVG(EXTRACT(EPOCH FROM (s.created_at - a.created_at)) / 3600), 2) AS avg_turnaround_hours
FROM applicant a
LEFT JOIN schedule s ON s.applicant_id = a.applicant_id
WHERE a.deleted_at IS NULL
GROUP BY date_trunc('week', a.created_at)
ORDER BY week_start DESC;

-- Q9. Risk score distribution by band (JOIN, GROUP BY, aggregate, ORDER BY)
SELECT
    (s.risk_flags_json->>'band') AS risk_band,
    COUNT(*) AS applicant_count,
    ROUND(AVG(s.risk_score), 2) AS avg_risk_score
FROM score s
JOIN response r ON s.response_id = r.response_id
JOIN applicant a ON r.applicant_id = a.applicant_id
WHERE a.deleted_at IS NULL
GROUP BY (s.risk_flags_json->>'band')
ORDER BY avg_risk_score DESC;

-- Q10. Knockout question failure rates (JOIN, subquery, GROUP BY, aggregate, ORDER BY, LIMIT)
SELECT
    qc.question_id,
    qc.section,
    qc.question_text,
    COUNT(*) AS total_submissions,
    COUNT(r.response_id) AS failed_count,
    ROUND(100.0 * COUNT(r.response_id) / NULLIF(COUNT(*), 0), 2) AS failure_rate_pct
FROM question_config qc
JOIN response r ON r.applicant_id IN (
    SELECT applicant_id FROM response WHERE submitted_at IS NOT NULL
)
WHERE qc.deleted_at IS NULL
  AND qc.is_knockout = TRUE
GROUP BY qc.question_id, qc.section, qc.question_text
ORDER BY failure_rate_pct DESC
LIMIT 10;
