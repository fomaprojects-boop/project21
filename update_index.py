
import os

file_path = 'index.php'

with open(file_path, 'r') as f:
    content = f.read()

# Replacement 1: loadConversations
search_1 = """                    const lastContactMessageTimestamp = c.last_contact_message_at ? `'${c.last_contact_message_at}'` : 'null';

                    // Note: Passing null for profileImage as it is not yet in API
                    container.innerHTML += `
                        <div onclick="selectConversation(${c.conversation_id}, '${c.contact_name.replace(/'/g, "\\'")}', '${c.phone_number}', '${c.status}', '${c.assignee_name || ''}', null, ${lastContactMessageTimestamp})" class="p-4 cursor-pointer border-b transition-all ${isActive}">
                            <div class="flex justify-between items-start mb-1">
                                <div class="flex items-center">"""

replace_1 = """                    const lastContactMessageTimestamp = c.last_contact_message_at ? `'${c.last_contact_message_at}'` : 'null';
                    const closedByName = c.closed_by_name ? `'${c.closed_by_name.replace(/'/g, "\\'")}'` : 'null';
                    const closedAt = c.closed_at ? `'${c.closed_at}'` : 'null';

                    // Note: Passing null for profileImage as it is not yet in API
                    container.innerHTML += `
                        <div onclick="selectConversation(${c.conversation_id}, '${c.contact_name.replace(/'/g, "\\'")}', '${c.phone_number}', '${c.status}', '${c.assignee_name || ''}', null, ${lastContactMessageTimestamp}, ${closedByName}, ${closedAt})" class="p-4 cursor-pointer border-b transition-all ${isActive}">
                            <div class="flex justify-between items-start mb-1">
                                <div class="flex items-center">"""

if search_1 in content:
    content = content.replace(search_1, replace_1)
    print("Applied loadConversations update.")
else:
    print("Failed to find loadConversations block.")

# Replacement 2: selectConversation signature
search_2 = "function selectConversation(id, name, phone, status, assignee, profileImage = null, lastContactMessageAt = null) {"
replace_2 = "function selectConversation(id, name, phone, status, assignee, profileImage = null, lastContactMessageAt = null, closedByName = null, closedAt = null) {"

if search_2 in content:
    content = content.replace(search_2, replace_2)
    print("Applied selectConversation signature update.")
else:
    print("Failed to find selectConversation signature.")

# Replacement 3: selectConversation logic
search_3 = """            // 24-Hour Window Logic
            const now = new Date();
            const lastMessageDate = lastContactMessageAt ? safeDate(lastContactMessageAt) : null;
            const hoursDiff = lastMessageDate ? (now - lastMessageDate) / (1000 * 60 * 60) : Infinity;

            const messageInput = document.getElementById('messageInput');
            const inputWrapper = document.getElementById('input-wrapper');
            const chatFooter = document.getElementById('chat-footer');

            // Remove previous indicators
            const existingIndicator = document.getElementById('chat-closed-indicator');
            if (existingIndicator) {
                existingIndicator.remove();
            }

            if (hoursDiff > 24) {
                // Window is CLOSED
                messageInput.disabled = true;
                messageInput.placeholder = 'Select a template to restart the conversation.';
                inputWrapper.classList.add('opacity-50', 'bg-gray-100');

                // Add the "chat closed" indicator message
                const closedIndicator = document.createElement('div');
                closedIndicator.id = 'chat-closed-indicator';
                closedIndicator.className = 'my-2 p-3 rounded-lg bg-yellow-100 text-yellow-800 text-sm text-center italic';
                closedIndicator.innerHTML = `<i>Chat closed at ${lastMessageDate.toLocaleString()} by ${assignee || 'system'}. You must send a template to continue.</i>`;
                chatFooter.parentNode.insertBefore(closedIndicator, chatFooter);

            } else {
                // Window is OPEN
                messageInput.disabled = false;
                messageInput.placeholder = 'Type a message...';
                inputWrapper.classList.remove('opacity-50', 'bg-gray-100');
            }"""

