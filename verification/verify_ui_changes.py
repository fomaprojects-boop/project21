
from playwright.sync_api import sync_playwright
import os
import re

def verify_workflow_ui():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        # Use 1280x720 to ensure visibility
        page = browser.new_page(viewport={'width': 1280, 'height': 720})

        # Read index.php to mock it
        with open('index.php', 'r') as f:
            content = f.read()

        # Mock PHP variables
        content = content.replace('<?php echo $baseUrl; ?>', 'http://localhost:8080')
        content = content.replace('<?php echo $userId; ?>', '1')
        content = content.replace('<?php echo $userName; ?>', 'Test User')
        content = content.replace('<?php echo $userRole; ?>', 'Admin')
        content = content.replace('<?php echo defined(\'FACEBOOK_APP_ID\') ? FACEBOOK_APP_ID : \'\'; ?>', '')

        # Strip PHP tags
        content = re.sub(r'<\?php.*?\?>', '', content, flags=re.DOTALL)

        # Inject Mock JS to handle APIs and Loader
        mock_script = """
        <script>
            window.fetchApi = async function(endpoint, options) {
                console.log('Mock fetchApi called for:', endpoint);
                return { status: 'success', default_currency: 'TZS', revenue: 1000, expenses: 500 };
            };
            // Remove loader
            setInterval(() => {
                const loader = document.getElementById('page-loader');
                if(loader) loader.style.display = 'none';
            }, 50);
        </script>
        """

        content = content.replace('</body>', mock_script + '</body>')

        with open('verification_index.html', 'w') as f:
            f.write(content)

        page.goto("http://localhost:8080/verification_index.html")

        # Wait for initial load
        page.wait_for_timeout(2000)

        # Navigate to workflows
        print("Navigating to Workflows...")
        page.evaluate("showView('workflows')")
        page.wait_for_timeout(2000)

        # Open Editor
        print("Opening Editor...")
        page.click('button:has-text("Start From Scratch")', force=True)

        # Check Trigger Modal
        print("Checking Trigger Modal...")
        page.wait_for_selector('#selectTriggerModal', state='visible')

        # Verify new trigger options exist
        # "Message Received", "Payment Completed", "Order Status Changed"
        triggers = ["Message Received", "Payment Completed", "Order Status Changed", "New Contact", "Tag Added"]
        for trigger in triggers:
            if page.locator(f'button:has-text("{trigger}")').is_visible():
                print(f"SUCCESS: Trigger '{trigger}' found.")
            else:
                print(f"FAIL: Trigger '{trigger}' NOT found.")

        # Verify Close Button exists
        if page.locator('#selectTriggerModal button i.fa-times').is_visible():
             print("SUCCESS: Trigger Modal Close Button found.")
        else:
             print("FAIL: Trigger Modal Close Button NOT found.")

        # Select a trigger to enter editor
        page.click('button:has-text("Conversation Started")', force=True)
        page.wait_for_selector('#workflow-editor-view', state='visible')

        # Add a node to verify style
        page.click('.workflow-connector button', force=True)
        page.wait_for_selector('#configureNodeModal', state='visible')
        page.select_option('#node-type-select', 'question')
        page.fill('#config-content', 'Soft Node Test')
        page.click('#configureNodeModal button:has-text("Save")', force=True)
        page.wait_for_timeout(1000)

        # Check styles via JS eval or screenshot visual check
        # We specifically check if .workflow-connector has height 50px and nodes have rounded corners
        connector_height = page.evaluate("document.querySelector('.workflow-connector').offsetHeight")
        print(f"Connector Height: {connector_height}px")

        if connector_height >= 50:
            print("SUCCESS: Connector height increased.")
        else:
            print("FAIL: Connector height incorrect.")

        page.screenshot(path="verification/workflow_ui_verify.png")
        browser.close()

if __name__ == "__main__":
    verify_workflow_ui()
