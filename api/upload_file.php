<?php
session_start();
require_once 'config.php'; // Ensure config is loaded for base URL construction if needed

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// We don't strictly need conversation_id for just uploading, but it's passed for context if needed later
// $conversation_id = $_POST['conversation_id'] ?? null;

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit();
}

// File details
$file = $_FILES['file'];
$file_name = $file['name'];
$file_tmp = $file['tmp_name'];
$file_error = $file['error'];
$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

// Allowed extensions
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];

if ($file_error !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload error code: ' . $file_error]);
    exit();
}

if (!in_array($file_ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'File type not allowed.']);
    exit();
}

// Move uploaded file to a public directory
$upload_dir = '../uploads/';
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory.']);
        exit();
    }
}

// Use a unique name to prevent overwrites
$unique_name = uniqid('chat_') . '.' . $file_ext;
$file_path = $upload_dir . $unique_name;

if (move_uploaded_file($file_tmp, $file_path)) {
    // Construct the public URL
    // Assumption: 'uploads' is in the root, and this script is in 'api/'

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $domain_name = $_SERVER['HTTP_HOST'];

    // Determine the relative path from document root to the uploads folder
    // This script is at /api/upload_file.php. We need /uploads/filename.
    // dirname($_SERVER['SCRIPT_NAME']) gives /api. dirname(..., 2) gives root /.

    $base_path = dirname($_SERVER['SCRIPT_NAME']); // e.g. /app/api
    $root_path = dirname($base_path); // e.g. /app

    // Clean up slashes
    $root_path = rtrim($root_path, '/\\');

    $file_url = $protocol . $domain_name . $root_path . '/uploads/' . $unique_name;

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully.',
        'file_url' => $file_url,
        'file_name' => $file_name
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
}
?>
