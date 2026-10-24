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

INSERT INTO question_config (section_no, section, question_text, answer_config, passing_answer, is_knockout, is_consent, is_data_protection, reminder_text) VALUES
-- Section 1
(1, 'Sec 1 - Consent & Readiness', 'Have you read the job description thoroughly?', NULL, 'Yes', false, false, false, NULL),
(1, 'Sec 1 - Consent & Readiness', 'Are you willing to work on the schedule as advertised?', NULL, 'Yes', false, false, false, NULL),
(1, 'Sec 1 - Consent & Readiness', 'Are you willing to work on-site in BGC, Taguig?', NULL, 'Yes', true, false, false, 'Please note this may affect your application.'),
(1, 'Sec 1 - Consent & Readiness', 'Are you willing to go through the assessment and interviews?', NULL, 'Yes', false, false, false, NULL),
(1, 'Sec 1 - Consent & Readiness', 'Rate your interest level in pursuing your application.', jsonb_build_object('max', 10, 'labels', '["Not interested", "Very interested"]'), '4', false, false, false, NULL),

-- Section 2
(2, 'Sec 2 - Logistics & Compliance', 'When is your earliest possible start date?', NULL, NULL, false, false, false, NULL),
(2, 'Sec 2 - Logistics & Compliance', 'Are you currently employed? If yes, what is your notice period?', jsonb_build_object('choices', '["Yes - immediate", "Yes - 2 weeks", "Yes - 1 month", "No"]'), NULL, false, false, false, NULL),
(2, 'Sec 2 - Logistics & Compliance', 'Do you have access to a reliable internet connection and a device suitable for completing the assessment and virtual steps?', NULL, 'Yes', false, false, false, NULL),
(2, 'Sec 2 - Logistics & Compliance', 'Are you willing to undergo a background check as part of the hiring process?', NULL, 'Yes', false, false, false, NULL),
(2, 'Sec 2 - Logistics & Compliance', 'Can you provide required pre-employment documents (e.g. NBI clearance, TIN, SSS) upon request?', NULL, 'Yes', false, false, false, NULL),

-- Section 3
(3, 'Sec 3 - Confidentiality Acknowledgement', 'This role involves handling sensitive client information. Do you agree to comply with confidentiality and data protection requirements?', NULL, 'Yes', true, false, true, 'This is a mandatory requirement.'),

-- Section 4
(4, 'Sec 4 - Comprehension', 'In 2-3 sentences, describe what you understand this role to involve based on the job posting.', NULL, NULL, false, false, false, NULL),

-- Section 5
(5, 'Sec 5 - Data Protection', 'Please read and accept the data protection statement.', jsonb_build_object('statement', 'I agree to the data protection terms.', 'checkbox_label', 'I agree'), 'Yes', true, true, true, 'You must agree to proceed.');


-- (No sample applicants — the application creates applicants via the web form; seeds only core reference tables.)