
from playwright.sync_api import sync_playwright
import os
import re

def verify_workflow_ui():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page(viewport={'width': 1280, 'height': 720})

        # Read index.php
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
            // Override fetchApi to return dummy data
            window.fetchApi = async function(endpoint, options) {
                console.log('Mock fetchApi called for:', endpoint);
                return { status: 'success', default_currency: 'TZS', revenue: 1000, expenses: 500 };
            };

            // Aggressively remove loader
            setInterval(() => {
                const loader = document.getElementById('page-loader');
                if(loader) loader.style.display = 'none';
            }, 50);
        </script>
        """

        # Insert mock script before closing body
        content = content.replace('</body>', mock_script + '</body>')

        with open('verification_index.html', 'w') as f:
            f.write(content)

        page.goto("http://localhost:8080/verification_index.html")

        page.wait_for_timeout(2000)

        print("Clicking Workflows link...")
        page.evaluate("document.querySelector('a[onclick*=\"workflows\"]').click()")

        page.wait_for_timeout(2000)

        if page.is_visible('#workflow-main-view'):
            print("Workflows view visible.")
        else:
            print("Workflows view NOT visible. Trying to force showView('workflows') via JS")
            page.evaluate("showView('workflows')")
            page.wait_for_timeout(2000)

        print("Opening Editor...")
        page.click('button:has-text("Start From Scratch")', force=True)

        page.wait_for_selector('#selectTriggerModal', state='visible')

        print("Selecting Trigger...")
        page.click('button:has-text("Conversation Started")', force=True)

        page.wait_for_selector('#workflow-editor-view', state='visible')

        # Verify Buttons
        export_btn = page.locator('button[title="Export JSON"]')
        import_btn = page.locator('button[title="Import JSON"]')

        if export_btn.is_visible():
            print("SUCCESS: Export button visible")
        else:
            print("FAIL: Export button not visible")

        if import_btn.is_visible():
            print("SUCCESS: Import button visible")
        else:
            print("FAIL: Import button not visible")

        # Add Question Node
        print("Adding Question Node...")
        page.click('.workflow-connector button', force=True)

        page.wait_for_selector('#configureNodeModal', state='visible')

        # Change type
        page.select_option('#node-type-select', 'question')

        # Fill data
        page.fill('#config-content', 'Do you want pizza?')
        page.fill('#config-options', 'Yes, No, Maybe')

        # Click Save inside the modal
        print("Saving node configuration...")
        page.click('#configureNodeModal button:has-text("Save")', force=True)

        # Wait for render
        page.wait_for_timeout(1000)

        # Verify the node rendered with branches
        content_visible = page.locator('text="Do you want pizza?"').is_visible()
        yes_visible = page.locator('text="Yes"').first.is_visible()
        no_visible = page.locator('text="No"').first.is_visible()
        maybe_visible = page.locator('text="Maybe"').first.is_visible()

        if content_visible and yes_visible and no_visible and maybe_visible:
            print("SUCCESS: Question node and branches rendered correctly.")
        else:
            print(f"FAIL: Node render issue. Content: {content_visible}, Yes: {yes_visible}, No: {no_visible}, Maybe: {maybe_visible}")

        page.screenshot(path="verification/workflow_editor_canvas.png")
        browser.close()

if __name__ == "__main__":
    verify_workflow_ui()
