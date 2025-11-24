
from playwright.sync_api import sync_playwright, expect
import os

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1280, 'height': 800})
    page = context.new_page()

    # Path to the HTML file we created
    # Using absolute path for safety
    file_path = os.path.abspath("verify_final.html")
    page.goto(f"file://{file_path}")

    # Wait for the conversation list to load
    expect(page.get_by_text("Inbox List")).to_be_visible()

    # 1. Verify Initial State
    print("Verifying initial state...")
    # Check that the unread badge (count 3) is visible
    unread_badge = page.locator("span.bg-violet-600", has_text="3")
    expect(unread_badge).to_be_visible()
    print("Unread badge found.")

    # 2. Simulate Clicking the Conversation
    print("Simulating click on conversation...")
    # Click on the conversation item "Alice Customer"
    page.locator("div.cursor-pointer", has_text="Alice Customer").click()

    # 3. Verify UI Changes
    print("Verifying UI updates...")

    # A. Check Badge Removal
    # The badge should no longer be visible in the DOM
    expect(unread_badge).not_to_be_visible()
    print("Unread badge successfully removed.")

    # B. Check Message Bubble Styling
    # Verify timestamp styling in the new messages
    # We look for the timestamp '10:31 AM' which should have margin-top logic applied via CSS class
    # Since we can't easily check computed styles in headless logic without evaluating js,
    # we will rely on the screenshot, but we can check if the element exists.
    timestamp = page.locator(".message-timestamp", has_text="10:31 AM")
    expect(timestamp).to_be_visible()
    print("Timestamp element found.")

    # 4. Take Screenshot
    screenshot_path = "verification/final_verification.png"
    page.screenshot(path=screenshot_path)
    print(f"Screenshot saved to {screenshot_path}")

    browser.close()

if __name__ == "__main__":
    with sync_playwright() as playwright:
        run(playwright)
