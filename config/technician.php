<?php

return [
    // Effective voluntary rejections reduce the matching score by 15 points each.
    'rejection_penalty' => (float) env('TECHNICIAN_REJECTION_PENALTY', 15),

    // System/duplicate/customer-unavailable rejections do not affect future ranking.
    'rejection_penalty_exempt_reasons' => [
        'system_error',
        'duplicate_offer',
        'customer_unavailable',
    ],
];
