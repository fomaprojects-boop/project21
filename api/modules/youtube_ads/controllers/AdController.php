<?php

namespace Modules\YouTubeAds\Controllers;

require_once __DIR__ . '/../../../../api/config.php';
require_once __DIR__ . '/../../../../api/db.php';
require_once __DIR__ . '/../../../../api/modules/youtube_ads/models/Advertiser.php';
require_once __DIR__ . '/../../../../api/modules/youtube_ads/models/Ad.php';
require_once __DIR__ . '/../../../../api/modules/youtube_ads/models/AdVideoMap.php';
require_once __DIR__ . '/../../../../api/modules/youtube_ads/services/UploadService.php';
require_once __DIR__ . '/../../../../api/modules/youtube_ads/models/YoutubeToken.php';
require_once __DIR__ . '/../../../../api/modules/youtube_ads/services/EncryptionService.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../api/mailer_config.php';
require_once __DIR__ . '/../../../../api/invoice_templates.php';

use Modules\YouTubeAds\Models\Advertiser;
use Modules\YouTubeAds\Models\Ad;
use Modules\YouTubeAds\Models\AdVideoMap;
use Modules\YouTubeAds\Models\YoutubeToken;
use Google_Client;

class AdController {
    private $db;
    private $advertiserModel;
    private $adModel;
    private $adVideoMapModel;
    private $tenantId; // <-- Tuta-set ID ya biashara (ambayo ni user_id) hapa

    public function __construct() {
        global $pdo;
        $this->db = $pdo;
        $this->advertiserModel = new Advertiser($this->db);
        $this->adModel = new Ad($this->db);
        $this->adVideoMapModel = new AdVideoMap($this->db);

        // --- REKEBISHO LIKO HAPA ---
        // Tunaita hii function kuanzisha session na ku-set tenantId
        $this->initializeSessionAndTenant();
    }

