<?php
// Washa uonyeshaji wa makosa
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre>"; // Tumia preformatted text kwa usomaji rahisi

require 'db.php'; // Unganisha na database

$email_to_check = 'admin@chatme.com';
$password_to_test = 'admin123';

echo "UCHUNGUZI WA NENOSIRI\n";
echo "========================\n\n";

try {
    // 1. Pata mtumiaji kutoka kwenye database
    $stmt = $pdo->prepare("SELECT password FROM users WHERE email = ?");
    $stmt->execute([$email_to_check]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $stored_hash = $user['password'];
        
        echo "Email ya Mtumiaji: " . htmlspecialchars($email_to_check) . "\n";
        echo "Nenosiri la Kujaribu: " . htmlspecialchars($password_to_test) . "\n\n";
        
        echo "Nenosiri Lililohifadhiwa (Hash): \n";
        echo htmlspecialchars($stored_hash) . "\n\n";
        
        // 2. Linganisha nenosiri na hash kwa kutumia password_verify()
        echo "Matokeo ya password_verify(): \n";
        if (password_verify($password_to_test, $stored_hash)) {
            echo "SUCCESS: Nenosiri ni sahihi!\n";
        } else {
            echo "FAILED: Nenosiri si sahihi.\n";
        }

    } else {
        echo "KOSA: Mtumiaji mwenye email '" . htmlspecialchars($email_to_check) . "' hajapatikana kwenye database.\n";
    }

} catch (PDOException $e) {
    echo "KOSA LA DATABASE: " . $e->getMessage() . "\n";
}

echo "\n========================\n";
echo "Uchunguzi umekamilika.";
echo "</pre>";

?>