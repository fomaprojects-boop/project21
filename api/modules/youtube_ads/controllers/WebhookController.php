<?php

namespace Modules\YouTubeAds\Controllers;

require_once __DIR__ . '/../services/WebhookService.php';

use Modules\YouTubeAds\Services\WebhookService;

class WebhookController {
    private $webhookService;

    public function __construct() {
        $this->webhookService = new WebhookService();
    }

    public function handleRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->handleVerification();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleNotification();
        } else {
            http_response_code(405);
            echo "This endpoint only supports GET (for verification) and POST (for notifications).";
        }
    }

    private function handleVerification() {
        if (isset($_GET['hub_challenge'])) {
            echo $_GET['hub_challenge'];
        } else {
            http_response_code(400);
        }
    }

    private function handleNotification() {
        $secret = getenv('WEBHOOK_SECRET');
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
        $payload = file_get_contents('php://input');

        if (!$this->webhookService->verifySignature($payload, $signature, $secret)) {
            http_response_code(403);
            exit;
        }

        $this->webhookService->processNotification($payload);
        http_response_code(200);
        echo 'ok';
    }
}

$webhookController = new WebhookController();
$webhookController->handleRequest();
