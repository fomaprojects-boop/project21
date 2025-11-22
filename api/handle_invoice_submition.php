<?php
// api/handle_invoice_submition.php
require_once 'db.php';
require_once 'config.php';
header('Content-Type: application/json');

// Hii ni kwa ajili ya kuonyesha error kama zipo
error_reporting(0); // Zima errors kwa production
ini_set('display_errors', 0);
ini_set('log_errors', 1); // Washa ku-log

$response = ['status' => 'error', 'message' => 'An unknown error occurred.'];

try {
    // 1. Pokea na Safisha Data
    $token = $_POST['token'] ?? null;
    $payout_request_id = $_POST['payout_request_id'] ?? null;
    $service_type = trim($_POST['service_type'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    
    // Pata Currency
    $stmt_settings = $pdo->query("SELECT default_currency FROM settings WHERE id = 1");
    $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
    $currency = $settings['default_currency'] ?? 'TZS';

    $payment_method = trim($_POST['payment_method'] ?? '');
    $invoice_file = $_FILES['invoice_file'] ?? null;
    
    // Taarifa za malipo
    $bank_name = trim($_POST['bank_name'] ?? null);
    $account_name = trim($_POST['account_name'] ?? null);
    $account_number = trim($_POST['account_number'] ?? null);
    $mobile_network = trim($_POST['mobile_network'] ?? null);
    $mobile_phone = trim($_POST['mobile_phone'] ?? null);

    // Kitufe cha 'use_existing'
    $use_existing = $_POST['use_existing_details'] ?? 'no';

    if (empty($token) || empty($payout_request_id) || empty($service_type) || empty($amount)) {
        throw new Exception('Service type and amount are required.');
    }

    // 2. Hakiki Tokeni na Pata ID ya Vendor
    $stmt = $pdo->prepare("SELECT id, vendor_id FROM payout_requests WHERE id = ? AND request_token = ? AND status = 'Pending'");
    $stmt->execute([$payout_request_id, $token]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new Exception('Invalid, expired, or already used submission link.');
    }
    $vendor_id = $request['vendor_id'];

    // 3. Shughulikia Upakiaji wa Faili
    $file_url = null;
    if (isset($invoice_file) && $invoice_file['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/invoices/'; 
        if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }
        
        $file_extension = strtolower(pathinfo($invoice_file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($file_extension, $allowed_extensions)) { throw new Exception('Invalid file type.'); }
        if ($invoice_file['size'] > 5000000) { throw new Exception('File is too large (Max 5MB).'); }

        $new_filename = 'invoice_' . $payout_request_id . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;
        if (!move_uploaded_file($invoice_file['tmp_name'], $upload_path)) { throw new Exception('Failed to upload invoice file.'); }
        $file_url = 'uploads/invoices/' . $new_filename; 
    } else {
        throw new Exception('Invoice file is required.');
    }

    $pdo->beginTransaction();
    
    // 4. Sasisha (Update) Jedwali la `payout_requests`
    if ($use_existing == 'yes') {
        // Ikiwa anatumia taarifa za zamani, tunazikopi kutoka `vendors` kwenda `payout_requests`
        $stmt_vendor = $pdo->prepare("SELECT payment_method, bank_name, account_name, account_number, mobile_network, mobile_phone FROM vendors WHERE id = ?");
        $stmt_vendor->execute([$vendor_id]);
        $vendor_details = $stmt_vendor->fetch();

        $stmt_update_request = $pdo->prepare(
            "UPDATE payout_requests SET 
                service_type = ?, amount = ?, currency = ?, invoice_url = ?, status = 'Submitted', submitted_at = CURRENT_TIMESTAMP, request_token = NULL,
                payment_method = ?, bank_name = ?, account_name = ?, account_number = ?, mobile_network = ?, mobile_phone = ?
             WHERE id = ?"
        );
        $stmt_update_request->execute([
            $service_type, $amount, $currency, $file_url,
            $vendor_details['payment_method'], $vendor_details['bank_name'], $vendor_details['account_name'], $vendor_details['account_number'], 
            $vendor_details['mobile_network'], $vendor_details['mobile_phone'],
            $payout_request_id
        ]);
        
    } else {
        // Ikiwa anatumia taarifa mpya, tunazisasisha zote mbili
        
        // 4a. Sasisha `payout_requests` (kwa ombi hili)
        $stmt_update_request = $pdo->prepare(
            "UPDATE payout_requests SET 
                service_type = ?, amount = ?, currency = ?, payment_method = ?, bank_name = ?, account_name = ?, 
                account_number = ?, mobile_network = ?, mobile_phone = ?, invoice_url = ?, status = 'Submitted', 
                submitted_at = CURRENT_TIMESTAMP, request_token = NULL
             WHERE id = ?"
        );
        $stmt_update_request->execute([
            $service_type, $amount, $currency, $payment_method, $bank_name, $account_name, 
            $account_number, $mobile_network, $mobile_phone, $file_url, 
            $payout_request_id
        ]);

        // 4b. Sasisha `vendors` (kwa matumizi ya baadaye)
        $stmt_update_vendor = $pdo->prepare(
            "UPDATE vendors SET 
                payment_method = ?, bank_name = ?, account_name = ?, account_number = ?, 
                mobile_network = ?, mobile_phone = ?
             WHERE id = ?"
        );
        $stmt_update_vendor->execute([
            $payment_method, $bank_name, $account_name, $account_number, 
            $mobile_network, $mobile_phone, $vendor_id
        ]);
    }
    
    $pdo->commit();
    $response = ['status' => 'success', 'message' => 'Invoice submitted successfully! You will be notified once it is processed.'];

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Handle Invoice Error: " . $e->getMessage()); // Andika kosa kwenye server log
    $response = ['status' => 'error', 'message' => $e->getMessage()];
    http_response_code(400); // Bad Request
}

echo json_encode($response);
?>