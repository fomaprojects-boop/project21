# YouTube Ads Module

This module allows content creators to connect their YouTube channels, manage ads from advertisers, and automatically insert ads into their videos.

## Setup

1.  Copy `.env.example` to `.env` and fill in the required environment variables.
2.  Run the database migrations:
    ```
    mysql -u [username] -p [database_name] < modules/youtube_ads/migrations/001_initial_youtube_schema.sql
    mysql -u [username] -p [database_name] < modules/youtube_ads/migrations/002_create_ads_tables.sql
    ```
3.  (Optional) Seed the database with demo data:
    ```
    mysql -u [username] -p [database_name] < modules/youtube_ads/migrations/003_seed_demo_data.sql
    ```
4.  Set up a cron job to run the scheduler every hour:
    ```
    * * * * * php /path/to/your/project/api/modules/youtube_ads/jobs/scheduler.php
    ```

## Environment Variables

*   `GOOGLE_CLIENT_ID`: Your Google API client ID.
*   `GOOGLE_CLIENT_SECRET`: Your Google API client secret.
*   `GOOGLE_REDIRECT_URI`: The redirect URI for the OAuth2 callback.
*   `ENCRYPTION_KEY`: A 32-byte key for encrypting and decrypting tokens.
*   `WEBHOOK_SECRET`: A secret for verifying webhook signatures.
*   `UPLOAD_TEMP_DIR`: The directory for temporary file uploads.
*   `FFMPEG_PATH`: The path to the FFmpeg executable.
*   `USE_FFMPEG`: Set to `true` to use FFmpeg for ad insertion. Otherwise, the YouTube Editor API will be used.
*   `SMTP_*`: Your SMTP server settings for sending emails.

## API

*   `POST /api/modules/youtube_ads/controllers/AdController.php?action=createAdvertiser`
*   `POST /api/modules/youtube_ads/controllers/AdController.php?action=createAd`
*   `POST /api/modules/youtube_ads/controllers/AdController.php?action=mapAdToVideo`
*   `POST /api/modules/youtube_ads/controllers/WebhookController.php`

## Webhook

The webhook endpoint is `/api/modules/youtube_ads/controllers/WebhookController.php`. You will need to configure this in your YouTube Pub/Sub settings.

## Testing with cURL

Here are some example cURL commands to test the API endpoints. Replace placeholders like `[USER_COOKIE]` and `[ADVERTISER_ID]` with your actual data.

**Create an Advertiser:**
```bash
curl -X POST -b "PHPSESSID=[USER_COOKIE]" -F "name=Test Advertiser" -F "email=test@advertiser.com" -F "phone=555-1234" https://yourdomain.com/api/modules/youtube_ads/controllers/AdController.php?action=createAdvertiser
```

**Create an Ad:**
```bash
curl -X POST -b "PHPSESSID=[USER_COOKIE]" -F "advertiser_id=[ADVERTISER_ID]" -F "title=My Test Ad" -F "placement=intro" -F "start_date=2025-01-01" -F "end_date=2025-01-31" -F "price=100.00" -F "ad_file=@/path/to/your/ad_video.mp4" https://yourdomain.com/api/modules/youtube_ads/controllers/AdController.php?action=createAd
```

**Map Ad to Video:**
```bash
curl -X POST -b "PHPSESSID=[USER_COOKIE]" -F "ad_id=[AD_ID]" -F "video_id=[YOUTUBE_VIDEO_ID]" https://yourdomain.com/api/modules/youtube_ads/controllers/AdController.php?action=mapAdToVideo
```

**Simulate a Webhook Notification:**
```bash
curl -X POST -H "Content-Type: application/xml" -H "X-Hub-Signature: sha1=yoursecrethash" --data @/path/to/sample_notification.xml https://yourdomain.com/api/modules/youtube_ads/controllers/WebhookController.php
```
