
from playwright.sync_api import sync_playwright, expect
import os

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1280, 'height': 800})
    page = context.new_page()

    # Since we can't run PHP, we can't serve index.php directly.
    # We will assume the user can check the code structure.
    # But we can check if we generated the correct frontend code in index.php by reading it?
    # No, we need to render it.
    # I'll create a static HTML file that mimics the structure of index.php to verify the JS/CSS logic I added.

    # However, since I modified index.php directly, I cannot easily "mock" it without stripping PHP tags.
    # Let's create a mock HTML file based on the content of index.php but stripping PHP.

    with open('index.php', 'r') as f:
        content = f.read()

    # Remove PHP tags for static verification
    import re
    content = re.sub(r'<\?php.*?\?>', '', content, flags=re.DOTALL)

    # Fix variable injections if any (e.g., echo $userId)
    content = content.replace('<?php echo $baseUrl; ?>', 'http://localhost')
    content = content.replace('<?php echo $userId; ?>', '1')
    content = content.replace('<?php echo $userRole; ?>', 'Admin')
    content = content.replace('<?php echo $userName; ?>', 'Test User')
    content = content.replace('<?php if ($userRole === \'Admin\' || $userRole === \'Accountant\'): ?>', '')
    content = content.replace('<?php endif; ?>', '')
    content = content.replace('<?php echo defined(\'FACEBOOK_CONFIG_ID\') ? FACEBOOK_CONFIG_ID : \'\'; ?>', '123')
    content = content.replace('<?php echo defined(\'FACEBOOK_APP_ID\') ? FACEBOOK_APP_ID : \'\'; ?>', '123')

    with open('verification/mock_index.html', 'w') as f:
        f.write(content)

    file_path = os.path.abspath("verification/mock_index.html")
    page.goto(f"file://{file_path}")

    # 1. Verify Chat Area Loaded (Basic Check)
    # We need to simulate clicking "Inbox" or triggering showView('conversations')
    # Since the mock might not have all JS context from external files (it assumes api/config etc),
    # we might need to be careful.
    # But the JS is inline in index.php mostly.

    # Manually trigger view
    page.evaluate("showView('conversations')")

    # Wait for view
    expect(page.locator("#message-view-placeholder")).to_be_visible()

    # 2. Verify Pro Features UI Elements

    # A. Internal Note Toggle
    # Force show message view content
    page.evaluate("document.getElementById('message-view-content').classList.remove('hidden');")

    expect(page.locator("button", has_text="Internal Note")).to_be_visible()
    print("Internal Note toggle visible.")

    # B. Click Note Mode and Verify Style
    page.click("button:has-text('Internal Note')")
    # Check if textarea or wrapper background changed (class bg-yellow-50)
    input_wrapper = page.locator("#input-wrapper")
    expect(input_wrapper).to_have_class(re.compile(r"bg-yellow-50"))
    print("Internal Note mode active (Yellow background).")

    # C. Send Later Button
    expect(page.get_by_title("Send Later")).to_be_visible()
    print("Send Later button visible.")

    # D. Snooze Button
    # It's in the header
    expect(page.locator("button .fa-clock")).to_be_visible()
    print("Snooze button visible.")

    # E. CRM Sidebar Toggle
    expect(page.get_by_title("Toggle Customer Details")).to_be_visible()
    print("CRM Sidebar toggle visible.")

    # F. Open CRM Sidebar
    page.click("button[title='Toggle Customer Details']")
    expect(page.locator("#crm-sidebar")).to_be_visible()
    print("CRM Sidebar opened.")

    # Take Screenshot
    page.screenshot(path="verification/pro_features_ui.png")
    print("Screenshot saved.")

    browser.close()

if __name__ == "__main__":
    with sync_playwright() as playwright:
        run(playwright)
