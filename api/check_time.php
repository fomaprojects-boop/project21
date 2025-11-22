<?php
require_once 'config.php';
echo "Server Time: " . date('Y-m-d H:i:s') . "<br>";
echo "Timezone: " . date_default_timezone_get() . "<br>";
?>