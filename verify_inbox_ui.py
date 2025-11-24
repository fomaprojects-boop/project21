
from playwright.sync_api import sync_playwright
import os

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    page = browser.new_page()

    # 1. Simulate Webhook Request (Mock using curl or python requests if PHP server was running, but here we just verify UI logic)
    # Since we cannot run PHP, we will inject the HTML of index.php and mock the JS variables/functions to see if render works.

    # Load the HTML content directly
    with open('index.php', 'r') as f:
        content = f.read()

    # Strip PHP tags for basic rendering test
    import re
    content = re.sub(r'<\?php.*?\?>', '', content, flags=re.DOTALL)
    content = content.replace('<?php echo $userId; ?>', '1')
    content = content.replace('<?php echo $baseUrl; ?>', 'http://localhost')

    page.set_content(content)

    # Mock `fetchApi` to return messages with status
    page.evaluate("""
        window.fetchApi = async (endpoint) => {
            if (endpoint.includes('get_messages.php')) {
                return {
                    success: true,
                    messages: [
                        { id: 1, sender_type: 'contact', content: 'Hello', created_at: '2023-01-01 10:00:00', status: 'received' },
                        { id: 2, sender_type: 'agent', content: 'Hi there', created_at: '2023-01-01 10:01:00', status: 'sent' },
                        { id: 3, sender_type: 'agent', content: 'Delivered Msg', created_at: '2023-01-01 10:02:00', status: 'delivered' },
                        { id: 4, sender_type: 'agent', content: 'Read Msg', created_at: '2023-01-01 10:03:00', status: 'read' }
                    ]
                };
            }
            return { success: true };
        };

        // Manually trigger loadMessages
        // We need to mock DOM elements first
        document.body.innerHTML += '<div id="message-container"></div>';
        document.body.innerHTML += '<div id="message-view-placeholder"></div>';
        document.body.innerHTML += '<div id="message-view-content"></div>';
        document.body.innerHTML += '<div id="chat-partner-name"></div>';

        loadMessages(1, 'Test User');
    """)

    # Verify Ticks
    # Wait for rendering
    page.wait_for_timeout(1000)

    content = page.content()

    if 'fa-check text-gray-300' in content:
        print("SUCCESS: Found Sent Tick")
    else:
        print("FAILURE: Missing Sent Tick")

    if 'fa-check-double text-gray-300' in content:
        print("SUCCESS: Found Delivered Tick")
    else:
        print("FAILURE: Missing Delivered Tick")

    if 'fa-check-double text-blue-300' in content:
        print("SUCCESS: Found Read Tick")
    else:
        print("FAILURE: Missing Read Tick")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
