from playwright.sync_api import sync_playwright, Page, expect
import time

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1280, 'height': 800})
    page = context.new_page()

    # 1. Super Admin Dashboard Verification
    print("Verifying Super Admin Dashboard...")
    # Since we can't run PHP, we load the raw PHP file which will be served as text or parsed incorrectly by Python HTTP server.
    # However, the user provided memory says: "In the absence of a PHP runtime... UI verification requires converting PHP files to static HTML".
    # I will create a static version of dashboard for verification.

    # Let's mock the session state by creating a static HTML file that mimics the authenticated state
    # Reading the content of super_admin/dashboard.php and stripping PHP
    with open('super_admin/dashboard.php', 'r') as f:
        content = f.read()

    # Remove PHP blocks roughly
    import re
    static_content = re.sub(r'<\?php.*?\?>', '', content, flags=re.DOTALL)

    # We need to manually inject some mock data since the fetch won't work without backend
    mock_script = """
    <script>
        // Overwrite fetch to return mock data
        window.fetch = async (url) => {
            console.log("Mock fetch called for:", url);
            if (url.includes('get_dashboard_data.php')) {
                return {
                    json: async () => ({
                        status: 'success',
                        stats: { total_tenants: 10, active_tenants: 8, suspended_tenants: 2 },
                        tenants: [
                            { id: 1, business_name: 'Acme Corp', created_at: '2023-01-01', remote_access_enabled: 1, support_pin: 'AB12CD', subscription_status: 'active' },
                            { id: 2, business_name: 'Stark Ind', created_at: '2023-02-15', remote_access_enabled: 0, support_pin: null, subscription_status: 'suspended' }
                        ]
                    })
                };
            }
            return { json: async () => ({}) };
        };
    </script>
    """

    # Insert mock script before the closing body
    static_content = static_content.replace('</body>', mock_script + '</body>')

    with open('verification/static_dashboard.html', 'w') as f:
        f.write(static_content)

    page.goto("http://localhost:8080/verification/static_dashboard.html")
    page.wait_for_timeout(1000) # Wait for mock fetch
    page.screenshot(path="verification/super_admin_dashboard.png")
    print("Screenshot saved: verification/super_admin_dashboard.png")

    # 2. Tenant Settings Verification (Support Access)
    print("Verifying Tenant Settings...")
    # We need to render index.php viewTemplates['settings']
    # I'll create a static file that renders just the settings part or the full index shell with settings loaded

    with open('index.php', 'r') as f:
        index_content = f.read()

    # Extract the settings template string
    settings_template_match = re.search(r'settings:\s*`([\s\S]*?)`,', index_content)
    if settings_template_match:
        settings_html = settings_template_match.group(1)

        # Wrap in basic HTML structure with Tailwind
        full_html = f"""
        <!DOCTYPE html>
        <html>
        <head>
            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        </head>
        <body class="bg-gray-100 p-10">
            {settings_html}
            <script>
                // Mock toggle function
                function toggleRemoteAccess() {{ console.log('Toggled'); }}

                // Show the PIN container manually to verify styling
                document.getElementById('support-pin-container').classList.remove('hidden');
                document.getElementById('support-pin-display').textContent = 'XYZ789';
                document.getElementById('remote-access-toggle').checked = true;

                // Show profile tab
                document.getElementById('settings-profile').style.display = 'block';
            </script>
        </body>
        </html>
        """

        with open('verification/static_settings.html', 'w') as f:
            f.write(full_html)

        page.goto("http://localhost:8080/verification/static_settings.html")
        page.wait_for_timeout(1000)
        page.screenshot(path="verification/tenant_settings.png")
        print("Screenshot saved: verification/tenant_settings.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
