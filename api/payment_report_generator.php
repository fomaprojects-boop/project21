<?php
// api/payment_report_generator.php

function generatePaymentReportPDF($pdo, $payout_request_id, $output_mode = 'F', $pdf_path = '', $reference_number_override = null) {
    // Get settings
    $stmt_settings = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        throw new Exception("System settings not found.");
    }

    $currency_symbol = $settings['default_currency'] ?? 'TZS';
    
    // Base URL for uploads
    $base_upload_url = 'https://app.chatme.co.tz/uploads';

    $logo_url = '';
    if (!empty($settings['profile_picture_url'])) {
        $logo_filename = basename($settings['profile_picture_url']);
        if (!empty($logo_filename)) {
            $logo_url = $base_upload_url . '/' . $logo_filename;
        }
    }
    $stamp_url = '';
    if (!empty($settings['business_stamp_url'])) {
        $stamp_filename = basename($settings['business_stamp_url']);
        if (!empty($stamp_filename)) {
            $stamp_url = $base_upload_url . '/' . $stamp_filename;
        }
    }
 
    // Get payout details
    $stmt = $pdo->prepare(
        "SELECT v.full_name, v.email, pr.amount, pr.service_type, 
                 pr.payment_method, pr.transaction_reference, pr.processed_at,
                 pr.bank_name, pr.account_name, pr.account_number, 
                 pr.mobile_network, pr.mobile_phone
          FROM vendors v
          JOIN payout_requests pr ON v.id = pr.vendor_id
          WHERE pr.id = ?"
    );
    $stmt->execute([$payout_request_id]);
    $details = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$details) {
        throw new Exception("Payout request not found.");
    }

    // Calculations
    $subtotal = (float)$details['amount'];
    $service_type = $details['service_type'] ?? '';
    $pay_service = $service_type;
    
    $withholding_tax_rate = 0.0;
    $vat_rate_percent = 0; 
    $tax_rate_percent = 0;

    if (strpos($service_type, 'Professional Service') !== false) {
        $withholding_tax_rate = 0.05;
        $tax_rate_percent = 5;
    } elseif (strpos($service_type, 'Goods/Products') !== false) {
        $withholding_tax_rate = 0.03;
        $tax_rate_percent = 3;
    } elseif (strpos($service_type, 'Rent') !== false) {
        $withholding_tax_rate = 0.10;
        $tax_rate_percent = 10;
    }
    
    $vat_amount = $subtotal * ($vat_rate_percent / 100);
    $tax_amount = $subtotal * $withholding_tax_rate;
    $total_transferred = $subtotal + $vat_amount - $tax_amount;

    $payment_date_full = $details['processed_at'] ? date("F j, Y", strtotime($details['processed_at'])) : date("F j, Y");
    
    $reference_number = $reference_number_override ?? $details['transaction_reference'] ?? 'N/A';
    $track_no = $reference_number;
    $vat_per = $vat_rate_percent;
    $withholdings_per = $tax_rate_percent;
    $amount = $subtotal;
    $vat_amount_fmt = number_format($vat_amount, 2);
    $withholding_amount_fmt = number_format($tax_amount, 2);
    $new_amount_fmt = number_format($total_transferred, 2);
    $amount_fmt = number_format($amount, 2);

    // Payment Info: Kurekebisha jina la akaunti/vendor
    $endcode = 'N/A';
    $account_identifier = '';
    $payment_recipient_name = '';

    if (($details['payment_method'] ?? '') == 'Bank Transfer') {
        $endcode = substr($details['account_number'] ?? '', -4);
        $account_identifier = "Automatic ACH transfer to bank account";
        // TUMIA ACCOUNT_NAME
        $payment_recipient_name = strtoupper(htmlspecialchars($details['account_name'] ?? $details['full_name'] ?? ''));
    } else {
        // Mobile Money
        $endcode = substr($details['mobile_phone'] ?? '', -4);
        $account_identifier = "Automatic transfer to mobile money account";
        // TUMIA FULL_NAME
        $payment_recipient_name = strtoupper(htmlspecialchars($details['full_name'] ?? ''));
    }
    
    $payment_info_html = "{$account_identifier}<br><a href=\"#\" style=\"color:#0b57a4;\"><b>•••••••••{$endcode}</b></a><br><b>{$payment_recipient_name}</b>";
    
    $to_company_name = htmlspecialchars($details['full_name'] ?? '');

    // Initialize TCPDF
    $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->setPrintHeader(false); 
    $pdf->setPrintFooter(false); 
    $pdf->AddPage(); 
    $pdf->SetFont('dejavusans', '', 10);

    // Helper to embed images as base64 (returns data URI or empty string)
    $embedImageFromUrl = function($url) {
        if (empty($url)) return '';
        try {
            $context = stream_context_create(['http' => ['timeout' => 5]]);
            $data = @file_get_contents($url, false, $context);
            if ($data === false) return '';
            $type = pathinfo($url, PATHINFO_EXTENSION);
            if (empty($type)) $type = 'png';
            $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
            return $base64;
        } catch (Exception $e) {
            return '';
        }
    };

    $logo_base64 = $embedImageFromUrl($logo_url);
    $stamp_base64 = $embedImageFromUrl($stamp_url);
    $signature_base64 = $stamp_base64; 

    // Escape variables for safe insertion
    $esc_payment_date = htmlspecialchars($payment_date_full);
    $esc_track_no = htmlspecialchars($track_no);
    $esc_pay_service = htmlspecialchars($pay_service);
    $esc_amount_fmt = htmlspecialchars($amount_fmt);
    $esc_vat_amount_fmt = htmlspecialchars($vat_amount_fmt);
    $esc_withholding_amount_fmt = htmlspecialchars($withholding_amount_fmt);
    $esc_new_amount_fmt = htmlspecialchars($new_amount_fmt);
    $esc_vat_per = htmlspecialchars($vat_per);
    $esc_withholdings_per = htmlspecialchars($withholdings_per);
    $esc_currency_symbol = htmlspecialchars($currency_symbol);
    $esc_to_company_name = htmlspecialchars($to_company_name);

    // Build TCPDF-friendly HTML (Final Classic Style)
    $pdf_html = '
    <style>
        .main-body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #333; }
        .header-info { color: #444; }
        .date-text { font-size: 11pt; font-weight: bold; }
        .tracking-text { font-size: 12pt; color: #0b57a4; font-weight: 700; }
        a { color: #0b57a4; text-decoration: none; }
        .title-bar { 
            background-color:#0b57a4; 
            color:#ffffff; 
            font-size:16pt; 
            font-weight:bold;
            padding: 20px 10px;
        }
        .info-recipient {
            font-size: 9pt;
            padding-bottom: 10px; 
            border-bottom: 1px solid #ddd; /* Divider line */
        }
        .summary-row td { border-bottom: 1px solid #ccc; }
    </style>
    ';
    
    // Outer Border (FRAME IMERUDI)
    $pdf_html .= '<div class="main-body" style="border: 1px solid #ddd; padding: 25px;">';
    
    // Header Section (Logo | Date & Tracking)
    $pdf_html .= '
    <table cellpadding="0" cellspacing="0" style="width:100%;">
    <tr>';
    // left: logo 
    $pdf_html .= '<td width="50%" style="vertical-align:top;">';
    if (!empty($logo_base64)) {
        $pdf_html .= '<img src="' . $logo_base64 . '" width="100" alt="logo" />';
    }
    $pdf_html .= '</td>';

    // right: date & tracking 
    $pdf_html .= '<td width="50%" align="right" class="header-info">';
    $pdf_html .= '<span class="date-text">' . $esc_payment_date . '</span><br>';
    $pdf_html .= '<span class="tracking-text">Ref: <span style="color:#d9534f;">' . $esc_track_no . '</span></span>';
    $pdf_html .= '</td>';
    $pdf_html .= '</tr></table>';

    // Title Bar (Blue Background)
    $pdf_html .= '<br /><div class="title-bar" align="center">PAYMENT REPORT</div><br />';
    
    // To Line 
    $pdf_html .= '<div class="info-recipient">';
    $pdf_html .= 'To: <b>' . $esc_to_company_name . '</b>';
    $pdf_html .= '</div>';
    
    // Main table header
    $pdf_html .= '
    <table cellpadding="8" cellspacing="0" style="width:100%; font-size:10pt; border-collapse: collapse; margin-top: 10px;">
        <tr style="background-color:#f5f5f5; color:#444; font-weight:bold; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd;">
            <td width="70%">SERVICE DETAILS</td>
            <td width="30%" align="right">AMOUNT (' . $esc_currency_symbol . ')</td>
        </tr>
    ';

    // Row: service
    $pdf_html .= '
        <tr>
            <td style="border-bottom: 1px solid #eee;">' . $esc_pay_service . '</td>
            <td align="right" style="border-bottom: 1px solid #eee;">' . $esc_amount_fmt . '</td>
        </tr>
    ';
    
    // End of main details section
    $pdf_html .= '</table>';
    
    // Summary Table (Taxes and Total)
    $pdf_html .= '<br />';
    
    $pdf_html .= '
    <table cellpadding="6" cellspacing="0" style="width:100%; font-size:10pt; border-collapse: collapse;">
    
        <tr class="summary-row">
            <td width="70%" align="right" style="font-weight:bold;">SUBTOTAL:</td>
            <td width="30%" align="right" style="font-weight:bold;">' . $esc_amount_fmt . ' ' . $esc_currency_symbol . '</td>
        </tr>

        <tr class="summary-row">
            <td align="right">VAT (' . $esc_vat_per . '%)</td>
            <td align="right">' . $esc_vat_amount_fmt . ' ' . $esc_currency_symbol . '</td>
        </tr>

        <tr class="summary-row">
            <td align="right">Withholding Tax (' . $esc_withholdings_per . '%)</td>
            <td align="right">' . $esc_withholding_amount_fmt . ' ' . $esc_currency_symbol . '</td>
        </tr>
    ';

    // Total transferred
    $pdf_html .= '
        <tr style="background-color:#e6f1ff; color:#0b57a4; font-weight:bold; border-top: 1px solid #0b57a4;">
            <td align="right" style="padding-top: 8px; padding-bottom: 8px; font-size: 11pt;">TOTAL TRANSFERRED AMOUNT</td>
            <td align="right" style="padding-top: 8px; padding-bottom: 8px; font-size: 11pt;">' . $esc_new_amount_fmt . ' ' . $esc_currency_symbol . '</td>
        </tr>
    ';

    $pdf_html .= '</table>';

    // Signature/Stamp Area (Kushoto)
    $pdf_html .= '<br /><br />';
    $pdf_html .= '<div style="width:240px; text-align:left; border-top:1px solid #444; height:1px;">';
    
    if (!empty($signature_base64)) {
        $pdf_html .= '<img src="' . $signature_base64 . '" width="120" alt="stamp" style="margin-top: 5px; opacity: 0.8;" />';
    }
    $pdf_html .= '</div>';
    
    // Payment info (HOW YOU GET PAID) - Rudi kulia
    $pdf_html .= '
    <table cellpadding="0" cellspacing="0" style="width:80%; margin-top: 25px;">
        <tr>
            <td width="1%">&nbsp;</td><td width="90%" valign="top">
                <div style="padding: 7px; background-color: #f7f7f7; border-left: 3px solid #0b57a4;">
                    <div style="font-weight:bold; color: #0b57a4; margin-bottom: 5px;">HOW YOU GET PAID</div>
                    ' . $payment_info_html . '
                </div>
            </td>
        </tr>
    </table>
    ';

    // Footer (Safi na haina link)
    $pdf_html .= '<div style="margin-top: 30px; border-top: 1px solid #ddd;"></div>';
    $pdf_html .= '<div style="text-align: center; font-size: 8pt; color: #999; padding-top: 5px;">Payment Processed by ' . htmlspecialchars($settings['business_name'] ?? 'FOMA Entertainment') . '</div>';
    
    $pdf_html .= '</div>'; // close main-body border

    // QR Code Generation
    $qr_code_base64 = '';
    if ($track_no !== 'N/A') {
        include_once('phpqrcode.php');
        $track_url = 'https://app.chatme.co.tz/track_payment.php?ref=' . urlencode($track_no);
        
        ob_start();
        QRcode::png($track_url, null, QR_ECLEVEL_L, 3, 2);
        $qr_image_data = ob_get_contents();
        ob_end_clean();
        
        $qr_code_base64 = 'data:image/png;base64,' . base64_encode($qr_image_data);
    }
    
    if (!empty($qr_code_base64)) {
        $pdf_html .= '
            <div style="text-align: center; margin-top: 20px;">
                <img src="' . $qr_code_base64 . '" width="80" alt="QR Code" />
                <div style="font-size: 7pt; color: #666; margin-top: 5px;">Scan to Track Payment</div>
            </div>';
    }

    // Write HTML to TCPDF
    $pdf->writeHTML($pdf_html, true, false, true, false, '');

    // Output
    if ($output_mode == 'F' && $pdf_path) {
        $pdf->Output($pdf_path, 'F');
    } else {
        $pdf->Output('payment_report_preview.pdf', 'I');
    }
}
?>
