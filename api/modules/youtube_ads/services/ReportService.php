<?php

namespace Modules\YouTubeAds\Services;

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../mailer_config.php'; 
require_once __DIR__ . '/../../../phpqrcode.php'; 
require_once __DIR__ . '/../models/Ad.php';
require_once __DIR__ . '/../models/Advertiser.php';
require_once __DIR__ . '/../models/AdReport.php';
require_once __DIR__ . '/../models/YoutubeToken.php';
require_once __DIR__ . '/YouTubeService.php';
require_once __DIR__ . '/EmailService.php'; 
require_once __DIR__ . '/EncryptionService.php';

use TCPDF;
use Modules\YouTubeAds\Models\YoutubeToken;
use Modules\YouTubeAds\Services\EncryptionService;
use Services\EmailService;

class ReportService {
    private $db;
    private $adModel;
    private $advertiserModel;
    private $adReportModel;
    private $youtubeService;
    private $emailService;

    public function __construct($db) {
        $this->db = $db;
        $this->adModel = new \Modules\YouTubeAds\Models\Ad($db);
        $this->advertiserModel = new \Modules\YouTubeAds\Models\Advertiser($db);
        $this->adReportModel = new \Modules\YouTubeAds\Models\AdReport($db);
        
        $encryptionService = new EncryptionService();
        $youtubeTokenModel = new YoutubeToken($db, $encryptionService);
        $this->youtubeService = new YouTubeService($db, $youtubeTokenModel);
        
        $this->emailService = new EmailService($db);
    }

