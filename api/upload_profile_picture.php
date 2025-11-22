<?php
// api/upload_profile_picture.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

try {
    // 1. Angalia kama kuna faili lililotumwa na hakuna kosa
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Invalid upload or no file uploaded.');
    }

    $file = $_FILES['profile_picture'];

    // 2. Weka mipaka ya ukubwa wa faili (kwa mfano, 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Exceeded filesize limit of 5MB.');
    }

    // 3. Hakikisha ni aina ya picha inayoruhusiwa
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($mime_type, $allowed_types)) {
        throw new RuntimeException('Invalid file format. Only JPG, PNG, and GIF are allowed.');
    }

    // 4. Tengeneza jina jipya na la kipekee kwa ajili ya picha
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = uniqid('profile_', true) . '.' . $extension;

    // 5. Weka eneo la kuhifadhi picha (nje ya 'api' folder)
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $destination = $upload_dir . $new_filename;

    // 6. Hamisha faili lililopakiwa kwenda kwenye eneo lake jipya
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    // 7. Pata URL kamili ya picha iliyohifadhiwa
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $file_url = "{$protocol}://{$host}/uploads/{$new_filename}";

    // 8. Hifadhi URL hiyo kwenye database
    $stmt = $pdo->prepare("UPDATE settings SET profile_picture_url = ? WHERE id = 1");
    $stmt->execute([$file_url]);

    // 9. Tuma majibu ya mafanikio
    echo json_encode([
        'status' => 'success',
        'message' => 'Profile picture uploaded successfully.',
        'url' => $file_url
    ]);

} catch (RuntimeException $e) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500); // Server Error
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

