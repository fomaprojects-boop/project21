<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['is_impersonating']) || !isset($_SESSION['super_admin_id'])) {
    // Not impersonating or not a super admin, just redirect to login if session lost
    echo json_encode(['status' => 'error', 'message' => 'Not impersonating']);
    exit;
}

// Restore Super Admin context by clearing tenant data
unset($_SESSION['user_id']);
unset($_SESSION['user_name']);
unset($_SESSION['user_role']);
unset($_SESSION['role_id']);
unset($_SESSION['tenant_id']);
unset($_SESSION['permissions']);
unset($_SESSION['is_impersonating']);

// super_admin_id remains set, so they are logged in as super admin

echo json_encode(['status' => 'success', 'redirect' => '../super_admin/dashboard.php']);
?>