    /**
     * Helper function to start session and set the tenantId
     * Kwenye structure yako, tenantId NI user_id ya aliyelogin.
     */
    private function initializeSessionAndTenant() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
            exit;
        }
        
        // Hii ndiyo logic sahihi kulingana na database structure yako
        // tenantId inalingana na user_id ya aliyelogin
        $this->tenantId = $_SESSION['user_id']; 
    }

    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'createAdvertiser':
                $this->createAdvertiser();
                break;
            case 'verifyEmail':
                $this->verifyEmail();
                break;
            case 'createAd':
                $this->createAd();
                break;
            case 'mapAdToVideo':
                $this->mapAdToVideo();
                break;
            case 'linkManualVideo':
                $this->linkManualVideo();
                break;
            case 'getLinkedVideos':
                $this->getLinkedVideos();
                break;
            case 'getPendingCampaigns':
                $this->getPendingCampaigns();
                break;
            case 'getActiveCampaigns':
                $this->getActiveCampaigns();
                break;
            case 'getAdvertisers':
                $this->getAdvertisers();
                break;
            default:
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
        }
    }

    public function verifyEmail() {
        $email = $_POST['email'];
        $code = $_POST['code'];

        // Hii function haihitaji tenantId, ni sawa
        if ($this->advertiserModel->verifyEmail($email, $code, $this->tenantId)) {
            echo json_encode(['status' => 'success', 'message' => 'Email verified successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid verification code or code has expired.']);
        }
    }

    public function createAdvertiser() {
        // Tunatumia $this->tenantId iliyokuwa set kwenye construct
        $tenantId = $this->tenantId; 

        $name = $_POST['name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $tin = $_POST['tin'] ?? null;
        $vrn = $_POST['vrn'] ?? null;
        $address = $_POST['address'] ?? null;

        // --- REKEBISHO LA VERIFICATION CODE HAPA ---
        $verificationCode = random_int(100000, 999999);
        $codeExpiresAt = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');

        // Send verification email
        $subject = 'Verify your email address';
        $message = "Your verification code is: $verificationCode";
        $headers = 'From: info@fomaentertainment.com' . "\r\n" .
            'Reply-To: info@fomaentertainment.com' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

        mail($email, $subject, $message, $headers);

        // Hii $tenantId sasa ni user_id ya aliyelogin, ambayo ni sahihi kwa database yako
        $this->advertiserModel->create($tenantId, $name, $email, $phone, $tin, $vrn, $address, $verificationCode, $codeExpiresAt);
        echo json_encode(['status' => 'success', 'message' => 'Advertiser created successfully. A verification email has been sent.']);
    }

    public function createAd()
    {
        global $pdo;
        $tenantId = $this->tenantId;

        // 1. Pata hizi data kwanza
        $advertiserId = $_POST['advertiser_id'];
        $title = $_POST['title'];
        $campaignType = $_POST['campaign_type']; // Pata hii mapema
        $price = (float)$_POST['price']; // Hii ni Subtotal kabla ya VAT

        // 2. Weka na validate tarehe
        $startDateInput = $_POST['start_date'];
        $endDateInput = $_POST['end_date'];

        $validateDate = function($date) {
            $d = \DateTime::createFromFormat('Y-m-d', $date);
            return $d && $d->format('Y-m-d') === $date;
        };

        if (!$validateDate($startDateInput)) {
             throw new \Exception("Invalid Start Date. Hakikisha format ni YYYY-MM-DD.");
        }
         if (!$validateDate($endDateInput)) {
             throw new \Exception("Invalid End Date. Hakikisha format ni YYYY-MM-DD.");
        }

        $startDate = $startDateInput;
        $endDate = $endDateInput;


        // 3. Rekebisha logic ya 'placement'
        $placement = null; 
        if ($campaignType === 'Manual') {
            if (empty($_POST['placement'])) {
                throw new \Exception("Placement is required for Manual Sponsorship campaigns.");
            }
            $placement = $_POST['placement'];
        }

        try {
            $pdo->beginTransaction();

            $advertiser = $this->advertiserModel->findById($advertiserId);
            if (!$advertiser) {
                throw new \Exception("Advertiser not found.");
            }

            // --- MABADILIKO YA VAT YANAANZIA HAPA ---

            // 1. Tenga variables za hesabu ya kodi
            $subtotal = $price;
            $vatRate = 0.00;
            $taxAmount = 0.00;
            $totalAmount = $subtotal;

            // 2. Pata settings za tenant (mteja) kutoka kwenye database
            $stmt_settings = $pdo->prepare("SELECT vat_number FROM settings WHERE tenant_id = ?");
            $stmt_settings->execute([$tenantId]);
            $tenantSettings = $stmt_settings->fetch(\PDO::FETCH_ASSOC);

            // 3. Angalia kama tenant ana VAT number kwenye settings zake
            if ($tenantSettings && !empty($tenantSettings['vat_number'])) {
                $vatRate = 18.00; // Weka 18% VAT
                $taxAmount = $subtotal * ($vatRate / 100);
                $totalAmount = $subtotal + $taxAmount;
            }
            // --- MABADILIKO YA VAT YANAISHIA HAPA ---


            $stmt_customer = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
            $stmt_customer->execute([$advertiser['email']]);
            $customerId = $stmt_customer->fetchColumn();

            if (!$customerId) {
                $stmt_create_customer = $pdo->prepare("INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)");
                $stmt_create_customer->execute([$advertiser['name'], $advertiser['email'], $advertiser['contact_phone']]);
                $customerId = $pdo->lastInsertId();
            }

            // --- Invoice Creation Logic (imebadilishwa kutumia variables za VAT) ---
            $stmt_num = $pdo->query("SELECT MAX(id) AS max_id FROM invoices");
            $last_id = $stmt_num->fetchColumn();
            $invoice_number = "INV-" . date('Y') . "-" . str_pad(($last_id ?: 0) + 1, 4, '0', STR_PAD_LEFT);
            
            $issueDate = date('Y-m-d');
            $dueDate = (new \DateTime('+30 days'))->format('Y-m-d');

            // Tumia $vatRate hapa badala ya '0.00'
            $stmt_inv = $pdo->prepare("INSERT INTO invoices (customer_id, user_id, invoice_number, status, issue_date, due_date, tax_rate) VALUES (?, ?, ?, 'Draft', ?, ?, ?)");
            $stmt_inv->execute([$customerId, $tenantId, $invoice_number, $issueDate, $dueDate, $vatRate]);
            $invoiceId = $pdo->lastInsertId();

            // Tumia $subtotal hapa
            $stmt_item = $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total) VALUES (?, ?, 1, ?, ?)");
            $stmt_item->execute([$invoiceId, $title, $subtotal, $subtotal]);

            // Tumia $subtotal, $totalAmount, na $totalAmount hapa
            $stmt_update = $pdo->prepare("UPDATE invoices SET subtotal = ?, total_amount = ?, balance_due = ? WHERE id = ?");
            $stmt_update->execute([$subtotal, $totalAmount, $totalAmount, $invoiceId]);

            // PDF Generation (Hii haihitaji kubadilika, itachukua data mpya)
            $settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
            $customer_main_info = $pdo->query("SELECT * FROM customers WHERE id = $customerId")->fetch(\PDO::FETCH_ASSOC);
            $invoice_data_db = $pdo->query("SELECT * FROM invoices WHERE id = $invoiceId")->fetch(\PDO::FETCH_ASSOC);
            $items_data_db = $pdo->query("SELECT * FROM invoice_items WHERE invoice_id = $invoiceId")->fetchAll(\PDO::FETCH_ASSOC);

            if (isset($invoice_data_db['subtotal']) && isset($invoice_data_db['tax_rate'])) {
                $invoice_data_db['tax_amount'] = $invoice_data_db['subtotal'] * ($invoice_data_db['tax_rate'] / 100);
            } else {
                $invoice_data_db['tax_amount'] = 0;
            }
            
            // Muhimu: Hakikisha 'invoice_templates.php' inaweza kupokea na kuonyesha vizuri
            // invoice_data_db['tax_rate'] na 'total_amount' iliyokokotolewa.
            $pdf_html = get_default_template_html($settings, $customer_main_info, [], $invoice_data_db, $items_data_db);

            $pdf = new \TCPDF();
            $pdf->AddPage();
            $pdf->writeHTML($pdf_html, true, false, true, false, '');
            $upload_dir = __DIR__ . '/../../../../uploads/customer_invoices/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);
            $pdf_filename = 'invoice_' . $invoice_number . '.pdf';
            $pdf_path = $upload_dir . $pdf_filename;
            $pdf->Output($pdf_path, 'F');
            $pdf_url = 'uploads/customer_invoices/' . $pdf_filename;

            $stmt_pdf = $pdo->prepare("UPDATE invoices SET pdf_url = ?, status = 'Unpaid' WHERE id = ?");
            $stmt_pdf->execute([$pdf_url, $invoiceId]);
            // --- End Invoice Logic ---

            // Handle file upload
            $uploadDir = __DIR__ . '/../../../../uploads/ads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fileName = basename($_FILES['ad_file']['name']);
            $filePath = $uploadDir . $fileName;
            move_uploaded_file($_FILES['ad_file']['tmp_name'], $filePath);

            $status = ($campaignType == 'Dedicated') ? 'Queued for Upload' : 'Pending Payment';
            $paymentStatus = 'Pending';
            
            // Hifadhi 'price' kama 'amount' ya tangazo. Hii inawakilisha gharama ya msingi ya tangazo.
            // Invoice ndio ina total_amount (pamoja na kodi).
            $this->adModel->create($tenantId, $advertiserId, $invoiceId, $title, $fileName, $placement, $campaignType, null, $startDate, $endDate, $price, $status, $paymentStatus);

            $pdo->commit();

            // Send Email
            $mail = getMailerInstance($pdo);
            $mail->addAddress($advertiser['email'], $advertiser['name']);
            $mail->isHTML(true);
            $mail->Subject = "Invoice {$invoice_number} for your Ad Campaign";
            $mail->Body = "<p>Hello {$advertiser['name']},</p><p>Please find the invoice for your ad campaign '{$title}' attached. The ad will be activated upon payment.</p>";
            $mail->addAttachment($pdf_path, $pdf_filename);
            $mail->send();

            echo json_encode(['status' => 'success', 'message' => 'Ad scheduled and invoice sent successfully.']);
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            error_log("Ad Creation Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to create ad: ' . $e->getMessage()]);
        }
    }

    private function getAuthenticatedGoogleClient() {
        $youtubeTokenModel = new YoutubeToken($this->db, new \Modules\YouTubeAds\Services\EncryptionService());
        $tokenData = $youtubeTokenModel->getTokens($this->tenantId);

        if (!$tokenData) {
            return null;
        }

        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setAccessToken($tokenData);

        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $newAccessToken = $client->getAccessToken();
            $expires_at = new \DateTime();
            $expires_at->add(new \DateInterval('PT' . $newAccessToken['expires_in'] . 'S'));
            $youtubeTokenModel->saveTokens(
                $this->tenantId,
                $newAccessToken['access_token'],
                $newAccessToken['refresh_token'] ?? $client->getRefreshToken(),
                $expires_at
            );
        }

        return $client;
    }

    public function mapAdToVideo() {
        // Tunatumia $this->tenantId iliyokuwa set kwenye construct
        $tenantId = $this->tenantId;
        
        $adId = $_POST['ad_id'];
        $videoId = $_POST['video_id'];
        $this->adVideoMapModel->create($adId, $tenantId, $videoId);
        echo json_encode(['status' => 'success', 'message' => 'Ad mapped to video successfully.']);
    }

    private function extractYouTubeVideoId($url) {
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/';
        preg_match($pattern, $url, $matches);
        return $matches[1] ?? $url; // Return the original string if no match
    }

    public function linkManualVideo() {
        $tenantId = $this->tenantId;
        // Read JSON data from the request body
        $data = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['ad_id']) || !isset($data['video_id'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing ad_id/video_id in request.']);
            return;
        }

        $adId = $data['ad_id'];
        $rawVideoId = $data['video_id'];
        $videoId = $this->extractYouTubeVideoId($rawVideoId);

        $this->adVideoMapModel->create($adId, $tenantId, $videoId);
        echo json_encode(['status' => 'success', 'message' => 'Video linked successfully.']);
    }

    public function getLinkedVideos() {
        $adId = $_GET['ad_id'];
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
        $result = $this->adVideoMapModel->getVideosByAdId($adId, $page, $limit);
        echo json_encode(['status' => 'success', 'videos' => $result['data'], 'total' => $result['total']]);
    }

    public function getPendingCampaigns() {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3;
        $result = $this->adModel->getPendingCampaignsByTenant($this->tenantId, $page, $limit);
        echo json_encode(['status' => 'success', 'campaigns' => $result['data'], 'total' => $result['total']]);
    }

    public function getActiveCampaigns() {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3;
        $result = $this->adModel->getActiveCampaignsByTenant($this->tenantId, $page, $limit);
        echo json_encode(['status' => 'success', 'campaigns' => $result['data'], 'total' => $result['total']]);
    }

    public function getAdvertisers() {
        $advertisers = $this->advertiserModel->getVerifiedByTenant($this->tenantId);
        echo json_encode(['status' => 'success', 'advertisers' => $advertisers]);
    }
}

$adController = new AdController();
$adController->handleRequest();