<?php
// api/get_templates.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$userId = $_SESSION['user_id'];

function fetchTemplates($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, name, body, header, footer, quick_replies, status, variables FROM message_templates WHERE user_id = ? ORDER BY name");
    $stmt->execute([$userId]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($templates as &$template) {
        $decoded_replies = json_decode($template['quick_replies']);
        if (json_last_error() === JSON_ERROR_NONE) {
            $template['quick_replies'] = $decoded_replies;
        } else {
            $template['quick_replies'] = !empty($template['quick_replies']) ? array_map('trim', explode(',', $template['quick_replies'])) : [];
        }

        $template['variables'] = !empty($template['variables']) ? json_decode($template['variables']) : [];
    }
    return $templates;
}

try {
    $templates = fetchTemplates($pdo, $userId);

    // If the user has no templates, create some default ones
    if (count($templates) === 0) {
        $defaultTemplates = [
            [
                'name' => 'order_confirmation',
                'category' => 'UTILITY',
                'body' => 'Hi {{customer_name}}! 👋 Thanks for your order #{{order_number}}. We have received it and will notify you once it has been shipped. You can view your order status here: {{order_status}}',
                'header' => 'Your Order is Confirmed!',
                'footer' => 'Thank you for shopping with us.',
                'quick_replies' => '["Track Order", "Contact Support"]',
                'status' => 'APPROVED' // Assume default templates are pre-approved for display
            ],
            [
                'name' => 'appointment_reminder',
                'category' => 'UTILITY',
                'body' => 'Hi {{customer_name}}, this is a reminder for your upcoming appointment on {{delivery_date}}. Please reply YES to confirm or NO to reschedule. We look forward to seeing you!',
                'header' => 'Appointment Reminder',
                'footer' => 'Your trusted clinic.',
                'quick_replies' => '["YES", "NO"]',
                'status' => 'APPROVED'
            ],
            [
                'name' => 'welcome_message',
                'category' => 'MARKETING',
                'body' => 'Hello and welcome to {{company_name}}! 🎉 We are so happy to have you. As a special welcome gift, enjoy 10% off your first purchase with code WELCOME10.',
                'header' => '',
                'footer' => 'Reply STOP to unsubscribe.',
                'quick_replies' => '["Shop Now"]',
                'status' => 'APPROVED'
            ]
        ];

        $insertStmt = $pdo->prepare(
            "INSERT INTO message_templates (user_id, name, category, body, header, footer, quick_replies, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($defaultTemplates as $tpl) {
            $insertStmt->execute([
                $userId,
                $tpl['name'],
                $tpl['category'],
                $tpl['body'],
                $tpl['header'],
                $tpl['footer'],
                $tpl['quick_replies'],
                $tpl['status']
            ]);
        }

        // Re-fetch templates after creating the default ones
        $templates = fetchTemplates($pdo, $userId);
    }

    echo json_encode($templates);

} catch (PDOException $e) {
    error_log('Database error in get_templates.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
?>
