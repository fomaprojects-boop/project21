<?php
// hili ni faili la: api/invoice_templates.php

/**
 * 1. DEFAULT TEMPLATE (REDESIGNED)
 * This now uses the same modern design as the Tax Invoice template.
 */
function get_default_template_html($settings, $customer, $contact, $invoice, $items) {
    // This is a direct call to the redesigned tax invoice function.
    // We pass all arguments directly to it.
    return get_tax_invoice_template_html($settings, $customer, $contact, $invoice, $items);
}


/**
 * 2. MODERN BLUE TEMPLATE (REDESIGNED V2)
 * This is a new professional design with a blue theme.
 */
function get_modern_blue_template_html($settings, $customer, $contact, $invoice, $items) {
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');

    // Reconstruct image URLs to be absolute for PDF rendering
    $base_url = 'https://mteja.fomaentertainment.com/';
    $logo_url = '';
    if (!empty($settings['profile_picture_url'])) {
        $logo_filename = basename($settings['profile_picture_url']);
        if (!empty($logo_filename)) {
            $logo_url = $base_url . 'uploads/' . $logo_filename;
        }
    }
    $stamp_url = '';
    if (!empty($settings['business_stamp_url'])) {
        $stamp_filename = basename($settings['business_stamp_url']);
        if (!empty($stamp_filename)) {
            $stamp_url = $base_url . 'uploads/' . $stamp_filename;
        }
    }
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Invoice</title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #333; font-size: 10pt; }
            .page { padding: 35px; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; border-bottom: 2px solid #3498db; }
            .header-table td { vertical-align: top; padding-bottom: 10px; }
            .company-logo { width: 80px; height: auto; }
            .company-details h2 { font-size: 16pt; font-weight: bold; margin: 0; color: #2980b9; }
            .company-details p { margin: 2px 0; font-size: 9pt; color: #555; }
            h1 { font-size: 28pt; color: #3498db; margin: 0; text-align: right; font-weight: bold; }
            .invoice-details { text-align: right; line-height: 1.6; }
            .customer-section { margin: 30px 0; }
            .customer-box { padding: 15px; background-color: #f2f8fc; border-left: 4px solid #3498db; }
            .customer-box b { color: #333; }
            .items-table { width: 100%; border-collapse: collapse; }
            .items-table th { background-color: #3498db; color: #fff; padding: 12px; text-align: left; font-size: 9pt; text-transform: uppercase; }
            .items-table tr:nth-child(even) td { background-color: #f2f8fc; }
            .items-table td { border-bottom: 1px solid #dceaf4; padding: 12px; }
            .items-table .right { text-align: right; }
            .totals-section { width: 45%; float: right; margin-top: 20px; }
            .totals-table { width: 100%; border-collapse: collapse; }
            .totals-table td { padding: 10px; text-align: right; }
            .totals-table .label { font-weight: bold; color: #555; }
            .totals-table .total-row td { font-weight: bold; font-size: 14pt; background-color: #3498db; color: #fff; }
            .footer-section { width: 100%; border-top: 1px solid #ccc; padding-top: 10px; position: absolute; bottom: 35px; }
            .footer-section td { vertical-align: top; font-size: 9pt; }
            .business-stamp { width: 100px; height: auto; }
        </style>
    </head>
    <body>
        <div class="page">
            <table class="header-table">
                <tr>
                    <td style="width: 60%;" class="company-details">
                        <?php if ($logo_url): ?>
                            <img src="<?php echo $logo_url; ?>" alt="Logo" class="company-logo">
                        <?php endif; ?>
                        <h2 style="margin-top: 10px;"><?php echo htmlspecialchars($settings['business_name']); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></p>
                        <p><b>TIN:</b> <?php echo htmlspecialchars($settings['tin_number']); ?> | <b>VRN:</b> <?php echo htmlspecialchars($settings['vat_number']); ?></p>
                    </td>
                    <td style="width: 40%;">
                        <h1>INVOICE</h1>
                        <div class="invoice-details">
                            <p><b>Invoice #:</b> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                            <p><b>Issue Date:</b> <?php echo date("F d, Y", strtotime($invoice['issue_date'])); ?></p>
                            <p><b>Due Date:</b> <?php echo date("F d, Y", strtotime($invoice['due_date'])); ?></p>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="customer-section">
                <div class="customer-box">
                    <b>BILL TO:</b><br>
                    <b style="font-size: 11pt;"><?php echo htmlspecialchars($customer['name']); ?></b><br>
                    <?php echo htmlspecialchars($customer['email']); ?><br>
                    <b>TIN:</b> <?php echo htmlspecialchars($customer['tin_number']); ?> | <b>VRN:</b> <?php echo htmlspecialchars($customer['vrn_number']); ?>
                </div>
            </div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:45%;">Item Description</th>
                        <th class="right" style="width:15%;">Quantity</th>
                        <th class="right" style="width:15%;">Unit Price</th>
                        <th class="right" style="width:20%;">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td class="right"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="right"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="right"><?php echo $currency; ?> <?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals-section">
                <table class="totals-table">
                    <tr>
                        <td class="label" style="width:50%;">Subtotal</td>
                        <td style="width:50%;"><?php echo $currency; ?> <?php echo number_format($invoice['subtotal'], 2); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Tax (<?php echo htmlspecialchars($invoice['tax_rate']); ?>%)</td>
                        <td><?php echo $currency; ?> <?php echo number_format($invoice['tax_amount'], 2); ?></td>
                    </tr>
                     <tr class="total-row">
                        <td class="label">TOTAL</td>
                        <td><?php echo $currency; ?> <?php echo number_format($invoice['total_amount'], 2); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="clear: both;"></div>

            <table class="footer-section">
                <tr>
                    <td style="width: 50%;">
                        <p><b>Payment Information:</b><br><?php echo nl2br(htmlspecialchars($invoice['payment_method_info'])); ?></p>
                    </td>
                    <td style="width: 50%; text-align: right;">
                         <?php if ($stamp_url): ?>
                            <img src="<?php echo $stamp_url; ?>" alt="Stamp" class="business-stamp"><br>
                        <?php endif; ?>
                        <p style="border-top: 1px solid #333; padding-top: 5px; margin-top: 5px; display: inline-block;">Authorized Signature</p>
                    </td>
                </tr>
            </table>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}


/**
 * 3. CLASSIC BLACK & WHITE TEMPLATE
 * IMEBADILISHWA: Table sasa ina column 2 (Description & Total)
 * Font size imeongezwa kidogo.
 */
function get_classic_bw_template_html($settings, $customer_main_info, $contact_info, $invoice_data_db, $items_data_db) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Invoice</title>
        <style>
            body {
                font-family: 'helvetica', 'sans-serif';
                color: #000;
                font-size: 10pt; /* IMEONGEZWA KIDOGO */
                line-height: 1.5; /* IMEONGEZWA KIDOGO */
            }
            .container {
                width: 100%;
                padding: 10px;
            }
            .header-info {
                text-align: left;
                margin-bottom: 15px; 
            }
            .header-info h1 {
                margin: 0;
                font-size: 17pt; /* IMEONGEZWA KIDOGO */
            }
            .header-info p {
                margin: 0;
                font-size: 9pt; /* IMEONGEZWA KIDOGO */
            }
            .invoice-title {
                text-align: center;
                font-size: 21pt; /* IMEONGEZWA KIDOGO */
                font-weight: bold;
                margin-bottom: 20px; 
                border-top: 2px solid #000;
                border-bottom: 2px solid #000;
                padding: 7px 0; /* IMEONGEZWA KIDOGO */
            }
            .details-table {
                width: 100%;
                margin-bottom: 20px; 
            }
            .details-table td {
                width: 50%;
                vertical-align: top; 
            }
            .invoice-details table {
                width: 100%;
                border-collapse: collapse;
                text-align: right;
            }
            .invoice-details th {
                text-align: right;
                padding: 5px; /* IMEONGEZWA KIDOGO */
                font-weight: bold;
                background-color: #eee;
                font-size: 10pt; /* IMEONGEZWA KIDOGO */
            }
            .invoice-details td {
                text-align: right;
                padding: 5px; /* IMEONGEZWA KIDOGO */
                font-size: 10pt; /* IMEONGEZWA KIDOGO */
            }
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px; 
            }
            .items-table thead {
                display: table-header-group; 
            }
            .items-table th, .items-table td {
                border: 1px solid #000;
                padding: 6px; /* IMEONGEZWA KIDOGO */
                text-align: left;
                font-size: 9pt; /* IMEONGEZWA KIDOGO */
            }
            .items-table th {
                background-color: #eee;
                font-weight: bold;
                font-size: 10pt; /* IMEONGEZWA KIDOGO */
            }
            .items-table .right {
                text-align: right;
            }
            
            /* CSS KWA AJILI YA TOTALS (Muundo wa Column 2) */
            .items-table td.no-border {
                border-left: none;
                border-bottom: none;
                border-top: none;
            }
            .items-table tr.totals-row td {
                padding: 6px; /* IMEONGEZWA KIDOGO */
                text-align: right;
                border: 1px solid #000;
            }
            .items-table tr.totals-row td.label {
                font-weight: bold;
                background-color: #eee;
            }
            .items-table tr.total-row td {
                padding: 6px; /* IMEONGEZWA KIDOGO */
                text-align: right;
                font-weight: bold;
                font-size: 12pt; /* IMEONGEZWA KIDOGO */
                background-color: #ddd;
                border-top-width: 2px;
            }
            .items-table td.amount {
                text-align: right;
            }
            /* MWISHO WA CSS */

            .footer-notes {
                margin-top: 20px; 
                padding-top: 10px;
                border-top: 1px solid #ccc;
                font-size: 8pt; /* IMEONGEZWA KIDOGO */
            }
        </style>
    </head>
    <body>
        <div class="container">
            <?php if (!empty($settings['business_stamp_url'])): ?>
                <img src="<?php echo htmlspecialchars($settings['business_stamp_url']); ?>" alt="Stamp" style="position: absolute; bottom: 10px; left: 10px; max-width: 100px;">
            <?php endif; ?>
            <div class="header-info">
                <h1><?php echo htmlspecialchars($settings['business_name']); ?></h1>
                <p><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></p>
                <p><?php echo htmlspecialchars($settings['business_email']); ?></p>
                <p>
                    <?php if (!empty($settings['tin_number'])): ?>
                        TIN: <?php echo htmlspecialchars($settings['tin_number']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($settings['vat_number'])): ?>
                        VAT: <?php echo htmlspecialchars($settings['vat_number']); ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="invoice-title">
                INVOICE
            </div>

            <table class="details-table">
                <tr>
                    <td>
                        <strong>BILL TO:</strong><br>
                        <strong><?php echo htmlspecialchars($customer_main_info['name']); ?></strong><br>
                        <?php if(!empty($contact_info['name'])): ?>
                            Attn: <?php echo htmlspecialchars($contact_info['name']); ?><br>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($customer_main_info['email']); ?><br>
                        <?php echo htmlspecialchars($customer_main_info['phone']); ?>
                    </td>
                    <td>
                        <table class="invoice-details">
                            <tr>
                                <th>Invoice #:</th>
                                <td><?php echo htmlspecialchars($invoice_data_db['invoice_number']); ?></td>
                            </tr>
                            <tr>
                                <th>Issue Date:</th>
                                <td><?php echo date("Y-m-d", strtotime($invoice_data_db['issue_date'])); ?></td>
                            </tr>
                            <tr>
                                <th>Due Date:</th>
                                <td><?php echo date("Y-m-d", strtotime($invoice_data_db['due_date'])); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <table class="items-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="right" style="width: 120px;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_data_db as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td class="right"><?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <tr class="totals-row">
                        <td class="label">Subtotal</td>
                        <td class="right amount"><?php echo number_format($invoice_data_db['subtotal'], 2); ?></td>
                    </tr>
                    <tr class="totals-row">
                        <td class="label">Tax (<?php echo htmlspecialchars($invoice_data_db['tax_rate']); ?>%)</td>
                        <td class="right amount"><?php echo number_format($invoice_data_db['tax_amount'], 2); ?></td>
                    </tr>
                    <tr class="total-row">
                        <td class="label">Total Amount</td>
                        <td class="right amount"><?php echo htmlspecialchars($settings['default_currency']); ?> <?php echo number_format($invoice_data_db['total_amount'], 2); ?></td>
                    </tr>
                    </tbody>
            </table>

            <div class="footer-notes">
                <strong>Notes:</strong>
                <p><?php echo nl2br(htmlspecialchars($invoice_data_db['notes'])); ?></p>
                <br>
                <strong>Payment Information:</strong>
                <p><?php echo nl2br(htmlspecialchars($invoice_data_db['payment_method_info'])); ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * 4. CLASSIC STATEMENT TEMPLATE (FOR REPORTS)
 * New template for generating filtered statements.
 */
function get_classic_statement_template_html($settings, $invoices, $filter_details, $summary_totals) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Account Statement</title>
        <style>
            body {
                font-family: 'helvetica', 'sans-serif';
                color: #333;
                font-size: 10pt;
                line-height: 1.6;
            }
            .container {
                width: 100%;
                padding: 15px;
            }
            .header-table {
                width: 100%;
                border-bottom: 2px solid #333;
                margin-bottom: 20px;
                padding-bottom: 10px;
            }
            .header-table td {
                vertical-align: middle;
            }
            .logo {
                max-width: 120px;
                max-height: 60px;
            }
            h1 {
                font-size: 24pt;
                color: #333;
                margin: 0;
                text-align: right;
                font-weight: bold;
            }
            .statement-details {
                text-align: right;
                font-size: 9pt;
                color: #555;
            }
            .summary-table {
                width: 100%;
                margin-bottom: 25px;
                border-collapse: collapse;
            }
            .summary-table td {
                padding: 10px;
                font-size: 11pt;
                text-align: center;
                border: 1px solid #ddd;
                background-color: #f9f9f9;
            }
            .summary-table td strong {
                display: block;
                font-size: 9pt;
                color: #555;
                margin-bottom: 5px;
                text-transform: uppercase;
            }
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            .items-table th {
                background-color: #333;
                color: #ffffff;
                padding: 10px;
                text-align: left;
                border: 1px solid #333;
                font-size: 9pt;
                font-weight: bold;
            }
            .items-table td {
                padding: 8px 10px;
                border: 1px solid #ddd;
                font-size: 9pt;
            }
            .items-table tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            .items-table th.right, .items-table td.right {
                text-align: right;
            }
            .footer {
                margin-top: 30px;
                text-align: center;
                font-size: 8pt;
                color: #777;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <table class="header-table">
                <tr>
                    <td style="width: 50%;">
                        <?php if (!empty($settings['profile_picture_url'])): ?>
                            <img src="<?php echo htmlspecialchars($settings['profile_picture_url']); ?>" alt="Logo" class="logo">
                        <?php endif; ?>
                        <h2 style="margin-top: 10px; margin-bottom: 0; font-size: 14pt;"><?php echo htmlspecialchars($settings['business_name']); ?></h2>
                    </td>
                    <td style="width: 50%;">
                        <h1>Account Statement</h1>
                        <p class="statement-details">
                            <strong>Date Generated:</strong> <?php echo date("F d, Y"); ?><br>
                            <strong>Filters Applied:</strong> Status (<?php echo $filter_details['status']; ?>), Period (<?php echo htmlspecialchars($filter_details['period']); ?>)
                        </p>
                    </td>
                </tr>
            </table>

            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 18%;">Invoice #</th>
                        <th style="width: 25%;">Customer</th>
                        <th style="width: 12%;">Date</th>
                        <th style="width: 12%;">Status</th>
                        <th class="right" style="width: 11%;">Total</th>
                        <th class="right" style="width: 11%;">Paid</th>
                        <th class="right" style="width: 11%;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice):
                        $balance = $invoice['total_amount'] - $invoice['amount_paid'];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                        <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                        <td><?php echo date("Y-m-d", strtotime($invoice['issue_date'])); ?></td>
                        <td><?php echo htmlspecialchars($invoice['status']); ?></td>
                        <td class="right"><?php echo number_format($invoice['total_amount'], 2); ?></td>
                        <td class="right"><?php echo number_format($invoice['amount_paid'], 2); ?></td>
                        <td class="right"><?php echo number_format($balance, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <table class="summary-table" style="margin-top: 20px;">
                <tr>
                    <td>
                        <strong>Total Billed</strong>
                        <?php echo htmlspecialchars($settings['default_currency']); ?> <?php echo number_format($summary_totals['total_billed'], 2); ?>
                    </td>
                    <td>
                        <strong>Total Paid</strong>
                        <?php echo htmlspecialchars($settings['default_currency']); ?> <?php echo number_format($summary_totals['total_paid'], 2); ?>
                    </td>
                    <td>
                        <strong>Balance Due</strong>
                        <?php echo htmlspecialchars($settings['default_currency']); ?> <?php echo number_format($summary_totals['balance_due'], 2); ?>
                    </td>
                </tr>
            </table>

            <div class="footer">
                <p>This is a computer-generated statement and does not require a signature.</p>
                <p><?php echo htmlspecialchars($settings['business_name']); ?> | <?php echo htmlspecialchars($settings['business_email']); ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// =============================================================================
// == NEW WORLD-CLASS TEMPLATES START HERE ==
// =============================================================================

/**
 * 5. TAX INVOICE TEMPLATE (REDESIGNED V2)
 * This version fixes image paths and refines the layout to match the user's provided image.
 */
function get_tax_invoice_template_html($settings, $customer, $contact, $invoice, $items) {
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');
    
    // Dynamic title based on VAT setting
    $document_title = (!empty($settings['vat_number']) && trim($settings['vat_number']) !== '') ? 'TAX INVOICE' : 'INVOICE';

    // FIX: Reconstruct image URLs to be absolute and prevent broken images in PDF.
    $base_url = 'https://mteja.fomaentertainment.com/';
    $logo_url = '';
    if (!empty($settings['profile_picture_url'])) {
        $logo_filename = basename($settings['profile_picture_url']);
        if (!empty($logo_filename)) {
            $logo_url = $base_url . 'uploads/' . $logo_filename;
        }
    }
    $stamp_url = '';
    if (!empty($settings['business_stamp_url'])) {
        $stamp_filename = basename($settings['business_stamp_url']);
        if (!empty($stamp_filename)) {
            $stamp_url = $base_url . 'uploads/' . $stamp_filename;
        }
    }

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Tax Invoice</title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #333; font-size: 10pt; background-color: #fff; }
            .page { padding: 40px; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; border-bottom: 2px solid #000; }
            .header-table td { vertical-align: top; padding-bottom: 15px; }
            .company-logo { width: 80px; height: auto; margin-bottom: 10px; }
            .company-details h2 { font-size: 14pt; font-weight: bold; margin: 0; color: #000; text-transform: uppercase; }
            .company-details p { margin: 2px 0; font-size: 9pt; color: #555; line-height: 1.4; }
            .company-details .tax-info { font-weight: bold; }
            h1 { font-size: 28pt; color: #000; margin: 0; text-align: right; font-weight: bold; }
            .invoice-details { text-align: right; font-size: 10pt; line-height: 1.6; }
            .invoice-details p { margin: 0; }
            .customer-section { margin-bottom: 30px; }
            .customer-box { padding: 12px; background-color: #f0f4f7; border-left: 4px solid #8096a8; }
            .customer-box p { margin: 0; line-height: 1.5; font-size: 9pt; padding-left: 10px; } /* Added padding */
            .items-table { width: 100%; border-collapse: collapse; }
            .items-table th { background-color: #333a45; color: #fff; padding: 12px; text-align: left; font-size: 9pt; text-transform: uppercase; font-weight: 400; }
            .items-table tr:nth-child(even) td { background-color: #f6f7f9; }
            .items-table td { border-bottom: 1px solid #e8e8e8; padding: 12px; font-size: 9.5pt; }
            .items-table .right { text-align: right; }
            .totals-section { width: 45%; float: right; margin-top: 15px; }
            .totals-table { width: 100%; border-collapse: collapse; }
            .totals-table td { padding: 10px 12px; font-size: 10pt; text-align: right;}
            .totals-table .label { text-align: right; font-weight: bold; color: #444; }
            .totals-table tr:last-child { border-top: 2px solid #333a45; }
            .totals-table .total-row td { font-weight: bold; font-size: 13pt; background-color: #333a45; color: #fff; }
            .footer-section { width: 100%; border-top: 1px solid #ccc; padding-top: 10px; position: absolute; bottom: 40px; }
            .footer-section td { vertical-align: top; font-size: 9pt; }
            .business-stamp { width: 100px; height: auto; }
        </style>
    </head>
    <body>
        <div class="page">
            <table class="header-table">
                <tr>
                    <td style="width: 60%;" class="company-details">
                        <?php if ($logo_url): ?>
                            <img src="<?php echo $logo_url; ?>" alt="Logo" class="company-logo">
                        <?php endif; ?>
                        <h2 style="margin-top: 10px;"><?php echo htmlspecialchars($settings['business_name']); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></p>
                        <p><b>TIN:</b> <?php echo htmlspecialchars($settings['tin_number']); ?> | <b>VRN:</b> <?php echo htmlspecialchars($settings['vat_number']); ?></p>
                    </td>
                    <td style="width: 45%;">
                        <h1><?php echo $document_title; ?></h1>
                        <div class="invoice-details">
                            <p><b>Invoice #:</b> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                            <p><b>Issue Date:</b> <?php echo date("F d, Y", strtotime($invoice['issue_date'])); ?></p>
                            <p><b>Due Date:</b> <?php echo date("F d, Y", strtotime($invoice['due_date'])); ?></p>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="customer-section">
                <div class="customer-box">
                    <b>BILL TO:</b><br>
                    <b style="font-size: 11pt;font-weight:bold; color: #555;"><?php echo htmlspecialchars($customer['name']); ?></b><br>
                    <?php echo htmlspecialchars($customer['email']); ?><br>
                    <b>TIN:</b> <?php echo htmlspecialchars($customer['tin_number']); ?> | <b>VRN:</b> <?php echo htmlspecialchars($customer['vrn_number']); ?>
                </div>
            </div>


            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:45%;">Item Description</th>
                        <th class="right" style="width:15%;">Quantity</th>
                        <th class="right" style="width:15%;">Unit Price</th>
                        <th class="right" style="width:20%;">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td class="right"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="right"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="right"><?php echo $currency; ?> <?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals-section">
                <table class="totals-table">
                    <tr>
                        <td class="label" style="width:50%;">Subtotal</td>
                        <td class="right" style="width:50%;"><?php echo $currency; ?> <?php echo number_format($invoice['subtotal'], 2); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Tax (<?php echo htmlspecialchars($invoice['tax_rate']); ?>%)</td>
                        <td class="right"><?php echo $currency; ?> <?php echo number_format($invoice['tax_amount'], 2); ?></td>
                    </tr>
                     <tr class="total-row">
                        <td class="label">TOTAL</td>
                        <td class="right"><?php echo $currency; ?> <?php echo number_format($invoice['total_amount'], 2); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="clear: both;"></div>

            <table class="footer-section">
                <tr>
                    <td style="width: 60%;">
                        <p><b>Payment Information:</b><br><?php echo nl2br(htmlspecialchars($invoice['payment_method_info'])); ?></p>
                    </td>
                    <td style="width: 40%; text-align: right;">
                         <?php if ($stamp_url): ?>
                            <img src="<?php echo $stamp_url; ?>" alt="Stamp" class="business-stamp"><br>
                        <?php endif; ?>
                        <p style="border-top: 1px solid #333; padding-top: 5px; margin-top: 5px; display: inline-block;">Authorized Signature</p>
                    </td>
                </tr>
            </table>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * 6. PROFORMA INVOICE TEMPLATE
 */
function get_proforma_invoice_template_html($settings, $customer, $contact, $invoice, $items) {
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');
    ob_start();
    ?>
     <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8"><title>Proforma Invoice</title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #444; font-size: 10pt; }
            .page { padding: 20px; background-color: #fdfdfd; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border-bottom: 3px solid #6a1b9a; }
            .header-table td { vertical-align: bottom; padding: 10px; }
            .company-details h2 { font-size: 16pt; font-weight: bold; margin: 0; color: #6a1b9a; }
            h1 { font-size: 24pt; color: #6a1b9a; margin: 0; font-weight: 300; text-align: right; }
            .details-table { width: 100%; margin-bottom: 25px; }
            .details-table td { width: 50%; vertical-align: top; }
            .customer-box p { margin: 0; line-height: 1.5; }
            .invoice-details { text-align: right; }
            .invoice-details p { margin: 2px 0; }
            .items-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .items-table th { background-color: #6a1b9a; color: #fff; padding: 10px; text-align: left; }
            .items-table td { border-bottom: 1px solid #e0e0e0; padding: 10px; }
            .items-table .right { text-align: right; }
            .totals-table { width: 50%; float: right; border-collapse: collapse; margin-top: 10px; }
            .totals-table td { padding: 8px 10px; }
            .totals-table .label { text-align: right; font-weight: bold; }
            .totals-table .total-row td { font-weight: bold; font-size: 12pt; border-top: 2px solid #6a1b9a; }
            .footer { margin-top: 40px; text-align: center; font-size: 8pt; color: #888; }
        </style>
    </head>
    <body>
        <div class="page">
            <table class="header-table">
                <tr>
                    <td class="company-details"><h2><?php echo htmlspecialchars($settings['business_name']); ?></h2></td>
                    <td><h1>PROFORMA INVOICE</h1></td>
                </tr>
            </table>

            <table class="details-table">
                <tr>
                    <td class="customer-box">
                        <p><b>Proforma For:</b></p>
                        <p><?php echo htmlspecialchars($customer['name']); ?></p>
                        <p><?php echo htmlspecialchars($customer['email']); ?></p>
                    </td>
                    <td class="invoice-details">
                        <p><b>Proforma #:</b> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                        <p><b>Date:</b> <?php echo date("Y-m-d", strtotime($invoice['issue_date'])); ?></p>
                        <p><b>Expires on:</b> <?php echo date("Y-m-d", strtotime($invoice['due_date'])); ?></p>
                    </td>
                </tr>
            </table>

            <table class="items-table">
                <thead>
                    <tr><th style="width:50%;">Description</th><th class="right">Quantity</th><th class="right">Unit Price</th><th class="right">Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td class="right"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="right"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="right"><?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

             <table class="totals-table">
                <tr>
                    <td class="label" style="width:50%;">Subtotal</td>
                    <td class="right" style="width:50%;"><?php echo number_format($invoice['subtotal'], 2); ?></td>
                </tr>
                <tr>
                    <td class="label">Tax (<?php echo htmlspecialchars($invoice['tax_rate']); ?>%)</td>
                    <td class="right"><?php echo number_format($invoice['tax_amount'], 2); ?></td>
                </tr>
                 <tr class="total-row">
                    <td class="label">AMOUNT DUE</td>
                    <td class="right"><?php echo $currency; ?> <?php echo number_format($invoice['total_amount'], 2); ?></td>
                </tr>
            </table>

             <div class="footer">
                <p><b>Notes:</b> This is not a tax invoice. A final tax invoice will be issued upon delivery of goods/services.</p>
                <p><b>Payment Information:</b><br><?php echo nl2br(htmlspecialchars($invoice['payment_method_info'])); ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * 7. QUOTATION / ESTIMATE TEMPLATE
 */
function get_quotation_template_html($settings, $customer, $contact, $invoice, $items) {
    $is_quote = $invoice['document_type'] === 'Quotation';
    $title = $is_quote ? 'QUOTATION' : 'ESTIMATE';
    $doc_num_label = $is_quote ? 'Quote #' : 'Estimate #';
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');
    ob_start();
    ?>
     <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8"><title><?php echo $title; ?></title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #333; font-size: 9.5pt; }
            .page { padding: 20px; }
            .header-table { width: 100%; border-collapse: collapse; }
            .header-table td { vertical-align: top; }
            .company-details h2 { font-size: 15pt; font-weight: bold; margin: 0; color: #1a237e; }
            h1 { font-size: 20pt; color: #1a237e; margin: 0; text-align: right; }
            .info-bar { background-color: #e8eaf6; padding: 5px 10px; margin-top: 20px; text-align: center; }
            .details-table { width: 100%; margin: 20px 0; }
            .items-table { width: 100%; border-collapse: collapse; }
            .items-table th, .items-table td { border-bottom: 1px solid #ccc; padding: 9px; }
            .items-table th { border-top: 2px solid #1a237e; border-bottom: 2px solid #1a237e; text-align: left; }
            .items-table .right { text-align: right; }
            .totals-table { width: 40%; float: right; margin-top: 10px; }
            .totals-table td { padding: 5px; text-align: right; }
            .totals-table tr.total-row td { font-weight: bold; font-size: 11pt; border-top: 1px solid #333; }
            .footer { margin-top: 25px; font-size: 8pt; color: #666; }
        </style>
    </head>
    <body>
        <div class="page">
            <table class="header-table">
                <tr>
                    <td class="company-details" style="width:50%;">
                        <h2><?php echo htmlspecialchars($settings['business_name']); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></p>
                    </td>
                    <td style="width:50%;">
                        <h1><?php echo $title; ?></h1>
                    </td>
                </tr>
            </table>

            <div class="info-bar">
                <b><?php echo $doc_num_label; ?>:</b> <?php echo htmlspecialchars($invoice['invoice_number']); ?> |
                <b>Date:</b> <?php echo date("Y-m-d", strtotime($invoice['issue_date'])); ?> |
                <b>Valid Until:</b> <?php echo date("Y-m-d", strtotime($invoice['due_date'])); ?>
            </div>

            <table class="details-table">
                <tr>
                    <td style="width: 50%;">
                        <strong>Prepared for:</strong><br>
                        <?php echo htmlspecialchars($customer['name']); ?><br>
                        <?php echo htmlspecialchars($customer['email']); ?>
                    </td>
                </tr>
            </table>

            <table class="items-table">
                <thead>
                    <tr><th>Description</th><th class="right">Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td class="right"><?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

             <table class="totals-table">
                <tr><td>Subtotal</td><td class="right"><?php echo number_format($invoice['subtotal'], 2); ?></td></tr>
                <tr><td>Tax (<?php echo htmlspecialchars($invoice['tax_rate']); ?>%)</td><td class="right"><?php echo number_format($invoice['tax_amount'], 2); ?></td></tr>
                <tr class="total-row"><td>Total</td><td class="right"><?php echo $currency; ?> <?php echo number_format($invoice['total_amount'], 2); ?></td></tr>
            </table>

            <div class="footer">
                <p><b>Terms & Conditions:</b><br><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}


/**
 * 8. RECEIPT TEMPLATE (REDESIGNED)
 */
function get_receipt_template_html($settings, $customer, $contact, $invoice, $items) {
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8"><title>Payment Receipt</title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #333; font-size: 10pt; background-color: #fff; }
            .page { padding: 35px; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
            .header-table td { vertical-align: top; }
            .company-logo { width: 15%; max-width: 60px; height: auto; border-radius: 30px; object-fit: cover; }
            .company-details h2 { font-size: 18pt; font-weight: bold; margin: 0; color: #2c3e50; }
            h1 { font-size: 24pt; color: #2c3e50; margin: 0; text-align: right; font-weight: 300; }
            .receipt-details { text-align: right; line-height: 1.5; }
            .customer-details { margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #1abc9c; }
            .summary-box { background-color: #1abc9c; color: #fff; padding: 20px; text-align: center; margin: 25px 0; }
            .summary-box h3 { margin: 0; font-size: 11pt; text-transform: uppercase; font-weight: normal; }
            .summary-box p { margin: 5px 0 0 0; font-size: 22pt; font-weight: bold; }
            .items-table { width: 100%; border-collapse: collapse; }
            .items-table th, .items-table td { padding: 10px; border-bottom: 1px solid #ecf0f1; }
            .items-table th { text-align: left; font-weight: bold; color: #7f8c8d; text-transform: uppercase; font-size: 9pt; }
            .items-table .right { text-align: right; }
            .totals-table { width: 40%; float: right; margin-top: 15px; }
            .totals-table td { padding: 8px; text-align: right; }
            .totals-table .label { font-weight: bold; }
            .signature-area { margin-top: 60px; text-align: right; }
            .business-stamp { width: 20%; max-width: 70px; height: auto; }
            .footer { margin-top: 30px; text-align: center; font-size: 8pt; color: #95a5a6; border-top: 1px solid #ecf0f1; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class="page">
            <table class="header-table">
                <tr>
                    <td style="width: 60%;">
                        <?php if (!empty($settings['profile_picture_url'])): ?>
                            <img src="<?php echo htmlspecialchars($settings['profile_picture_url']); ?>" alt="Logo" class="company-logo">
                        <?php endif; ?>
                        <h2 style="margin-top: 10px;"><?php echo htmlspecialchars($settings['business_name']); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></p>
                    </td>
                    <td style="width: 40%;">
                        <h1>RECEIPT</h1>
                        <div class="receipt-details">
                            <p><b>Receipt #:</b> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                            <p><b>Payment Date:</b> <?php echo date("F d, Y", strtotime($invoice['issue_date'])); ?></p>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="customer-details">
                <b>Received From:</b><br>
                <?php echo htmlspecialchars($customer['name']); ?><br>
                <?php echo htmlspecialchars($customer['email']); ?>
            </div>

            <div class="summary-box">
                <h3>Total Amount Paid</h3>
                <p><?php echo $currency; ?> <?php echo number_format($invoice['total_amount'], 2); ?></p>
            </div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th>Payment For</th>
                        <th class="right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td class="right"><?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <table class="totals-table">
                 <tr>
                    <td class="label">Subtotal:</td>
                    <td><?php echo number_format($invoice['subtotal'], 2); ?></td>
                </tr>
                <tr>
                    <td class="label">Tax:</td>
                    <td><?php echo number_format($invoice['tax_amount'], 2); ?></td>
                </tr>
            </table>
            
            <div style="clear: both;"></div>
            
            <div class="signature-area">
                <?php if (!empty($settings['business_stamp_url'])): ?>
                    <img src="<?php echo htmlspecialchars($settings['business_stamp_url']); ?>" alt="Stamp" class="business-stamp" style="margin-bottom: -5px;">
                <?php endif; ?>
                <p style="border-top: 1px solid #ccc; padding-top: 5px; margin-top: 5px;">Authorized Signature</p>
            </div>

            <div class="footer">
                <p>Thank you for your business!</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}


/**
 * 9. DELIVERY NOTE TEMPLATE
 */
function get_delivery_note_template_html($settings, $customer, $contact, $invoice, $items) {
    ob_start();
    ?>
     <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8"><title>Delivery Note</title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #333; font-size: 10pt; }
            .page { padding: 20px; }
            h1 { font-size: 18pt; text-align: center; border-bottom: 2px solid #333; padding-bottom: 5px; }
            .details-table { width: 100%; margin: 20px 0; }
            .details-table td { width: 50%; vertical-align: top; padding: 5px; }
            .box { padding: 10px; border: 1px solid #aaa; }
            .items-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .items-table th, .items-table td { border: 1px solid #aaa; padding: 10px; text-align: left; }
            .items-table th { background-color: #f0f0f0; }
            .signature-area { margin-top: 50px; width: 100%; }
            .signature-area td { width: 50%; padding-top: 30px; border-top: 1px solid #333; }
        </style>
    </head>
    <body>
        <div class="page">
            <h1>DELIVERY NOTE</h1>
            <p align="right"><b>DN #:</b> <?php echo htmlspecialchars($invoice['invoice_number']); ?><br><b>Date:</b> <?php echo date("Y-m-d", strtotime($invoice['issue_date'])); ?></p>

            <table class="details-table">
                <tr>
                    <td>
                        <div class="box">
                            <b>FROM:</b><br>
                            <?php echo htmlspecialchars($settings['business_name']); ?><br>
                            <?php echo nl2br(htmlspecialchars($settings['business_address'])); ?>
                        </div>
                    </td>
                     <td>
                        <div class="box">
                            <b>DELIVER TO:</b><br>
                            <?php echo htmlspecialchars($customer['name']); ?><br>
                             <?php echo htmlspecialchars($customer['email']); ?>
                        </div>
                    </td>
                </tr>
            </table>

            <table class="items-table">
                <thead><tr><th style="width:10%;">#</th><th style="width:70%;">Description</th><th style="width:20%;">Quantity</th></tr></thead>
                <tbody>
                <?php $i = 1; foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <table class="signature-area">
                <tr>
                    <td>Received By:</td>
                    <td>Date:</td>
                </tr>
            </table>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}


/**
 * 10. PURCHASE ORDER TEMPLATE
 */
function get_purchase_order_template_html($settings, $customer, $contact, $invoice, $items) {
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head><meta charset="utf-8"><title>Purchase Order</title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #333; font-size: 10pt; }
            .page { padding: 25px; }
            h1 { font-size: 20pt; text-align: right; color: #d32f2f; }
            .header-table { width: 100%; margin-bottom: 25px; }
            .header-table td { vertical-align: top; }
            .details-table { width: 100%; margin-bottom: 25px; border-collapse: collapse; }
            .details-table td { width: 50%; padding: 10px; border: 1px solid #ddd; background-color: #fafafa; }
            .items-table { width: 100%; border-collapse: collapse; }
            .items-table th, .items-table td { padding: 9px; text-align: left; border-bottom: 1px solid #ddd; }
            .items-table th { background-color: #d32f2f; color: #fff; }
            .items-table .right { text-align: right; }
            .totals-table { width: 40%; float: right; margin-top: 15px; }
            .totals-table td { text-align: right; padding: 5px; }
            .totals-table .total-row td { font-weight: bold; border-top: 2px solid #d32f2f; }
            .footer { margin-top: 30px; }
        </style>
    </head>
    <body>
        <div class="page">
            <table class="header-table">
                <tr>
                    <td>
                        <h2><?php echo htmlspecialchars($settings['business_name']); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></p>
                    </td>
                    <td>
                        <h1>PURCHASE ORDER</h1>
                        <p align="right"><b>PO #:</b> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                        <p align="right"><b>Date:</b> <?php echo date("Y-m-d", strtotime($invoice['issue_date'])); ?></p>
                    </td>
                </tr>
            </table>

            <table class="details-table">
                <tr>
                    <td><b>VENDOR:</b><br><?php echo htmlspecialchars($customer['name']); ?></td>
                    <td><b>SHIP TO:</b><br><?php echo htmlspecialchars($settings['business_name']); ?><br><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></td>
                </tr>
            </table>

            <table class="items-table">
                <thead>
                    <tr><th>Item</th><th class="right">Qty</th><th class="right">Unit Price</th><th class="right">Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td class="right"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="right"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="right"><?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

             <table class="totals-table">
                <tr><td>Subtotal</td><td><?php echo number_format($invoice['subtotal'], 2); ?></td></tr>
                <tr><td>Tax (<?php echo htmlspecialchars($invoice['tax_rate']); ?>%)</td><td><?php echo number_format($invoice['tax_amount'], 2); ?></td></tr>
                <tr class="total-row"><td>Total</td><td><?php echo $currency; ?> <?php echo number_format($invoice['total_amount'], 2); ?></td></tr>
            </table>

             <div class="footer">
                <p><b>Notes:</b><br><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
                <p style="margin-top: 30px;"><b>Authorized By:</b> _________________________</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}


/**
 * 11. DEMAND NOTICE TEMPLATE
 */
function get_demand_notice_template_html($settings, $customer, $invoice) {
    $currency = htmlspecialchars($settings['default_currency'] ?? 'TZS');
    $total_amount_due = $invoice['total_amount'] - $invoice['amount_paid'];
    $overdue_days = (new DateTime())->diff(new DateTime($invoice['due_date']))->days;

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Demand Notice</title>
        <style>
            body { font-family: 'dejavusans', sans-serif; color: #333; font-size: 11pt; line-height: 1.6; }
            .page { padding: 40px; }
            .header { text-align: center; margin-bottom: 30px; }
            .header h1 { font-size: 18pt; color: #b71c1c; font-weight: bold; text-transform: uppercase; margin: 0; }
            .header h2 { font-size: 14pt; font-weight: normal; margin: 0; }
            .content { border: 1px solid #ccc; padding: 25px; }
            .details-table { width: 100%; margin-bottom: 20px; }
            .details-table td { vertical-align: top; padding: 5px 0; }
            .footer { margin-top: 40px; text-align: center; font-size: 9pt; color: #777; }
            .warning { border: 1px solid #b71c1c; background-color: #ffcdd2; padding: 15px; margin-top: 20px; text-align: center; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="header">
                <h1>Demand Notice for Overdue Payment</h1>
                <h2><?php echo htmlspecialchars($settings['business_name']); ?></h2>
            </div>

            <div class="content">
                <table class="details-table">
                    <tr>
                        <td style="width: 120px;"><b>Date:</b></td>
                        <td><?php echo date("F d, Y"); ?></td>
                    </tr>
                    <tr>
                        <td><b>To:</b></td>
                        <td>
                            <b><?php echo htmlspecialchars($customer['name']); ?></b><br>
                            <?php echo htmlspecialchars($customer['email']); ?>
                        </td>
                    </tr>
                     <tr>
                        <td><b>From:</b></td>
                        <td>
                            <b><?php echo htmlspecialchars($settings['business_name']); ?></b><br>
                            <?php echo nl2br(htmlspecialchars($settings['business_address'])); ?>
                        </td>
                    </tr>
                </table>

                <hr>

                <p><b>RE: Final Demand for Payment of Overdue Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></b></p>

                <p>
                    Dear <?php echo htmlspecialchars($customer['name']); ?>,
                </p>

                <p>
                    This letter serves as a final formal demand for payment of the outstanding amount related to Invoice #<b><?php echo htmlspecialchars($invoice['invoice_number']); ?></b>, which was issued on <?php echo date("F d, Y", strtotime($invoice['issue_date'])); ?>.
                </p>

                <p>
                    The due date for this invoice was <b><?php echo date("F d, Y", strtotime($invoice['due_date'])); ?></b>, and as of today, it is <b><?php echo $overdue_days; ?> days overdue</b>. The total outstanding balance is <b><?php echo $currency; ?> <?php echo number_format($total_amount_due, 2); ?></b>.
                </p>

                <div class="warning">
                    Please remit payment in full within seven (7) business days from the date of this notice to avoid further action.
                </div>

                <p>
                    Failure to settle this outstanding amount may result in additional collection efforts, which could include legal action to recover the debt, the costs of which you will be liable for.
                </p>

                <p>
                    We urge you to treat this matter with the urgency it requires. Please contact us immediately at <?php echo htmlspecialchars($settings['business_email']); ?> to arrange payment.
                </p>

                <p>Sincerely,</p>
                <br>
                <p><b>The Management</b><br><?php echo htmlspecialchars($settings['business_name']); ?></p>

            </div>

            <div class="footer">
                This is a legally binding demand notice.
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}
?>