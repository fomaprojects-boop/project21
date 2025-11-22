<?php
// api/auth_check.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Checks if a user is logged in and has one of the allowed roles.
 *
 * @param array|string $allowed_roles A single role or an array of roles that are allowed to access the script.
 */
function require_role($allowed_roles) {
    // Kwanza, hakikisha mtumiaji ameingia
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401); // Unauthorized
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: You must be logged in to perform this action.']);
        exit();
    }
    
    // Pili, pata role ya mtumiaji kutoka kwenye session
    $user_role = $_SESSION['user_role'] ?? 'Staff'; // Default kwa 'Staff' kama haijawekwa
    
    // Geuza $allowed_roles kuwa array kama ni string moja
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    
    // Tatu, angalia kama role ya mtumiaji ipo ndani ya roles zinazoruhusiwa
    if (!in_array($user_role, $allowed_roles)) {
        http_response_code(403); // Forbidden
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: You do not have the required permissions to perform this action.']);
        exit();
    }
}

/**
 * Checks if the logged-in user is an Admin.
 * A shortcut for require_role('Admin').
 */
function require_admin() {
    require_role('Admin');
}

?>