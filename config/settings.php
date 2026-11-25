<?php
// ============================================================
// The values behind the rules (SDD class attributes). Change them here; there is no settings table (SDD Table 3.1).
// ============================================================
return [
    // AccountController
    'MAX_FAILED_ATTEMPTS' => 5,        // failed logins before the account is locked
    'LOCKOUT_MINUTES'     => 15,       // how long the account stays locked
    'IDLE_TIMEOUT_MIN'    => 15,       // minutes before an idle session ends
    'CODE_EXPIRY_MINUTES' => 15,       // a verification code expires after this many minutes
    'UIS_EMAIL_DOMAINS'   => ['uis.edu.my'],
    // RequestController
    'BUFFER_HOURS'        => 1,        // the reservation lasts until this many hours after the return time
    'CHARGE_PER_KM'       => 3.00,     // Personal request: RM per km of the round trip
    'URGENT_HOURS'        => 48,       // a request departing within this many hours is Urgent
    // AssignmentController
    'TASK_DURATION_HOURS' => 1,        // default length of the outbound and the return task
    // RecommendationService
    'W_TASKS'             => 0.5,      // weight of the number of tasks this month
    'W_WEEKEND'           => 0.3,      // weight of the number of weekend tasks this month
    'W_EXPERIENCE'        => 0.2,      // weight of the years of experience
    'RECOMMEND_TOP'       => 3,        // the best-ranked drivers marked Recommended
    // ScheduledJob
    'REMINDER_HOURS'      => 24,       // reminder email after one day
    'LAPSE_HOURS'         => 48,       // lapse after two days
];