replace_3 = """            // 24-Hour Window Logic & Closed Status Logic
            const now = new Date();
            const lastMessageDate = lastContactMessageAt ? safeDate(lastContactMessageAt) : null;
            const hoursDiff = lastMessageDate ? (now - lastMessageDate) / (1000 * 60 * 60) : Infinity;

            const messageInput = document.getElementById('messageInput');
            const inputWrapper = document.getElementById('input-wrapper');
            const chatFooter = document.getElementById('chat-footer');
            const sendBtn = document.getElementById('send-btn');

            // Remove previous indicators
            const existingIndicator = document.getElementById('chat-closed-indicator');
            if (existingIndicator) {
                existingIndicator.remove();
            }

            if (status === 'closed') {
                // Chat is manually closed (Resolved)
                resolveBtn.innerHTML = '<i class="fas fa-undo mr-2"></i> Reopen';
                resolveBtn.className = 'text-sm border border-gray-300 text-gray-600 hover:bg-yellow-50 hover:text-yellow-600 hover:border-yellow-300 px-3 py-1.5 rounded-lg transition-all flex items-center';

                // Disable input and hide send button
                messageInput.disabled = true;
                messageInput.placeholder = 'Conversation is closed.';
                inputWrapper.classList.add('opacity-50', 'bg-gray-100');
                if (sendBtn) sendBtn.style.display = 'none';

                // Add Yellow Banner
                const closedIndicator = document.createElement('div');
                closedIndicator.id = 'chat-closed-indicator';
                closedIndicator.className = 'my-2 p-3 rounded-lg bg-yellow-100 text-yellow-800 text-sm text-center italic border border-yellow-200 shadow-sm';

                let closedText = `<i>Chat closed`;
                if (closedByName && closedByName !== 'null') closedText += ` by ${closedByName}`;
                if (closedAt && closedAt !== 'null') closedText += ` on ${new Date(closedAt).toLocaleString()}`;
                closedText += `</i>`;

                closedIndicator.innerHTML = closedText;
                if(chatFooter && chatFooter.parentNode) chatFooter.parentNode.insertBefore(closedIndicator, chatFooter);

            } else {
                // Chat is OPEN
                resolveBtn.innerHTML = '<i class="fas fa-check mr-2"></i> <span class="hidden md:inline">Resolve</span>';
                resolveBtn.className = 'text-sm border border-gray-300 text-gray-600 hover:bg-green-50 hover:text-green-600 hover:border-green-300 px-3 py-1.5 rounded-lg transition-all flex items-center';

                // Enable input and show send button
                messageInput.disabled = false;
                messageInput.placeholder = 'Type a message...';
                inputWrapper.classList.remove('opacity-50', 'bg-gray-100');
                if (sendBtn) sendBtn.style.display = 'inline-flex';

                // Check 24-hour window ONLY if chat is open
                if (hoursDiff > 24) {
                    // Window is CLOSED (Meta Rule)
                    messageInput.disabled = true;
                    messageInput.placeholder = 'Select a template to restart the conversation.';
                    inputWrapper.classList.add('opacity-50', 'bg-gray-100');
                    if (sendBtn) sendBtn.style.display = 'none';

                    const windowIndicator = document.createElement('div');
                    windowIndicator.id = 'chat-closed-indicator';
                    windowIndicator.className = 'my-2 p-3 rounded-lg bg-red-50 text-red-800 text-sm text-center italic border border-red-100';
                    windowIndicator.innerHTML = `<i>24-hour window closed. Last message at ${lastMessageDate ? lastMessageDate.toLocaleString() : 'Unknown'}. You must send a template to continue.</i>`;
                    if(chatFooter && chatFooter.parentNode) chatFooter.parentNode.insertBefore(windowIndicator, chatFooter);
                }
            }"""

if search_3 in content:
    content = content.replace(search_3, replace_3)
    print("Applied selectConversation logic update.")
else:
    print("Failed to find selectConversation logic block.")

with open(file_path, 'w') as f:
    f.write(content)
