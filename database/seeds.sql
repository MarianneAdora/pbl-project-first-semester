-- ============================================================
-- USERS
-- Passwords are bcrypt hashes. Regenerate with generate_hash.php
-- if you need to change them.
-- ============================================================
DELETE FROM users WHERE email IN ('admin@expertise.com', 'hr@expertise.com');

INSERT INTO users (role, name, email, password_hash) VALUES
    ('admin', 'System Admin', 'admin@expertise.com', '$2y$10$oOC0r1YyU3QOURWLlWQ26./BSESWAUkAfop0Dv2zSjgDaNFMePYnG'),
    ('hr',    'HR Staff',     'hr@expertise.com',    '$2y$10$KSG4v4z3WMhknlDs8LZf3.d066DL98eITbXnpPboAXQ5P6es4fFuy');


-- ============================================================
-- QUESTIONS
-- 13 questions across 5 sections. Safe to re-run (DELETE first).
-- ============================================================
DELETE FROM question_config;

INSERT INTO question_config
    (section_no, section, question_text, question_type, answer_config, passing_answer, is_knockout, is_consent, is_data_protection, reminder_text)
VALUES

-- ------------------------------------------------------------
-- Section 1: Consent & Readiness
-- ------------------------------------------------------------
(1, 'Consent & Readiness',
    'Have you read the {link} thoroughly?',
    'yesno',
    jsonb_build_object(
        'no_note', 'Please read the job description first. The link above opens it in a new tab.',
        'link',    jsonb_build_object(
                       'text', 'job description',
                       'url',  'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf'
                   )
    ),
    NULL,
    false, false, false, NULL),

(1, 'Consent & Readiness',
    'Are you willing to work on the schedule as advertised?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(1, 'Consent & Readiness',
    'Are you willing to work on-site in BGC, Taguig?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, 'Please note this may affect your application.'),

(1, 'Consent & Readiness',
    'Are you willing to go through the assessment and interviews?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(1, 'Consent & Readiness',
    'Rate your interest level in pursuing your application.',
    'scale',
    jsonb_build_object(
        'max',    5,
        'labels', jsonb_build_array('Not very interested', 'Extremely interested')
    ),
    NULL,
    false, false, false, NULL),

-- ------------------------------------------------------------
-- Section 2: Logistics & Compliance
-- ------------------------------------------------------------
(2, 'Logistics & Compliance',
    'When is your earliest possible start date?',
    'date',
    jsonb_build_object('hint', 'Choose a date'),
    NULL,
    false, false, false, NULL),

(2, 'Logistics & Compliance',
    'Are you currently employed? If yes, what is your notice period?',
    'yesno_select',
    jsonb_build_object(
        'followup_when',    'Yes',
        'followup_label',   'Your notice period',
        'followup_options', jsonb_build_array(
                                'Less than 1 week',
                                '1 to 2 weeks',
                                '3 to 4 weeks (30 days)',
                                '1 to 2 months',
                                'More than 2 months'
                            )
    ),
    NULL,
    false, false, false, NULL),

(2, 'Logistics & Compliance',
    'Do you have access to a reliable internet connection and a device suitable for completing the assessment and virtual steps?',
    'yesno',
    jsonb_build_object(
        'no_note', 'A stable connection and a working device are needed for the assessment and the virtual steps.'
    ),
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(2, 'Logistics & Compliance',
    'Are you willing to undergo a background check as part of the hiring process?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(2, 'Logistics & Compliance',
    'Can you provide required pre-employment documents (e.g. NBI clearance, TIN, SSS) upon request?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

-- ------------------------------------------------------------
-- Section 3: Confidentiality Acknowledgment
-- ------------------------------------------------------------
(3, 'Confidentiality Acknowledgment',
    'This role involves handling sensitive client information. Do you agree to comply with confidentiality and data protection requirements?',
    'yesno',
    jsonb_build_object(
        'no_note', 'Complying with confidentiality and data protection requirements is required for this role.'
    ),
    jsonb_build_object('answer', 'Yes'),
    true, false, true, NULL),

-- ------------------------------------------------------------
-- Section 4: Comprehension
-- ------------------------------------------------------------
(4, 'Comprehension',
    'In 2-3 sentences, describe what you understand this role to involve based on the job posting.',
    'text',
    jsonb_build_object(
        'hint',        'Write it in your own words.',
        'placeholder', 'Type your answer here...',
        'min_chars',   30,
        'max_chars',   500
    ),
    NULL,
    false, false, false, NULL),

-- ------------------------------------------------------------
-- Section 5: Data Protection
-- ------------------------------------------------------------
(5, 'Data Protection',
    'Please read and accept the data protection statement.',
    'consent',
    jsonb_build_object(
        'body', jsonb_build_array(
            'By submitting your application, you agree that Expertise Company will use your data by collecting, processing, storing, and using your personal information for legitimate recruitment and employment purposes only, including applicant screening, assessment, interview scheduling, and communication. Your information will be kept confidential and secure and will only be accessed by authorized personnel or disclosed when required or permitted by law.',
            'You have the right to access, correct, and request the deletion or restriction of your personal information, subject to applicable laws and regulations.',
            'In accordance with the Data Privacy Act of 2012 (Republic Act No. 10173), The Company is committed to protecting the privacy and confidentiality of your personal information. Rest assured that Expertise will not use your personal information for purposes unrelated to recruitment without obtaining the appropriate consent or having another lawful basis for such processing.',
            'To keep the screening fair and secure, we also collect technical information such as your device identifier and IP address, and how you move through the screening (for example, the time spent on each question). This information is used only to protect the integrity of the screening and is reviewed by authorized personnel.'
        ),
        'checkbox', 'I have read and agree to the data protection statement.'
    ),
    NULL,
    false, true, true, 'You must agree to proceed.');
