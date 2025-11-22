<?php
// api/add_customer.php
require_once __DIR__ . '/auth_check.php'; // Ongeza hii
require_once __DIR__ . '/db.php';

header('Content-Type: application/json'); // Hakikisha ipo

$data = json_decode(file_get_contents('php://input'), true);

$name = trim($data['name'] ?? '');
$email = !empty($data['email']) ? trim($data['email']) : null; // Weka null kama empty
$phone = !empty($data['phone']) ? trim($data['phone']) : null; // Weka null kama empty
$tin_number = trim($data['tin_number'] ?? '');
$vrn_number = trim($data['vrn_number'] ?? '');
$contact_ids = $data['contact_ids'] ?? [];

// --- Validation ya Awali ---
if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'Customer name is required.']);
    exit();
}
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit();
}
// Unaweza kuongeza validation ya phone number hapa kama unataka

$pdo->beginTransaction();

try {
    // --- Kagua Duplicates (IMEREKEBISHWA) ---
    $checkSql = "SELECT id, name, email, phone FROM customers WHERE name = :name";
    $params = [':name' => $name];
    $orConditions = [];

    if ($email !== null) {
        $checkSql .= " OR email = :email";
        $params[':email'] = $email;
    }
    if ($phone !== null) {
        $checkSql .= " OR phone = :phone";
        $params[':phone'] = $phone;
    }

    $stmt_check = $pdo->prepare($checkSql);
    $stmt_check->execute($params);
    $existingCustomer = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($existingCustomer) {
        // Mteja anayefanana amepatikana, tengeneza ujumbe wa kosa
        $errorMessage = "Cannot add customer. ";
        if (strcasecmp($existingCustomer['name'], $name) == 0) { // strcasecmp for case-insensitive comparison
             $errorMessage .= "A customer with the name '{$name}' already exists.";
        } elseif ($email !== null && strcasecmp($existingCustomer['email'], $email) == 0) {
             $errorMessage .= "The email address '{$email}' is already associated with customer '{$existingCustomer['name']}'.";
        } elseif ($phone !== null && $existingCustomer['phone'] == $phone) {
             $errorMessage .= "The phone number '{$phone}' is already associated with customer '{$existingCustomer['name']}'.";
        } else {
            // Fallback message kama hali isiyotarajiwa itatokea
            $errorMessage .= "A similar customer already exists.";
        }
        throw new Exception($errorMessage);
    }

    // Kama hakuna duplicate, endelea kuongeza
    $stmt_insert = $pdo->prepare(
        "INSERT INTO customers (name, email, phone, tin_number, vrn_number) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt_insert->execute([
        $name,
        $email, // Tayari ni null kama ilikuwa empty
        $phone, // Tayari ni null kama ilikuwa empty
        $tin_number ?: null,
        $vrn_number ?: null
    ]);

    $new_customer_id = $pdo->lastInsertId();

    // Unganisha contacts (Hakuna mabadiliko hapa)
    if (!empty($contact_ids) && is_array($contact_ids)) {
        $sanitized_ids = array_filter($contact_ids, 'is_numeric');
        if (!empty($sanitized_ids)) {
            $placeholders = implode(',', array_fill(0, count($sanitized_ids), '?'));
            $stmt_update_contacts = $pdo->prepare(
                "UPDATE contacts SET customer_id = ? WHERE id IN ($placeholders) AND customer_id IS NULL" // Ongeza 'AND customer_id IS NULL' kuzuia ku-reassign
            );
            $update_params = array_merge([$new_customer_id], $sanitized_ids);
            $stmt_update_contacts->execute($update_params);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "Customer '{$name}' added successfully."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400); // Bad Request (kwa sababu kosa linaweza kuwa data aliyoingiza mtumiaji)
    // Rudisha ujumbe wa kosa uliotoka kwenye Exception
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>