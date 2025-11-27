import re
import os
from playwright.sync_api import sync_playwright

def convert_php_to_html(php_file_path, html_file_path):
    with open(php_file_path, 'r') as f:
        content = f.read()

    content = re.sub(r'<\?php.*?\?>', '', content, flags=re.DOTALL)
    content = re.sub(r'<\?.*?\?>', '', content, flags=re.DOTALL)

    with open(html_file_path, 'w') as f:
        f.write(content)

def verify_template_ui():
    convert_php_to_html('index.php', 'verification/index.html')
    file_path = os.path.abspath('verification/index.html')

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        page.goto(f'file://{file_path}')

        # 1. Force Render Modals - This injects HTML into #modal-container
        # Now that we moved the definition to modalTemplates, it SHOULD render.
        page.evaluate("renderAllModals()")

        # 2. Check existence
        if page.locator("#fillTemplateVariablesModal").count() > 0:
            print("Modal successfully found in DOM!")
        else:
            print("Modal still NOT found in DOM. Debug needed.")

        complex_template = {
            "id": 123,
            "name": "complex_template",
            "body": "Hello {{name}}, your order {{order_id}} is ready.",
            "header": "Welcome {{customer}}",
            "header_type": "IMAGE",
            "buttons_data": [
                {"type": "URL", "text": "Track Order", "url": "https://example.com/track/{{1}}"}
            ],
            "status": "APPROVED"
        }

        # 3. Trigger logic
        page.evaluate(f"selectTemplateContent({complex_template})")

        # 4. Wait for visibility & Screenshot
        page.wait_for_selector('#fillTemplateVariablesModal', state='visible')
        page.screenshot(path='verification/template_modal.png')
        print("Screenshot taken: verification/template_modal.png")
        browser.close()

if __name__ == "__main__":
    verify_template_ui()