    private function getTenantSettings($tenantId) {
        $stmt = $this->db->prepare("SELECT * FROM settings WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$settings) {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$tenantId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($user) {
                $settings = [
                    'business_name' => $user['company_name'] ?? 'Your Business',
                    'business_email' => $user['email'],
                    'business_address' => '',
                    'business_phone' => $user['phone'] ?? '',
                    'default_currency' => 'TZS',
                    'profile_picture_url' => '',
                    'business_stamp_url' => ''
                ];
            }
        }
        return $settings;
    }

    public function generateAndEmailReport($adId) {
        $ad = $this->adModel->findById($adId);
        if (!$ad) {
            error_log("ReportService: Ad with ID {$adId} not found.");
            return;
        }

        $advertiser = $this->advertiserModel->findById($ad['advertiser_id']);
        if (!$advertiser) {
            error_log("ReportService: Advertiser not found for ad ID {$adId}.");
            return;
        }
        
        $adVideoMaps = $this->adModel->getAdVideoMaps($adId);
        $videoIds = array_map(function($map) { return $map['new_video_id'] ?? $map['video_id']; }, $adVideoMaps);
        $videoIds = array_filter($videoIds);

        if (empty($videoIds)) {
            error_log("ReportService: No videos mapped to ad ID {$adId} for reporting.");
            return;
        }

        $analyticsData = $this->youtubeService->fetchVideoAnalytics($videoIds, $ad['tenant_id']);

        $aggregatedAnalytics = [
            'analytics' => [
                'total' => [
                    'views' => 0,
                    'likes' => 0,
                    'comments' => 0,
                    'estimatedMinutesWatched' => 0,
                ]
            ]
        ];

        if (isset($analyticsData['analytics']) && !empty($analyticsData['analytics'])) {
            foreach ($analyticsData['analytics'] as $metrics) {
                $aggregatedAnalytics['analytics']['total']['views'] += $metrics['views'];
                $aggregatedAnalytics['analytics']['total']['likes'] += $metrics['likes'];
                $aggregatedAnalytics['analytics']['total']['comments'] += $metrics['comments'];
                $aggregatedAnalytics['analytics']['total']['estimatedMinutesWatched'] += $metrics['estimatedMinutesWatched'];
            }
        }

        $pdfPath = $this->generatePdfReport($ad, $advertiser, $aggregatedAnalytics, $analyticsData);
        
        if ($pdfPath) {
            $this->adReportModel->createReport($adId, $ad['tenant_id'], $ad['advertiser_id'], date('Y-m-d'), $aggregatedAnalytics, $pdfPath);
            
            $settings = $this->getTenantSettings($ad['tenant_id']);
            $businessName = $settings['business_name'] ?? 'ChatMe';

            try {
                $mail = getMailerInstance($this->db, $ad['tenant_id']);
                $mail->addAddress($advertiser['email'], $advertiser['name']);
                $mail->isHTML(true);
                $mail->Subject = "Campaign Performance Report: " . $ad['title'];
                
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; color: #333;'>
                        <h2 style='color: #0b57a4;'>Hello " . htmlspecialchars($advertiser['name']) . ",</h2>
                        <p>Here is the latest performance report for your campaign <strong>" . htmlspecialchars($ad['title']) . "</strong>.</p>
                        <p>The attached document contains detailed analytics and our marketing recommendations based on the current reach and engagement.</p>
                        <br>
                        <p>Best Regards,<br><strong>{$businessName}</strong></p>
                    </div>
                ";
                
                $fullPdfPath = __DIR__ . '/../../../../' . $pdfPath;
                if (file_exists($fullPdfPath)) {
                    $mail->addAttachment($fullPdfPath);
                }
                
                $mail->send();
            } catch (\Exception $e) {
                error_log("Report Email Error: " . $e->getMessage());
            }
        }
    }

    private function generatePdfReport($ad, $advertiser, $aggregatedAnalytics, $rawAnalytics = []) {
        $settings = $this->getTenantSettings($ad['tenant_id']);
        
        $embedImageFromUrl = function($url) {
            if (empty($url)) return '';
            $base_upload_url = 'https://app.chatme.co.tz/uploads'; 
            if (strpos($url, 'http') === false) {
                $url = $base_upload_url . '/' . basename($url);
            }
            try {
                $context = stream_context_create(['http' => ['timeout' => 5]]);
                $data = @file_get_contents($url, false, $context);
                if ($data === false) return '';
                $type = pathinfo($url, PATHINFO_EXTENSION);
                if (empty($type)) $type = 'png';
                return 'data:image/' . $type . ';base64,' . base64_encode($data);
            } catch (\Exception $e) { return ''; }
        };

        $logo_base64 = $embedImageFromUrl($settings['profile_picture_url'] ?? '');
        $stamp_base64 = $embedImageFromUrl($settings['business_stamp_url'] ?? '');

        $qr_code_base64 = '';
        $videoId = '';
        if (!empty($rawAnalytics['analytics'][0]['video_id'])) {
            $videoId = $rawAnalytics['analytics'][0]['video_id'];
        }
        
        if ($videoId) {
            $videoUrl = "https://www.youtube.com/watch?v=" . $videoId;
            if (class_exists('QRcode')) {
                ob_start();
                \QRcode::png($videoUrl, null, QR_ECLEVEL_L, 3, 2); 
                $qr_image_data = ob_get_contents();
                ob_end_clean();
                $qr_code_base64 = 'data:image/png;base64,' . base64_encode($qr_image_data);
            }
        }

        $totalViews = $aggregatedAnalytics['analytics']['total']['views'] ?? 0;
        $totalLikes = $aggregatedAnalytics['analytics']['total']['likes'] ?? 0;
        $totalComments = $aggregatedAnalytics['analytics']['total']['comments'] ?? 0;
        
        $engagementRate = $totalViews > 0 ? (($totalLikes + $totalComments) / $totalViews) * 100 : 0;
        $engagementRateFmt = number_format($engagementRate, 2) . '%';

        // --- MARKETING FOCUSED RECOMMENDATIONS ---
        $recommendations = [];
        if ($engagementRate > 5) {
            $recommendations[] = "<strong>High Audience Resonance:</strong> The audience is connecting strongly with your brand message. This indicates a high potential for conversion. We recommend extending this campaign to maximize brand recall.";
        } elseif ($engagementRate < 1 && $totalViews > 100) {
            $recommendations[] = "<strong>Maximize Impact:</strong> While reach is good, interaction is lower than average. For future creatives, consider a more direct 'Call to Action' to drive viewer response.";
        }
        
        if ($totalViews > 1000 && $totalLikes < 10) {
             $recommendations[] = "<strong>Brand Visibility:</strong> You are achieving excellent brand visibility (Reach). To convert this awareness into deeper engagement, ensure the ad narrative closely aligns with the audience's immediate interests.";
        }
        
        if (empty($recommendations)) {
            $recommendations[] = "<strong>Campaign Continuity:</strong> Your campaign is active and gaining traction. Continuous brand presence is key to building long-term trust with this audience. We will continue to monitor performance.";
        }

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($settings['business_name'] ?? 'ChatMe');
        $pdf->SetTitle('Ad Report: ' . $ad['title']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $styles = '
            <style>
                body { font-family: helvetica; color: #333; }
                .header-table { border-bottom: 2px solid #0b57a4; padding-bottom: 10px; }
                .business-name { font-size: 18pt; font-weight: bold; color: #0b57a4; }
                .report-title { font-size: 14pt; font-weight: bold; color: #555; text-align: right; }
                .section-title { font-size: 12pt; font-weight: bold; color: #0b57a4; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-top: 20px; }
                .stat-box { border: 1px solid #eee; background-color: #f9f9f9; padding: 10px; text-align: center; }
                .stat-number { font-size: 20pt; font-weight: bold; color: #333; }
                .stat-label { font-size: 10pt; color: #777; text-transform: uppercase; }
                .rec-box { background-color: #eef7ff; border-left: 4px solid #0b57a4; padding: 10px; margin-bottom: 5px; }
                .footer-text { text-align: center; font-size: 8pt; color: #999; }
            </style>
        ';

        $html = $styles;
        $html .= '<table class="header-table" width="100%"><tr>';
        
        $html .= '<td width="60%">';
        if ($logo_base64) {
            $html .= '<img src="' . $logo_base64 . '" height="50" /><br><br>';
        }
        $html .= '<span class="business-name">' . htmlspecialchars($settings['business_name'] ?? 'Business Name') . '</span><br>';
        $html .= htmlspecialchars($settings['business_email'] ?? '') . '<br>';
        $html .= htmlspecialchars($settings['business_phone'] ?? '') . '</td>';
        
        $html .= '<td width="40%" align="right">';
        $html .= '<div class="report-title">PERFORMANCE REPORT</div>';
        $html .= 'Date: ' . date('F j, Y') . '<br>';
        $html .= 'Ad ID: #' . $ad['id'] . '<br>';
        $html .= 'Client: <b>' . htmlspecialchars($advertiser['name']) . '</b></td>';
        $html .= '</tr></table>';

        $html .= '<div class="section-title">EXECUTIVE SUMMARY: ' . htmlspecialchars($ad['title']) . '</div><br>';
        
        $html .= '<table width="100%" cellpadding="5"><tr>';
        $html .= '<td width="25%"><div class="stat-box"><div class="stat-number">' . number_format($totalViews) . '</div><div class="stat-label">Total Views</div></div></td>';
        $html .= '<td width="25%"><div class="stat-box"><div class="stat-number">' . number_format($totalLikes) . '</div><div class="stat-label">Likes</div></div></td>';
        $html .= '<td width="25%"><div class="stat-box"><div class="stat-number">' . number_format($totalComments) . '</div><div class="stat-label">Comments</div></div></td>';
        $html .= '<td width="25%"><div class="stat-box"><div class="stat-number" style="color:#2ecc71;">' . $engagementRateFmt . '</div><div class="stat-label">Eng. Rate</div></div></td>';
        $html .= '</tr></table>';

        $html .= '<br><div class="section-title">ENGAGEMENT BREAKDOWN</div><br>';
        
        $maxVal = max($totalLikes, $totalComments, 1);
        $likePct = ($totalLikes / $maxVal) * 100;
        $commentPct = ($totalComments / $maxVal) * 100;
        $likeW = max(5, $likePct);
        $commW = max(5, $commentPct);

        $html .= '<table width="100%" cellpadding="5" border="0">';
        $html .= '<tr><td width="20%"><strong>Likes</strong></td>';
        $html .= '<td width="60%"><table width="100%"><tr><td width="'.$likeW.'%" style="background-color:#3498db; height:12px;"></td><td width="'.(100-$likeW).'%" style="background-color:#f0f0f0;"></td></tr></table></td>';
        $html .= '<td width="20%" align="right">'.number_format($totalLikes).'</td></tr>';
        $html .= '<tr><td width="20%"><strong>Comments</strong></td>';
        $html .= '<td width="60%"><table width="100%"><tr><td width="'.$commW.'%" style="background-color:#e67e22; height:12px;"></td><td width="'.(100-$commW).'%" style="background-color:#f0f0f0;"></td></tr></table></td>';
        $html .= '<td width="20%" align="right">'.number_format($totalComments).'</td></tr>';
        $html .= '</table>';

        $html .= '<br><div class="section-title">MARKETING INSIGHTS</div><br>';
        foreach ($recommendations as $rec) {
            $html .= '<div class="rec-box">' . $rec . '</div>';
        }

        // --- Footer (Signature Only) ---
        $html .= '<br><br><br>';
        $html .= '<table width="100%"><tr>';
        
        $html .= '<td width="50%">';
        $html .= '<strong>Generated By:</strong><br>' . htmlspecialchars($settings['business_name'] ?? 'ChatMe') . '<br>';
        $html .= '<em>Automated Report System</em>';
        $html .= '</td>';
        
        $html .= '<td width="50%" align="right">';
        if ($stamp_base64) {
            $html .= '<br><br><img src="' . $stamp_base64 . '" width="100" />';
        }
        $html .= '</td>';
        
        $html .= '</tr></table>';
        
        // Divider Line
        $html .= '<div style="border-top: 1px solid #ccc; margin-top: 20px;"></div>';
        
        // Copyright
        $html .= '<div class="footer-text" style="margin-top: 5px;">';
        $html .= '© ' . date('Y') . ' ' . htmlspecialchars($settings['business_name'] ?? '') . '. All Rights Reserved.';
        $html .= '</div>';

        // --- QR Code (Centered Bottom) ---
        if ($qr_code_base64) {
            $html .= '<div style="text-align: center; margin-top: 15px;">';
            $html .= '<img src="' . $qr_code_base64 . '" width="80" /><br>';
            $html .= '<span style="font-size:8pt; color:#555;">Scan to Watch Video</span>';
            $html .= '</div>';
        }

        $pdf->writeHTML($html, true, false, true, false, '');

        $reportsDir = __DIR__ . '/../../../../uploads/reports/';
        if (!is_dir($reportsDir)) mkdir($reportsDir, 0755, true);
        
        $fileName = 'report_' . $ad['id'] . '_' . time() . '.pdf';
        $pdfPath = $reportsDir . $fileName;
        $pdf->Output($pdfPath, 'F');
        
        return 'uploads/reports/' . $fileName;
    }
}