<?php
// api/preview_template.php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_templates.php'; // Muhimu: Tunatumia templates zetu

// Hakikisha mtumiaji ameingia
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit();
}

$user_id = $_SESSION['user_id'];
$chosen_template = $_GET['template'] ?? 'default'; // Pata jina la template kutoka URL

try {
    
    // --- 1. ANDAA DUMMY DATA ---
    
    // Pata Settings (ili tupate jina la biashara na logo)
    $settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if(!$settings) {
        // Kama settings hazipo, tengeneza za mfano
        $settings = [
            'business_name' => 'Your Company Name',
            'business_address' => '123 Main Street<br>City, Country',
            'business_email' => 'info@yourcompany.com',
            'profile_picture_url' => '' // Acha tupu kama hakuna
        ];
    }
    
    // Tengeneza Mteja wa Mfano
    $customer_main_info = [
        'name' => 'Sample Customer Inc.',
        'email' => 'customer@example.com',
        'phone' => '+123 456 7890',
        'tin_number' => '100-200-300',
        'vrn_number' => '40-001234-A'
    ];
    
    // Tengeneza Mtu wa Mawasiliano wa Mfano
    $contact_info = [
        'name' => 'Mr. John Doe',
        'email' => 'john.doe@example.com',
        'phone_number' => '+123 987 6543'
    ];

    // Tengeneza Items za Mfano
    $items_data_db = [
        [
            'description' => 'Website Design Service',
            'quantity' => 1,
            'unit_price' => 1500000.00,
            'total' => 1500000.00
        ],
        [
            'description' => 'Monthly Hosting (Jan 2025)',
            'quantity' => 1,
            'unit_price' => 50000.00,
            'total' => 50000.00
        ],
        [
            'description' => 'Logo Design',
            'quantity' => 1,
            'unit_price' => 250000.00,
            'total' => 250000.00
        ]
    ];
    
    // Kokotoa Jumla
    $subtotal = 1800000.00;
    $tax_rate = 18.00; // Mfano wa VAT
    $tax_amount = $subtotal * ($tax_rate / 100);
    $total_amount = $subtotal + $tax_amount;

    // Tengeneza Invoice Data ya Mfano
    $invoice_data_db = [
        'invoice_number' => 'INV-PREVIEW',
        'issue_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'subtotal' => $subtotal,
        'tax_rate' => $tax_rate,
        'tax_amount' => $tax_amount,
        'total_amount' => $total_amount,
        'notes' => 'This is a sample preview of the invoice template. This invoice is not saved.',
        'payment_method_info' => 'Bank Name: Sample Bank<br>Account: 1234567890<br>Name: Your Company Name'
    ];

    // --- 2. CHAGUA TEMPLATE HTML ---
    $pdf_html = '';

    switch ($chosen_template) {
        case 'modern_blue':
            $pdf_html = get_modern_blue_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        case 'classic_bw':
            // $pdf_html = get_classic_bw_template_html(...); // (Tutaongeza hii baadaye)
            $pdf_html = get_default_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
        case 'default':
        default:
            $pdf_html = get_default_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db);
            break;
    }

    // --- 3. TENGENEZA PDF NA ITUME KWENYE BROWSER ---
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($pdf_html, true, false, true, false, '');
    
    // 'I' inamaanisha 'inline' (fungua kwenye browser)
    $pdf->Output('template_preview.pdf', 'I'); 

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error generating preview: ' . $e->getMessage();
}
?>