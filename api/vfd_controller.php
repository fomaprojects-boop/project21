<?php
/**
 * VFDController
 *
 * This class handles all interactions with the TRA VFD API.
 * It is designed to be used by cron jobs and other parts of the application
 * that need to register clients, request tokens, and submit receipts.
 *
 * @version 1.0
 * @author Jules
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class VFDController {
    
    private $pdo;
    private $base_url = 'https://vfd.tra.go.tz';

    /**
     * Constructor
     */
    public function __construct() {
        // Use the global PDO connection
        global $pdo;
        if ($pdo === null) {
            // Handle case where global PDO is not available (e.g., direct script execution)
             $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
             try {
                $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
             } catch (PDOException $e) {
                 die("Failed to connect to DB: " . $e->getMessage());
             }
        } else {
            $this->pdo = $pdo;
        }
    }

    /**
     * Main function to process due submissions for a specific client.
     * This would be called by the cron job.
     *
     * @param int $clientId The ID of the user/client.
     * @return array Result of the submission process.
     */
    public function processClientSubmissions($clientId) {
        // This is a high-level function that orchestrates the entire process.

        // 1. Get client's TIN from the users table.
        $stmt = $this->pdo->prepare("SELECT tin_number FROM users WHERE id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client || empty($client['tin_number'])) {
            return ['status' => 'error', 'message' => 'Client TIN not found.'];
        }

        $tin = $client['tin_number'];

        // 2. Get a valid access token for the client.
        $tokenResult = $this->getAccessToken($tin);
        if ($tokenResult['status'] === 'error') {
            return $tokenResult; // Return the error message from getToken
        }
        $token = $tokenResult['token'];

        // 3. Fetch all paid invoices for the client that haven't been submitted yet.
        //    (This logic will need to be very robust in a real system)
        $invoices = $this->getUnsubmittedInvoices($clientId);
        if (empty($invoices)) {
            return ['status' => 'success', 'message' => 'No new paid invoices to submit.'];
        }
        
        // 4. Format the invoices into the required Z-Report JSON structure.
        $receipt = $this->prepareReceiptPayload($tin, $invoices);

        // 5. Submit the formatted receipt to the VFD API.
        $submissionResult = $this->submitReceipt($token, $receipt);
        
        // 6. Log the result and update the status of the submitted invoices.
        $this->logSubmission($clientId, $submissionResult, $invoices);
        if ($submissionResult['status'] === 'success') {
            $this->markInvoicesAsSubmitted($invoices);
        }

        return $submissionResult;
    }

    /**
     * Registers a client with the VFD system.
     * This is a one-time process per client (TIN).
     *
     * @param string $tin The client's TIN number.
     * @return array The result from the VFD API.
     */
    public function registerClient($tin) {
        $endpoint = '/api/v1/reg';

        // DEVELOPER ACTION REQUIRED:
        // You must fill in your VFD_PROVIDER_REGID from the config file.
        // This is the registration ID provided to you by TRA as a VFD provider.
        if (!defined('VFD_PROVIDER_REGID') || empty(VFD_PROVIDER_REGID)) {
             return ['status' => 'error', 'message' => 'Configuration error: VFD_PROVIDER_REGID is not set.'];
        }

        $payload = [
            'tin' => $tin,
            'regid' => VFD_PROVIDER_REGID
        ];
        
        $headers = ['Content-Type: application/json'];
        
        // This is a placeholder for the actual API call.
        // You will need to implement the 'sendRequest' method to make a real HTTP POST request.
        $response = $this->sendRequest($this->base_url . $endpoint, 'POST', json_encode($payload), $headers);

        // After a successful registration, you should update the user's status in your database.
        // For example, set `vfd_is_verified` to true.
        if ($response && isset($response['success']) && $response['success']) {
             $stmt = $this->pdo->prepare("UPDATE users SET vfd_is_verified = 1 WHERE tin_number = ?");
             $stmt->execute([$tin]);
        }
        
        return $response;
    }

    /**
     * Gets an authentication token from the VFD API.
     * Tokens are required for submitting receipts.
     *
     * @param string $tin The client's TIN number.
     * @return array An array containing the status and the token, or an error message.
     */
    private function getAccessToken($tin) {
        $endpoint = '/api/v1/auth';
        
        // DEVELOPER ACTION REQUIRED:
        // You must fill in your VFD_PROVIDER_CERTKEY from the config file.
        // This is the certificate key/serial provided to you by TRA as a VFD provider.
        if (!defined('VFD_PROVIDER_CERTKEY') || empty(VFD_PROVIDER_CERTKEY)) {
             return ['status' => 'error', 'message' => 'Configuration error: VFD_PROVIDER_CERTKEY is not set.'];
        }

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            // The documentation specifies 'certkey' as a header.
            'certkey: ' . VFD_PROVIDER_CERTKEY
        ];
        
        $payload = http_build_query([
            'tin' => $tin,
        ]);
        
        // IMPORTANT: The TRA documentation shows this as a POST request.
        $response = $this->sendRequest($this->base_url . $endpoint, 'POST', $payload, $headers);
        
        // Check the response structure based on TRA documentation.
        // A successful response should contain a token.
        if (isset($response['token'])) { // Adjust key based on actual response
            return ['status' => 'success', 'token' => $response['token']];
        } else {
            // Log the full error for debugging
            log_message("VFD Auth Error for TIN $tin: " . json_encode($response));
            return ['status' => 'error', 'message' => 'Failed to get VFD token. Check logs.'];
        }
    }

    /**
     * Submits a single consolidated receipt (Z-Report) to the VFD API.
     *
     * @param string $token The authentication token.
     * @param array $receiptPayload The formatted receipt data.
     * @return array The result from the VFD API.
     */
    private function submitReceipt($token, $receiptPayload) {
        $endpoint = '/api/v1/receipt';
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ];

        $response = $this->sendRequest($this->base_url . $endpoint, 'POST', json_encode($receiptPayload), $headers);
        
        // The TRA documentation specifies a detailed success response.
        // You should parse this and return a structured success/error message.
        if (isset($response['success']) && $response['success']) {
             return [
                 'status' => 'success',
                 'message' => 'Receipt submitted successfully.',
                 'receipt_number' => $response['receipt_number'] ?? null, // Example field
                 'vfd_timestamp' => $response['timestamp'] ?? null // Example field
             ];
        } else {
            return [
                'status' => 'error',
                'message' => 'Receipt submission failed.',
                'details' => $response['error_details'] ?? $response // Example field
            ];
        }
    }

    /**
     * A generic method to send HTTP requests using cURL.
     * This is a crucial part that needs to be implemented robustly.
     *
     * @param string $url The full URL for the request.
     * @param string $method The HTTP method (e.g., 'POST', 'GET').
     * @param mixed $data The data to send with the request.
     * @param array $headers An array of HTTP headers.
     * @return array The decoded JSON response from the server.
     */
    private function sendRequest($url, $method, $data, $headers) {
        // This is a placeholder implementation.
        // In a real application, use a library like Guzzle or implement robust cURL handling.
        
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        
        // It's good practice to set a timeout.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // In production, you should verify the SSL certificate.
        // For development, you might need to disable it if you have local cert issues.
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            log_message("cURL Error for $url: " . $error);
            return ['success' => false, 'error_details' => 'HTTP Request Failed: ' . $error];
        }

        // Decode the JSON response into an associative array
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message("JSON Decode Error for $url: " . json_last_error_msg());
             return ['success' => false, 'error_details' => 'Failed to decode API response.'];
        }
        
        return $decodedResponse;
    }

    // --- Helper Methods for Database Interaction ---

    /**
     * Fetches paid invoices for a client that have not been submitted to VFD yet.
     */
    private function getUnsubmittedInvoices($clientId) {
        // This query is an example. You will need to adapt it to your database schema.
        // It assumes you have a way to track which invoices have been submitted,
        // for instance, a `vfd_submitted_at` column in your `invoices` table.
        $stmt = $this->pdo->prepare("
            SELECT * FROM invoices 
            WHERE customer_id = ? 
            AND status = 'Paid' 
            AND vfd_submitted_at IS NULL
        ");
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prepares the JSON payload for the VFD receipt API.
     */
    private function prepareReceiptPayload($tin, $invoices) {
        // This function should aggregate all invoice data into the structure
        // required by the TRA VFD documentation for a Z-Report.
        
        $totalAmount = 0;
        $totalTax = 0;
        $items = [];

        foreach ($invoices as $invoice) {
            // You would loop through invoice items here in a real scenario
            $totalAmount += $invoice['total_amount'];
            $totalTax += $invoice['tax_amount']; // Assuming you have this column
        }

        // This structure is based on a typical Z-Report payload.
        // **You must adapt this to match the exact TRA VFD documentation.**
        return [
            "tin" => $tin,
            "report_type" => "Z", // Daily/Weekly/Monthly summary is a Z-Report
            "timestamp" => date('Y-m-d H:i:s'),
            "summary" => [
                "total_net" => $totalAmount - $totalTax,
                "total_tax" => $totalTax,
                "grand_total" => $totalAmount,
                "invoice_count" => count($invoices)
            ]
        ];
    }
    
    /**
     * Logs the result of a VFD submission attempt.
     */
    private function logSubmission($clientId, $result, $invoices) {
        $stmt = $this->pdo->prepare("
            INSERT INTO vfd_submissions_log (user_id, status, response_message, raw_response, submitted_invoice_ids)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $invoiceIds = array_column($invoices, 'id');
        
        $stmt->execute([
            $clientId,
            $result['status'], // 'success' or 'error'
            $result['message'],
            json_encode($result), // Store the full response for auditing
            implode(',', $invoiceIds)
        ]);
    }

    /**
     * Marks a list of invoices as submitted in the database.
     */
    private function markInvoicesAsSubmitted($invoices) {
        if (empty($invoices)) {
            return;
        }
        
        $invoiceIds = array_column($invoices, 'id');
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));

        $stmt = $this->pdo->prepare("
            UPDATE invoices 
            SET vfd_submitted_at = NOW() 
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($invoiceIds);
    }
}
?>