<?php
// api/download_payroll_template.php

// The headers for our CSV file
$headers = [
    'Employee Name',
    'Employee Email',
    'Basic Salary',
    'Allowances',
    'Deductions (NSSF, Loans, etc.)',
    'Income Tax (PAYE)',
    'Net Salary'
];

$filename = 'payroll_template.csv';

// Set headers to force download as a CSV file
header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Open the output stream
$output = fopen('php://output', 'w');

// Write the header row to the CSV
fputcsv($output, $headers);

// Close the stream
fclose($output);

exit;
?>
