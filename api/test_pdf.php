<style>
        body { background: #fff; color: #333; font-family: helvetica; font-size: 10pt; }
        .container { width: 100%; }
        .invoice { padding: 40px; background: #fff; }
        .logo { width: 100px; }
        .document-type { text-align: right; color: #444; font-size: 22pt; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border-bottom: 1px solid #ddd; padding: 8px; }
        .table th { background: #f3f4f6; font-weight: bold; }
        .table-sm td { padding: 5px; }
        .conditions, .bottom-page { font-size: 8pt; color: #666; margin-top: 30px; }
    </style>

    <div class='container'>
      <div class='invoice'>
        <table width='100%'>
          <tr>
            <td width='60%'>
              <img src='{$settings['profile_picture_url']}' class='logo'><br>
              <strong>{$settings['business_name']}</strong><br>
              {$settings['business_address']}<br>
              {$settings['business_email']}
            </td>
            <td width='40%' align='right'>
              <h1 class='document-type'>INVOICE</h1>
              <strong>{$invoice_number}</strong><br>
              <strong>Issue Date:</strong> {$issue_date}<br>
              <strong>Due Date:</strong> {$due_date}
            </td>
          </tr>
        </table>

        <br><br>
        <table width='100%'>
          <tr>
            <td width='50%'>
              <strong>Bill To:</strong><br>
              {$display_name}<br>
              {$display_email}<br>
              {$display_phone}
            </td>
            <td width='50%'></td>
          </tr>
        </table>

        <br><br>
        <table class='table'>
          <thead>
            <tr>
              <th>Description</th>
              <th>Qty</th>
              <th>Unit</th>
              <th>Unit Price</th>
              <th>VAT</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            {$items_html}
          </tbody>
        </table>

        <br>
        <table width='40%' align='right' class='table table-sm'>
          <tr><td><strong>Subtotal</strong></td><td align='right'>" . number_format($subtotal, 2) . " TZS</td></tr>
          <tr><td>VAT ({$tax_rate}%)</td><td align='right'>" . number_format($tax_amount, 2) . " TZS</td></tr>
          <tr><td><strong>Total Due</strong></td><td align='right'><strong>" . number_format($total_amount, 2) . " TZS</strong></td></tr>
        </table>

        <div class='conditions'>
          <strong>Notes:</strong><br>" . nl2br(htmlspecialchars($notes)) . "
          <br><br>
          Tafadhali fanya malipo kabla ya tarehe ya mwisho iliyoonyeshwa hapo juu.
          <br>Asante kwa kufanya biashara nasi.
        </div>

        <div class='bottom-page' align='right'>
          {$settings['business_name']}<br>
          {$settings['business_address']}<br>
          {$settings['business_email']}
        </div>
      </div>
    </div>
    ";