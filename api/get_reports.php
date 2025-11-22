<?php
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Hii ni data ya mfano. Baadaye, itatolewa kutoka kwenye database.
$report_data = [
    "stats" => [
        "new_conversations" => 124,
        "messages_sent" => 876,
        "avg_response_time" => "1m 24s"
    ],
    "chart" => [
        "labels" => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        "data" => [12, 19, 8, 15, 10, 13, 19]
    ]
];

echo json_encode($report_data);
?>