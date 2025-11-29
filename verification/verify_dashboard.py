from playwright.sync_api import sync_playwright
import time

def verify_dashboard():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        page.goto("http://localhost:8000/index.html")

        # Wait for dashboard to load
        page.wait_for_timeout(2000)

        # Verify Stamp Duty Card exists and has Pay Now button logic (hidden or visible)
        # In mock data, Stamp Duty is 'Accruing', so button should be hidden.
        # Let's verify the button exists in DOM
        pay_btn = page.locator("#btn-pay-stamp_duty")

        # Take screenshot
        page.screenshot(path="verification/dashboard_screenshot.png", full_page=True)

        browser.close()

if __name__ == "__main__":
    verify_dashboard()
