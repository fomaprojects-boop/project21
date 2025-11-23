<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ChatMe Webhook Diagnostic</title>
    <style>body{font-family:sans-serif;padding:20px;} .success{color:green;} .error{color:red;} pre{background:#f0f0f0;padding:10px;overflow-x:auto;}</style>
</head>
<body>
    <h1>Webhook Diagnostic Tool</h1>

    <h3>1. File Check</h3>
    <ul>
        <li>Root webhook.php: <?php echo file_exists(__DIR__ . '/webhook.php') ? '<span class="success">Found</span>' : '<span class="error">Missing</span>'; ?></li>
        <li>API webhook.php: <?php echo file_exists(__DIR__ . '/api/webhook.php') ? '<span class="success">Found</span>' : '<span class="error">Missing</span>'; ?></li>
    </ul>

    <h3>2. Database Check</h3>
    <?php
    try {
        require_once 'api/db.php';
        echo '<p class="success">Database Connection Successful.</p>';
    } catch (Exception $e) {
        echo '<p class="error">Database Error: ' . $e->getMessage() . '</p>';
    }
    ?>

    <h3>3. Self-Test (Simulation)</h3>
    <?php
    function testUrl($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        // Simulate a tiny WhatsApp payload
        $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        // We expect 200 OK
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $httpCode, 'response' => $response];
    }

    $rootUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]/webhook.php";
    $apiUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]/api/webhook.php";

    $resRoot = testUrl($rootUrl);
    $resApi = testUrl($apiUrl);

    echo "<p><strong>Testing $rootUrl:</strong> HTTP " . $resRoot['code'] . " (Expected 200)</p>";
    echo "<p><strong>Testing $apiUrl:</strong> HTTP " . $resApi['code'] . " (Expected 200)</p>";
    ?>

    <h3>4. Recent Logs (Last 20 lines)</h3>
    <h4>webhook_debug.log</h4>
    <pre><?php
    $debugLog = __DIR__ . '/webhook_debug.log';
    if (file_exists($debugLog)) {
        echo htmlspecialchars(shell_exec("tail -n 20 $debugLog"));
    } else {
        echo "Log file not found.";
    }
    ?></pre>

    <h4>webhook_log.txt</h4>
    <pre><?php
    $mainLog = __DIR__ . '/webhook_log.txt';
    if (file_exists($mainLog)) {
        echo htmlspecialchars(shell_exec("tail -n 20 $mainLog"));
    } else {
        echo "Log file not found.";
    }
    ?></pre>

</body>
</html>
