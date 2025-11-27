
import os

file_path = 'index.php'

with open(file_path, 'r') as f:
    content = f.read()

# 1. Update setupEventListeners to use delegation for file input change
old_listener = """            // --- FILE INPUT LISTENER ---
            const fileInput = document.getElementById('file-input');
            if(fileInput) {
                fileInput.addEventListener('change', handleFileUpload);
            }"""

new_listener = """            // --- FILE INPUT LISTENER (Delegation) ---
            document.body.addEventListener('change', function(event) {
                if (event.target && event.target.id === 'file-input') {
                    handleFileUpload(event);
                }
            });"""

if old_listener in content:
    content = content.replace(old_listener, new_listener)
    print("Updated setupEventListeners")
else:
    print("Could not find setupEventListeners block")

# 2. Reset isEmojiPickerInitialized in showView
# We'll insert it right after `loader.style.display = 'flex';`
search_view = "loader.style.display = 'flex';"
replace_view = """loader.style.display = 'flex';
            // Reset emoji picker flag so it re-initializes on new view render
            isEmojiPickerInitialized = false;"""

if search_view in content and "isEmojiPickerInitialized = false;" not in content:
    content = content.replace(search_view, replace_view, 1) # Only first occurrence (inside showView)
    print("Updated showView")
else:
    print("Could not find showView block or already updated")

# 3. Fix Emoji Picker Callback
# Old: document.querySelector('#messageInput').value += emoji;
# New: document.querySelector('#messageInput').value += (emoji.emoji || emoji);

search_emoji = "document.querySelector('#messageInput').value += emoji;"
replace_emoji = "document.querySelector('#messageInput').value += (emoji.emoji || emoji);"

if search_emoji in content:
    content = content.replace(search_emoji, replace_emoji)
    print("Updated initEmojiPicker")
else:
    print("Could not find initEmojiPicker block")

with open(file_path, 'w') as f:
    f.write(content)
