import time
import threading
import http.server
import socketserver
import os
from playwright.sync_api import sync_playwright

PORT = 8000
DIRECTORY = "verification"

class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIRECTORY, **kwargs)

def start_server():
    socketserver.TCPServer.allow_reuse_address = True
    with socketserver.TCPServer(("", PORT), Handler) as httpd:
        print(f"Serving at port {PORT}")
        httpd.serve_forever()

def run_verification():
    # Start server in background
    server_thread = threading.Thread(target=start_server, daemon=True)
    server_thread.start()
    time.sleep(2) # Give server time to start

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        print("Navigating to workflows static view...")
        page.goto(f"http://localhost:{PORT}/workflows_view.html")

        # Wait for render
        page.wait_for_timeout(1000)

        print("Waiting for 'Automate Your Business' text...")
        try:
            page.wait_for_selector("text=Automate Your Business", timeout=5000)
            print("Found 'Automate Your Business'!")
        except Exception as e:
            print(f"Error finding text: {e}")
            page.screenshot(path="verification/error_workflows_static.png")
            browser.close()
            return

        # Take success screenshot
        print("Taking screenshot...")
        page.screenshot(path="verification/workflows_ui_verified.png", full_page=True)

        browser.close()
        print("Verification complete.")

if __name__ == "__main__":
    run_verification()
