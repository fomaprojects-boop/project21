from playwright.sync_api import sync_playwright, expect
import os
import re

def generate_static_html():
    # Read the PHP file
    with open("index.php", "r") as f:
        php_content = f.read()

    # Basic stripping of PHP tags to make it renderable as static HTML for UI testing
    import re
    html_content = re.sub(r"<\?php.*?\?>", "", php_content, flags=re.DOTALL)

    # Replace the defined constants/variables with dummy values
    html_content = html_content.replace("<?php echo $baseUrl; ?>", "http://localhost:8000")
    html_content = html_content.replace("<?php echo $userId; ?>", "1")
    html_content = html_content.replace("<?php echo $userRole; ?>", "Admin")
    html_content = html_content.replace("<?php echo $userName; ?>", "Test User")
    html_content = html_content.replace("<?php echo defined(\"FACEBOOK_APP_ID\") ? FACEBOOK_APP_ID : \"\"; ?>", "1234567890")
    html_content = html_content.replace("<?php echo defined(\"FACEBOOK_CONFIG_ID\") ? FACEBOOK_CONFIG_ID : \"\"; ?>", "0987654321")
    html_content = html_content.replace("<?php if (FEATURE_ENHANCED_EXPENSE_WORKFLOW): ?>", "")
    html_content = html_content.replace("<?php endif; ?>", "")
    html_content = html_content.replace("<?php if ($userRole === \"Admin\" || $userRole === \"Accountant\"): ?>", "")
    html_content = html_content.replace("<?php if ($userRole === \"Admin\" || $userRole === \"Staff\" || $userRole === \"Accountant\"): ?>", "")
    html_content = html_content.replace("<?php if ($userRole === \"Client\"): ?>", "")

    # Save as static HTML
    with open("verification/index_test.html", "w") as f:
        f.write(html_content)

def test_inbox_navigation():
    generate_static_html()

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        # Load the static HTML file
        page.goto("file://" + os.path.abspath("verification/index_test.html"))

        # Mock fetchApi and filterConversations
        page.evaluate("""
            window.fetchApi = async (endpoint) => {
                console.log("Mock fetchApi called for:", endpoint);
                if (endpoint.includes("get_conversations")) {
                    return {
                        success: true,
                        conversations: [
                            { conversation_id: 1, contact_name: "John Doe", phone_number: "123456", status: "open", last_message_preview: "Hello", updated_at: "2023-01-01 12:00:00", unread_count: 0 },
                            { conversation_id: 2, contact_name: "Jane Smith", phone_number: "654321", status: "closed", last_message_preview: "Bye", updated_at: "2023-01-01 11:00:00", unread_count: 0 }
                        ]
                    };
                }
                return { status: "success" };
            };

            // We overwrite the original filterConversations to ensure we can track its execution and force UI updates for the test
            window.filterConversations = (filter) => {
                console.log("filterConversations called with:", filter);
                window.lastFilterApplied = filter;

                // Manually update the DOM classes as the original function would
                ["open", "closed", "all"].forEach(t => {
                    const btn = document.getElementById("tab-" + t);
                    if (btn) {
                        if (filter === t) {
                            btn.classList.add("active", "bg-white", "text-violet-700", "shadow-sm");
                            btn.classList.remove("text-gray-500");
                        } else {
                            btn.classList.remove("active", "bg-white", "text-violet-700", "shadow-sm");
                            btn.classList.add("text-gray-500");
                        }
                    }
                });
                // Mock loading content
                const container = document.getElementById("conversations-container");
                if(container) container.innerHTML = "Loaded: " + filter;
            };
        """)

        # Navigate to Dashboard first (if not already there by default)
        # Find Inbox link by text
        inbox_link = page.get_by_role("link", name="Inbox")

        # Click Inbox
        inbox_link.click()

        # Wait a bit
        page.wait_for_timeout(500)

        # Verify that "filterConversations(open)" was effectively called.
        # We check the UI state of the tabs.
        open_tab = page.locator("#tab-open")
        expect(open_tab).to_have_class(re.compile(r"active"))

        closed_tab = page.locator("#tab-closed")
        expect(closed_tab).not_to_have_class(re.compile(r"active"))

        # Verify global var check
        filter_val = page.evaluate("window.lastFilterApplied")
        if filter_val != "open":
            print(f"FAILED: Expected open, got {filter_val}")
            exit(1)

        print(f"SUCCESS: Filter reset to {filter_val}")

        # Take screenshot
        page.screenshot(path="verification/inbox_navigation_verified.png")
        print("Screenshot saved to verification/inbox_navigation_verified.png")

        browser.close()

if __name__ == "__main__":
    test_inbox_navigation()
