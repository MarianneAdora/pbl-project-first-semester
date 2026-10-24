<?php
/**
 * config/risk_config.php — Single source of truth for all risk scoring thresholds.
 * Change values here; the scoring engine and tests read from this file.
 */
return [
    // Result bands: 0..low_max = Low, low_max+1..medium_max = Medium, above = High
    'bands' => ['low_max' => 3, 'medium_max' => 6],

    // OTP: failed attempts before success (max 2 pts)
    'otp_failed' => ['mid' => 3, 'high' => 5],   // <3 = 0, 3-4 = 1, >=5 or locked = 2

    // OTP: requests within a rolling window (max 2 pts)
    'otp_requests' => ['window_minutes' => 10, 'mid' => 3, 'high' => 5],

    // Questionnaire speed (max 3 pts) — higher of total-time score vs fast-item-share score
    'speed' => [
        'fast_item_ms'  => 1500,              // item answered in under 1.5 s is "fast"
        'total_seconds' => [60, 40, 25],      // >=60 -> 0, 40-59 -> 1, 25-39 -> 2, <25 -> 3
        'fast_share'    => [0.50, 0.70, 0.90] // <50% -> 0, >=50% -> 1, >=70% -> 2, >=90% -> 3
    ],

    // Re-applying after a REJECT (max 2 pts)
    'reapply_days' => ['high' => 7, 'mid' => 30],

    // Same IP: min distinct applicants within window to score 1 pt (IP alone = 0)
    'ip' => ['window_hours' => 24, 'min_others' => 3],

    // Same device ID as other applicants within window (max 3 pts; >=2 = automatic High)
    'device' => ['window_days' => 7],

    // Logistics contradiction: notice-period minimum days per label
    'notice_days' => [
        'Less than 1 week'       => 0,
        '1 to 2 weeks'           => 7,
        '3 to 4 weeks (30 days)' => 21,
        '1 to 2 months'          => 30,
        'More than 2 months'     => 60,
    ],

    // Video page: minimum seconds expected for a live recording
    'video_page_min_seconds' => 10,

    // Automatic-High triggers (checked by name in the scoring engine)
    'auto_high_triggers' => [
        'same_email_different_name',
        'same_device_two_or_more',
        'video_hash_match',
    ],
];
