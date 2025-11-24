import os
from playwright.sync_api import sync_playwright

def test_bubbles():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        # Load the mock file
        file_path = os.path.abspath("verify_bubbles.html")
        page.goto(f"file://{file_path}")

        # Take screenshot
        page.screenshot(path="verification/bubbles_verified.png")
        print("Screenshot taken: verification/bubbles_verified.png")

        browser.close()

if __name__ == "__main__":
    test_bubbles()
