<?php
// api/get_dashboard_summary.php

require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit();
}

header('Content-Type: application/json');

// In a real application, you would query the database here.
// For this example, we will simulate the data.
$summary_data = [
    'new_contacts_30d' => 12,
    'invoices_sent_30d' => 25,
    'pending_payouts' => 3,
    'recent_activity' => [
        [
            'description' => 'Invoice #INV-0012 was paid.',
            'timestamp' => date('c', strtotime('-1 hour')),
        ],
        [
            'description' => 'New contact "John Doe" was added.',
            'timestamp' => date('c', strtotime('-3 hours')),
        ],
        [
            'description' => 'Broadcast "November Promo" was sent.',
            'timestamp' => date('c', strtotime('-1 day')),
        ],
        [
            'description' => 'Payout request for "Creative Designs Ltd" was approved.',
            'timestamp' => date('c', strtotime('-2 days')),
        ]
    ]
];

echo json_encode($summary_data);

?>
