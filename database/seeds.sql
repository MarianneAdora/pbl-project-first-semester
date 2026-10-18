-- ============================================================
-- 1. SEED USERS
-- ============================================================
-- NOTE: Before running this, generate the hashes using your generate_hash.php file
-- and replace the placeholders below.

-- Clean up existing seed users to prevent duplicate key errors
DELETE FROM users WHERE email IN ('admin@expertise.com', 'hr@expertise.com');

INSERT INTO users (role, name, email, password_hash) VALUES 
('admin', 'System Admin', 'admin@expertise.com', '$2y$10$oOC0r1YyU3QOURWLlWQ26./BSESWAUkAfop0Dv2zSjgDaNFMePYnG'),
('hr', 'HR Staff', 'hr@expertise.com', '$2y$10$KSG4v4z3WMhknlDs8LZf3.d066DL98eITbXnpPboAXQ5P6es4fFuy');


-- ============================================================
-- 2. SEED QUESTIONS (From your UI Screenshots)
-- ============================================================
-- Optional: Clear existing questions so you don't get duplicates if you run this twice
DELETE FROM question_config;

INSERT INTO question_config (section, question_text, answer_type, passing_answer, is_knockout, reminder_text) VALUES
-- Section 1
('Sec 1 - Consent & Readiness', 'Have you read the job description thoroughly?', 'BOOLEAN', 'Yes', false, NULL),
('Sec 1 - Consent & Readiness', 'Are you willing to work on the schedule as advertised?', 'BOOLEAN', 'Yes', false, NULL),
('Sec 1 - Consent & Readiness', 'Are you willing to work on-site in BGC, Taguig?', 'BOOLEAN', 'Yes', true, 'Please note this may affect your application.'),
('Sec 1 - Consent & Readiness', 'Are you willing to go through the assessment and interviews?', 'BOOLEAN', 'Yes', false, NULL),
('Sec 1 - Consent & Readiness', 'Rate your interest level in pursuing your application.', 'SCALE', '4', false, NULL),

-- Section 2
('Sec 2 - Logistics & Compliance', 'When is your earliest possible start date?', 'DATE', NULL, false, NULL),
('Sec 2 - Logistics & Compliance', 'Are you currently employed? If yes, what is your notice period?', 'MULTIPLE_CHOICE', NULL, false, NULL),
('Sec 2 - Logistics & Compliance', 'Do you have access to a reliable internet connection and a device suitable for completing the assessment and virtual steps?', 'BOOLEAN', 'Yes', false, NULL),
('Sec 2 - Logistics & Compliance', 'Are you willing to undergo a background check as part of the hiring process?', 'BOOLEAN', 'Yes', false, NULL),
('Sec 2 - Logistics & Compliance', 'Can you provide required pre-employment documents (e.g. NBI clearance, TIN, SSS) upon request?', 'BOOLEAN', 'Yes', false, NULL),

-- Section 3
('Sec 3 - Confidentiality Acknowledgement', 'This role involves handling sensitive client information. Do you agree to comply with confidentiality and data protection requirements?', 'BOOLEAN', 'Yes', true, 'This is a mandatory requirement.'),

-- Section 4
('Sec 4 - Comprehension', 'In 2-3 sentences, describe what you understand this role to involve based on the job posting.', 'TEXT', NULL, false, NULL),

-- Section 5
('Sec 5 - Data Protection', 'Please read and accept the data protection statement.', 'BOOLEAN', 'Yes', true, 'You must agree to proceed.');


-- ============================================================
-- 3. SEED A TEST APPLICANT (For testing the OTP flow)
-- ============================================================
-- This creates an applicant you can use to test your API endpoints and UI.
INSERT INTO applicant (reference_code, secure_token, name, email, phone_number, status) VALUES 
('APP-2026-0001', 'test-token-abc123', 'Clark Kent', 'ebsmi.screening@gmail.com', '09123456789', 'pending');