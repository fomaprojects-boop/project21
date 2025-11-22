<?php
// Faili hili ni kwa ajili ya kutengeneza hash mpya ya nenosiri

$password_to_hash = 'admin123';

// Tengeneza hash mpya
$new_hash = password_hash($password_to_hash, PASSWORD_DEFAULT);

echo "<h2>Nenosiri Jipya (Hash)</h2>";
echo "<p>Nakili (copy) msimbo wote huu hapa chini na uweke kwenye database:</p>";
echo "<hr>";
echo "<pre style='background-color:#f0f0f0; padding:10px; border:1px solid #ccc; word-wrap:break-word;'>" . htmlspecialchars($new_hash) . "</pre>";
echo "<hr>";
echo "<p>Baada ya kunakili, unaweza kufuta faili hili (generate_hash.php) kwa usalama.</p>";

?>