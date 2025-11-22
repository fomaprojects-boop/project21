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
        $settings = [
            'business_name' => '',
            'business_email' => '',
            'business_address' => '',
            'business_phone' => '',
            'default_currency' => 'TZS',
            'profile_picture_url' => '',
            'business_stamp_url' => ''
        ];

        // Helper function to merge non-empty values
        $mergeSettings = function(&$target, $source) {
            if (!$source) return;
            foreach ($target as $key => $value) {
                // If source has this key and it's not empty, overwrite target
                if (isset($source[$key]) && !empty($source[$key])) {
                    $target[$key] = $source[$key];
                }
                // Special mapping for company_name if business_name is empty
                if ($key === 'business_name' && isset($source['company_name']) && !empty($source['company_name'])) {
                    $target[$key] = $source['company_name'];
                }
                 // Special mapping for users table email/phone
                if ($key === 'business_email' && isset($source['email']) && !empty($source['email']) && empty($target[$key])) {
                    $target[$key] = $source['email'];
                }
                if ($key === 'business_phone' && isset($source['phone']) && !empty($source['phone']) && empty($target[$key])) {
                    $target[$key] = $source['phone'];
                }
            }
        };

        // 1. Fetch Global Settings (ID = 1) - Baseline
        try {
            $stmt = $this->db->query("SELECT * FROM settings WHERE id = 1");
            $globalSettings = $stmt->fetch(\PDO::FETCH_ASSOC);
            $mergeSettings($settings, $globalSettings);
        } catch (\Exception $e) {
            // Ignore if table missing or error
        }

        // 2. Fetch Tenant Settings (by Tenant ID) - Overwrite global
        if ($tenantId) {
            try {
                $stmt = $this->db->prepare("SELECT * FROM settings WHERE tenant_id = ?");
                $stmt->execute([$tenantId]);
                $tenantSettings = $stmt->fetch(\PDO::FETCH_ASSOC);
                $mergeSettings($settings, $tenantSettings);
            } catch (\Exception $e) {}
        }

        // 3. Fetch User Info (Fallback for Name/Email if still empty)
        if (empty($settings['business_name']) && $tenantId) {
            try {
                $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$tenantId]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                $mergeSettings($settings, $user);
            } catch (\Exception $e) {}
        }

        // 4. Final Defaults if absolutely everything failed
        if (empty($settings['business_name'])) {
            $settings['business_name'] = 'ChatMe Platform';
        }
        if (empty($settings['business_email'])) {
             $settings['business_email'] = 'support@chatme.co.tz';
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
                // Added explicit logging for image fetching failures
                $context = stream_context_create(['http' => ['timeout' => 5]]);
                $data = @file_get_contents($url, false, $context);
                if ($data === false) {
                     $error = error_get_last();
                     // Log error but don't break execution
                     // error_log("ReportService Image Fetch Error for URL {$url}: " . ($error['message'] ?? 'Unknown error'));
                     return '';
                }
                $type = pathinfo($url, PATHINFO_EXTENSION);
                if (empty($type)) $type = 'png';
                return 'data:image/' . $type . ';base64,' . base64_encode($data);
            } catch (\Exception $e) {
                return '';
            }
        };

        $logo_base64 = $embedImageFromUrl($settings['profile_picture_url'] ?? '');
        $stamp_base64 = $embedImageFromUrl($settings['business_stamp_url'] ?? '');

        // --- Data Preparation ---
        $totalViews = $aggregatedAnalytics['analytics']['total']['views'] ?? 0;
        $totalLikes = $aggregatedAnalytics['analytics']['total']['likes'] ?? 0;
        $totalComments = $aggregatedAnalytics['analytics']['total']['comments'] ?? 0;

        // Avoid division by zero
        $engagementRate = $totalViews > 0 ? (($totalLikes + $totalComments) / $totalViews) * 100 : 0;
        $engagementRateFmt = number_format($engagementRate, 2) . '%';

        // Simple projection: Assume linear growth based on current state (simplified logic)
        // In a real scenario, this would use historical data points.
        $projectedViews = $totalViews * 1.10; // 10% growth projection
        $projectedLikes = $totalLikes * 1.08; // 8% growth

        // --- MARKETING INSIGHTS ENGINE ---
        $insights = [];
        $growthStatus = "Stable";
        $growthColor = "#f39c12"; // Orange

        if ($engagementRate > 5) {
            $growthStatus = "Excellent";
            $growthColor = "#27ae60"; // Green
            $insights[] = [
                'title' => 'High Engagement Resonance',
                'text' => 'Your audience is highly receptive. The engagement rate exceeds 5%, indicating strong content fit. We recommend increasing budget on this creative.',
                'icon' => '[+]' // Using safe ASCII instead of emoji to prevent encoding errors
            ];
        } elseif ($engagementRate < 1 && $totalViews > 500) {
             $growthStatus = "Needs Attention";
             $growthColor = "#c0392b"; // Red
             $insights[] = [
                'title' => 'Low Interaction Rate',
                'text' => 'While visibility is high, interaction is low. Consider revising the "Call to Action" in the video or testing a new thumbnail to drive curiosity.',
                'icon' => '[!]'
             ];
        } else {
             $insights[] = [
                'title' => 'Steady Performance',
                'text' => 'Campaign performance is consistent with industry averages. Continue monitoring.',
                'icon' => '[OK]'
             ];
        }

        if ($totalViews > 1000) {
            $insights[] = [
                'title' => 'Brand Awareness Milestone',
                'text' => 'You have surpassed 1,000 views! This is a key milestone for brand recall. Consider a retargeting campaign for viewers who watched >50%.',
                'icon' => '[UP]'
             ];
        }

        // --- PDF Generation ---
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($settings['business_name'] ?? 'ChatMe');
        $pdf->SetTitle('Performance Report: ' . $ad['title']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        // Colors
        $primaryColor = "#0b57a4"; // Main Blue
        $secondaryColor = "#f4f6f9"; // Light Gray/Blue background
        $accentColor = $growthColor;

        $pdf_html = '
        <style>
            body { font-family: dejavusans, helvetica; color: #333; font-size: 10pt; }
            .header-table { border-bottom: 2px solid '.$primaryColor.'; padding-bottom: 15px; }
            .business-name { font-size: 18pt; font-weight: bold; color: '.$primaryColor.'; }
            .business-meta { font-size: 9pt; color: #666; line-height: 1.4; }
            .report-title-box { background-color: '.$primaryColor.'; color: #fff; font-size: 14pt; font-weight: bold; padding: 12px; text-align: center; border-radius: 4px; }

            .section-header { font-size: 12pt; font-weight: bold; color: '.$primaryColor.'; border-bottom: 1px solid #ddd; padding-bottom: 8px; margin-top: 25px; margin-bottom: 10px; }

            .kpi-container { padding: 10px 0; }
            .kpi-box { background-color: '.$secondaryColor.'; border: 1px solid #e0e0e0; padding: 12px; text-align: center; border-radius: 6px; }
            .kpi-val { font-size: 18pt; font-weight: bold; color: #333; }
            .kpi-label { font-size: 8pt; color: #777; text-transform: uppercase; letter-spacing: 0.5px; }

            .insight-box { background-color: #fff; border-left: 5px solid '.$primaryColor.'; padding: 12px; margin-bottom: 10px; border: 1px solid #eee; border-left-width: 5px; }
            .insight-title { font-weight: bold; color: '.$primaryColor.'; font-size: 11pt; margin-bottom: 4px; }
            .insight-text { font-size: 10pt; color: #555; }

            .projection-table td { border-bottom: 1px solid #eee; padding: 10px; }
            .projection-header { background-color: '.$secondaryColor.'; font-weight: bold; color: #555; }

            .footer { text-align: center; font-size: 8pt; color: #aaa; margin-top: 40px; border-top: 1px solid #eee; padding-top: 10px; }
        </style>
        ';

        // --- 1. Header (Logo & Business Info) ---
        $pdf_html .= '<table class="header-table" width="100%"><tr>';

        $pdf_html .= '<td width="60%" valign="top">';
        if ($logo_base64) {
            $pdf_html .= '<img src="' . $logo_base64 . '" height="55" style="margin-bottom:10px;" /><br>';
        }
        $pdf_html .= '<span class="business-name">' . htmlspecialchars($settings['business_name'] ?? '') . '</span><br>';
        $pdf_html .= '<span class="business-meta">';
        $pdf_html .= htmlspecialchars($settings['business_address'] ?? '') . '<br>';
        $pdf_html .= htmlspecialchars($settings['business_email'] ?? '') . ' | ' . htmlspecialchars($settings['business_phone'] ?? '');
        $pdf_html .= '</span></td>';

        $pdf_html .= '<td width="40%" align="right" valign="top">';
        $pdf_html .= '<span style="font-size: 10pt; color: #999;">Report Date: ' . date('F j, Y') . '</span><br>';
        $pdf_html .= '<span style="font-size: 10pt; font-weight:bold; color: #333;">Client: ' . htmlspecialchars($advertiser['name']) . '</span><br>';
        $pdf_html .= '<span style="font-size: 9pt; color: #666;">Campaign: ' . htmlspecialchars($ad['title']) . '</span>';
        $pdf_html .= '</td>';
        $pdf_html .= '</tr></table>';

        // --- 2. Title Bar ---
        $pdf_html .= '<br><div class="report-title-box">PERFORMANCE REPORT</div><br>';

        // --- 3. Key Performance Indicators (Grid) ---
        $pdf_html .= '<div class="section-header">PERFORMANCE OVERVIEW</div>';
        $pdf_html .= '<table width="100%" cellpadding="5" cellspacing="5"><tr>';
        $pdf_html .= '<td width="25%"><div class="kpi-box"><div class="kpi-val">' . number_format($totalViews) . '</div><div class="kpi-label">Total Views</div></div></td>';
        $pdf_html .= '<td width="25%"><div class="kpi-box"><div class="kpi-val">' . number_format($totalLikes) . '</div><div class="kpi-label">Likes</div></div></td>';
        $pdf_html .= '<td width="25%"><div class="kpi-box"><div class="kpi-val">' . number_format($totalComments) . '</div><div class="kpi-label">Comments</div></div></td>';
        $pdf_html .= '<td width="25%"><div class="kpi-box"><div class="kpi-val" style="color:'.$growthColor.';">' . $engagementRateFmt . '</div><div class="kpi-label">Eng. Rate</div></div></td>';
        $pdf_html .= '</tr></table>';

        // --- 4. Visual Engagement Breakdown (Bars) ---
        $pdf_html .= '<div class="section-header">ENGAGEMENT VISUALIZATION</div>';

        $maxVal = max($totalLikes, $totalComments, 1);
        $likePct = min(100, ($totalLikes / $maxVal) * 85); // Scale relative to max
        $commPct = min(100, ($totalComments / $maxVal) * 85);

        $pdf_html .= '<table width="100%" cellpadding="8">';

        // Likes Bar
        $pdf_html .= '<tr><td width="20%"><strong>Likes</strong></td>';
        $pdf_html .= '<td width="65%"><table width="100%"><tr><td width="'.$likePct.'%" style="background-color:#3498db; height:14px;"></td><td width="'.(100-$likePct).'%" style="background-color:#f0f0f0;"></td></tr></table></td>';
        $pdf_html .= '<td width="15%" align="right" style="font-weight:bold;">'.number_format($totalLikes).'</td></tr>';

        // Comments Bar
        $pdf_html .= '<tr><td width="20%"><strong>Comments</strong></td>';
        $pdf_html .= '<td width="65%"><table width="100%"><tr><td width="'.$commPct.'%" style="background-color:#e67e22; height:14px;"></td><td width="'.(100-$commPct).'%" style="background-color:#f0f0f0;"></td></tr></table></td>';
        $pdf_html .= '<td width="15%" align="right" style="font-weight:bold;">'.number_format($totalComments).'</td></tr>';

        $pdf_html .= '</table>';

        // --- 5. Marketing Insights (The "Brain" part) ---
        $pdf_html .= '<div class="section-header">INTELLIGENT MARKETING INSIGHTS</div>';
        foreach ($insights as $insight) {
            $pdf_html .= '<div class="insight-box">';
            $pdf_html .= '<div class="insight-title">' . $insight['icon'] . ' ' . $insight['title'] . '</div>';
            $pdf_html .= '<div class="insight-text">' . $insight['text'] . '</div>';
            $pdf_html .= '</div>';
        }

        // --- 6. Growth Projection (Future Outlook) ---
        $pdf_html .= '<div class="section-header">GROWTH PROJECTION (NEXT 7 DAYS)</div>';
        $pdf_html .= '<p style="font-size:9pt; color:#666; margin-bottom: 10px;">Based on current momentum, here is the estimated performance for next week:</p>';

        $pdf_html .= '<table class="projection-table" width="100%" cellpadding="6">';
        $pdf_html .= '<tr class="projection-header"><td width="40%">Metric</td><td width="30%">Current</td><td width="30%">Projected</td></tr>';
        $pdf_html .= '<tr><td><strong>Views</strong></td><td>' . number_format($totalViews) . '</td><td style="color:#27ae60; font-weight:bold;">' . number_format($projectedViews) . ' (UP)</td></tr>';
        $pdf_html .= '<tr><td><strong>Likes</strong></td><td>' . number_format($totalLikes) . '</td><td style="color:#27ae60; font-weight:bold;">' . number_format($projectedLikes) . ' (UP)</td></tr>';
        $pdf_html .= '</table>';

        // --- 7. Footer (Stamp Only - No Signature) ---
        $pdf_html .= '<br><br>';
        $pdf_html .= '<table width="100%"><tr>';

        // Left Side: Signature/Stamp
        $pdf_html .= '<td width="50%" valign="bottom" align="left">';
        if ($stamp_base64) {
            $pdf_html .= '<img src="' . $stamp_base64 . '" width="100" style="margin-bottom: 5px;" /><br>';
        }
        $pdf_html .= '<div style="border-bottom: 1px solid #444; width: 150px; margin-bottom: 5px;"></div>'; // Line
        $pdf_html .= '<div style="font-size:9pt; color:#444; font-weight:bold;">Authorized Signature</div>';
        $pdf_html .= '</td>';

        // Right Side
        $pdf_html .= '<td width="50%" align="right">';
         // Empty right side for balance
        $pdf_html .= '</td>';
        $pdf_html .= '</tr></table>';

        $pdf_html .= '<div class="footer">';
        $pdf_html .= 'Generated by ' . htmlspecialchars($settings['business_name'] ?? 'System') . ' | Automated Performance Engine';
        $pdf_html .= '</div>';

        // --- QR Code ---
        if (!empty($rawAnalytics['analytics'][0]['video_id'])) {
             $videoId = $rawAnalytics['analytics'][0]['video_id'];
             $videoUrl = "https://www.youtube.com/watch?v=" . $videoId;
             if (class_exists('QRcode')) {
                ob_start();
                \QRcode::png($videoUrl, null, QR_ECLEVEL_L, 3, 2);
                $qr_image_data = ob_get_contents();
                ob_end_clean();
                $qr_code_base64 = 'data:image/png;base64,' . base64_encode($qr_image_data);

                $pdf_html .= '<div style="text-align: center; margin-top: 20px;">';
                $pdf_html .= '<img src="' . $qr_code_base64 . '" width="70" /><br>';
                $pdf_html .= '<span style="font-size:8pt; color:#999;">Watch Ad</span>';
                $pdf_html .= '</div>';
            }
        }

        $pdf->writeHTML($pdf_html, true, false, true, false, '');

        $reportsDir = __DIR__ . '/../../../../uploads/reports/';
        if (!is_dir($reportsDir)) mkdir($reportsDir, 0755, true);

        $fileName = 'performance_report_' . $ad['id'] . '_' . date('Ymd_His') . '.pdf';
        $pdfPath = $reportsDir . $fileName;
        $pdf->Output($pdfPath, 'F');

        return 'uploads/reports/' . $fileName;
    }
}
