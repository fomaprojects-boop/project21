from playwright.sync_api import sync_playwright, Page, expect
import re

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1280, 'height': 800})
    page = context.new_page()

    # 1. Prepare Static Files for Verification
    # We need to verify 3 UI changes:
    # A. Super Admin Dashboard (PIN Verification)
    # B. Tenant Settings (Remote Access Toggle)
    # C. YouTube Ads (Channel Selector)

    print("Generating static verification files...")

    # --- A. Super Admin Dashboard ---
    with open('super_admin/dashboard.php', 'r') as f:
        sa_content = f.read()

    # Strip PHP
    sa_static = re.sub(r'<\?php.*?\?>', '', sa_content, flags=re.DOTALL)

    # Inject Mock Logic for PIN Verification
    sa_mock = """
    <script>
        // Mock fetch for verification
        window.fetch = async (url, options) => {
            console.log("Mock fetch:", url);
            if (url.includes('get_tenant_by_pin.php')) {
                return {
                    json: async () => ({
                        status: 'success',
                        tenant: {
                            id: 99,
                            business_name: 'Mock Tenant Ltd',
                            owner_email: 'admin@mock.com',
                            subscription_status: 'active',
                            remote_access_enabled: 1,
                            invoice_summary: { paid: 5000, due: 0 }
                        }
                    })
                };
            }
            if (url.includes('get_dashboard_data.php')) {
                return { json: async () => ({ status: 'success', stats: {}, tenants: [] }) };
            }
            return { json: async () => ({}) };
        };

        // Auto-fill and click for screenshot
        setTimeout(() => {
            document.getElementById('support-pin-input').value = 'MOCK-123';
            // We won't auto-click to avoid alert popups in headless, but we show the filled UI
        }, 500);
    </script>
    """
    sa_static = sa_static.replace('</body>', sa_mock + '</body>')
    with open('verification/verify_sa_dashboard.html', 'w') as f: f.write(sa_static)


    # --- B. Tenant Settings & C. YouTube Ads ---
    with open('index.php', 'r') as f:
        index_content = f.read()

    # Extract Templates
    settings_tpl = re.search(r'settings:\s*`([\s\S]*?)`,', index_content).group(1)
    youtube_tpl = re.search(r'youtube-ads":\s*`([\s\S]*?)`,', index_content).group(1)

    # Wrap Wrapper
    base_html = """
    <!DOCTYPE html>
    <html>
    <head>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>
    <body class="bg-gray-100 p-8 space-y-10">
        <h1 class="text-3xl font-bold">Verification Page</h1>

        <section class="border-b pb-10">
            <h2 class="text-xl font-bold mb-4 text-violet-600">1. YouTube Channel Selector</h2>
            <!-- YOUTUBE UI MOCK -->
            <div id="youtube-mock">
                REPLACE_YOUTUBE
            </div>
        </section>

        <section>
            <h2 class="text-xl font-bold mb-4 text-violet-600">2. Remote Access Settings</h2>
            <!-- SETTINGS UI MOCK -->
            <div id="settings-mock" class="bg-white p-6 rounded shadow">
                REPLACE_SETTINGS
            </div>
        </section>

        <script>
            // Mock Data injection
            // YouTube: Show loading state or populated state
            const ytContainer = document.getElementById('youtube-mock');

            // Settings: Show Remote Access Toggle & PIN
            setTimeout(() => {
               document.getElementById('settings-profile').style.display = 'block';
               const pinContainer = document.getElementById('support-pin-container');
               if(pinContainer) {
                   pinContainer.classList.remove('hidden');
                   document.getElementById('support-pin-display').textContent = 'VERIFIED-PIN';
                   document.getElementById('remote-access-toggle').checked = true;
               }
            }, 100);
        </script>
    </body>
    </html>
    """

    final_html = base_html.replace('REPLACE_YOUTUBE', youtube_tpl).replace('REPLACE_SETTINGS', settings_tpl)
    with open('verification/verify_ui_components.html', 'w') as f: f.write(final_html)


    # 2. Execution & Screenshotting

    # A. Super Admin Dashboard
    print("Capturing Super Admin Dashboard...")
    page.goto("http://localhost:8080/verification/verify_sa_dashboard.html")
    page.wait_for_timeout(1000)
    page.screenshot(path="verification/sa_dashboard_pin.png")

    # B. Components (Settings + YouTube)
    print("Capturing Tenant UI Components...")
    page.goto("http://localhost:8080/verification/verify_ui_components.html")
    page.wait_for_timeout(1000)
    page.screenshot(path="verification/tenant_features.png")

    print("Verification Complete.")
    browser.close()

with sync_playwright() as playwright:
    run(playwright)
