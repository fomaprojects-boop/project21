<?php
// Set the content type header to application/json
header('Content-Type: application/json');

/*
 * In a real application, this data would come from a database.
 * You would connect to your MySQL database, run a query to calculate
 * these statistics, and then fetch the results.
 *
 * For now, we are using static data as an example.
*/

$report_data = [
    "new_conversations" => 157,
    "messages_sent"     => 952,
    "avg_response_time" => "1m 15s"
];

// Encode the PHP array into a JSON string and output it.
echo json_encode($report_data);

?>