<?php
require_once 'api/db.php';

$token = $_GET['token'] ?? null;
$error_message = null;
$vendor_details = null;
$payout_request_id = null;
$has_existing_details = false;
$existing_details_html = '';
$default_currency = 'TZS';

if (!$token) {
    $error_message = "Invalid or missing submission link.";
} else {
    try {
        // Get settings for currency
        $stmt_settings = $pdo->query("SELECT default_currency FROM settings WHERE id = 1");
        $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
        if ($settings && !empty($settings['default_currency'])) {
            $default_currency = $settings['default_currency'];
        }

        // Tafuta ombi NA taarifa za vendor kwa pamoja
        $stmt = $pdo->prepare(
            "SELECT 
                pr.id, 
                pr.status, 
                v.full_name, 
                v.payment_method, 
                v.bank_name, 
                v.account_name, 
                v.account_number, 
                v.mobile_network, 
                v.mobile_phone
             FROM payout_requests pr 
             JOIN vendors v ON pr.vendor_id = v.id 
             WHERE pr.request_token = ?"
        );
        $stmt->execute([$token]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($request) {
            if ($request['status'] !== 'Pending') {
                 $error_message = "This invoice request has already been submitted or processed.";
            } else {
                $vendor_details = $request;
                $payout_request_id = $request['id'];
                
                // Angalia kama ana taarifa za zamani
                if (!empty($request['payment_method'])) {
                    $has_existing_details = true;
                    if ($request['payment_method'] == 'Bank Transfer') {
                        $existing_details_html = "<strong>Bank Transfer:</strong> {$request['bank_name']} / {$request['account_name']} / ......." . substr($request['account_number'], -4);
                    } else {
                        $existing_details_html = "<strong>Mobile Money:</strong> {$request['mobile_network']} / {$request['mobile_phone']}";
                    }
                }
            }
        } else {
            $error_message = "This invoice request is invalid or has expired.";
        }
    } catch (PDOException $e) {
        $error_message = "A database error occurred. Please try again later.";
        error_log("submit_invoice.php DB Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Invoice - ChatMe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto max-w-2xl mt-10">
        <div class="flex justify-center mb-6 items-center">
            <i class="fas fa-comments text-indigo-400 text-5xl"></i>
            <h1 class="ml-3 text-4xl font-bold text-gray-800">ChatMe</h1>
        </div>

        <div class="bg-white p-8 rounded-lg shadow-md border">
            <?php if ($error_message): ?>
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-red-600">Request Invalid</h2>
                    <p class="text-gray-600 mt-4"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php else: ?>
                <h2 class="text-2xl font-bold text-gray-800 mb-6">Invoice Submission</h2>
                
                <form id="invoiceSubmitForm" class="space-y-6" 
                      x-data="{ paymentMethod: '<?php echo $vendor_details['payment_method'] ?? ''; ?>', useExisting: <?php echo $has_existing_details ? "'true'" : "'false'"; ?> }"
                      x-init="$watch('useExisting', value => { if (value == 'false') { paymentMethod = '' } })">
                    
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="payout_request_id" value="<?php echo $payout_request_id; ?>">
                    <input type="hidden" name="use_existing_details" :value="useExisting ? 'yes' : 'no'">

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Full Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($vendor_details['full_name']); ?>" class="mt-1 w-full p-3 border-gray-300 border rounded-md bg-gray-100" readonly>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label for="service_type" class="block text-sm font-medium text-gray-700">Service Type</label>
                            <select id="service_type" name="service_type" class="mt-1 w-full p-3 border-gray-300 border rounded-md" required>
                                <option value="">Please select...</option>
                                <option value="Professional Service">Professional Service (WHT 5%)</option>
                                <option value="Goods/Products">Goods/Products (WHT 3%)</option>
                                <option value="Rent">Rent (WHT 10%)</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                         <div>
                            <label for="amount" class="block text-sm font-medium text-gray-700">Amount (<?php echo htmlspecialchars($default_currency); ?>)</label>
                            <input type="number" id="amount" name="amount" class="mt-1 w-full p-3 border-gray-300 border rounded-md" placeholder="Enter total amount" required>
                        </div>
                    </div>

                    <?php if ($has_existing_details): ?>
                    <div class="border p-4 rounded-md bg-gray-50 space-y-3">
                        <label class="block text-sm font-medium text-gray-900">Payment Details</label>
                        <div class="text-sm p-3 bg-white rounded border border-indigo-200">
                            <?php echo $existing_details_html; ?>
                        </div>
                        <div class="flex space-x-4">
                            <label class="flex items-center"><input type="radio" name="useExistingRadio" x-model="useExisting" value="true" class="mr-2"> Use these details</label>
                            <label class="flex items-center"><input type="radio" name="useExistingRadio" x-model="useExisting" value="false" class="mr-2"> Change details</label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="space-y-6" x-show="useExisting === 'false'" x-cloak>
                        <div>
                            <label for="payment_method" class="block text-sm font-medium text-gray-700">Payment Method</label>
                            <select id="payment_method" name="payment_method" x-model="paymentMethod" class="mt-1 w-full p-3 border-gray-300 border rounded-md" :required="!useExisting">
                                <option value="">Select payment method...</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Mobile Money">Mobile Money</option>
                            </select>
                        </div>

                        <div x-show="paymentMethod === 'Bank Transfer'" class="space-y-4 border p-4 rounded-md bg-gray-50" x-cloak>
                            <h3 class="font-medium text-gray-700">Bank Details</h3>
                            <div><label for="bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label><input type="text" id="bank_name" name="bank_name" class="mt-1 w-full p-3 border-gray-300 border rounded-md"></div>
                            <div class="grid grid-cols-2 gap-6">
                                <div><label for="account_name" class="block text-sm font-medium text-gray-700">Account Name</label><input type="text" id="account_name" name="account_name" class="mt-1 w-full p-3 border-gray-300 border rounded-md"></div>
                                <div><label for="account_number" class="block text-sm font-medium text-gray-700">Account Number</label><input type="text" id="account_number" name="account_number" class="mt-1 w-full p-3 border-gray-300 border rounded-md"></div>
                            </div>
                        </div>

                        <div x-show="paymentMethod === 'Mobile Money'" class="space-y-4 border p-4 rounded-md bg-gray-50" x-cloak>
                            <h3 class="font-medium text-gray-700">Mobile Money Details</h3>
                             <div class="grid grid-cols-2 gap-6">
                                 <div><label for="mobile_network" class="block text-sm font-medium text-gray-700">Mobile Network</label><select id="mobile_network" name="mobile_network" class="mt-1 w-full p-3 border-gray-300 border rounded-md"><option value="">Select network...</option><option value="M-Pesa (Vodacom)">M-Pesa (Vodacom)</option><option value="Tigo Pesa">Mixx By Yas</option><option value="Airtel Money">Airtel Money</option><option value="HaloPesa">HaloPesa</option></select></div>
                                 <div><label for="mobile_phone" class="block text-sm font-medium text-gray-700">Phone Number</label><input type="text" id="mobile_phone" name="mobile_phone" class="mt-1 w-full p-3 border-gray-300 border rounded-md" placeholder="e.g. 07XXXXXXXX"></div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="invoice_file" class="block text-sm font-medium text-gray-700">Upload Invoice (PDF, JPG, PNG)</label>
                        <input type="file" id="invoice_file" name="invoice_file" class="mt-1 w-full p-2 border-gray-300 border rounded-md" required>
                    </div>

                    <div id="form-messages" class="hidden text-red-600"></div>
                    <div><button type="submit" id="submit-btn" class="w-full bg-indigo-600 text-white px-6 py-3 rounded-lg hover:bg-indigo-700 font-semibold">Submit Invoice</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        document.getElementById('invoiceSubmitForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submit-btn');
            const formMessages = document.getElementById('form-messages');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
            formMessages.classList.add('hidden');

            const formData = new FormData(this);

            try {
                // --- REKEBISHO LIPO HAPA (lenye 't' moja) ---
                const response = await fetch('api/handle_invoice_submition.php', {
                    method: 'POST',
                    body: formData 
                });

                const result = await response.json();

                if (response.ok && result.status === 'success') {
                    document.querySelector('.bg-white.p-8').innerHTML = `
                        <div class="text-center">
                            <h2 class="text-2xl font-bold text-green-600">Submission Successful!</h2>
                            <p class="text-gray-600 mt-4">${result.message}</p>
                            <p class="mt-4 text-sm">You can now close this window.</p>
                        </div>
                    `;
                } else {
                    throw new Error(result.message || 'An unknown error occurred.');
                }
            } catch (error) {
                formMessages.textContent = error.message;
                formMessages.classList.remove('hidden');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Invoice';
            }
        });
    </script>
</body>
</html>