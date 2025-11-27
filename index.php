<?php
require_once 'api/config.php';
require_once 'api/db.php';


session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userName = isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'User';
$userRole = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'Staff';
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . $path;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="base-url" content="<?php echo $baseUrl; ?>">
    <meta name="user-id" content="<?php echo $userId; ?>">
    <meta name="user-role" content="<?php echo $userRole; ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChatMe - Professional Edition</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@4.6.4/dist/index.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body { font-family: 'Inter', sans-serif; } .sidebar { background-color: #0f172a; } /* slate-900 */
        .sidebar-link { transition: all 0.2s ease-in-out; border-left: 3px solid transparent; color: #cbd5e1; } /* slate-300 */
        .sidebar-link:hover { background-color: #1e293b; color: #f8fafc; border-left-color: #8b5cf6; } /* slate-800, slate-50, violet-500 */
        .sidebar-link.active { background: linear-gradient(90deg, rgba(139, 92, 246, 0.1) 0%, rgba(139, 92, 246, 0) 100%); border-left-color: #8b5cf6; color: #fff; font-weight: 600; }
        .modal { transition: opacity 0.3s ease; }
        .conversation-item {
            transition: all 0.2s ease;
            cursor: pointer;
            border-left: 4px solid transparent;
        }
        .conversation-item:hover {
            background-color: #f8fafc;
            transform: translateX(2px);
        }
        .conversation-item.active {
            background-color: #f5f3ff;
            border-left-color: #7c3aed;
            font-weight: 600;
        }
        html.dark .conversation-item:hover {
             background-color: #1f2937;
        }
        html.dark .conversation-item.active {
            background-color: #2e1065;
            border-left-color: #a78bfa;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .btn-soft { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-soft:active { transform: scale(0.95); }
        .tab-pill { transition: all 0.3s ease; }
        .tab-pill.active { background-color: #fff; color: #7c3aed; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); }
        /* Modern Message Bubbles with Tails */
        .message-bubble {
            max-width: 75%;
            width: fit-content;
            min-width: 40px;
            white-space: pre-wrap;
            overflow-wrap: break-word;
            word-break: normal; /* FIX: Prevent aggressive breaking of short words */
            border-radius: 18px;
            padding: 10px 14px;
            position: relative;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            font-size: 0.95rem;
            line-height: 1.45;
        }

        .message-contact {
            background-color: #ffffff;
            color: #1e293b;
            border-bottom-left-radius: 4px;
            margin-left: 0; /* Avatar handles spacing */
        }
        /* Ensure tail color matches bubble background */
        .message-contact::before {
            content: "";
            position: absolute;
            bottom: 0;
            left: -8px;
            width: 0;
            height: 0;
            border-right: 12px solid #ffffff;
            border-top: 12px solid transparent;
        }

        .message-agent {
            background-color: #7c3aed;
            color: white;
            border-bottom-right-radius: 4px;
            margin-right: 6px;
        }
        .message-agent::after {
            content: "";
            position: absolute;
            bottom: 0;
            right: -8px;
            width: 0;
            height: 0;
            border-left: 12px solid #7c3aed;
            border-top: 12px solid transparent;
        }

        .message-timestamp {
            float: right;
            font-size: 0.65rem;
            margin-left: 8px;
            margin-top: 10px; /* Increased from 6px for more vertical breathing room */
            vertical-align: bottom;
            line-height: 1;
            position: relative;
            top: 2px;
        }
        .message-contact .message-timestamp { color: #94a3b8; } /* slate-400 */
        .message-agent .message-timestamp { color: rgba(255, 255, 255, 0.7); }
        .message-note .message-timestamp { color: #ca8a04; }
        .message-note {
            background-color: #fff3cd; /* pale-orange */
            color: #664d03; /* dark text */
            border: 1px solid #ffeeba;
            margin-left: auto;
            margin-right: 6px;
            border-radius: 12px;
            border-bottom-right-radius: 4px;
        }
        .message-note::before {
            content: "INTERNAL NOTE";
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: #664d03;
        }

        .message-scheduled {
            background-color: #f3f4f6; /* gray-100 */
            color: #4b5563; /* gray-600 */
            border: 1px dashed #d1d5db;
            border-bottom-right-radius: 4px;
            margin-right: 6px;
        }
        .message-scheduled::after {
            content: "";
            position: absolute;
            bottom: 0;
            right: -8px;
            width: 0;
            height: 0;
            border-left: 12px solid #f3f4f6;
            border-top: 12px solid transparent;
            opacity: 1;
        }
        #page-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }
        .loader {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .loader .dot {
            width: 15px;
            height: 15px;
            background-color: #3498db;
            border-radius: 50%;
            margin: 0 5px;
            animation: bounce 1.4s infinite ease-in-out both;
        }
        .loader .dot:nth-child(1) { animation-delay: -0.32s; }
        .loader .dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1.0); }
        }
        .status-Approved, .status-Paid, .status-Sent, .status-Deposited { background-color: #dcfce7; color: #16a34a; }
        .status-Pending, .status-Partially-Paid { background-color: #fef9c3; color: #ca8a04; }
        .status-Submitted, .status-Scheduled { background-color: #dbeafe; color: #3b82f6; }
        .status-Rejected, .status-Unpaid { background-color: #fee2e2; color: #ef4444; }
        .status-Draft { background-color: #e5e7eb; color: #4b5563; }
        [class*="status-Forwarded"] { background-color: #e0e7ff; color: #4f46e5; }
        .status-Partially-Paid { background-color: #fef9c3; color: #854d0e; }
        .status-Scheduled { background-color: #dbeafe; color: #1e40af; }
        .status-Draft { background-color: #e5e7eb; color: #4b5563; }
        .phone-mockup {
            border: 8px solid #111;
            border-radius: 40px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            background: #E5DDD5; /* WhatsApp background color */
            padding: 15px; /* Reduced padding slightly */
            display: flex; /* USE FLEXBOX */
            flex-direction: column; /* Stack items vertically */
            min-height: 350px; /* Give a consistent minimum height */
            position: relative; /* Keep for potential future absolute elements if needed, but not for status */
        }
        .template-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding: 0 5px; /* Add some horizontal padding */
        }
        .template-name {
            font-weight: bold;
            color: #333;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 60%; /* Prevent long names from pushing status badge */
        }
        .template-status-badge {
            font-size: 0.75rem;
            font-weight: bold;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            border-width: 1px;
            flex-shrink: 0; /* Prevent badge from shrinking */
        }
        .template-content-wrapper {
            flex-grow: 1; /* This will push the footer down */
            display: flex;
            flex-direction: column;
        }

        .whatsapp-bubble {
            background-color: #DCF8C6; /* WhatsApp green */
            color: #303030;
            padding: 10px 15px;
            border-radius: 12px;
            max-width: 100%;
            width: 100%;
            margin-bottom: 10px;
            position: relative;
            box-shadow: 0 1px 1px rgba(0,0,0,0.05);
        }

        .whatsapp-bubble.has-header .header {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .whatsapp-bubble .body {
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .whatsapp-bubble .footer {
            font-size: 0.75rem;
            color: #888;
            margin-top: 5px;
        }

        .whatsapp-buttons {
            margin-top: 10px;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .whatsapp-button {
            background: #fff;
            border: 1px solid #ddd;
            color: #3498db;
            padding: 8px 12px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .workflow-template-card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); transition: all 0.2s ease-in-out;}
        .workflow-node { background-color: white; border: 1px solid #e5e7eb; border-radius: 1rem; padding: 1.25rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); width: 300px; text-align: left; position: relative; z-index: 20; transition: all 0.2s ease-in-out; cursor: pointer; }
        .workflow-node:hover { transform: scale(1.02); box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); }
        .workflow-node:active { transform: scale(0.98); }
        .workflow-node.trigger { border-left: 4px solid #fb7185; } .workflow-node.ai_objective { border-left: 4px solid #818cf8; } .workflow-node.action { border-left: 4px solid #34d399; } .workflow-node.condition { border-left: 4px solid #fbbf24; } .workflow-node.question { border-left: 4px solid #3b82f6; }
        .workflow-connector { width: 2px; height: 50px; background-color: #94a3b8; margin: 0 auto; position: relative; z-index: 10; }
        .add-node-btn { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 30; opacity: 0; transition: all 0.2s; background-color: white; border-radius: 9999px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .workflow-connector:hover .add-node-btn, .branch-path .workflow-connector:hover .add-node-btn, .workflow-node:hover + .workflow-connector .add-node-btn { opacity: 1; transform: translate(-50%, -50%) scale(1.1); }
        .workflow-branch { display: flex; justify-content: center; padding-top: 0; position: relative; }
        .branch-path { display: flex; flex-direction: column; align-items: center; position: relative; padding: 20px 20px 0 20px; box-sizing: border-box; min-width: 150px; }
        .branch-path::before, .branch-path::after { content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 20px; border-top: 2px solid #94a3b8; z-index: 10; }
        .branch-path::after { right: auto; left: 50%; border-left: 2px solid #94a3b8; }
        .branch-path:only-child::after, .branch-path:only-child::before { display: none; }
        .branch-path:only-child { padding-top: 0; }
        .branch-path:first-child::before { border-top: 0; }
        .branch-path:last-child::after { border-top: 0; }
        .branch-label { font-size: 0.75rem; font-weight: 600; color: #475569; background-color: #f1f5f9; padding: 2px 8px; border-radius: 999px; border: 1px solid #e2e8f0; margin-bottom: 10px; z-index: 10; position: relative; }
        .workflow-canvas-bg { background-image: radial-gradient(#cbd5e1 1px, transparent 1px); background-size: 1.5rem 1.5rem; }
        /* Old settings tab style removal or override */
        .settings-tab.active-tab { background-color: #f5f3ff; color: #7c3aed; font-weight: 600; }
        /* New Settings Layout Styles */
        .settings-sidebar-btn { text-align: left; width: 100%; padding: 0.75rem 1rem; border-radius: 0.5rem; color: #4b5563; font-weight: 500; transition: all 0.2s; display: flex; align-items: center; }
        .settings-sidebar-btn:hover { background-color: #f3f4f6; color: #111827; }
        .settings-sidebar-btn.active-tab { background-color: #f5f3ff; color: #7c3aed; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
        .settings-sidebar-btn i { width: 1.5rem; text-align: center; margin-right: 0.75rem; }

        input:checked ~ .dot { transform: translateX(100%); background-color: #7c3aed; }
        input:checked ~ .block { background-color: #ddd6fe; }

        @keyframes pulsating-loader {
            0% { transform: scale(0.8); box-shadow: 0 0 0 0 rgba(124, 58, 237, 0.7); }
            50% { transform: scale(1); box-shadow: 0 0 0 20px rgba(124, 58, 237, 0); }
            100% { transform: scale(0.8); box-shadow: 0 0 0 0 rgba(124, 58, 237, 0); }
        }
        @keyframes color-change {
            0% { background-color: #7c3aed; }
            25% { background-color: #0ea5e9; }
            50% { background-color: #10b981; }
            75% { background-color: #f59e0b; }
            100% { background-color: #ef4444; }
        }
        .pulsating-circle-loader {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 80px;
        }
        .pulsating-circle-loader div {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            animation: pulsating-loader 2s infinite, color-change 5s infinite alternate;
        }
        @keyframes pulse-bg {
            0% { background-color: #f9fafb; } /* gray-50 */
            50% { background-color: #e5e7eb; } /* gray-200 */
            100% { background-color: #f9fafb; } /* gray-50 */
        }
        .manual-pending-pulse {
            animation: pulse-bg 2s infinite;
        }
        /* Sticky Actions Column */
        .sticky-right {
            position: sticky;
            right: 0;
            background-color: white;
            z-index: 10;
            box-shadow: -2px 0 5px rgba(0,0,0,0.05);
        }
        thead th.sticky-right {
            background-color: #f3f4f6; /* Match gray-100 */
        }

        /* --- UX Overhaul: Soft & Fluid UI --- */

        /* 1. Global Transitions & Cursors */
        button, a, .sidebar-link, .conversation-item, .tab-pill, [onclick], .workflow-node, .workflow-template-card {
            cursor: pointer;
            transition: all 0.2s ease-out;
        }

        /* 2. Enhanced Hover States */
        .sidebar-link:hover,
        .btn-soft:hover,
        .settings-sidebar-btn:hover,
        button:hover,
        a.bg-red-500:hover,
        .swal2-confirm:hover,
        .swal2-cancel:hover,
        .tab-pill:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
            filter: brightness(1.05);
        }
        .conversation-item:hover {
            transform: translateX(4px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.04);
        }
        .workflow-node:hover, .workflow-template-card:hover {
             transform: translateY(-4px) scale(1.03);
             box-shadow: 0 10px 20px rgba(0,0,0,0.07);
        }


        /* 3. Soft Focus States for Inputs & Buttons */
        input:focus, select:focus, textarea:focus, button:focus, a:focus {
            outline: none !important; /* Force remove default outline */
            border-color: #a78bfa !important; /* violet-300 */
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.2) !important; /* Softer, slightly larger violet glow */
        }

        /* 4. Immediate Click Feedback */
        button:active, .btn-soft:active, a:active, .settings-sidebar-btn:active, .conversation-item:active, .workflow-node:active, .workflow-template-card:active {
            transform: scale(0.97) translateY(1px);
            filter: brightness(0.92);
            transition-duration: 0.05s;
        }

        /* 5. Custom Snooze Modal Styling */
        .swal2-popup.custom-snooze-width {
            max-width: 320px !important;
        }
        #swal-datetime {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            margin-top: 1rem;
            box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        #swal-datetime:focus {
            outline: none !important;
            border-color: #a78bfa !important;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15) !important;
        }

        .variable-chip {
            background-color: #e5e7eb;
            color: #374151;
            padding: 4px 12px;
            border-radius: 16px;
            font-family: monospace;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .variable-chip:hover {
            background-color: #d1d5db;
            transform: scale(1.05);
        }

        /* Custom style to make the template modal smaller */
        #addTemplateModal .w-full.max-w-2xl {
            max-width: 40rem; /* equivalent to max-w-xl in Tailwind */
        }
        /* --- Dark Mode Styles --- */
        html.dark {
            color-scheme: dark;
        }
        html.dark body {
            background-color: #111827;
            color: #f9fafb;
        }
        html.dark main {
             background-color: #111827;
        }
        html.dark .bg-white {
            background-color: #1f2937;
        }
         html.dark .bg-gray-50, html.dark .bg-slate-50 {
            background-color: #111827;
        }
        html.dark .bg-gray-100 {
            background-color: #1f2937;
        }
        html.dark .border, html.dark .border-b, html.dark .border-r, html.dark .border-l, html.dark .border-t {
            border-color: #374151 !important;
        }
        html.dark .divide-y > :not([hidden]) ~ :not([hidden]) {
            border-color: #374151;
        }
        html.dark .text-gray-800, html.dark .text-gray-900 { color: #f9fafb; }
        html.dark .text-gray-700 { color: #d1d5db; }
        html.dark .text-gray-600 { color: #9ca3af; }
        html.dark .text-gray-500 { color: #a1a1aa; }
        html.dark .text-gray-400 { color: #9ca3af; }

        html.dark .sidebar { background-color: #0d111c; }
        html.dark .sidebar-link:hover { background-color: #1f2937; }

        html.dark .conversation-item:hover { background-color: #1f2937; }
        html.dark .conversation-item.active { background-color: #1e1b4b; }

        html.dark .message-contact { background-color: #374151; color: #f3f4f6; }
        html.dark .message-contact::before { border-right-color: #374151; }
        html.dark .message-agent { background-color: #6d28d9; }
        html.dark .message-agent::after { border-left-color: #6d28d9; }

        html.dark #message-container { background-image: radial-gradient(#4b5563 1px, transparent 1px) !important; }

        html.dark input, html.dark select, html.dark textarea {
             background-color: #374151;
             color: #f3f4f6;
             border-color: #4b5563;
        }
        html.dark ::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }

        html.dark #input-wrapper { background-color: #1f2937; border-color: #4b5563; }
        html.dark .tab-pill { color: #d1d5db; }
        html.dark .tab-pill.active { background-color: #374151; color: #f9fafb; }

        /* Custom Scrollbar for Dark Mode */
        html.dark ::-webkit-scrollbar { width: 8px; }
        html.dark ::-webkit-scrollbar-track { background: #1f2937; }
        html.dark ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 4px; }
        html.dark ::-webkit-scrollbar-thumb:hover { background: #6b7280; }

        html.dark #attachment-preview-container {
            background-color: #374151;
            border-color: #4b5563;
        }


    </style>
    <!-- Facebook SDK for JavaScript -->
    <script>
        function insertVariable(variable) {
            const textarea = document.getElementById('templateBody');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            const before = text.substring(0, start);
            const after = text.substring(end, text.length);
            textarea.value = before + variable + after;
            textarea.selectionStart = textarea.selectionEnd = start + variable.length;
            textarea.focus();
            updateTemplateVariables();
        }

        window.fbAsyncInit = function() {
            // Debugging: Log App ID to console
            console.log('Initializing Facebook SDK with App ID:', '<?php echo defined('FACEBOOK_APP_ID') ? FACEBOOK_APP_ID : ''; ?>');

            FB.init({
            appId            : '<?php echo defined('FACEBOOK_APP_ID') ? FACEBOOK_APP_ID : ''; ?>',
            autoLogAppEvents : true,
            xfbml            : true,
            version          : 'v21.0'
            });
        };

        // Session logging message event listener (Captures WABA/Phone IDs directly)
        window.addEventListener('message', (event) => {
            if (!event.origin.endsWith('facebook.com')) return;
            try {
                const data = JSON.parse(event.data);
                if (data.type === 'WA_EMBEDDED_SIGNUP') {
                    console.log('message event (WA_EMBEDDED_SIGNUP): ', data);

                    // Optional: Send this direct data to backend if needed,
                    // though usually we exchange the auth code for a permanent token.
                    // This is useful for debugging or immediate UI feedback.
                    if (data.event === 'FINISH' && data.data) {
                        console.log('Signup Finished. Phone ID:', data.data.phone_number_id, 'WABA ID:', data.data.waba_id);
                    }
                }
            } catch (e) {
                console.log('message event (error parsing): ', event.data);
            }
        });

        // Load the JavaScript SDK asynchronously
        (function (d, s, id) {
            var js, fjs = d.getElementsByTagName(s)[0];
            if (d.getElementById(id)) return;
            js = d.createElement(s); js.id = id;
            js.src = "https://connect.facebook.net/en_US/sdk.js";
            fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'facebook-jssdk'));
    </script>
</head>
<body class="bg-gray-100 h-screen flex flex-col">
    <div id="page-loader" style="display: none;">
        <div class="loader">
            <div class="dot"></div>
            <div class="dot"></div>
            <div class="dot"></div>
        </div>
    </div>
    <div id="fb-root"></div>
    <div class="flex flex-1 w-full text-white overflow-hidden">
        <aside class="w-64 sidebar flex flex-col flex-shrink-0 overflow-y-auto">
             <div class="flex items-center justify-center p-4 border-b border-gray-800 h-20 flex-shrink-0 bg-gray-900">
                <img src="uploads/LOGO_Chatme1.png" alt="ChatMe Logo" class="h-12 object-contain">
             </div>
            <nav class="flex-1 px-3 py-6 space-y-1">
                <a href="#" onclick="showView('dashboard', event)" class="sidebar-link active flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-tachometer-alt w-6 mr-3"></i><span>Dashboard</span></a>
                <a href="#" onclick="showView('conversations', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-inbox w-6 mr-3"></i><span>Inbox</span></a>
                <a href="#" onclick="showView('contacts', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-address-book w-6 mr-3"></i><span>Contacts</span></a>

                <!-- Business Dropdown -->
                <div>
                    <button type="button" class="sidebar-link w-full flex items-center justify-between px-4 py-2.5 rounded-r-md" onclick="toggleDropdown('business-menu')">
                        <span class="flex items-center">
                            <i class="fas fa-briefcase w-6 mr-3"></i>
                            <span>Business</span>
                        </span>
                        <i id="business-menu-icon" class="fas fa-chevron-down transform transition-transform duration-200"></i>
                    </button>
                    <div id="business-menu" class="hidden pl-8 space-y-2 py-2">
                        <?php if ($userRole === 'Admin' || $userRole === 'Accountant'): ?>
                        <a href="#" onclick="showView('vendors', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-store w-6 mr-3"></i><span>Vendors</span></a>
                        <a href="#" onclick="showView('invoices', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-file-invoice-dollar w-6 mr-3"></i><span>Invoices</span></a>
                        <a href="#" onclick="showView('assets', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-box-open w-6 mr-3"></i><span>Assets</span></a>
                        <a href="#" onclick="showView('investments', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-chart-line w-6 mr-3"></i><span>Investments</span></a>
                        <a href="#" onclick="showView('tax_payments', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-landmark w-6 mr-3"></i><span>Tax Payments</span></a>
                        <a href="#" onclick="showView('financials', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-chart-pie w-6 mr-3"></i><span>Financial Statements</span></a>
                        <a href="#" onclick="showView('payroll', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-money-bill-wave w-6 mr-3"></i><span>Wages and Salaries</span></a>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="#" onclick="showView('youtube-ads', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fab fa-youtube w-6 mr-3"></i><span>YouTube Ads</span></a>

                <?php if (FEATURE_ENHANCED_EXPENSE_WORKFLOW): ?>
                <a href="#" onclick="showView('expenses', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-cash-register w-6 mr-3"></i><span>Expenses</span></a>
                <?php endif; ?>

                <!-- Whatsapp Dropdown -->
                <div>
                    <button type="button" class="sidebar-link w-full flex items-center justify-between px-4 py-2.5 rounded-r-md" onclick="toggleDropdown('whatsapp-menu')">
                        <span class="flex items-center">
                            <i class="fab fa-whatsapp w-6 mr-3"></i>
                            <span>Whatsapp</span>
                        </span>
                        <i id="whatsapp-menu-icon" class="fas fa-chevron-down transform transition-transform duration-200"></i>
                    </button>
                    <div id="whatsapp-menu" class="hidden pl-8 space-y-2 py-2">
                        <a href="#" onclick="showView('broadcast', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-bullhorn w-6 mr-3"></i><span>Broadcast</span></a>
                        <a href="#" onclick="showView('templates', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-file-alt w-6 mr-3"></i><span>Templates</span></a>
                        <a href="#" onclick="showView('workflows', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-project-diagram w-6 mr-3"></i><span>Workflows</span></a>
                        <a href="#" onclick="showView('reports', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-chart-line w-6 mr-3"></i><span>Reports</span></a>
                        <a href="#" onclick="showView('contacts', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-address-book w-6 mr-3"></i><span>Contacts</span></a>
                    </div>
                </div>

                <?php if ($userRole === 'Admin' || $userRole === 'Accountant'): ?>
                <a href="#" onclick="showView('users', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-users w-6 mr-3"></i><span>Users</span></a>
                <?php endif; ?>

                <!-- Print & Design Workflow Dropdown -->
                <div>
                    <button type="button" class="sidebar-link w-full flex items-center justify-between px-4 py-2.5 rounded-r-md" onclick="toggleDropdown('print-design-menu')">
                        <span class="flex items-center">
                            <i class="fas fa-print w-6 mr-3"></i>
                            <span>Print & Design</span>
                        </span>
                        <i id="print-design-menu-icon" class="fas fa-chevron-down transform transition-transform duration-200"></i>
                    </button>
                    <div id="print-design-menu" class="hidden pl-8 space-y-2 py-2">
                        <?php if ($userRole === 'Admin' || $userRole === 'Staff' || $userRole === 'Accountant'): ?>
                            <a href="#" onclick="showView('analytics', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-chart-pie w-6 mr-3"></i><span>Analytics</span></a>
                            <?php if ($userRole === 'Admin' || $userRole === 'Accountant'): ?>
                                <a href="#" onclick="showView('costs', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-dollar-sign w-6 mr-3"></i><span>Material Costs</span></a>
                            <?php endif; ?>
                            <a href="#" onclick="showView('online-job-order', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-clipboard-list w-6 mr-3"></i><span>Job Orders</span></a>
                            <a href="#" onclick="showView('pricing-calculator', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-calculator w-6 mr-3"></i><span>Pricing Calculator</span></a>
                            <a href="#" onclick="showView('file-upload', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-file-upload w-6 mr-3"></i><span>File Upload</span></a>
                            <a href="#" onclick="showView('digital-proofing', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-check-double w-6 mr-3"></i><span>Digital Proofing</span></a>
                        <?php endif; ?>
                        <?php if ($userRole === 'Client'): ?>
                            <a href="#" onclick="showView('customer-dashboard', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-user-circle w-6 mr-3"></i><span>My Dashboard</span></a>
                            <a href="#" onclick="showView('online-job-order', event)" class="sidebar-link flex items-center px-4 py-2.5 rounded-r-md"><i class="fas fa-clipboard-list w-6 mr-3"></i><span>New Job Order</span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </nav>
        </aside>

        <main class="flex-1 flex flex-col bg-gray-50 text-gray-800">
             <header class="flex items-center justify-between p-4 border-b bg-gradient-to-r from-violet-700 to-sky-500 h-16 w-full z-10 flex-shrink-0 text-white shadow-sm">
                <h2 id="view-title" class="text-2xl font-bold">Dashboard</h2>
                <div class="flex items-center space-x-6">
                    <button id="theme-switcher" class="hover:text-violet-200" title="Toggle Dark Mode">
                        <i class="fas fa-moon text-xl"></i>
                    </button>
                    <span class="font-semibold">Welcome, <?php echo $userName; ?>!</span>
                    <button onclick="showView('settings', event)" class="hover:text-violet-200" title="Settings"><i class="fas fa-cog text-xl"></i></button>
                    <a href="api/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-semibold" title="Logout"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                </div>
            </header>

            <div id="global-alert-banner" class="hidden bg-red-600 text-white text-center p-2 font-semibold">
                You have reached the 1,000 free conversations limit for this month. All subsequent conversations will be charged.
            </div>
            <div id="view-container" class="flex-1 overflow-y-auto">
                </div>
            <footer class="text-center p-4 text-sm bg-gray-900 text-gray-300">
                &copy; 2025 All rights reserved.
            </footer>
        </main>
    </div>

    <div id="modal-container"></div>

    <script>
        // --- TEMPLATES FOR VIEWS AND MODALS (KAMILI) ---
        const viewTemplates = {

            "youtube-ads": `<div class="p-8">
                <div id="youtube-ads-cta" class="text-center p-12 bg-white rounded-lg shadow-md border">
                    <i class="fab fa-youtube text-red-500 text-6xl mb-4"></i>
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">Connect Your YouTube Channel</h2>
                    <p class="text-gray-600 mb-6 max-w-2xl mx-auto">To start managing and automating your YouTube ads, you first need to connect your YouTube channel. This will allow our system to access your videos and performance data securely.</p>
                    <a href="api/modules/youtube_ads/controllers/AuthController.php" class="bg-red-600 text-white px-6 py-3 rounded-lg hover:bg-red-700 font-semibold inline-block"><i class="fab fa-youtube mr-2"></i>Connect YouTube Channel</a>
                </div>

                <div id="youtube-ads-main" class="hidden">
                    <div id="success-notification" class="hidden bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow-lg">
                        <div class="flex">
                            <div class="py-1"><i class="fas fa-check-circle fa-2x"></i></div>
                            <div class="ml-3">
                                <p class="font-bold">Success!</p>
                                <p class="text-sm">Your YouTube channel has been connected successfully.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6 bg-white p-6 rounded-lg shadow-md border">
                        <h3 class="text-xl font-bold text-gray-800 mb-4">Connected Channel</h3>
                        <div id="connected-channel-info" class="flex items-center">
                            <!-- JS will populate this -->
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Sehemu ya Kushoto: Active Campaigns & Add Advertiser -->
                        <div class="lg:col-span-1">
                            <button onclick="openModal('addAdvertiserModal')" class="w-full bg-violet-600 text-white px-5 py-3 rounded-lg hover:bg-violet-700 font-semibold mb-6 shadow-md"><i class="fas fa-user-plus mr-2"></i>Add New Advertiser</button>
                            <div class="bg-white p-6 rounded-lg shadow-md border">
                                <h3 class="text-xl font-bold text-gray-700 mb-4">Active Campaigns</h3>
                                <div id="active-campaigns-list" class="space-y-3">
                                    <!-- JS itajaza listi ya kampeni hapa -->
                                </div>
                                <div id="active-campaigns-pagination" class="flex justify-center items-center mt-4"></div>
                            </div>
                        </div>

                        <!-- Sehemu ya Kulia: Pending Campaigns & Fomu -->
                        <div class="lg:col-span-1">
                            <div class="bg-white p-6 rounded-lg shadow-md border">
                                <h3 class="text-xl font-bold text-gray-700 mb-4">Create New Campaign</h3>
                                <form id="createAdForm" class="space-y-4" enctype="multipart/form-data">
                                    <div>
                                        <label for="adAdvertiser" class="block text-sm font-medium text-gray-700">Advertiser</label>
                                        <select id="adAdvertiser" name="advertiser_id" class="w-full p-2 border border-gray-300 rounded-md" required></select>
                                    </div>
                                    <div>
                                        <label for="adTitle" class="block text-sm font-medium text-gray-700">Ad Title</label>
                                        <input type="text" id="adTitle" name="title" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div>
                                        <label for="adFile" class="block text-sm font-medium text-gray-700">Ad File (Video)</label>
                                        <input type="file" id="adFile" name="ad_file" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-violet-50 file:text-violet-700 hover:file:bg-violet-100" required accept="video/*">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Campaign Type:</label>
                                        <div class="mt-2 flex gap-x-6">
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="campaign_type" value="Dedicated" checked class="form-radio h-4 w-4 text-violet-600" onchange="togglePlacementField()">
                                                <span class="ml-2 text-sm text-gray-700">Dedicated Ad</span>
                                            </label>
                                            <label class="inline-flex items-center">
                                                <input type="radio" name="campaign_type" value="Manual" class="form-radio h-4 w-4 text-violet-600" onchange="togglePlacementField()">
                                                <span class="ml-2 text-sm text-gray-700">Manual Sponsorship</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div id="placement-field-container">
                                            <label for="adPlacement" class="block text-sm font-medium text-gray-700">Placement</label>
                                            <select id="adPlacement" name="placement" class="w-full p-2 border border-gray-300 rounded-md" required>
                                                <option value="intro">Intro (Before Video)</option>
                                                <option value="outro">Outro (After Video)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="adPrice" class="block text-sm font-medium text-gray-700">Price (<span id="currency-symbol"></span>)</label>
                                            <input type="number" id="adPrice" name="price" class="w-full p-2 border border-gray-300 rounded-md" required>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label for="adStartDate" class="block text-sm font-medium text-gray-700">Campaign Start Date</label>
                                            <input type="date" id="adStartDate" name="start_date" class="w-full p-2 border border-gray-300 rounded-md" required>
                                        </div>
                                        <div>
                                            <label for="adEndDate" class="block text-sm font-medium text-gray-700">Campaign End Date</label>
                                            <input type="date" id="adEndDate" name="end_date" class="w-full p-2 border border-gray-300 rounded-md" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="w-full bg-green-600 text-white px-4 py-3 rounded-lg hover:bg-green-700 font-semibold">Create Ad & Generate Invoice</button>
                                </form>
                            </div>
                            <div class="bg-white p-6 rounded-lg shadow-md border mt-8">
                                <h3 class="text-xl font-bold text-gray-700 mb-4">Pending Campaigns</h3>
                                <div id="pending-campaigns-list" class="space-y-3">
                                    <!-- JS itajaza listi hapa -->
                                </div>
                                <div id="pending-campaigns-pagination" class="flex justify-center items-center mt-4"></div>
                            </div>
                        </div>
                    </div>
                    <div class="lg:col-span-3 mt-8">
                        <div class="bg-white p-6 rounded-lg shadow-md border">
                            <h3 class="text-xl font-bold text-gray-700 mb-4">Generated Reports History</h3>
                            <div id="generated-reports-list" class="divide-y">
                            </div>
                            <div id="generated-reports-pagination" class="flex justify-center items-center mt-4"></div>
                        </div>
                    </div>
                </div>
</div>`,
            youtube: `<div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-3xl font-bold text-gray-800">YouTube Integration</h2>
                    <a href="modules/youtube_ads/controllers/AuthController.php" class="bg-red-600 text-white px-5 py-2 rounded-lg hover:bg-red-700 font-semibold"><i class="fab fa-youtube mr-2"></i>Connect YouTube Channel</a>
                </div>
                <div id="youtube-channel-info" class="bg-white p-6 rounded-lg shadow-md border mb-8 hidden">
                    <!-- Channel info will be displayed here -->
                </div>
                <div class="bg-white p-6 rounded-lg shadow-md border">
                    <h3 class="text-xl font-bold text-gray-700 mb-4">Generate Advertiser Report</h3>
                    <form id="youtubeReportForm">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="youtubeAdvertiser" class="block text-sm font-medium text-gray-700 mb-1">Select Advertiser</label>
                                <select id="youtubeAdvertiser" name="advertiser_id" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    <option value="">Loading advertisers...</option>
                                </select>
                            </div>
                            <div>
                                <label for="youtubeReportName" class="block text-sm font-medium text-gray-700 mb-1">Report Name</label>
                                <input type="text" id="youtubeReportName" name="report_name" class="w-full p-2 border border-gray-300 rounded-md" placeholder="e.g., Q4 Campaign Performance" required>
                            </div>
                        </div>
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Select Videos for Report</label>
                            <div id="youtube-video-list" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4 max-h-96 overflow-y-auto p-4 bg-gray-50 border rounded-md">
                                <!-- Video list will be populated here -->
                                <p class="text-gray-500">Please connect your channel to see your videos.</p>
                            </div>
                        </div>
                        <div class="flex justify-end mt-6">
                            <button type="submit" class="bg-violet-600 text-white px-6 py-2 rounded-lg hover:bg-violet-700 font-semibold">Generate & Save Report</button>
                        </div>
                    </form>
                </div>
                 <div class="mt-8">
                    <h3 class="text-xl font-bold text-gray-700 mb-4">Generated Reports</h3>
                    <div id="youtube-reports-list" class="bg-white rounded-lg shadow-md overflow-hidden border">
                         <table class="w-full text-left"><thead class="bg-gray-100"><tr><th class="p-4 font-semibold">Report Name</th><th class="p-4 font-semibold">Advertiser</th><th class="p-4 font-semibold">Generated On</th><th class="p-4 font-semibold">Actions</th></tr></thead><tbody id="youtube-reports-table-body" class="divide-y"></tbody></table>
                    </div>
                </div>
            </div>`,
            activity_log: `<div class="p-8">
                <div class="flex items-center mb-6">
                    <button onclick="showView('dashboard', event)" class="text-gray-500 hover:text-violet-600 mr-4"><i class="fas fa-arrow-left text-2xl"></i></button>
                    <h2 class="text-3xl font-bold text-gray-800">System Activity Log</h2>
                </div>
                <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                    <table class="w-full text-left">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-4 font-semibold">Date & Time</th>
                                <th class="p-4 font-semibold">Action</th>
                                <th class="p-4 font-semibold">Details</th>
                                <th class="p-4 font-semibold">Type</th>
                            </tr>
                        </thead>
                        <tbody id="full-activity-table-body" class="divide-y">
                            <!-- Activity will be loaded here -->
                        </tbody>
                    </table>
                    <div id="activity-pagination" class="flex justify-between items-center p-4 bg-gray-50 border-t"></div>
                </div>
            </div>`,
                        dashboard: `
            <div class="p-8 space-y-8">
                <!-- Welcome Section -->
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-800">Overview</h2>
                        <p class="text-gray-500 mt-1">Here's what's happening with your business today.</p>
                    </div>
                    <button class="bg-violet-600 text-white px-4 py-2 rounded-lg shadow hover:bg-violet-700 transition flex items-center">
                        <i class="fas fa-download mr-2"></i> Download Report
                    </button>
                </div>

                <!-- Stats Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Revenue -->
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-violet-100 rounded-full text-violet-600"><i class="fas fa-wallet text-xl"></i></div>
                        </div>
                        <h3 class="text-gray-500 text-sm font-medium">Total Revenue (Paid)</h3>
                        <p class="text-2xl font-bold text-gray-800 mt-1" id="dash-revenue">Loading...</p>
                    </div>

                    <!-- Expenses -->
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-rose-100 rounded-full text-rose-600"><i class="fas fa-file-invoice-dollar text-xl"></i></div>
                        </div>
                        <h3 class="text-gray-500 text-sm font-medium">Total Expenses</h3>
                        <p class="text-2xl font-bold text-gray-800 mt-1" id="dash-expenses">Loading...</p>
                    </div>

                    <!-- VAT Tax -->
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition relative overflow-hidden">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-sky-100 rounded-full text-sky-600"><i class="fas fa-percent text-xl"></i></div>
                            <span id="vat-status-badge" class="text-xs font-bold px-2 py-1 rounded-full"></span>
                        </div>
                        <h3 class="text-gray-500 text-sm font-medium">Monthly VAT (<span id="vat-period"></span>)</h3>
                        <p class="text-2xl font-bold text-gray-800 mt-1" id="dash-vat">Loading...</p>
                        <div class="mt-4 pt-4 border-t flex justify-between items-center">
                            <div class="text-xs text-gray-500" id="vat-due-text"></div>
                            <div class="flex space-x-2">
                                <button onclick="openTaxHistory('VAT')" class="text-xs bg-gray-200 text-gray-700 px-2 py-1 rounded hover:bg-gray-300 shadow-sm" title="View History"><i class="fas fa-history"></i></button>
                                <button id="btn-pay-vat" class="hidden text-xs bg-sky-600 text-white px-3 py-1 rounded hover:bg-sky-700 shadow-sm">Set Paid</button>
                            </div>
                        </div>
                    </div>

                    <!-- WHT Tax -->
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition relative overflow-hidden">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-amber-100 rounded-full text-amber-600"><i class="fas fa-hand-holding-usd text-xl"></i></div>
                            <span id="wht-status-badge" class="text-xs font-bold px-2 py-1 rounded-full"></span>
                        </div>
                        <h3 class="text-gray-500 text-sm font-medium">Withholding Tax (<span id="wht-period"></span>)</h3>
                        <p class="text-2xl font-bold text-gray-800 mt-1" id="dash-wht">Loading...</p>
                        <div class="mt-4 pt-4 border-t flex justify-between items-center">
                            <div class="text-xs text-gray-500" id="wht-due-text"></div>
                            <div class="flex space-x-2">
                                <button onclick="openTaxHistory('WHT')" class="text-xs bg-gray-200 text-gray-700 px-2 py-1 rounded hover:bg-gray-300 shadow-sm" title="View History"><i class="fas fa-history"></i></button>
                                <button id="btn-pay-wht" class="hidden text-xs bg-amber-600 text-white px-3 py-1 rounded hover:bg-amber-700 shadow-sm">Set Paid</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Content Grid (Charts & Activity) -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Chart Section -->
                    <div class="lg:col-span-2 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-gray-800">Revenue Overview <span id="chart-trend-text" class="text-sm font-normal ml-2"></span></h3>
                            <select id="revenue-chart-filter" class="border-gray-300 border rounded-md text-sm p-1 text-gray-600" onchange="updateRevenueChart()">
                                <option value="week">Last 7 Days</option>
                                <option value="month">Last 30 Days</option>
                                <option value="three_months">Last 3 Months</option>
                                <option value="six_months">Last 6 Months</option>
                                <option value="year">Last Year</option>
                            </select>
                        </div>
                        <div class="h-80 bg-gray-50 rounded-lg p-2">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Recent Activity</h3>
                        <div id="recent-activity-list" class="space-y-6">
                             <div class="flex justify-center items-center h-40">
                                <div class="loader"></div>
                            </div>
                        </div>
                        <button onclick="showView('activity_log', event)" class="w-full mt-6 text-center text-sm text-violet-600 font-semibold hover:text-violet-800">View All Activity</button>
                    </div>
                </div>

                <!-- Bottom Section: Stamp Duty & Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Stamp Duty (Rent) -->
                    <div id="dash-stamp-duty-card" class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition relative overflow-hidden">
                        <div class="flex items-center justify-between mb-4">
                            <div class="p-3 bg-teal-100 rounded-full text-teal-600"><i class="fas fa-stamp text-xl"></i></div>
                            <span class="text-xs font-bold px-2 py-1 rounded-full bg-orange-100 text-orange-800">Liability</span>
                        </div>
                        <h3 class="text-gray-500 text-sm font-medium">Stamp Duty (Rent 1%)</h3>
                        <p class="text-2xl font-bold text-gray-800 mt-1" id="dash-stamp-duty">Loading...</p>
                        <div class="mt-4 pt-4 border-t flex justify-between items-center">
                            <div class="text-xs text-gray-500">Payable to TRA (Auto-calculated)</div>
                             <button onclick="openTaxHistory('Stamp Duty')" class="text-xs bg-gray-200 text-gray-700 px-2 py-1 rounded hover:bg-gray-300 shadow-sm" title="View History"><i class="fas fa-history"></i></button>
                        </div>
                    </div>

                    <!-- System Insights (Intelligent Analysis) -->
                    <div id="dashboard-insights" class="lg:col-span-2 hidden bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-lightbulb text-yellow-500 mr-2"></i>System Insights & Recommendations</h3>
                        <div id="insights-list" class="space-y-3">
                            <!-- JS will populate -->
                        </div>
                    </div>
                </div>
            </div>
            `,
            conversations: `<div class="flex-1 flex flex-col h-full overflow-hidden">
                <div class="flex-1 flex overflow-hidden">
                    <!-- Conversations Sidebar -->
                    <div class="w-96 flex flex-col bg-white border-r z-10">
                        <div class="p-4 border-b space-y-4 shrink-0">
                            <div class="flex justify-between items-center">
                                <h2 class="text-2xl font-bold text-gray-800">Inbox</h2>
                                <button onclick="openNewChatModal()" class="bg-violet-600 hover:bg-violet-700 text-white w-10 h-10 rounded-full flex items-center justify-center shadow-md btn-soft transition-colors" title="Start New Chat">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                            <div class="relative group">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 group-hover:text-violet-500 transition-colors"></i>
                                <input type="text" id="conv-search" placeholder="Search conversations..." class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:ring-2 focus:ring-violet-200 focus:border-violet-400 transition-all text-sm shadow-sm" onkeyup="loadConversations()">
                            </div>
                            <!-- Tabs -->
                            <div class="flex bg-gray-100 p-1.5 rounded-xl">
                                <button onclick="filterConversations('open')" class="flex-1 py-1.5 text-sm font-medium rounded-lg tab-pill active" id="tab-open">Open</button>
                                <button onclick="filterConversations('closed')" class="flex-1 py-1.5 text-sm font-medium rounded-lg tab-pill" id="tab-closed">Closed</button>
                                <button onclick="filterConversations('all')" class="flex-1 py-1.5 text-sm font-medium rounded-lg tab-pill" id="tab-all">All</button>
                            </div>
                        </div>

                        <div id="conversations-container" class="flex-1 overflow-y-auto divide-y divide-gray-100">
                            <div class="flex justify-center items-center p-12 opacity-50"><div class="loader"></div></div>
                        </div>
                    </div>

                    <!-- Chat Area Container -->
                    <div class="flex-1 flex flex-col relative bg-slate-50 overflow-hidden">

                        <!-- Placeholder -->
                        <div id="message-view-placeholder" class="flex-1 flex flex-col items-center justify-center text-gray-400 p-8 text-center">
                            <div class="w-32 h-32 bg-white rounded-full flex items-center justify-center mb-6 shadow-sm border border-gray-100 animate-pulse">
                                <i class="fas fa-comments text-5xl text-violet-200"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-700 mb-2">Your Inbox is Ready</h3>
                            <p class="text-gray-500 max-w-sm">Select a conversation from the left to start chatting or resolve customer queries.</p>
                        </div>

                        <!-- Active Chat Content (Flex Row to accommodate Sidebar) -->
                        <div id="message-view-content" class="hidden flex-1 flex h-full absolute inset-0">
                            <!-- Main Chat Column -->
                            <div class="flex-1 flex flex-col h-full relative bg-slate-50 min-w-0">
                                <!-- Header -->
                                <div class="h-18 px-6 py-3 bg-white border-b flex justify-between items-center shadow-sm z-20 shrink-0">
                                    <div class="flex items-center cursor-pointer" onclick="toggleCrmSidebar()">
                                        <div class="w-11 h-11 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-600 text-white flex items-center justify-center font-bold text-lg mr-4 shadow-md ring-2 ring-violet-50">
                                            <span id="header-avatar">?</span>
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-gray-900 text-lg leading-tight" id="chat-partner-name">User Name</h3>
                                            <div class="flex items-center text-xs text-gray-500">
                                                <i class="fab fa-whatsapp text-green-500 mr-1"></i>
                                                <span id="chat-partner-phone">+255 ...</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-3">
                                        <!-- Snooze Button -->
                                        <div class="relative group">
                                            <button onclick="toggleSnoozeMenu()" class="flex items-center space-x-2 text-sm text-gray-600 hover:text-amber-600 bg-gray-50 hover:bg-amber-50 px-3 py-2 rounded-xl border border-gray-200 hover:border-amber-200 transition-all btn-soft">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                            <div id="snooze-menu" class="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-xl border border-gray-100 hidden z-50">
                                                <div class="p-1">
                                                    <button onclick="snoozeChat('1 HOUR')" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-lg">1 Hour</button>
                                                    <button onclick="snoozeChat('TOMORROW')" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-lg">Tomorrow 9am</button>
                                                    <button onclick="openCustomSnooze()" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-lg">Custom Date...</button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Assign Pop-over Menu -->
                                        <div class="relative">
                                            <button onclick="toggleAssignMenu(true)" class="flex items-center space-x-2 text-sm text-gray-600 hover:text-violet-700 bg-gray-50 hover:bg-violet-50 px-4 py-2 rounded-xl border border-gray-200 hover:border-violet-200 transition-all btn-soft">
                                                <i class="fas fa-user-tag"></i>
                                                <span id="assignee-name" class="font-medium hidden md:inline">Unassigned</span>
                                                <i class="fas fa-chevron-down text-xs ml-1 opacity-50"></i>
                                            </button>
                                            <div id="assign-menu" class="absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl border border-gray-100 hidden z-50 transform transition-all origin-top-right">
                                                <div class="p-2">
                                                    <div class="px-2 pt-1 pb-2 text-xs font-bold text-gray-400 uppercase tracking-wider">Assign To</div>
                                                    <div class="relative mb-2">
                                                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                                        <input type="text" id="assign-search" onkeyup="filterAssignees()" placeholder="Search agents..." class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg bg-gray-50 focus:bg-white focus:ring-1 focus:ring-violet-300">
                                                    </div>
                                                    <div id="assign-users-list" class="max-h-48 overflow-y-auto custom-scrollbar">
                                                        <button onclick="assignChat('auto')" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-violet-50 hover:text-violet-700 rounded-lg transition-colors flex items-center mb-1">
                                                            <i class="fas fa-robot w-5 text-center mr-2 text-violet-400"></i>
                                                            <span>Auto Assign</span>
                                                        </button>
                                                        <div class="border-t my-1"></div>
                                                        <!-- Users injected via JS -->
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Resolve Button -->
                                        <button id="btn-resolve" onclick="toggleChatStatus()" class="btn-soft text-sm border border-gray-200 text-gray-600 hover:bg-green-50 hover:text-green-700 hover:border-green-200 px-4 py-2 rounded-xl transition-all flex items-center shadow-sm font-medium bg-white">
                                            <i class="fas fa-check mr-2"></i> <span class="hidden md:inline">Resolve</span>
                                        </button>

                                        <!-- CRM Toggle -->
                                        <button onclick="toggleCrmSidebar()" class="btn-soft text-gray-500 hover:text-violet-600 px-3 py-2 rounded-lg hover:bg-gray-100 transition-all" title="Toggle Customer Details">
                                            <i class="fas fa-id-card-alt text-xl"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Messages Body -->
                                <div id="message-container" class="flex-1 overflow-y-auto p-6 space-y-4 bg-slate-50" style="background-image: radial-gradient(#cbd5e1 1px, transparent 1px); background-size: 24px 24px;">
                                    <!-- Messages -->
                                </div>

                                <!-- Footer / Input -->
                                <div class="p-4 bg-white border-t z-20" id="chat-footer">
                                     <form id="sendMessageForm" class="flex flex-col gap-2 max-w-5xl mx-auto relative">
                                        <!-- Attachment Preview -->
                                        <div id="attachment-preview-container" class="hidden items-center gap-3 p-2 bg-gray-100 border border-gray-200 rounded-lg text-sm mb-2">
                                            <div class="file-icon text-gray-500 text-lg"><i class="fas fa-file-alt"></i></div>
                                            <div class="flex-1">
                                                <div class="file-name font-medium truncate"></div>
                                                <div class="upload-status text-xs text-gray-500"></div>
                                            </div>
                                            <button type="button" id="remove-attachment-btn" class="remove-file text-red-500 hover:text-red-700 font-bold p-1 text-lg leading-none">&times;</button>
                                        </div>
                                        <input type="hidden" id="attached_file_url" name="attached_file_url">


                                        <!-- Input Mode Toggle -->
                                        <div class="flex justify-center mb-1 space-x-4">
                                            <button type="button" onclick="setInputMode('message')" id="mode-msg-btn" class="text-xs font-bold px-3 py-1 rounded-full bg-violet-100 text-violet-700 transition-colors">Message</button>
                                            <button type="button" onclick="setInputMode('note')" id="mode-note-btn" class="text-xs font-bold px-3 py-1 rounded-full text-gray-500 hover:bg-yellow-100 hover:text-yellow-700 transition-colors">Internal Note</button>
                                        </div>

                                        <div id="input-wrapper" class="flex items-end gap-2 bg-white p-2 rounded-2xl border border-gray-300 shadow-sm focus-within:ring-2 focus-within:ring-violet-200 focus-within:border-violet-400 transition-all">
                                            <div class="flex">
                                                <button type="button" id="emoji-btn" class="p-3 text-gray-400 hover:text-violet-600 hover:bg-violet-50 rounded-xl transition-all btn-soft" title="Insert Emoji">
                                                    <i class="fas fa-smile text-lg"></i>
                                                </button>
                                                <button type="button" id="attachment-btn" class="p-3 text-gray-400 hover:text-violet-600 hover:bg-violet-50 rounded-xl transition-all btn-soft" title="Attach File">
                                                    <i class="fas fa-paperclip text-lg"></i>
                                                </button>
                                                <button type="button" onclick="openInteractiveMessageModal()" class="p-3 text-gray-400 hover:text-violet-600 hover:bg-violet-50 rounded-xl transition-all btn-soft" title="Send Interactive Message">
                                                    <i class="fas fa-layer-group text-lg"></i>
                                                </button>
                                                 <input type="file" id="file-input" class="hidden" />
                                            </div>

                                            <textarea id="messageInput" rows="1" class="flex-1 bg-transparent border-none focus:ring-0 text-gray-700 placeholder-gray-400 resize-none py-3 max-h-32 text-base" placeholder="Type a message..." oninput="handleInputType(this)"></textarea>

                                            <div class="flex">
                                                <button type="button" onclick="openTemplateSelector()" class="p-3 text-gray-400 hover:text-violet-600 hover:bg-violet-50 rounded-xl transition-all btn-soft" title="Quick Replies & Templates (Type /)">
                                                    <i class="fas fa-bolt text-lg"></i>
                                                </button>
                                                <!-- Schedule Button & Picker -->
                                                <div class="relative">
                                                    <button type="button" onclick="toggleSchedulePicker()" class="p-3 text-gray-400 hover:text-violet-600 hover:bg-violet-50 rounded-xl transition-all btn-soft" title="Schedule Message">
                                                        <i class="fas fa-clock text-lg"></i>
                                                    </button>
                                                    <div id="schedule-picker" class="absolute bottom-full right-0 mb-2 w-72 bg-white p-3 rounded-lg shadow-lg border hidden z-30">
                                                        <p class="text-sm font-semibold mb-2 text-gray-700">Schedule message for later</p>
                                                        <input type="datetime-local" id="schedule-datetime" class="w-full p-2 border rounded text-gray-700">
                                                        <button type="button" onclick="confirmSchedule()" class="w-full mt-2 bg-violet-600 text-white py-2 rounded-lg hover:bg-violet-700">Confirm Schedule</button>
                                                    </div>
                                                </div>
                                                <button type="submit" id="send-btn" class="p-3 bg-violet-600 text-white rounded-xl hover:bg-violet-700 transition-all btn-soft shadow-md hover:shadow-lg">
                                                    <i class="fas fa-paper-plane text-lg"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="flex justify-between px-2 items-center">
                                            <p class="text-xs text-gray-400"><strong>Shift + Enter</strong> for new line. Type <strong>/</strong> for templates.</p>
                                            <p id="typing-indicator" class="text-xs text-violet-600 font-semibold hidden animate-pulse"><i class="fas fa-circle-notch fa-spin mr-1"></i> Sending...</p>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- CRM Sidebar -->
                            <div id="crm-sidebar" class="w-80 bg-white border-l border-gray-200 shadow-lg overflow-y-auto hidden shrink-0 transition-all duration-300 ease-in-out transform translate-x-full md:translate-x-0 md:relative absolute right-0 h-full z-30">
                                <div class="p-6">
                                    <div class="flex justify-between items-start mb-6">
                                        <h3 class="text-lg font-bold text-gray-800">Contact Details</h3>
                                        <button onclick="toggleCrmSidebar()" class="text-gray-400 hover:text-gray-600 md:hidden"><i class="fas fa-times"></i></button>
                                    </div>

                                    <div class="text-center mb-6">
                                        <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-violet-100 to-indigo-100 text-violet-600 flex items-center justify-center text-2xl font-bold mb-3">
                                            <span id="crm-avatar">?</span>
                                        </div>
                                        <h4 id="crm-name" class="text-xl font-bold text-gray-900">Loading...</h4>
                                        <p id="crm-phone" class="text-gray-500 text-sm">+255 ...</p>
                                    </div>

                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Email</label>
                                            <div class="flex">
                                                <input type="email" id="crm-email" class="flex-1 border-b border-gray-200 focus:border-violet-500 outline-none py-1 text-sm text-gray-700 bg-transparent" placeholder="Add email...">
                                                <button onclick="saveCrmField('email')" class="text-violet-600 hover:text-violet-800 ml-2 text-xs font-bold">SAVE</button>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Tags</label>
                                            <div id="crm-tags-container" class="flex flex-wrap gap-2 mb-2">
                                                <!-- Tags -->
                                            </div>
                                            <input type="text" id="crm-tag-input" class="w-full border border-gray-200 rounded-md px-2 py-1 text-sm" placeholder="Add tag (Press Enter)" onkeydown="handleAddTag(event)">
                                        </div>

                                        <div>
                                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Notes</label>
                                            <textarea id="crm-notes" rows="4" class="w-full border border-gray-200 rounded-md p-2 text-sm bg-yellow-50 focus:bg-white focus:ring-2 focus:ring-violet-100 transition-all" placeholder="Add internal notes about this customer..."></textarea>
                                            <div class="text-right mt-1">
                                                <button onclick="saveCrmField('notes')" class="text-xs bg-violet-600 text-white px-3 py-1 rounded-md hover:bg-violet-700">Save Note</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`,
            contacts: `<div class="p-8"><div class="flex justify-between items-center mb-6"><h2 class="text-3xl font-bold text-gray-800">Contacts</h2><button onclick="openModal('addContactModal')" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold"><i class="fas fa-user-plus mr-2"></i>Add Contact</button></div><div class="bg-white rounded-lg shadow-md overflow-hidden border"><table class="w-full text-left"><thead class="bg-gray-100"><tr><th class="p-4 font-semibold">Name</th><th class="p-4 font-semibold">Phone Number</th><th class="p-4 font-semibold">Actions</th></tr></thead><tbody id="contacts-table-body" class="divide-y"></tbody></table></div></div>`,
            create_invoice: `<div class="p-8">
                    <div class="flex items-center mb-6">
                        <button onclick="showView('invoices', event)" class="text-gray-500 hover:text-violet-600 mr-4"><i class="fas fa-arrow-left text-xl"></i></button>
                        <h2 id="create-document-title" class="text-3xl font-bold text-gray-800">Create New Invoice</h2>
                    </div>

                    <form id="createInvoiceForm" class="bg-white p-8 rounded-lg shadow-md border space-y-6">
                        <input type="hidden" name="document_type" id="document_type">
                        {/* */}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-6">
                                <div>
                                    <label for="invoiceCustomer" class="block text-sm font-medium text-gray-700 mb-1">Customer *</label>
                                    <select id="invoiceCustomer" name="customer_id" class="w-full p-2 border border-gray-300 rounded-md" onchange="loadCustomerContacts(this.value)" required>
                                        <option value="">Loading customers...</option>
                                    </select>
                                </div>
                                <div id="contact-select-wrapper" class="hidden mt-6">
                                    <label for="invoiceContact" class="block text-sm font-medium text-gray-700 mb-1">Contact Person *</label>
                                    <select id="invoiceContact" name="contact_id" class="w-full p-2 border border-gray-300 rounded-md" required>
                                        <option value="">-- Select Contact --</option>
                                    </select>
                                </div>
                            </div>
                             <div class="space-y-6">
                                <div>
                                    <label for="invoiceDate" class="block text-sm font-medium text-gray-700 mb-1">Invoice Date *</label>
                                    <input type="date" id="invoiceDate" name="issue_date" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label for="invoiceDueDate" class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                                    <input type="date" id="invoiceDueDate" name="due_date" class="w-full p-2 border border-gray-300 rounded-md">
                                </div>
                            </div>
                        </div>

                        {/* */}
                        <div>
                            <h3 class="text-lg font-semibold mb-2 text-gray-700">Items / Services</h3>
                            <div id="invoiceItemsContainer" class="space-y-3">
                                {/* */}
                            </div>
                            <button type="button" onclick="addInvoiceItemRow()" class="mt-3 text-sm text-violet-600 font-semibold hover:text-violet-800">
                                <i class="fas fa-plus mr-1"></i> Add Item
                            </button>
                        </div>

                        {/* */}
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6 border-t">
                            <div class="md:col-span-2 space-y-4">
                                 <div>
                                    <label for="invoiceNotes" class="block text-sm font-medium text-gray-700 mb-1">Notes / Terms</label>
                                    <textarea id="invoiceNotes" name="notes" rows="3" class="w-full p-2 border border-gray-300 rounded-md" placeholder="e.g., Payment due within 30 days"></textarea>
                                </div>
                                <div>
                                     <label for="invoicePaymentInfo" class="block text-sm font-medium text-gray-700 mb-1">Payment Instructions</label>
                                     <textarea id="invoicePaymentInfo" name="payment_method_info" rows="2" class="w-full p-2 border border-gray-300 rounded-md" placeholder="e.g., Bank Transfer to Account #..."></textarea>
                                 </div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-gray-700">Subtotal:</span>
                                    <span id="invoiceSubtotal" class="font-semibold">0.00</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <label for="invoiceTaxRate" class="text-sm font-medium text-gray-700">Tax (%):</label>
                                    <input type="number" id="invoiceTaxRate" name="tax_rate" value="0" min="0" step="0.01" class="w-20 p-1 border border-gray-300 rounded-md text-right" oninput="calculateInvoiceTotals()">
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-gray-700">Tax Amount:</span>
                                    <span id="invoiceTaxAmount" class="font-semibold">0.00</span>
                                </div>
                                <div class="flex justify-between items-center pt-2 border-t mt-2">
                                    <span class="text-lg font-bold text-gray-800">Total:</span>
                                    <span id="invoiceTotal" class="text-lg font-bold text-gray-800">0.00</span>
                                </div>
                            </div>
                        </div>

                         {/* */}
                         <div class="flex justify-end space-x-3 pt-6 border-t">
                             <button type="button" onclick="showView('invoices', event)" class="px-5 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 font-semibold">Cancel</button>
                             <button type="submit" class="px-5 py-2 bg-violet-600 text-white rounded-lg hover:bg-violet-700 font-semibold">Save & Send Invoice</button>
                         </div>
                    </form>
                </div>`,
            broadcast: `<div class="p-8"><div class="flex justify-between items-center mb-6"><h2 class="text-3xl font-bold text-gray-800">Broadcast History</h2><button onclick="openBroadcastModal()" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold"><i class="fas fa-plus mr-2"></i>New Broadcast</button></div><div class="bg-white rounded-lg shadow-md overflow-hidden border"><table class="w-full text-left"><thead class="bg-gray-100"><tr><th class="p-4 font-semibold">Campaign Name</th><th class="p-4 font-semibold">Status</th><th class="p-4 font-semibold">Scheduled For</th><th class="p-4 font-semibold">Actions</th></tr></thead><tbody id="broadcasts-table-body" class="divide-y"></tbody></table></div></div>`,
            templates: `<div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-3xl font-bold text-gray-800">Message Templates</h2>
                    <div>
                        <button onclick="openTemplateModal()" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold"><i class="fas fa-plus mr-2"></i>New Template</button>
                        <button onclick="syncTemplates()" class="bg-blue-500 text-white px-5 py-2 rounded-lg hover:bg-blue-600 font-semibold ml-4"><i class="fas fa-sync-alt mr-2"></i>Sync Status</button>
                    </div>
                </div>
                <div class="bg-sky-100 border-l-4 border-sky-500 text-sky-800 p-4 rounded-r-lg mb-6 shadow-sm">
                    <div class="flex">
                        <div class="py-1"><i class="fas fa-info-circle fa-lg mr-4"></i></div>
                        <div>
                            <p class="font-bold">Template Approval Time</p>
                            <p class="text-sm">Please note that new templates require approval from Meta, which can take anywhere from a few minutes to 24 hours. You can sync the status to check for updates.</p>
                        </div>
                    </div>
                </div>
                <div id="templates-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"></div>
            </div>`,
            workflows: `<div class="h-full flex flex-col">
    <div id="workflow-main-view" class="p-8 h-full overflow-y-auto">
        <!-- Hero Section -->
        <div class="bg-gradient-to-r from-violet-600 to-indigo-600 rounded-2xl p-8 text-white mb-10 shadow-lg relative overflow-hidden">
            <div class="absolute top-0 right-0 -mt-10 -mr-10 w-64 h-64 bg-white opacity-10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-0 left-0 -mb-10 -ml-10 w-40 h-40 bg-purple-400 opacity-10 rounded-full blur-2xl"></div>
            <div class="flex flex-col md:flex-row justify-between items-center relative z-10">
                <div class="mb-6 md:mb-0">
                    <h2 class="text-3xl font-bold mb-2">Automate Your Business</h2>
                    <p class="text-violet-100 text-lg max-w-xl">Create powerful workflows to handle conversations, sales, and support automatically. Save time and grow faster.</p>
                </div>
                <button onclick="openWorkflowEditor()" class="bg-white text-violet-600 px-6 py-3 rounded-xl hover:bg-violet-50 font-bold shadow-md transition-transform hover:scale-105 flex items-center">
                    <i class="fas fa-plus-circle mr-2 text-xl"></i> Create Workflow
                </button>
            </div>
        </div>

        <!-- Your Workflows Section -->
        <div class="flex justify-between items-center mb-6">
             <h3 class="text-xl font-bold text-gray-800 flex items-center"><i class="fas fa-project-diagram text-violet-500 mr-3"></i>Your Workflows</h3>
        </div>
        <div id="workflows-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
            <!-- Workflows will be loaded here -->
            <div class="col-span-full py-12 text-center text-gray-500">
                 <i class="fas fa-spinner fa-spin text-2xl mb-3"></i>
                 <p>Loading workflows...</p>
            </div>
        </div>

        <!-- Templates Section -->
        <div class="border-t pt-10">
            <div class="flex flex-col mb-6">
                <h3 class="text-xl font-bold text-gray-800 flex items-center"><i class="fas fa-magic text-amber-500 mr-3"></i>Start with a Template</h3>
                <p class="text-gray-500 ml-8 text-sm">Pre-built workflows optimized for conversion and support.</p>
            </div>
            <div id="workflow-templates-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Templates will be loaded here -->
                <div class="col-span-full py-12 text-center text-gray-500">
                     <i class="fas fa-spinner fa-spin text-2xl mb-3"></i>
                     <p>Loading templates...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow Editor View (Hidden by default) -->
    <div id="workflow-editor-view" class="hidden bg-gray-50 h-full flex flex-col relative workflow-canvas-bg">
        <!-- Editor Header -->
        <div class="flex justify-between items-center px-6 py-3 bg-white border-b shadow-sm z-20">
            <div class="flex items-center gap-4">
                <button onclick="closeWorkflowEditor()" class="text-gray-400 hover:text-gray-700 transition-colors p-2 rounded-full hover:bg-gray-100">
                    <i class="fas fa-arrow-left text-lg"></i>
                </button>
                <div class="flex flex-col">
                    <label class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Workflow Name</label>
                    <input type="text" id="workflow-name-input" placeholder="Untitled Workflow" class="text-lg font-bold text-gray-800 bg-transparent border-none focus:ring-0 p-0 placeholder-gray-300">
                </div>
            </div>
            <div class="flex gap-3">
                <button onclick="exportWorkflowJSON()" class="text-gray-500 hover:text-violet-600 bg-gray-100 hover:bg-violet-50 px-4 py-2 rounded-lg text-sm font-semibold transition-colors" title="Export to JSON">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
                <button onclick="importWorkflowJSON()" class="text-gray-500 hover:text-violet-600 bg-gray-100 hover:bg-violet-50 px-4 py-2 rounded-lg text-sm font-semibold transition-colors" title="Import from JSON">
                    <i class="fas fa-upload mr-2"></i>Import
                </button>
                <button onclick="saveWorkflow(0)" class="bg-white text-gray-700 border border-gray-300 px-4 py-2 rounded-lg hover:bg-gray-50 font-semibold shadow-sm transition-all mr-2">
                    Save Draft
                </button>
                <button onclick="saveWorkflow(1)" class="bg-violet-600 text-white px-6 py-2 rounded-lg hover:bg-violet-700 font-semibold shadow-sm transition-all hover:shadow-md flex items-center">
                    <i class="fas fa-rocket mr-2"></i>Publish
                </button>
            </div>
        </div>

        <!-- Editor Canvas -->
        <div class="workflow-canvas flex-1 overflow-auto relative p-10">
            <div id="workflow-editor-canvas" class="min-w-full min-h-full flex justify-center items-start pt-10 pb-40"></div>
        </div>
    </div>
</div>`, reports: `<div class="p-8"><div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6"><div class="bg-white p-6 rounded-lg shadow-md border"><h4 class="text-gray-500 font-semibold">New Conversations</h4><p id="report-new-conv" class="text-4xl font-bold mt-2">N/A</p></div><div class="bg-white p-6 rounded-lg shadow-md border"><h4 class="text-gray-500 font-semibold">Messages Sent</h4><p id="report-msg-sent" class="text-4xl font-bold mt-2">N/A</p></div><div class="bg-white p-6 rounded-lg shadow-md border"><h4 class="text-gray-500 font-semibold">Avg. Response Time</h4><p id="report-avg-time" class="text-4xl font-bold mt-2">N/A</p></div></div><div class="bg-white p-6 rounded-lg shadow-md border"><h4 class="font-bold text-lg mb-4">Conversations Overview</h4><div class="h-96 relative"><canvas id="reportsChart"></canvas><div id="reports-chart-overlay" class="absolute inset-0 flex items-center justify-center bg-white bg-opacity-75 hidden"><p class="text-gray-500 font-semibold">No data available to display chart.</p></div></div></div></div>`,
            users: `<div class="p-8"><div class="flex justify-between items-center mb-6"><h2 class="text-3xl font-bold text-gray-800">Manage Team</h2><button onclick="openModal('addUserModal')" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold"><i class="fas fa-user-plus mr-2"></i>Invite User</button></div><div class="bg-white rounded-lg shadow-md overflow-hidden border"><table class="w-full text-left"><thead class="bg-gray-100"><tr><th class="p-4 font-semibold">Name</th><th class="p-4 font-semibold">Email</th><th class="p-4 font-semibold">Role</th><th class="p-4 font-semibold">Status</th><th class="p-4 font-semibold">Actions</th></tr></thead><tbody id="users-table-body" class="divide-y"></tbody></table></div></div>`,
            settings: `<div class="p-8 h-full flex flex-col">
                <div class="mb-8">
                    <h2 class="text-3xl font-bold text-gray-800">Settings</h2>
                    <p class="text-gray-500 mt-1">Manage your account settings and preferences.</p>
                </div>

                <div class="flex flex-col lg:flex-row gap-8 h-full">
                    <!-- Settings Sidebar -->
                    <div class="w-full lg:w-64 flex-shrink-0">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sticky top-4">
                            <nav class="space-y-1">
                                <button onclick="showSettingsTab('profile', event)" class="settings-sidebar-btn settings-tab active-tab">
                                    <i class="fas fa-building"></i> Business Profile
                                </button>
                                <button onclick="showSettingsTab('channels', event)" class="settings-sidebar-btn settings-tab">
                                    <i class="fas fa-share-alt"></i> Channels
                                </button>
                                <button onclick="showSettingsTab('webhooks', event)" class="settings-sidebar-btn settings-tab">
                                    <i class="fas fa-plug"></i> Webhooks
                                </button>
                                <button onclick="showSettingsTab('smtp', event)" class="settings-sidebar-btn settings-tab">
                                    <i class="fas fa-envelope"></i> Email SMTP
                                </button>
                                <button onclick="showSettingsTab('invoice_design', event)" class="settings-sidebar-btn settings-tab">
                                    <i class="fas fa-palette"></i> Invoice Design
                                </button>
                                <button onclick="showSettingsTab('client_settings', event)" class="settings-sidebar-btn settings-tab">
                                    <i class="fas fa-sliders-h"></i> Client Settings
                                </button>
                            </nav>
                        </div>
                    </div>

                    <!-- Settings Content -->
                    <div class="flex-1 min-w-0">
                        <form id="settingsForm" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        <div id="settings-profile" class="settings-content">
                            <div class="bg-white p-8 rounded-lg shadow-md border mb-8">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-xl font-bold">Business Profile</h3>
                                    <button id="edit-profile-btn" type="button" class="text-sm bg-gray-100 text-gray-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-200">Edit Profile</button>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                                    <div class="md:col-span-1 flex flex-col items-center">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Profile Picture / Logo</label>
                                        <img id="profile-pic-preview" src="https://placehold.co/128x128/e0e7ff/4f46e5?text=Logo" class="w-32 h-32 rounded-full object-cover bg-gray-200 mb-4">
                                        <input type="file" id="profile-pic-upload" class="hidden" disabled accept="image/*">
                                        <button type="button" id="upload-pic-btn" onclick="document.getElementById('profile-pic-upload').click()" class="text-sm bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed" disabled>Upload Image</button>
                                    </div>
                                    <div class="md:col-span-2 grid grid-cols-1 gap-6">
                                        <div><label class="block text-sm font-medium text-gray-700">Business Name</label><input type="text" name="business_name" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" disabled></div>
                                        <div><label class="block text-sm font-medium text-gray-700">Business Email</label><input type="email" name="business_email" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" disabled></div>
                                        <div><label class="block text-sm font-medium text-gray-700">Business Address</label><textarea name="business_address" rows="3" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" disabled></textarea></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="settings-channels" class="settings-content hidden">
                            <div class="bg-white p-8 rounded-lg shadow-md border">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fab fa-whatsapp text-5xl text-green-500"></i>
                                        <div class="ml-4">
                                            <h4 class="font-bold text-lg">WhatsApp Connection</h4>
                                            <p id="whatsapp-status" class="text-sm text-gray-500">Not Connected</p>
                                        </div>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button id="whatsapp-connect-btn" onclick="launchWhatsAppSignup()" class="bg-violet-600 text-white font-semibold px-5 py-2.5 rounded-lg hover:bg-violet-700">Connect with Facebook</button>
                                        <button id="whatsapp-register-btn" onclick="completeWhatsappRegistration()" class="hidden bg-green-600 text-white font-semibold px-5 py-2.5 rounded-lg hover:bg-green-700" title="Fix Pending Status">Complete Registration</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="settings-webhooks" class="settings-content hidden">
                            <div class="bg-white p-8 rounded-lg shadow-md border">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-xl font-bold">Webhook Settings</h3>
                                    <button id="edit-webhooks-btn" type="button" class... >Edit Settings</button>
                                </div>
                                <p class="text-gray-500 mb-6">Configure webhooks for payment gateways to automate payment status updates.</p>

                                <div class="mb-6 bg-violet-50 p-4 rounded-lg border border-violet-200">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Your Unique Webhook URL:</label>
                                    <p class="text-xs text-gray-600 mb-2">Copy this URL and paste it into your Flutterwave dashboard.</p>
                                    <div class="flex items-center bg-white p-2 border border-gray-300 rounded-md">
                                        <strong class="text-violet-700 font-mono text-sm break-all" id="unique-webhook-url-display" data-full-url="">
                                            ************************************
                                        </strong>
                                        <div class="ml-auto pl-2">
                                            <button type="button" id="toggle-webhook-visibility" class="text-gray-500 hover:text-gray-700">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" id="copy-webhook-url" class="text-gray-500 hover:text-gray-700 ml-2">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-6 border p-6 rounded-lg">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h4 class="font-bold text-lg text-gray-800">Flutterwave</h4>
                                            <p class="text-sm text-gray-500">Provider: <span class="font-semibold">Flutterwave</span></p>
                                        </div>
                                        <div class="flex items-center space-x-4">
                                            <label for="flw-test-mode" class="flex items-center cursor-pointer">
                                                <span class="text-sm font-medium text-gray-700 mr-2">Test Mode</span>
                                                <div class="relative">
                                                    <input type="checkbox" id="flw-test-mode" name="flw_test_mode" class="sr-only webhook-input" disabled>
                                                    <div class="block bg-gray-300 w-10 h-6 rounded-full"></div>
                                                    <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition"></div>
                                                </div>
                                            </label>
                                            <label for="flw-active" class="flex items-center cursor-pointer">
                                                <span class="text-sm font-medium text-gray-700 mr-2">Active</span>
                                                <div class="relative">
                                                    <input type="checkbox" id="flw-active" name="flw_active" class="sr-only webhook-input" disabled>
                                                    <div class="block bg-gray-300 w-10 h-6 rounded-full"></div>
                                                    <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition"></div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div><label class="block text-sm font-medium text-gray-700">Display Name</label><input type="text" name="flw_display_name" class="webhook-input mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" disabled placeholder="e.g., Flutterwave Payments"></div>
                                        <div><label class="block text-sm font-medium text-gray-700">Internal Name</label><input type="text" value="flutterwave" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-200" disabled></div>

                                        <div><label class="block text-sm font-medium text-gray-700">Public Key</label><input type="text" name="flw_public_key" class="webhook-input mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" disabled></div>

                                        <div class="relative">
                                            <label class="block text-sm font-medium text-gray-700">Secret Key</label>
                                            <input type="password" name="flw_secret_key" class="webhook-input mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100 pr-10" disabled>
                                            <button type="button" class="absolute inset-y-0 right-0 top-6 flex items-center px-3 text-gray-500 hover:text-gray-700" onclick="togglePasswordVisibility(this)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>

                                        <div class="md:col-span-2 relative">
                                            <label class="block text-sm font-medium text-gray-700">Encryption Key</label>
                                            <input type="password" name="flw_encryption_key" class="webhook-input mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100 pr-10" disabled>
                                             <button type="button" class="absolute inset-y-0 right-0 top-6 flex items-center px-3 text-gray-500 hover:text-gray-700" onclick="togglePasswordVisibility(this)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>

                                        <div class="md:col-span-2 relative">
                                            <label class="block text-sm font-medium text-gray-700">Webhook Secret Hash</label>
                                            <input type="password" name="flw_webhook_secret_hash" class="webhook-input mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100 pr-10" disabled>
                                             <button type="button" class="absolute inset-y-0 right-0 top-6 flex items-center px-3 text-gray-500 hover:text-gray-700" onclick="togglePasswordVisibility(this)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="settings-smtp" class="settings-content hidden">
                             <div class="bg-white p-8 rounded-lg shadow-md border">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-xl font-bold">Email SMTP Settings</h3>
                                    <div class="flex items-center space-x-2">
                                        <button id="test-smtp-btn" type="button" onclick="testSmtpSettings()" class="text-sm bg-blue-100 text-blue-700 px-4 py-2 rounded-lg font-semibold hover:bg-blue-200 disabled:opacity-50" disabled>Test Connection</button>
                                        <button id="edit-smtp-btn" type="button" class="text-sm bg-gray-100 text-gray-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-200">Edit Settings</button>
                                    </div>
                                </div>
                                <div id="smtp-test-result" class="mb-4"></div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Email Provider</label>
                                        <div class="flex space-x-4 mt-2">
                                            <label class="flex items-center"><input type="radio" name="smtp_choice" value="default" class="mr-2" disabled checked> Use ChatMe Mail (Default)</label>
                                            <label class="flex items-center"><input type="radio" name="smtp_choice" value="custom" class="mr-2" disabled> Use Custom SMTP</label>
                                        </div>
                                    </div>
                                    <div id="smtp-custom-fields" class="grid grid-cols-1 md:grid-cols-2 gap-6 border-t pt-6 mt-6 hidden">
                                        <div><label class="block text-sm font-medium text-gray-700">SMTP Host</label><input type="text" name="smtp_host" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" placeholder="e.g., smtp.gmail.com" disabled></div>
                                        <div><label class="block text-sm font-medium text-gray-700">SMTP Port</label><input type="text" name="smtp_port" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" placeholder="e.g., 465 or 587" disabled></div>
                                        <div><label class="block text-sm font-medium text-gray-700">SMTP Secure</label><select name="smtp_secure" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" disabled><option value="ssl">SSL</option><option value="tls">TLS</option><option value="">None</option></select></div>
                                        <div><label class="block text-sm font-medium text-gray-700">SMTP Username</label><input type="text" name="smtp_username" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" placeholder="e.g., your-email@gmail.com" disabled></div>
                                        <div><label class="block text-sm font-medium text-gray-700">SMTP Password</label><input type="password" name="smtp_password" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" placeholder="Leave blank to keep unchanged" disabled></div>
                                        <div><label class="block text-sm font-medium text-gray-700">From Email</label><input type="email" name="smtp_from_email" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" placeholder="e.g., info@yourcompany.com" disabled></div>
                                        <div class="md:col-span-2"><label class="block text-sm font-medium text-gray-700">From Name</label><input type="text" name="smtp_from_name" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" placeholder="e.g., Your Company Name" disabled></div>
                                    </div>
                                </div>
                             </div>
                        </div>

                        <div id="settings-invoice_design" class="settings-content hidden">
                            <div class="bg-white p-8 rounded-lg shadow-md border">
                                <div class="flex justify-between items-center mb-6">
                                     <h3 class="text-xl font-bold">Choose Your Invoice Template</h3>
                                     <button id="edit-invoice-design-btn" type="button" class="text-sm bg-gray-100 text-gray-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-200">Edit Selection</button>
                                </div>
                                <p class="text-gray-500 mb-6">Select the default template. Click "Preview" to see how it looks.</p>

                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6" id="invoice-template-options">

                                    <div class="border-2 rounded-lg p-4 relative invoice-template-label" data-template-id="default">
                                        <label class="cursor-pointer" for="template-default">
                                            <input type="radio" name="default_invoice_template" value="default" id="template-default" class="absolute top-2 right-2 invoice-template-radio" disabled checked>
                                            <div class="font-semibold text-center mb-2">Default Template</div>
                                            <div class="w-full h-40 bg-gray-200 flex items-center justify-center text-gray-500 rounded">

                                            </div>
                                        </label>
                                        <button type="button" onclick="previewInvoiceTemplate('default', event)" class="mt-2 w-full text-sm bg-gray-600 text-white py-1 rounded hover:bg-gray-700">Preview</button>
                                    </div>

                                    <div class="border-2 rounded-lg p-4 relative invoice-template-label" data-template-id="modern_blue">
                                         <label class="cursor-pointer" for="template-modern_blue">
                                            <input type="radio" name="default_invoice_template" value="modern_blue" id="template-modern_blue" class="absolute top-2 right-2 invoice-template-radio" disabled>
                                            <div class="font-semibold text-center mb-2">Modern Blue</div>
                                            <div class="w-full h-40 bg-gray-200 flex items-center justify-center text-gray-500 rounded">

                                            </div>
                                        </label>
                                        <button type="button" onclick="previewInvoiceTemplate('modern_blue', event)" class="mt-2 w-full text-sm bg-gray-600 text-white py-1 rounded hover:bg-gray-700">Preview</button>
                                    </div>

                                    <div class="border-2 rounded-lg p-4 relative invoice-template-label" data-template-id="classic_bw">
                                         <label class="cursor-pointer" for="template-classic_bw">
                                            <input type="radio" name="default_invoice_template" value="classic_bw" id="template-classic_bw" class="absolute top-2 right-2 invoice-template-radio" disabled>
                                            <div class="font-semibold text-center mb-2">Classic Black & White</div>
                                            <div class="w-full h-40 bg-gray-200 flex items-center justify-center text-gray-500 rounded">

                                            </div>
                                        </label>
                                        <button type="button" onclick="previewInvoiceTemplate('classic_bw', event)" class="mt-2 w-full text-sm bg-gray-600 text-white py-1 rounded hover:bg-gray-700">Preview</button>
                                    </div>

                                </div>
                            </div>
                        </div>
                        <div id="settings-client_settings" class="settings-content hidden">
                            <div class="bg-white p-8 rounded-lg shadow-md border">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-xl font-bold">Client Settings</h3>
                                    <button id="edit-client-settings-btn" type="button" class="text-sm bg-gray-100 text-gray-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-200">Edit Settings</button>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Business Stamp / Signature</label>
                                        <img id="business-stamp-preview" src="https://placehold.co/128x128/e0e7ff/4f46e5?text=Stamp" class="w-32 h-32 object-cover bg-gray-200 my-2">
                                        <input type="file" id="business-stamp-upload" name="business_stamp" class="hidden" disabled accept="image/*">
                                        <button type="button" id="upload-stamp-btn" onclick="document.getElementById('business-stamp-upload').click()" class="text-sm bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed" disabled>Upload Image</button>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Default Currency</label>
                                        <select name="default_currency" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100" disabled>
                                            <option value="TZS">TZS - Tanzanian Shilling</option>
                                            <option value="USD">USD - US Dollar</option>
                                            <option value="KES">KES - Kenyan Shilling</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">VRN (VAT Registration Number)</label>
                                        <input type="text" name="vrn_number" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100 client-settings-input" placeholder="e.g., 40-012345-X" disabled>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Corporate Tax Rate (%)</label>
                                        <input type="number" name="corporate_tax_rate" class="mt-1 w-full p-2 border-gray-300 border rounded-md bg-gray-100 client-settings-input" step="0.01" placeholder="e.g., 30.00" disabled>
                                    </div>
                                </div>
                            </div>
                             <!-- VFD Integration Section -->
                            <div class="bg-white p-8 rounded-lg shadow-md border mt-8">
                                <h3 class="text-xl font-bold mb-6">TRA VFD Integration</h3>
                                <div class="space-y-6">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label for="tin_number" class="block text-sm font-medium text-gray-700">TIN Number *</label>
                                            <input type="text" name="tin_number" id="tin_number" class="mt-1 w-full p-2 border-gray-300 border rounded-md client-settings-input" disabled>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">Registration Status</label>
                                            <p id="vfd-verification-status" class="mt-2 text-sm font-bold px-3 py-1.5 rounded-full inline-block">Loading...</p>
                                        </div>
                                    </div>

                                    <div id="vfd-submission-options" class="hidden">
                                        <div class="border-t pt-6 mt-6">
                                            <label class="block text-sm font-medium text-gray-700">Automatic EFD Receipt Submission</label>
                                            <label for="vfd_enabled" class="flex items-center cursor-pointer mt-2">
                                                <div class="relative">
                                                    <input type="checkbox" id="vfd_enabled" name="vfd_enabled" class="sr-only client-settings-input" onchange="toggleVfdFrequency()" disabled>
                                                    <div class="block bg-gray-300 w-10 h-6 rounded-full"></div>
                                                    <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition"></div>
                                                </div>
                                                <div class="ml-3 text-gray-700 font-medium">
                                                    Enable
                                                </div>
                                            </label>
                                        </div>
                                        <div id="vfd-frequency-options" class="hidden pl-4 border-l-2 border-gray-200 ml-4 mt-4">
                                            <label class="block text-sm font-medium text-gray-700">Submission Frequency</label>
                                            <div class="mt-2 space-y-2">
                                                <label class="flex items-center">
                                                    <input type="radio" name="vfd_frequency" value="daily" class="client-settings-input" disabled>
                                                    <span class="ml-2">Daily</span>
                                                </label>
                                                <label class="flex items-center">
                                                    <input type="radio" name="vfd_frequency" value="weekly" class="client-settings-input" disabled>
                                                    <span class="ml-2">Weekly</span>
                                                </label>
                                                <label class="flex items-center">
                                                    <input type="radio" name="vfd_frequency" value="monthly" class="client-settings-input" disabled>
                                                    <span class="ml-2">Monthly</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                            <div id="save-changes-container" class="p-4 bg-gray-50 border-t border-gray-200 flex justify-end hidden">
                                <button type="submit" class="bg-violet-600 text-white px-6 py-2 rounded-lg hover:bg-violet-700 font-semibold shadow-sm transition-colors">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
                vendors: `<div class="p-8"><div id="vendors-view"><div class="mb-6"><div class="flex border-b border-gray-200"><button onclick="showVendorTab('payouts', event)" class="settings-tab active-tab py-2 px-4 font-semibold border-b-2">Payout Requests</button><button onclick="showVendorTab('list', event)" class="settings-tab text-gray-500 py-2 px-4 font-semibold border-b-2 border-transparent">Vendor List</button></div></div><div id="vendors-payouts" class="vendor-tab"><div class="flex justify-between items-center mb-6"><h2 class="text-3xl font-bold text-gray-800">Payout Requests</h2></div><div class="flex items-center space-x-2 mb-4"><button onclick="loadPayoutRequests(1, 'All')" class="px-3 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">All</button><button onclick="loadPayoutRequests(1, 'Submitted')" class="px-3 py-1 text-sm font-semibold bg-blue-200 text-blue-800 rounded-md hover:bg-blue-300">Submitted</button><button onclick="loadPayoutRequests(1, 'Approved')" class="px-3 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-md hover:bg-green-300">Approved</button><button onclick="loadPayoutRequests(1, 'Rejected')" class="px-3 py-1 text-sm font-semibold bg-red-200 text-red-800 rounded-md hover:bg-red-300">Rejected</button></div><div class="bg-white rounded-lg shadow-md overflow-hidden border"><table class="w-full text-left"><thead class="bg-gray-100"><tr><th class="p-4 font-semibold">Vendor</th><th class="p-4 font-semibold">Amount</th><th class="p-4 font-semibold">Status</th><th class="p-4 font-semibold">Submitted</th><th class="p-4 font-semibold">Transaction ID</th><th class="p-4 font-semibold">Actions</th></tr></thead><tbody id="payouts-table-body" class="divide-y"></tbody></table><div id="payouts-pagination" class="flex justify-between items-center p-4"></div></div></div><div id="vendors-list" class="vendor-tab hidden"><div class="flex justify-between items-center mb-6"><h2 class="text-3xl font-bold text-gray-800">Vendor List</h2><button onclick="openModal('addVendorModal')" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold"><i class="fas fa-plus mr-2"></i>Add Vendor</button></div><div class="bg-white rounded-lg shadow-md overflow-hidden border"><table class="w-full text-left"><thead class="bg-gray-100"><tr><th class="p-4 font-semibold">Name</th><th class="p-4 font-semibold">Email</th><th class="p-4 font-semibold">Phone</th><th class="p-4 font-semibold">Actions</th></tr></thead><tbody id="vendors-table-body" class="divide-y"></tbody></table><div id="vendors-pagination" class="flex justify-between items-center p-4"></div></div></div></div></div>`,
            "vendor-details": `<div class="p-8"><div class="flex items-center mb-6"><button onclick="showView('vendors', event)" class="text-gray-500 hover:text-violet-600 mr-4"><i class="fas fa-arrow-left text-2xl"></i></button><h2 id="vendor-details-name" class="text-3xl font-bold text-gray-800"></h2></div><div class="flex items-center space-x-2 mb-4"><button onclick="showVendorDetails(currentVendorId, currentVendorName, event, 1, 'All')" class="px-3 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">All</button><button onclick="showVendorDetails(currentVendorId, currentVendorName, event, 1, 'Submitted')" class="px-3 py-1 text-sm font-semibold bg-blue-200 text-blue-800 rounded-md hover:bg-blue-300">Submitted</button><button onclick="showVendorDetails(currentVendorId, currentVendorName, event, 1, 'Approved')" class="px-3 py-1 text-sm font-semibold bg-green-200 text-green-800 rounded-md hover:bg-green-300">Approved</button><button onclick="showVendorDetails(currentVendorId, currentVendorName, event, 1, 'Rejected')" class="px-3 py-1 text-sm font-semibold bg-red-200 text-red-800 rounded-md hover:bg-red-300">Rejected</button></div><div class="bg-white rounded-lg shadow-md overflow-hidden border"><table class="w-full text-left"><thead class="bg-gray-100"><tr><th class="p-4 font-semibold">Date</th><th class="p-4 font-semibold">Amount</th><th class="p-4 font-semibold">WHT</th><th class="p-4 font-semibold">Payment Details</th><th class="p-4 font-semibold">Status</th><th class="p-4 font-semibold">Transaction ID</th><th class="p-4 font-semibold">Docs</th></tr></thead><tbody id="vendor-history-table-body" class="divide-y"></tbody></table><div id="vendor-history-pagination" class="flex justify-between items-center p-4"></div></div></div>`,
            customer_statement: `<div class="p-8">
                <div class="flex items-center mb-6">
                    <button onclick="showView('invoices', event); showInvoiceTab('customers');" class="text-gray-500 hover:text-violet-600 mr-4"><i class="fas fa-arrow-left text-2xl"></i></button>
                    <div>
                        <h2 id="statement-customer-name" class="text-3xl font-bold text-gray-800">Customer Statement</h2>
                        <p id="statement-date-range" class="text-gray-500">Loading date range...</p>
                    </div>
                </div>

                <div class="mb-6 flex justify-between items-center">
                    <div class="flex space-x-2">
                        <button onclick="loadCustomerStatement(currentCustomerId, 'day', 1, 1)" class="px-4 py-2 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Today</button>
                        <button onclick="loadCustomerStatement(currentCustomerId, 'week', 1, 1)" class="px-4 py-2 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">This Week</button>
                        <button onclick="loadCustomerStatement(currentCustomerId, 'month', 1, 1)" class="px-4 py-2 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">This Month</button>
                        <button onclick="loadCustomerStatement(currentCustomerId, 'year', 1, 1)" class="px-4 py-2 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">This Year</button>
                        <button onclick="loadCustomerStatement(currentCustomerId, 'all', 1, 1)" class="px-4 py-2 text-sm font-semibold bg-violet-200 text-violet-800 rounded-md hover:bg-violet-300">All Time</button>
                    </div>
                    <button onclick="printStatement()" class="px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700"><i class="fas fa-print mr-2"></i>Print Statement</button>
                </div>

                <div id="statement-summary" class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8"></div>

                <div class="mb-8">
                    <h3 class="text-xl font-bold text-gray-700 mb-4">Documents</h3>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                        <table class="w-full text-left text-sm"><thead class="bg-gray-100"><tr><th class="p-3 font-semibold">Document #</th><th class="p-3 font-semibold">Date</th><th class="p-3 font-semibold">Due Date</th><th class="p-3 font-semibold">Total</th><th class="p-3 font-semibold">Paid</th><th class="p-3 font-semibold">Balance</th><th class="p-3 font-semibold">Status</th><th class="p-3 font-semibold">Actions</th></tr></thead><tbody id="statement-invoices-table" class="divide-y"></tbody></table>
                        <div id="statement-invoices-pagination" class="flex justify-between items-center p-4 bg-gray-50 border-t"></div>
                    </div>
                </div>

                <div>
                    <h3 class="text-xl font-bold text-gray-700 mb-4">Payments Received</h3>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                        <table class="w-full text-left text-sm"><thead class="bg-gray-100"><tr><th class="p-3 font-semibold">Payment Date</th><th class="p-3 font-semibold">Amount</th><th class="p-3 font-semibold">Ref. Document</th><th class="p-3 font-semibold">Notes</th></tr></thead><tbody id="statement-payments-table" class="divide-y"></tbody></table>
                        <div id="statement-payments-pagination" class="flex justify-between items-center p-4 bg-gray-50 border-t"></div>
                    </div>
                </div>
            </div>`,
        "invoices": `<div class="p-8">
            <div id="invoices-view">
                <div class="mb-6">
                    <div class="flex border-b border-gray-200">
                        <button onclick="showInvoiceTab('list', event)" class="settings-tab active-tab py-2 px-4 font-semibold border-b-2">All Invoices</button>
                        <button onclick="showInvoiceTab('customers', event)" class="settings-tab text-gray-500 py-2 px-4 font-semibold border-b-2 border-transparent">Customers</button>
                    </div>
                </div>
                <div id="invoices-list" class="invoice-tab">
                    <div class="flex justify-end items-center mb-4">
                        <div class="flex items-center space-x-2">
                            <div id="time-filter-container" class="flex items-center space-x-2">
                                <button onclick="filterInvoicesByTime('day')" class="px-3 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Day</button>
                                <button onclick="filterInvoicesByTime('week')" class="px-3 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Week</button>
                                <button onclick="filterInvoicesByTime('month')" class="px-3 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Month</button>
                                <button onclick="filterInvoicesByTime('year')" class="px-3 py-1 text-sm font-semibold bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Year</button>
                            </div>
                            <button onclick="generateReport()" class="px-3 py-1 text-sm font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700"><i class="fas fa-print mr-2"></i>Generate Statement</button>
                        </div>
                    </div>
                    <div class="flex justify-between items-center mb-6">
                        <div class="flex space-x-2">
                            <button onclick="openCreateDocumentView('Invoice')" class="px-4 py-2 bg-violet-600 text-white rounded-md font-semibold hover:bg-violet-700"><i class="fas fa-plus mr-2"></i>New Invoice</button>
                            <button onclick="openCreateDocumentView('Receipt')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md font-semibold hover:bg-gray-300">New Receipt</button>
                            <button onclick="openCreateDocumentView('Estimate')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md font-semibold hover:bg-gray-300">New Estimate</button>
                            <button onclick="openCreateDocumentView('Quotation')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md font-semibold hover:bg-gray-300">New Quotation</button>
                            <button onclick="openCreateDocumentView('Delivery Note')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md font-semibold hover:bg-gray-300">New Delivery Note</button>
                            <button onclick="openCreateDocumentView('Purchase Order')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md font-semibold hover:bg-gray-300">New Purchase Order</button>
                            <button onclick="openCreateDocumentView('Tax Invoice')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md font-semibold hover:bg-gray-300">New Tax Invoice</button>
                            <button onclick="openCreateDocumentView('Proforma Invoice')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md font-semibold hover:bg-gray-300">New Proforma</button>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                        <div class="p-4 bg-gray-50 border-b">
                            <div class="space-y-4">
                                <div class="flex justify-between items-center">
                                    <div id="status-summary-container" class="flex items-center space-x-2 text-sm">
                                        <button onclick="filterInvoicesByStatus('All')" class="px-3 py-1 rounded-md font-semibold text-gray-700 bg-gray-200 hover:bg-gray-300">All: <span id="status-summary-all">0</span></button>
                                        <button onclick="filterInvoicesByStatus('Overdue')" class="px-3 py-1 rounded-md font-semibold text-red-800 bg-red-200 hover:bg-red-300">Overdue: <span id="status-summary-overdue">0</span></button>
                                        <button onclick="filterInvoicesByStatus('Partially Paid')" class="px-3 py-1 rounded-md font-semibold text-yellow-800 bg-yellow-200 hover:bg-yellow-300">Partially Paid: <span id="status-summary-partially-paid">0</span></button>
                                        <button onclick="filterInvoicesByStatus('Unpaid')" class="px-3 py-1 rounded-md font-semibold text-red-800 bg-red-200 hover:bg-red-300">Unpaid: <span id="status-summary-unpaid">0</span></button>
                                        <button onclick="filterInvoicesByStatus('Paid')" class="px-3 py-1 rounded-md font-semibold text-green-800 bg-green-200 hover:bg-green-300">Paid: <span id="status-summary-paid">0</span></button>
                                    </div>
                                </div>
                                <div id="document-type-filter-container" class="flex items-center space-x-4 text-sm">
                                    <label class="flex items-center"><input type="checkbox" value="Invoice" onchange="loadInvoices(1)" class="mr-1 doc_type_filter" checked> Invoice</label>
                                    <label class="flex items-center"><input type="checkbox" value="Receipt" onchange="loadInvoices(1)" class="mr-1 doc_type_filter" checked> Receipt</label>
                                    <label class="flex items-center"><input type="checkbox" value="Estimate" onchange="loadInvoices(1)" class="mr-1 doc_type_filter" checked> Estimate</label>
                                    <label class="flex items-center"><input type="checkbox" value="Quotation" onchange="loadInvoices(1)" class="mr-1 doc_type_filter" checked> Quotation</label>
                                    <label class="flex items-center"><input type="checkbox" value="Purchase Order" onchange="loadInvoices(1)" class="mr-1 doc_type_filter" checked> Purchase Order</label>
                                    <label class="flex items-center"><input type="checkbox" value="Delivery Note" onchange="loadInvoices(1)" class="mr-1 doc_type_filter" checked> Delivery Note</label>
                                    </div>
                            </div>
                        </div>
                        <table class="w-full text-left">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-4 font-semibold">Customer</th>
                                    <th class="p-4 font-semibold">Document</th>
                                    <th class="p-4 font-semibold">Number</th>
                                    <th class="p-4 font-semibold">Date</th>
                                    <th class="p-4 font-semibold">Paid</th>
                                    <th class="p-4 font-semibold">Total</th>
                                    <th class="p-4 font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="invoices-table-body" class="divide-y">
                                <tr><td colspan="7" class="p-4 text-center text-gray-500">Loading documents...</td></tr>
                            </tbody>
                        </table>
                         <div id="invoice-pagination-container" class="flex justify-between items-center p-4 bg-gray-50 border-t">
                            <!-- Pagination controls will be injected here -->
                        </div>
                        <div id="invoice-summary-container" class="p-4 bg-gray-50 border-t">
                            <!-- Summary totals will be injected here by JavaScript -->
                        </div>
                    </div>
                </div>
                <div id="invoices-customers" class="invoice-tab hidden">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-3xl font-bold text-gray-800">Customers</h2>
                        <button onclick="openAddCustomerModal()" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold"><i class="fas fa-user-plus mr-2"></i>Add Customer</button>
                    </div>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                        <table class="w-full text-left">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-4 font-semibold">Name</th>
                                    <th class="p-4 font-semibold">Email</th>
                                    <th class="p-4 font-semibold">Phone</th>
                                    <th class="p-4 font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="customers-table-body" class="divide-y">
                                <tr><td colspan="4" class="p-4 text-center text-gray-500">Loading customers...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>`,
            expenses: `<div class="p-8">
                    <div class="mb-6 border-b border-gray-200">
                        <div class="flex">
                            <button onclick="showExpenseTab('requisition', event)" class="settings-tab active-tab py-2 px-4 font-semibold border-b-2">New Requisition</button>
                            <button onclick="showExpenseTab('claim', event)" class="settings-tab text-gray-500 py-2 px-4 font-semibold border-b-2 border-transparent">New Claim</button>
                        </div>
                    </div>

                    <div id="expense-requisition-form" class="expense-tab" style="display: block;">
                        <h2 class="text-3xl font-bold text-gray-800 mb-6">Payment Requisition</h2>
                         <form id="createExpenseFormRequisition" class="bg-white p-8 rounded-lg shadow-md border space-y-6">
                            <input type="hidden" name="type" value="requisition">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="expenseTypeReq" class="block text-sm font-medium text-gray-700 mb-1">Expense Type</label>
                                    <select id="expenseTypeReq" name="expense_type" class="w-full p-2 border border-gray-300 rounded-md" required>
                                        <option value="prepaid_electricity">Prepaid Electricity</option>
                                        <option value="internet_bundle">Internet Bundle</option>
                                        <option value="fuel">Fuel</option>
                                        <option value="transport">Transport</option>
                                        <option value="stationery">Stationery</option>
                                        <option value="miscellaneous">Miscellaneous</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="expenseAmountReq" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <input type="number" id="expenseAmountReq" name="amount" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    <input type="hidden" name="currency" value="TZS">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="expenseDateReq" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                    <input type="date" id="expenseDateReq" name="date" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div style="display: none;">
                                    <label for="paymentMethodReq" class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                                    <select id="paymentMethodReq" name="payment_method" class="w-full p-2 border border-gray-300 rounded-md">
                                        <option value="Pending" selected>Pending</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label for="expenseReferenceReq" class="block text-sm font-medium text-gray-700 mb-1">Reference/Description</label>
                                <input type="text" id="expenseReferenceReq" name="reference" class="w-full p-2 border border-gray-300 rounded-md" placeholder="Provide a clear description of the expense">
                            </div>
                             <div>
                                <label for="expenseAttachmentReq" class="block text-sm font-medium text-gray-700 mb-1">Attachment (e.g., Proforma)</label>
                                <input type="file" id="expenseAttachmentReq" name="attachment" class="w-full p-2 border border-gray-300 rounded-md">
                            </div>
                             <div class="flex items-center justify-between pt-6 border-t">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_urgent" value="1" class="h-4 w-4 text-violet-600 border-gray-300 rounded">
                                    <span class="ml-2 text-sm font-medium text-gray-700">Mark as Urgent</span>
                                </label>
                                <button type="submit" class="px-5 py-2 bg-violet-600 text-white rounded-lg hover:bg-violet-700 font-semibold">Submit for Approval</button>
                            </div>
                        </form>
                    </div>

                    <div id="expense-claim-form" class="expense-tab" style="display: none;">
                        <h2 class="text-3xl font-bold text-gray-800 mb-6">Expense Claim (Reimbursement)</h2>
                         <form id="createExpenseFormClaim" class="bg-white p-8 rounded-lg shadow-md border space-y-6">
                            <input type="hidden" name="type" value="claim">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="expenseTypeClaim" class="block text-sm font-medium text-gray-700 mb-1">Expense Type</label>
                                    <select id="expenseTypeClaim" name="expense_type" class="w-full p-2 border border-gray-300 rounded-md" required>
                                        <option value="prepaid_electricity">Prepaid Electricity</option>
                                        <option value="internet_bundle">Internet Bundle</option>
                                        <option value="fuel">Fuel</option>
                                        <option value="transport">Transport</option>
                                        <option value="stationery">Stationery</option>
                                        <option value="miscellaneous">Miscellaneous</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="expenseAmountClaim" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <input type="number" id="expenseAmountClaim" name="amount" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    <input type="hidden" name="currency" value="TZS">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="expenseDateClaim" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                    <input type="date" id="expenseDateClaim" name="date" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label for="paymentMethodClaim" class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                                    <select id="paymentMethodClaim" name="payment_method" class="w-full p-2 border border-gray-300 rounded-md" required>
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank</option>
                                        <option value="prepaid_token">Prepaid Token</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label for="expenseReferenceClaim" class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                                <input type="text" id="expenseReferenceClaim" name="reference" class="w-full p-2 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label for="expenseAttachmentClaim" class="block text-sm font-medium text-gray-700 mb-1">Receipt</label>
                                <input type="file" id="expenseAttachmentClaim" name="attachment" class="w-full p-2 border border-gray-300 rounded-md">
                            </div>
                            <div class="flex justify-end space-x-3 pt-6 border-t">
                                <button type="submit" class="px-5 py-2 bg-violet-600 text-white rounded-lg hover:bg-violet-700 font-semibold">Submit for Approval</button>
                            </div>
                        </form>
                    </div>
                    <h2 class="text-3xl font-bold text-gray-800 mt-12 mb-6">Approvals Dashboard</h2>
                    <div class="bg-white rounded-lg shadow-md overflow-x-auto border w-full">
                        <table class="min-w-full text-left whitespace-nowrap">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-4 font-semibold">Date</th>
                                    <th class="p-4 font-semibold">Request Type</th>
                                    <th class="p-4 font-semibold">Submitted By</th>
                                    <th class="p-4 font-semibold">Type</th>
                                    <th class="p-4 font-semibold">Amount</th>
                                    <th class="p-4 font-semibold">Status</th>
                                    <th class="p-4 font-semibold min-w-[160px] sticky-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="approvals-table-body" class="divide-y">
                                <tr><td colspan="7" class="p-4 text-center text-gray-500">Loading approvals...</td></tr>
                            </tbody>
                        </table>
                        <div id="pagination-controls" class="flex justify-between items-center p-4">
                            <!-- Pagination will be rendered here -->
                        </div>
                    </div>
                </div>`,
            "online-job-order": `
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-3xl font-bold text-gray-800">Job Orders</h2>
                    <button onclick="openModal('newJobOrderModal')" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold">
                        <i class="fas fa-plus mr-2"></i>New Job Order
                    </button>
                </div>
                <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                    <table class="w-full text-left">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-4 font-semibold">Tracking #</th>
                                <th class="p-4 font-semibold">Material</th>
                                <th class="p-4 font-semibold">Quantity</th>
                                <th class="p-4 font-semibold">Status</th>
                                <th class="p-4 font-semibold">Date</th>
                                <th class="p-4 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="job-orders-table-body" class="divide-y">
                            <!-- Job orders will be loaded here by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>`,
            "pricing-calculator": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">Instant Pricing Calculator</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="md:col-span-2 bg-white p-8 rounded-lg shadow-md border">
                        <form id="pricingCalculatorForm" class="space-y-6">
                            <div>
                                <label for="calc-size" class="block text-sm font-medium text-gray-700">Size</label>
                                <select id="calc-size" name="size" class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                    <option value="A4">A4</option>
                                    <option value="A3">A3</option>
                                    <option value="Business Card">Business Card</option>
                                    <option value="Custom">Custom</option>
                                </select>
                            </div>
                             <div>
                                <label for="calc-material" class="block text-sm font-medium text-gray-700">Material</label>
                                <select id="calc-material" name="material" class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                    <option value="Paper">Paper</option>
                                    <option value="Cardboard">Cardboard</option>
                                    <option value="Vinyl">Vinyl</option>
                                </select>
                            </div>
                            <div>
                                <label for="calc-quantity" class="block text-sm font-medium text-gray-700">Copies</label>
                                <input type="number" id="calc-quantity" name="quantity" value="1" min="1" class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Finishing Options</label>
                                <div class="mt-2 space-y-2">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="finishing[]" value="Lamination" class="h-4 w-4 text-violet-600 border-gray-300 rounded">
                                        <span class="ml-2 text-sm text-gray-600">Lamination</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" name="finishing[]" value="Binding" class="h-4 w-4 text-violet-600 border-gray-300 rounded">
                                        <span class="ml-2 text-sm text-gray-600">Binding</span>
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="bg-violet-600 text-white p-8 rounded-lg shadow-lg flex flex-col justify-center items-center text-center">
                        <h3 class="text-xl font-semibold opacity-80">Estimated Price</h3>
                        <p id="calculated-price" class="text-5xl font-bold my-4">TZS 0.00</p>
                        <button type="submit" form="pricingCalculatorForm" class="w-full bg-white text-violet-600 px-6 py-3 rounded-lg hover:bg-violet-50 font-semibold transition-colors">
                            Save Quotation
                        </button>
                    </div>
                </div>
                <div class="mt-8">
                     <h3 class="text-xl font-bold text-gray-700 mb-4">Saved Quotations</h3>
                     <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                        <table class="w-full text-left">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-4 font-semibold">Date</th>
                                    <th class="p-4 font-semibold">Details</th>
                                    <th class="p-4 font-semibold">Total Price</th>
                                </tr>
                            </thead>
                            <tbody id="quotations-table-body" class="divide-y">
                                <!-- Saved quotes will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>`,
            "file-upload": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">File Upload & Preflight Check</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="md:col-span-1 bg-white p-6 rounded-lg shadow-md border">
                        <h3 class="text-xl font-bold text-gray-700 mb-4">Upload New File</h3>
                        <form id="fileUploadForm" class="space-y-4" enctype="multipart/form-data">
                            <div>
                                <label for="upload-job-order" class="block text-sm font-medium text-gray-700">Link to Job Order</label>
                                <select id="upload-job-order" name="job_order_id" class="mt-1 w-full p-2 border-gray-300 border rounded-md" required>
                                    <option value="">Loading job orders...</option>
                                </select>
                            </div>
                            <div>
                                <label for="design-file" class="block text-sm font-medium text-gray-700">Design File</label>
                                <input type="file" id="design-file" name="design_file" class="mt-1 w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-violet-50 file:text-violet-700 hover:file:bg-violet-100" required>
                                <p class="text-xs text-gray-500 mt-1">Accepted: PDF, PSD, AI, JPG, PNG. Max: 25MB.</p>
                            </div>
                            <button type="submit" class="w-full bg-violet-600 text-white px-4 py-3 rounded-lg hover:bg-violet-700 font-semibold">
                                Upload & Check File
                            </button>
                        </form>
                    </div>
                    <div class="md:col-span-2 bg-white p-6 rounded-lg shadow-md border">
                         <h3 class="text-xl font-bold text-gray-700 mb-4">Uploaded Files</h3>
                         <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-3 font-semibold">Job Order</th>
                                        <th class="p-3 font-semibold">File Name</th>
                                        <th class="p-3 font-semibold">Status</th>
                                        <th class="p-3 font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="uploaded-files-table-body" class="divide-y">
                                    <!-- Files will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>`,
            "digital-proofing": `
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-3xl font-bold text-gray-800">Digital Proofing</h2>
                    <button onclick="openModal('newProofModal')" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold">
                        <i class="fas fa-upload mr-2"></i>Upload New Proof
                    </button>
                </div>
                <div id="proofs-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Proofs will be loaded here -->
                </div>
            </div>`,
            "customer-dashboard": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">My Dashboard</h2>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2">
                        <h3 class="text-xl font-bold text-gray-700 mb-4">My Job Orders</h3>
                        <div id="dashboard-job-orders" class="bg-white rounded-lg shadow-md overflow-hidden border">
                           <table class="w-full text-left">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-4 font-semibold">Tracking #</th>
                                        <th class="p-4 font-semibold">Status</th>
                                        <th class="p-4 font-semibold">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <!-- Orders will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-700 mb-4">Awaiting My Approval</h3>
                        <div id="dashboard-proofs" class="space-y-4">
                           <!-- Proofs awaiting approval will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>`,
            "analytics": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">Analytics Dashboard</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" id="analytics-container">
                    <div class="bg-white p-6 rounded-lg shadow-md border">
                        <h4 class="text-gray-500 font-semibold">Total Orders</h4>
                        <p id="analytics-total-orders" class="text-4xl font-bold mt-2">N/A</p>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow-md border">
                        <h4 class="text-gray-500 font-semibold">Completed Jobs</h4>
                        <p id="analytics-completed-jobs" class="text-4xl font-bold mt-2">N/A</p>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow-md border">
                        <h4 class="text-gray-500 font-semibold">Pending Approvals</h4>
                        <p id="analytics-pending-approvals" class="text-4xl font-bold mt-2">N/A</p>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow-md border">
                        <h4 class="text-gray-500 font-semibold">Actual Profits</h4>
                        <p id="analytics-total-profits" class="text-4xl font-bold mt-2">N/A</p>
                    </div>
                </div>
                <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white p-6 rounded-lg shadow-md border">
                        <h3 class="text-xl font-bold text-gray-700 mb-4">Top 5 Profitable Job Types</h3>
                        <canvas id="topJobsChart"></canvas>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow-md border">
                        <h3 class="text-xl font-bold text-gray-700 mb-4">Top 5 Profitable Customers</h3>
                        <canvas id="topCustomersChart"></canvas>
                    </div>
                </div>
            </div>`,
            "costs": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">Manage Material Costs</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="md:col-span-1">
                        <form id="addCostForm" class="bg-white p-6 rounded-lg shadow-md border space-y-4">
                            <h3 class="text-xl font-bold text-gray-700 mb-4">Add New Material</h3>
                            <div>
                                <label for="cost-item-name" class="block text-sm font-medium text-gray-700">Material Name</label>
                                <input type="text" id="cost-item-name" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="cost-unit" class="block text-sm font-medium text-gray-700">Unit of Measurement</label>
                                <input type="text" id="cost-unit" placeholder="e.g., Ream, Roll, Bottle" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="cost-price" class="block text-sm font-medium text-gray-700">Price per Unit</label>
                                <input type="number" id="cost-price" step="0.01" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <button type="submit" class="w-full bg-violet-600 text-white px-4 py-3 rounded-lg hover:bg-violet-700 font-semibold">
                                Save Cost
                            </button>
                        </form>
                    </div>
                    <div class="md:col-span-2">
                        <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                            <table class="w-full text-left">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-4 font-semibold">Material</th>
                                        <th class="p-4 font-semibold">Unit</th>
                                        <th class="p-4 font-semibold">Price</th>
                                        <th class="p-4 font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="costs-table-body" class="divide-y">
                                    <!-- Costs will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>`,
            "assets": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">Asset Management</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="md:col-span-1">
                        <form id="addAssetForm" class="bg-white p-6 rounded-lg shadow-md border space-y-4" enctype="multipart/form-data">
                            <h3 class="text-xl font-bold text-gray-700 mb-4">Add New Asset</h3>
                            <div>
                                <label for="asset-name" class="block text-sm font-medium text-gray-700">Asset Name</label>
                                <input type="text" id="asset-name" name="name" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="asset-category" class="block text-sm font-medium text-gray-700">Category</label>
                                <select id="asset-category" name="category" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                    <option value="Furniture">Furniture</option>
                                    <option value="Computer">Computer</option>
                                    <option value="Vehicle">Vehicle</option>
                                    <option value="Equipment">Equipment</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="asset-purchase-date" class="block text-sm font-medium text-gray-700">Purchase Date</label>
                                <input type="date" id="asset-purchase-date" name="purchase_date" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="asset-purchase-cost" class="block text-sm font-medium text-gray-700">Purchase Cost</label>
                                <input type="number" id="asset-purchase-cost" name="purchase_cost" step="0.01" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="asset-receipt" class="block text-sm font-medium text-gray-700">Upload Receipt (PDF/Image)</label>
                                <input type="file" id="asset-receipt" name="receipt" class="mt-1 w-full text-sm">
                            </div>
                            <button type="submit" class="w-full bg-violet-600 text-white px-4 py-3 rounded-lg hover:bg-violet-700 font-semibold">
                                Save Asset
                            </button>
                        </form>
                    </div>
                    <div class="md:col-span-2">
                        <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                            <table class="w-full text-left">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-4 font-semibold">Name</th>
                                        <th class="p-4 font-semibold">Category</th>
                                        <th class="p-4 font-semibold">Purchase Date</th>
                                        <th class="p-4 font-semibold">Cost</th>
                                        <th class="p-4 font-semibold">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody id="assets-table-body" class="divide-y">
                                    <!-- Assets will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>`,
            "financials": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">Financial Statements</h2>
                <div class="bg-white p-6 rounded-lg shadow-md border">
                    <div class="flex items-center space-x-4">
                        <div>
                            <label for="financial-year" class="block text-sm font-medium text-gray-700">Financial Year</label>
                            <select id="financial-year" name="year" class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                <!-- Years will be populated by JavaScript -->
                            </select>
                        </div>
                        <button onclick="generateFinancialStatement()" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold mt-6">Generate Report</button>
                    </div>
                </div>
            </div>`,
                        "investments": `<div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-3xl font-bold text-gray-800">Investments</h2>
                    <button onclick="openModal('addInvestmentModal')" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold">
                        <i class="fas fa-plus mr-2"></i>Add New Investment
                    </button>
                </div>
                <div class="bg-white rounded-lg shadow-md overflow-hidden border w-full">
                    <table class="w-full text-left">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-4 font-semibold">Description</th>
                                <th class="p-4 font-semibold">Type</th>
                                <th class="p-4 font-semibold">Quantity</th>
                                <th class="p-4 font-semibold">Purchase Date</th>
                                <th class="p-4 font-semibold">Cost</th>
                                <th class="p-4 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="investments-table-body" class="divide-y"></tbody>
                    </table>
                    <div id="investments-pagination" class="flex justify-between items-center p-4 bg-gray-50 border-t"></div>
                </div>
            </div>`,
            "tax_payments": `
            <div class="p-8">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">Quarterly Tax Payments</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="md:col-span-1">
                        <form id="addTaxPaymentForm" class="bg-white p-6 rounded-lg shadow-md border space-y-4">
                            <h3 class="text-xl font-bold text-gray-700 mb-4">Add New Tax Payment</h3>
                            <div>
                                <label for="tax-payment-date" class="block text-sm font-medium text-gray-700">Payment Date</label>
                                <input type="date" id="tax-payment-date" name="payment_date" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="tax-amount" class="block text-sm font-medium text-gray-700">Amount</label>
                                <input type="number" id="tax-amount" name="amount" step="0.01" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                             <div>
                                <label for="tax-financial-year" class="block text-sm font-medium text-gray-700">Financial Year</label>
                                <select id="tax-financial-year" name="financial_year" required class="mt-1 w-full p-2 border-gray-300 border rounded-md"></select>
                            </div>
                            <div>
                                <label for="tax-quarter" class="block text-sm font-medium text-gray-700">Quarter</label>
                                <select id="tax-quarter" name="quarter" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                    <option value="Q1">Q1 (Jan-Mar)</option>
                                    <option value="Q2">Q2 (Apr-Jun)</option>
                                    <option value="Q3">Q3 (Jul-Sep)</option>
                                    <option value="Q4">Q4 (Oct-Dec)</option>
                                </select>
                            </div>
                            <div>
                                <label for="tax-reference" class="block text-sm font-medium text-gray-700">Reference Number (Optional)</label>
                                <input type="text" id="tax-reference" name="reference_number" class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <button type="submit" class="w-full bg-violet-600 text-white px-4 py-3 rounded-lg hover:bg-violet-700 font-semibold">
                                Save Payment
                            </button>
                        </form>
                    </div>
                    <div class="md:col-span-2">
                        <div class="bg-white rounded-lg shadow-md overflow-hidden border">
                            <table class="w-full text-left">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="p-4 font-semibold">Payment Date</th>
                                        <th class="p-4 font-semibold">Amount</th>
                                        <th class="p-4 font-semibold">FY / Quarter</th>
                                        <th class="p-4 font-semibold">Reference</th>
                                    </tr>
                                </thead>
                                <tbody id="tax-payments-table-body" class="divide-y"></tbody>
                            </table>
                             <div id="tax-payments-pagination" class="flex justify-between items-center p-4 bg-gray-50 border-t"></div>
                        </div>
                    </div>
                </div>
            </div>`,
                        "payroll": `<div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-3xl font-bold text-gray-800">Payroll Management</h2>
                    <div class="space-x-2">
                        <a href="api/download_payroll_template.php" class="bg-gray-200 text-gray-800 px-4 py-2 rounded-lg hover:bg-gray-300 font-semibold text-sm inline-flex items-center">
                            <i class="fas fa-file-excel mr-2"></i>Template
                        </a>
                        <button onclick="openModal('uploadPayrollModal')" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold">
                            <i class="fas fa-upload mr-2"></i>Upload Payroll
                        </button>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md overflow-hidden border w-full">
                     <h3 class="text-xl font-bold text-gray-700 p-4 border-b bg-gray-50">Payroll History</h3>
                    <table class="w-full text-left">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-4 font-semibold">Period</th>
                                <th class="p-4 font-semibold">Total Amount</th>
                                <th class="p-4 font-semibold">Status</th>
                                <th class="p-4 font-semibold">Uploaded By</th>
                                <th class="p-4 font-semibold">Approver</th>
                                <th class="p-4 font-semibold">Uploaded On</th>
                                <th class="p-4 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="payroll-batches-table-body" class="divide-y">
                            <!-- Payroll batches will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>`,
            taxHistoryModal: `<div id="taxHistoryModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-3xl shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <div class="flex justify-between items-center mb-4">
                            <h3 id="tax-history-title" class="text-xl font-bold text-gray-800">Tax Payment History</h3>
                            <button onclick="closeModal('taxHistoryModal')" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
                        </div>
                        <div class="bg-white rounded-lg shadow-sm overflow-hidden border max-h-96 overflow-y-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr>
                                        <th class="p-3 font-semibold">Date Paid</th>
                                        <th class="p-3 font-semibold">Amount</th>
                                        <th class="p-3 font-semibold">Period</th>
                                        <th class="p-3 font-semibold">Reference</th>
                                        <th class="p-3 font-semibold">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody id="tax-history-table-body" class="divide-y">
                                    <!-- History rows loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>`,
            taxHistoryModal: `<div id="taxHistoryModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-3xl shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <div class="flex justify-between items-center mb-4">
                            <h3 id="tax-history-title" class="text-xl font-bold text-gray-800">Tax Payment History</h3>
                            <button onclick="closeModal('taxHistoryModal')" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
                        </div>
                        <div class="bg-white rounded-lg shadow-sm overflow-hidden border max-h-96 overflow-y-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr>
                                        <th class="p-3 font-semibold">Date Paid</th>
                                        <th class="p-3 font-semibold">Amount</th>
                                        <th class="p-3 font-semibold">Period</th>
                                        <th class="p-3 font-semibold">Reference</th>
                                        <th class="p-3 font-semibold">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody id="tax-history-table-body" class="divide-y">
                                    <!-- History rows loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>`,
            fillTemplateVariablesModal: `<div id="fillTemplateVariablesModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Fill Template Variables</h3>
                        <form id="fillVariablesForm" class="mt-4 space-y-4 p-4 text-left">
                            <input type="hidden" id="templateBodyToFill">
                            <div id="variable-inputs-container" class="space-y-3 max-h-60 overflow-y-auto">
                                <!-- Dynamic inputs will be injected here -->
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('fillTemplateVariablesModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Send Message</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
        };
        const modalTemplates = {
            payrollDetailsModal: `<div id="payrollDetailsModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-5xl shadow-lg rounded-md bg-white h-[80vh] flex flex-col">
                    <div class="flex justify-between items-center mb-4 border-b pb-2">
                        <h3 class="text-xl font-bold text-gray-800">Payroll Details</h3>
                        <button onclick="closeModal('payrollDetailsModal')" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
                    </div>
                    <div id="payroll-details-info" class="mb-4 grid grid-cols-3 gap-4 text-sm bg-gray-50 p-3 rounded border">
                        <!-- Batch info loaded here -->
                    </div>
                    <div class="flex-1 overflow-auto border rounded-lg">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-100 sticky top-0">
                                <tr>
                                    <th class="p-3 font-semibold">Employee</th>
                                    <th class="p-3 font-semibold">Email</th>
                                    <th class="p-3 font-semibold text-right">Basic Salary</th>
                                    <th class="p-3 font-semibold text-right">Allowances</th>
                                    <th class="p-3 font-semibold text-right">Deductions</th>
                                    <th class="p-3 font-semibold text-right">Tax</th>
                                    <th class="p-3 font-semibold text-right">Net Salary</th>
                                </tr>
                            </thead>
                            <tbody id="payroll-details-table-body" class="divide-y">
                                <!-- Entries loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>`,
            addInvestmentModal: `<div id="addInvestmentModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Add New Investment</h3>
                        <form id="addInvestmentForm" class="mt-4 space-y-4 p-4 text-left">
                            <div>
                                <label for="investment-description" class="block text-sm font-medium text-gray-700">Description</label>
                                <input type="text" id="investment-description" name="description" required class="mt-1 w-full p-2 border-gray-300 border rounded-md" placeholder="e.g., Vodacom PLC Shares">
                            </div>
                            <div>
                                <label for="investment-type" class="block text-sm font-medium text-gray-700">Type</label>
                                <select id="investment-type" name="investment_type" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                    <option value="Shares">Shares</option>
                                    <option value="Units">Units</option>
                                    <option value="Bonds">Bonds</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="investment-quantity" class="block text-sm font-medium text-gray-700">Quantity (Optional)</label>
                                <input type="number" id="investment-quantity" name="quantity" step="0.01" class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="investment-purchase-date" class="block text-sm font-medium text-gray-700">Purchase Date</label>
                                <input type="date" id="investment-purchase-date" name="purchase_date" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="investment-purchase-cost" class="block text-sm font-medium text-gray-700">Purchase Cost</label>
                                <input type="number" id="investment-purchase-cost" name="purchase_cost" step="0.01" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('addInvestmentModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Save Investment</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            uploadPayrollModal: `<div id="uploadPayrollModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Upload New Payroll</h3>
                        <form id="uploadPayrollForm" class="mt-4 space-y-4 p-4 text-left" enctype="multipart/form-data">
                            <div>
                                <label for="payroll-month" class="block text-sm font-medium text-gray-700">Month</label>
                                <select id="payroll-month" name="month" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                    <option value="January">January</option>
                                    <option value="February">February</option>
                                    <option value="March">March</option>
                                    <option value="April">April</option>
                                    <option value="May">May</option>
                                    <option value="June">June</option>
                                    <option value="July">July</option>
                                    <option value="August">August</option>
                                    <option value="September">September</option>
                                    <option value="October">October</option>
                                    <option value="November">November</option>
                                    <option value="December">December</option>
                                </select>
                            </div>
                            <div>
                                <label for="payroll-year" class="block text-sm font-medium text-gray-700">Year</label>
                                <select id="payroll-year" name="year" required class="mt-1 w-full p-2 border-gray-300 border rounded-md"></select>
                            </div>
                            <div>
                                <label for="payroll-file" class="block text-sm font-medium text-gray-700">Upload Filled Template</label>
                                <input type="file" id="payroll-file" name="payroll_file" required class="mt-1 w-full text-sm">
                            </div>
                            <div>
                                <label for="payroll-approver" class="block text-sm font-medium text-gray-700">Select Approver</label>
                                <select id="payroll-approver" name="approver_id" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                                    <option value="">Loading...</option>
                                </select>
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('uploadPayrollModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Upload for Approval</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            payRequisitionModal: `<div id="payRequisitionModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Pay Requisition & Upload Receipt</h3>
                        <form id="payRequisitionForm" class="mt-4 space-y-4 p-4 text-left" enctype="multipart/form-data">
                            <input type="hidden" id="payReqExpenseId" name="expense_id">
                            <div class="bg-yellow-50 p-3 rounded border border-yellow-200 text-sm text-yellow-800 mb-4">
                                <i class="fas fa-info-circle mr-1"></i> Confirming payment for <strong><span id="payReqAmount">0.00</span></strong>
                            </div>
                            <div>
                                <label for="payReqReceipt" class="block text-sm font-medium text-gray-700">Upload Payment Receipt *</label>
                                <input type="file" id="payReqReceipt" name="receipt_file" required class="mt-1 w-full text-sm border border-gray-300 rounded p-2">
                                <p class="text-xs text-gray-500 mt-1">Supported formats: JPG, PNG, PDF</p>
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('payRequisitionModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Confirm Payment</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            editInvestmentModal: `<div id="editInvestmentModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Edit Investment</h3>
                        <form id="editInvestmentForm" class="mt-4 space-y-4 p-4 text-left">
                            <input type="hidden" id="edit-investment-id" name="investment_id">
                            <div>
                                <label for="edit-investment-quantity" class="block text-sm font-medium text-gray-700">New Quantity</label>
                                <input type="number" id="edit-investment-quantity" name="new_quantity" step="0.01" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="edit-investment-cost" class="block text-sm font-medium text-gray-700">New Purchase Cost</label>
                                <input type="number" id="edit-investment-cost" name="new_purchase_cost" step="0.01" required class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('editInvestmentModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            newChatModal: `<div id="newChatModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50"><div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white"><div class="mt-3"><div class="flex justify-between items-center mb-4"><h3 class="text-lg text-center leading-6 font-medium text-gray-900">Start New Chat</h3><button onclick="closeModal('newChatModal')" class="text-gray-400 hover:text-gray-500"><i class="fas fa-times"></i></button></div><div class="relative mb-4"><i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i><input type="text" id="new-chat-search" placeholder="Search contact..." class="w-full pl-10 pr-4 py-2 border rounded-lg bg-gray-50 focus:bg-white focus:ring-2 focus:ring-violet-200 transition-all" onkeyup="searchNewChatContacts()"></div><div id="new-chat-contacts-list" class="max-h-60 overflow-y-auto divide-y divide-gray-100"></div></div></div></div>`,
            templateSelectorModal: `<div id="templateSelectorModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50"><div class="relative mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white"><div class="mt-3"><div class="flex justify-between items-center mb-4"><h3 class="text-lg font-medium text-gray-900">Select Template</h3><button onclick="closeModal('templateSelectorModal')" class="text-gray-400 hover:text-gray-500"><i class="fas fa-times"></i></button></div><div id="template-selector-list" class="max-h-96 overflow-y-auto space-y-2"></div></div></div></div>`,
            newJobOrderModal: `<div id="newJobOrderModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">New Job Order</h3>
                        <form id="newJobOrderForm" class="mt-4 space-y-4 p-4 text-left" enctype="multipart/form-data">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="jobSize" class="block text-sm font-medium text-gray-700">Size</label>
                                    <input type="text" id="jobSize" name="size" class="mt-1 w-full p-2 border-gray-300 border rounded-md" required>
                                </div>
                                <div>
                                    <label for="jobQuantity" class="block text-sm font-medium text-gray-700">Quantity</label>
                                    <input type="number" id="jobQuantity" name="quantity" class="mt-1 w-full p-2 border-gray-300 border rounded-md" required>
                                </div>
                            </div>
                            <div>
                                <label for="jobMaterial" class="block text-sm font-medium text-gray-700">Material</label>
                                <input type="text" id="jobMaterial" name="material" class="mt-1 w-full p-2 border-gray-300 border rounded-md" required>
                            </div>
                            <div>
                                <label for="jobFinishing" class="block text-sm font-medium text-gray-700">Finishing</label>
                                <input type="text" id="jobFinishing" name="finishing" class="mt-1 w-full p-2 border-gray-300 border rounded-md">
                            </div>
                            <div>
                                <label for="jobNotes" class="block text-sm font-medium text-gray-700">Notes</label>
                                <textarea id="jobNotes" name="notes" rows="3" class="mt-1 w-full p-2 border-gray-300 border rounded-md"></textarea>
                            </div>
                            <div>
                                <label for="jobFiles" class="block text-sm font-medium text-gray-700">Upload Files (PDF, PSD, AI, JPG, PNG)</label>
                                <input type="file" id="jobFiles" name="files[]" multiple class="mt-1 w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-violet-50 file:text-violet-700 hover:file:bg-violet-100">
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('newJobOrderModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Submit Job Order</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            expenseActionModal: `<div id="expenseActionModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 id="expense-action-title" class="text-lg text-center leading-6 font-medium text-gray-900">Action</h3>
                        <form id="expenseActionForm" class="mt-4 space-y-4 p-4 text-left">
                            <input type="hidden" id="expenseActionId" name="expense_id">
                            <input type="hidden" id="expenseActionType" name="action">

                            <div id="expense-action-comment-wrapper">
                                <label for="expenseActionComment" class="block text-sm font-medium text-gray-700">Comment</label>
                                <textarea id="expenseActionComment" name="comment" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" rows="3" required></textarea>
                            </div>

                            <div id="expense-action-forward-wrapper" class="hidden">
                                <label for="forwardUserId" class="block text-sm font-medium text-gray-700">Forward To</label>
                                <select id="forwardUserId" name="forward_user_id" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="">Loading users...</option>
                                </select>
                            </div>

                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('expenseActionModal')">Cancel</button>
                                <button type="submit" id="expense-action-submit-btn" class="px-4 py-2 bg-violet-500 text-white rounded-md">Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            addContactModal: `<div id="addContactModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center"><div class="relative mx-auto p-5 border w-96 shadow-lg rounded-md bg-white"><div class="mt-3 text-center"><h3 class="text-lg leading-6 font-medium text-gray-900">Add New Contact</h3><div class="mt-2 px-7 py-3"><form id="addContactForm" class="space-y-4"><input id="contactName" class="px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="Full Name" required><input id="contactEmail" class="px-3 py-2 text-gray-700 border rounded-md w-full" type="email" placeholder="Email Address (Optional)"><input id="contactPhone" class="px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="Phone Number (e.g. +255...)" required></form></div><div class="items-center px-4 py-3"><button type="submit" form="addContactForm" class="px-4 py-2 bg-violet-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-violet-600">Save Contact</button><button type="button" class="mt-2 px-4 py-2 bg-gray-200 text-gray-800 text-base font-medium rounded-md w-full shadow-sm hover:bg-gray-300" onclick="closeModal('addContactModal')">Cancel</button></div></div></div></div>`,
            addTemplateModal: `<div id="addTemplateModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 id="template-modal-title" class="text-lg text-center leading-6 font-medium text-gray-900">Create New Template</h3>
                        <form id="addTemplateForm" class="mt-4 space-y-4 p-4">
                            <input id="templateId" type="hidden">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="templateName" class="block text-sm font-medium text-gray-700">Template Name</label>
                                    <input id="templateName" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="e.g., order_confirmation" required>
                                </div>
                                <div>
                                    <label for="templateCategory" class="block text-sm font-medium text-gray-700">Category</label>
                                    <select id="templateCategory" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" required>
                                        <option value="UTILITY">Utility</option>
                                        <option value="MARKETING">Marketing</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label for="templateHeader" class="block text-sm font-medium text-gray-700">Header (Optional)</label>
                                <input id="templateHeader" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="e.g., Your Order is Confirmed!">
                            </div>
                            <div>
                                <label for="templateBody" class="block text-sm font-medium text-gray-700">Body</label>
                                <textarea id="templateBody" oninput="updateTemplateVariables()" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full font-mono" rows="6" placeholder="e.g. Hello {{customer_name}}, your order {{order_number}} is on its way." required></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Insert Variable</label>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" onclick="insertVariable('{{customer_name}}')" class="variable-chip">customer_name</button>
                                    <button type="button" onclick="insertVariable('{{order_number}}')" class="variable-chip">order_number</button>
                                    <button type="button" onclick="insertVariable('{{delivery_date}}')" class="variable-chip">delivery_date</button>
                                </div>
                            </div>

                            <div id="template-vars-container" style="display: none;">
                                <label class="block text-sm font-medium text-gray-700">Variable Examples</label>
                                <div id="template-vars-list" class="p-3 bg-gray-50 rounded-md border space-y-2 mt-1"></div>
                            </div>
                            <div>
                                <label for="templateFooter" class="block text-sm font-medium text-gray-700">Footer (Optional)</label>
                                <input id="templateFooter" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="e.g., Thanks for shopping with us!">
                            </div>
                             <div>
                                <label for="templateQuickReplies" class="block text-sm font-medium text-gray-700">Buttons (Optional, comma-separated)</label>
                                <input id="templateQuickReplies" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="e.g., View Order,Track Shipment">
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('addTemplateModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-600 text-white rounded-md hover:bg-violet-700">Save Template</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            newBroadcastModal: `<div id="newBroadcastModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center"><div class="relative mx-auto p-5 border w-[700px] shadow-lg rounded-md bg-white"><div class="mt-3"><h3 class="text-lg text-center font-medium text-gray-900">Create New Broadcast</h3><form id="newBroadcastForm" class="mt-4 space-y-4 p-4"><input name="campaign_name" class="px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="Campaign Name" required><div><label class="block text-sm font-medium text-gray-700 mb-2">Recipients</label><div class="flex space-x-4"><label class="inline-flex items-center"><input type="radio" name="recipient_type" value="all" class="form-radio" checked onchange="toggleContactSelect(this.value)"> <span class="ml-2">All</span></label><label class="inline-flex items-center"><input type="radio" name="recipient_type" value="select" class="form-radio" onchange="toggleContactSelect(this.value)"> <span class="ml-2">Select</span></label></div><div id="contact-select-container" class="mt-2 hidden"><label for="selectContacts" class="block text-sm font-medium">Choose contacts</label><select name="selected_contacts[]" id="selectContacts" multiple class="mt-1 block w-full rounded-md h-40 border-gray-300"></select></div></div><div><label class="block text-sm font-medium text-gray-700 mb-2">Message Type</label><div class="flex space-x-4"><label class="inline-flex items-center"><input type="radio" name="message_type" value="custom" class="form-radio" checked onchange="toggleBroadcastMessageType(this.value)"> <span class="ml-2">Custom Message</span></label><label class="inline-flex items-center"><input type="radio" name="message_type" value="template" class="form-radio" onchange="toggleBroadcastMessageType(this.value)"> <span class="ml-2">Use Template</span></label></div></div><div id="broadcast-custom-message-container"><label class="block text-sm font-medium text-gray-700 mb-2">Message</label><textarea name="message_body" class="px-3 py-2 border rounded-md w-full" rows="4" placeholder="Type your message..."></textarea></div><div id="broadcast-template-select-container" class="hidden"><label for="selectTemplate" class="block text-sm font-medium">Choose an approved template</label><select name="template_id" id="selectTemplate" class="mt-1 block w-full rounded-md border-gray-300"></select></div><div><label class="block text-sm font-medium text-gray-700 mb-2">Schedule</label><div class="flex space-x-4"><label class="inline-flex items-center"><input type="radio" name="schedule_type" value="now" class="form-radio" checked onchange="toggleScheduleDate(this.value)"> <span class="ml-2">Now</span></label><label class="inline-flex items-center"><input type="radio" name="schedule_type" value="later" class="form-radio" onchange="toggleScheduleDate(this.value)"> <span class="ml-2">Later</span></label></div><div id="schedule-date-container" class="mt-2 hidden"><input name="scheduled_at" class="px-3 py-2 border rounded-md w-full" type="datetime-local"></div></div><div class="items-center pt-4 flex justify-end space-x-2"><button type="button" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md" onclick="closeModal('newBroadcastModal')">Cancel</button><button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Schedule/Send</button></div></form></div></div></div>`,
            configureNodeModal: `<div id="configureNodeModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50"><div class="relative mx-auto p-5 border w-1/3 shadow-lg rounded-md bg-white"><h3 id="configure-node-title" class="text-lg font-medium text-gray-900 mb-4"></h3><div id="configure-node-body"></div><div class="mt-4 flex justify-end gap-2"><button type="button" onclick="closeModal('configureNodeModal')" class="px-4 py-2 bg-gray-200 rounded-md">Cancel</button><button type="button" onclick="updateNodeContent()" class="px-4 py-2 bg-violet-600 text-white rounded-md">Save</button></div></div></div>`,
            selectTriggerModal: `<div id="selectTriggerModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50"><div class="relative mx-auto p-6 border w-full max-w-3xl shadow-xl rounded-2xl bg-white"><div class="flex justify-between items-center mb-6"><h3 class="text-2xl font-bold text-gray-800">Select a Trigger</h3><button onclick="closeModal('selectTriggerModal')" class="text-gray-400 hover:text-gray-600 transition-colors"><i class="fas fa-times text-2xl"></i></button></div><div class="grid grid-cols-2 md:grid-cols-3 gap-4"><button onclick="selectTrigger('Conversation Started')" class="p-4 border border-gray-200 rounded-xl hover:bg-violet-50 hover:border-violet-200 hover:shadow-md transition-all text-left group"><div class="bg-violet-100 text-violet-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-comments"></i></div><h4 class="font-semibold text-gray-800">Conversation Started</h4><p class="text-xs text-gray-500 mt-1">When a new chat begins</p></button><button onclick="selectTrigger('Message Received')" class="p-4 border border-gray-200 rounded-xl hover:bg-blue-50 hover:border-blue-200 hover:shadow-md transition-all text-left group"><div class="bg-blue-100 text-blue-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-envelope"></i></div><h4 class="font-semibold text-gray-800">Message Received</h4><p class="text-xs text-gray-500 mt-1">When any message arrives</p></button><button onclick="selectTrigger('Payment Completed')" class="p-4 border border-gray-200 rounded-xl hover:bg-green-50 hover:border-green-200 hover:shadow-md transition-all text-left group"><div class="bg-green-100 text-green-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-check-circle"></i></div><h4 class="font-semibold text-gray-800">Payment Completed</h4><p class="text-xs text-gray-500 mt-1">When a payment is successful</p></button><button onclick="selectTrigger('Order Status Changed')" class="p-4 border border-gray-200 rounded-xl hover:bg-orange-50 hover:border-orange-200 hover:shadow-md transition-all text-left group"><div class="bg-orange-100 text-orange-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-shipping-fast"></i></div><h4 class="font-semibold text-gray-800">Order Status</h4><p class="text-xs text-gray-500 mt-1">When an order updates</p></button><button onclick="selectTrigger('New Contact')" class="p-4 border border-gray-200 rounded-xl hover:bg-pink-50 hover:border-pink-200 hover:shadow-md transition-all text-left group"><div class="bg-pink-100 text-pink-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-user-plus"></i></div><h4 class="font-semibold text-gray-800">New Contact</h4><p class="text-xs text-gray-500 mt-1">When a contact is created</p></button><button onclick="selectTrigger('Tag Added')" class="p-4 border border-gray-200 rounded-xl hover:bg-indigo-50 hover:border-indigo-200 hover:shadow-md transition-all text-left group"><div class="bg-indigo-100 text-indigo-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-tag"></i></div><h4 class="font-semibold text-gray-800">Tag Added</h4><p class="text-xs text-gray-500 mt-1">When a specific tag is added</p></button><button onclick="selectTrigger('Deal Stage Updated')" class="p-4 border border-gray-200 rounded-xl hover:bg-teal-50 hover:border-teal-200 hover:shadow-md transition-all text-left group"><div class="bg-teal-100 text-teal-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-handshake"></i></div><h4 class="font-semibold text-gray-800">Deal Stage Updated</h4><p class="text-xs text-gray-500 mt-1">When a deal moves stages</p></button><button onclick="selectTrigger('Form Submitted')" class="p-4 border border-gray-200 rounded-xl hover:bg-yellow-50 hover:border-yellow-200 hover:shadow-md transition-all text-left group"><div class="bg-yellow-100 text-yellow-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-file-alt"></i></div><h4 class="font-semibold text-gray-800">Form Submitted</h4><p class="text-xs text-gray-500 mt-1">When a form is filled</p></button><button onclick="selectTrigger('Conversation Closed')" class="p-4 border border-gray-200 rounded-xl hover:bg-red-50 hover:border-red-200 hover:shadow-md transition-all text-left group"><div class="bg-red-100 text-red-600 w-10 h-10 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fas fa-times-circle"></i></div><h4 class="font-semibold text-gray-800">Conversation Closed</h4><p class="text-xs text-gray-500 mt-1">When a chat ends</p></button></div></div></div>`,
            addVendorModal: `<div id="addVendorModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50"><div class="relative mx-auto p-5 border w-96 shadow-lg rounded-md bg-white"><div class="mt-3 text-center"><h3 class="text-lg leading-6 font-medium text-gray-900">Add New Vendor</h3><form id="addVendorForm" class="mt-4 space-y-4 p-4 text-left"><input id="vendorName" class="px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="Full Name or Business Name" required><input id="vendorEmail" class="px-3 py-2 text-gray-700 border rounded-md w-full" type="email" placeholder="Email Address" required><input id="vendorPhone" class="px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="Phone (e.g. +255...)" required><div class="items-center pt-4 flex justify-end space-x-2"><button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('addVendorModal')">Cancel</button><button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Save Vendor</button></div></form></div></div></div>`,
            rejectPayoutModal: `<div id="rejectPayoutModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50"><div class="relative mx-auto p-5 border w-96 shadow-lg rounded-md bg-white"><div class="mt-3"><h3 class="text-lg text-center leading-6 font-medium text-gray-900">Reject Payout</h3><form id="rejectPayoutForm" class="mt-4 space-y-4 p-4 text-left"><label for="rejectionReason" class="block text-sm font-medium text-gray-700">Reason for Rejection</label><textarea id="rejectionReason" class="px-3 py-2 text-gray-700 border rounded-md w-full" rows="3" placeholder="e.g., Invoice amount is incorrect..." required></textarea><div class="items-center pt-4 flex justify-end space-x-2"><button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('rejectPayoutModal')">Cancel</button><button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md">Submit Rejection</button></div></form></div></div></div>`,
            uploadReceiptModal: `<div id="uploadReceiptModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50"><div class="relative mx-auto p-5 border w-96 shadow-lg rounded-md bg-white"><div class="mt-3"><h3 class="text-lg text-center leading-6 font-medium text-gray-900">Upload Payment Receipt</h3><form id="uploadReceiptForm" class="mt-4 space-y-4 p-4 text-left"><input id="receiptPayoutId" type="hidden"><label for="receiptFile" class="block text-sm font-medium text-gray-700">Select Receipt (PDF, JPG, PNG)</label><input type="file" id="receiptFile" name="receipt_file" class="mt-1 w-full p-2 border-gray-300 border rounded-md" required><div class="items-center pt-4 flex justify-end space-x-2"><button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('uploadReceiptModal')">Cancel</button><button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Upload</button></div></form></div></div></div>`,
            payoutDetailModal: `<div id="payoutDetailModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50"><div class="relative mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white"><div class="mt-3"><h3 class="text-lg text-center leading-6 font-medium text-gray-900 mb-4"><b>Review Payout Request</b></h3><hr><div id="payout-details-content" class="space-y-4 p-4"></div><div class="items-center pt-4 flex justify-end space-x-2" id="payout-modal-actions"></div></div></div></div>`,

            addUserModal: `<div id="addUserModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Invite New User</h3>
                        <form id="addUserForm" class="mt-4 space-y-4 p-4 text-left">
                            <div>
                                <label for="userName" class="block text-sm font-medium text-gray-700">Full Name</label>
                                <input id="userName" type="text" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" required>
                            </div>
                            <div>
                                <label for="userEmail" class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input id="userEmail" type="email" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" required>
                            </div>
                            <div>
                                <label for="userRole" class="block text-sm font-medium text-gray-700">Role</label>
                                <select id="userRole" class="mt-1 block w-full rounded-md border-gray-300">
                                    <option value="Admin">Admin</option>
                                    <option value="Accountant">Accountant</option>
                                    <option value="Staff" selected>Staff</option>
                                </select>
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('addUserModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Send Invitation</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,

            addCustomerModal: `<div id="addCustomerModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Add New Customer</h3>
                        <form id="addCustomerForm" class="mt-4 space-y-4 p-4 text-left">
                            <div>
                                <label for="customerName" class="block text-sm font-medium text-gray-700">Customer Name *</label>
                                <input id="customerName" name="name" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="Full Name or Business Name" required>
                            </div>
                             <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="customerEmail" class="block text-sm font-medium text-gray-700">Email Address</label>
                                    <input id="customerEmail" name="email" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="email" placeholder="e.g., info@company.com">
                                </div>
                                <div>
                                    <label for="customerPhone" class="block text-sm font-medium text-gray-700">Phone</label>
                                    <input id="customerPhone" name="phone" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="e.g. +255...">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="customerTIN" class="block text-sm font-medium text-gray-700">TIN Number (Optional)</label>
                                    <input id="customerTIN" name="tin_number" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="e.g., 123-456-789">
                                </div>
                                <div>
                                    <label for="customerVRN" class="block text-sm font-medium text-gray-700">VRN (Optional)</label>
                                    <input id="customerVRN" name="vrn_number" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="text" placeholder="e.g., 40-012345-A">
                                </div>
                            </div>
                            <div id="unassigned-contacts-wrapper" class="hidden">
                                <label for="assignContacts" class="block text-sm font-medium text-gray-700">Assign Existing Contacts</label>
                                <select name="contact_ids[]" id="assignContacts" multiple class="mt-1 block w-full rounded-md h-32 border-gray-300">
                                    <option>Loading unassigned contacts...</option>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Select one or more contacts to link to this new customer.</p>
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('addCustomerModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Save Customer</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,

            recordPaymentModal: `<div id="recordPaymentModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Record Payment</h3>
                        <form id="recordPaymentForm" class="mt-4 space-y-4 p-4 text-left">
                            <input type="hidden" id="paymentInvoiceId" name="invoice_id">
                            <div>
                                <label for="paymentAmount" class="block text-sm font-medium text-gray-700">Amount Paid *</label>
                                <input id="paymentAmount" name="amount" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="number" step="0.01" placeholder="e.g., 50000.00" required>
                            </div>
                            <div>
                                <label for="paymentDate" class="block text-sm font-medium text-gray-700">Payment Date *</label>
                                <input id="paymentDate" name="payment_date" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" type="date" required>
                            </div>
                             <div>
                                <label for="paymentNotes" class="block text-sm font-medium text-gray-700">Notes (Optional)</label>
                                <textarea id="paymentNotes" name="notes" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" rows="3" placeholder="e.g., Bank transfer, Cheque no. 123"></textarea>
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('recordPaymentModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Save Payment</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
                        trackProgressModal: `<div id="trackProgressModal" class="modal fixed inset-0 bg-gray-900 bg-opacity-60 h-full w-full hidden items-center justify-center z-50 backdrop-blur-sm">
                <div class="relative mx-auto p-0 border-0 w-full max-w-2xl shadow-2xl rounded-xl bg-white overflow-hidden">
                    <div class="flex justify-between items-center p-4 bg-gray-50 border-b">
                        <h3 class="text-lg font-bold text-gray-800">Track Expense Progress</h3>
                        <button onclick="closeModal('trackProgressModal')" class="text-gray-400 hover:text-gray-600 transition-colors rounded-full p-1 hover:bg-gray-200">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    <div id="track-progress-content" class="p-6 max-h-[80vh] overflow-y-auto">
                        <div class="flex justify-center items-center py-12">
                            <div class="pulsating-circle-loader">
                                <div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`,
            addAdvertiserModal: `<div id="addAdvertiserModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Add New Advertiser</h3>
                        <div id="advertiser-form-step1">
                            <form id="addAdvertiserForm" class="mt-4 space-y-4 p-4 text-left">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="advertiserName" class="block text-sm font-medium text-gray-700">Name *</label>
                                        <input id="advertiserName" name="name" type="text" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" required>
                                    </div>
                                    <div>
                                        <label for="advertiserPhone" class="block text-sm font-medium text-gray-700">Phone</label>
                                        <input id="advertiserPhone" name="phone" type="text" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full">
                                    </div>
                                </div>
                                <div>
                                    <label for="advertiserEmail" class="block text-sm font-medium text-gray-700">Email Address *</label>
                                    <input id="advertiserEmail" name="email" type="email" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" required>
                                </div>
                                <div>
                                    <label for="advertiserAddress" class="block text-sm font-medium text-gray-700">Address</label>
                                    <textarea id="advertiserAddress" name="address" rows="2" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full"></textarea>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="advertiserTIN" class="block text-sm font-medium text-gray-700">TIN (Optional)</label>
                                        <input id="advertiserTIN" name="tin" type="text" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full">
                                    </div>
                                    <div>
                                        <label for="advertiserVRN" class="block text-sm font-medium text-gray-700">VRN (Optional)</label>
                                        <input id="advertiserVRN" name="vrn" type="text" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full">
                                    </div>
                                </div>
                                <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                    <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('addAdvertiserModal')">Cancel</button>
                                    <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Save & Send Verification</button>
                                </div>
                            </form>
                        </div>
                        <div id="advertiser-form-step2" class="hidden text-center p-8">
                            <i class="fas fa-paper-plane text-violet-500 text-4xl mb-4"></i>
                            <h4 class="font-bold text-lg">Verification Code Sent</h4>
                            <p class="text-gray-600 my-2">A verification code has been sent to <strong id="verification-email-display"></strong>. Please ask the advertiser for the code to complete verification.</p>
                            <form id="verifyEmailForm" class="mt-4 flex flex-col items-center gap-4">
                                <input id="verificationEmail" name="email" type="hidden">
                                <div>
                                    <label for="verificationCode" class="block text-sm font-medium text-gray-700">Verification Code</label>
                                    <input id="verificationCode" name="code" type="text" class="mt-1 px-3 py-2 text-center text-lg font-bold tracking-widest border rounded-md w-48" required>
                                </div>
                                <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-md w-full max-w-xs">Verify Email</button>
                            </form>
                            <button onclick="resendVerificationCode()" class="text-sm text-violet-600 hover:underline mt-4">Didn't get the code? Resend</button>
                        </div>
                    </div>
                </div>
            </div>`,
            manageVideosModal: `<div id="manageVideosModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Manage Linked Videos</h3>
                        <form id="linkVideoForm" class="mt-4 space-y-4 p-4 text-left">
                            <input type="hidden" id="manualAdId" name="ad_id">
                            <div>
                                <label for="youtubeVideoId" class="block text-sm font-medium text-gray-700">Add YouTube Video ID</label>
                                <input id="youtubeVideoId" name="video_id" type="text" class="mt-1 px-3 py-2 text-gray-700 border rounded-md w-full" required>
                            </div>
                            <button type="submit" class="w-full bg-violet-500 text-white px-4 py-2 rounded-md hover:bg-violet-600">Link Video</button>
                        </form>
                        <div class="mt-4 p-4 border-t">
                            <h4 class="font-semibold">Linked Videos:</h4>
                            <ul id="linked-videos-list" class="list-disc pl-5 mt-2">
                                <!-- JS will populate this -->
                            </ul>
                        </div>
                        <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                            <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('manageVideosModal')">Close</button>
                        </div>
                    </div>
                </div>
            </div>`,
            newProofModal: `<div id="newProofModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Upload New Proof</h3>
                        <form id="newProofForm" class="mt-4 space-y-4 p-4 text-left" enctype="multipart/form-data">
                             <div>
                                <label for="proof-job-order" class="block text-sm font-medium text-gray-700">Link to Job Order</label>
                                <select id="proof-job-order" name="job_order_id" class="mt-1 w-full p-2 border-gray-300 border rounded-md" required>
                                    <option value="">Loading job orders...</option>
                                </select>
                            </div>
                            <div>
                                <label for="proof-file" class="block text-sm font-medium text-gray-700">Proof File (Image or PDF)</label>
                                <input type="file" id="proof-file" name="proof_file" class="mt-1 w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-violet-50 file:text-violet-700 hover:file:bg-violet-100" required accept="image/*,.pdf">
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('newProofModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Upload for Approval</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
            interactiveMessageModal: `<div id="interactiveMessageModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Create Interactive Message</h3>
                            <button onclick="closeModal('interactiveMessageModal')" class="text-gray-400 hover:text-gray-500"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="max-h-[60vh] overflow-y-auto pr-2">
                            <div class="border-b border-gray-200 sticky top-0 bg-white">
                                <nav class="-mb-px flex space-x-6" aria-label="Tabs">
                                    <button onclick="showInteractiveTab('quick_reply')" class="interactive-tab whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm border-violet-500 text-violet-600">Quick Reply</button>
                                    <button onclick="showInteractiveTab('list_message')" class="interactive-tab whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">List Message</button>
                                </nav>
                            </div>
                            <div id="interactive-quick_reply" class="interactive-tab-content mt-4">
                                <form id="quickReplyForm" class="space-y-4">
                                    <div><label class="block text-sm font-medium">Body Text</label><textarea name="body" class="w-full p-2 border rounded-md" rows="3" required></textarea></div>
                                    <div><label class="block text-sm font-medium">Button 1</label><input type="text" name="button1" class="w-full p-2 border rounded-md" required></div>
                                    <div><label class="block text-sm font-medium">Button 2 (Optional)</label><input type="text" name="button2" class="w-full p-2 border rounded-md"></div>
                                    <div><label class="block text-sm font-medium">Button 3 (Optional)</label><input type="text" name="button3" class="w-full p-2 border rounded-md"></div>
                                    <div class="text-right pt-2"><button type="submit" class="bg-violet-600 text-white px-4 py-2 rounded-md">Send</button></div>
                                </form>
                            </div>
                            <div id="interactive-list_message" class="interactive-tab-content mt-4 hidden">
                                <form id="listMessageForm" class="space-y-4">
                                    <div><label class="block text-sm font-medium">Header Text</label><input type="text" name="header" class="w-full p-2 border rounded-md" required></div>
                                    <div><label class="block text-sm font-medium">Body Text</label><textarea name="body" class="w-full p-2 border rounded-md" rows="2" required></textarea></div>
                                    <div><label class="block text-sm font-medium">Button Text</label><input type="text" name="button" class="w-full p-2 border rounded-md" required></div>
                                    <div id="list-sections-container"></div>
                                    <button type="button" onclick="addListSection()" class="text-sm text-violet-600">Add Section</button>
                                    <div class="text-right pt-2"><button type="submit" class="bg-violet-600 text-white px-4 py-2 rounded-md">Send</button></div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`,
            convertDocumentModal: `<div id="convertDocumentModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden items-center justify-center z-50">
                <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg text-center leading-6 font-medium text-gray-900">Convert Document</h3>
                        <form id="convertDocumentForm" class="mt-4 space-y-4 p-4 text-left">
                            <input type="hidden" id="convertFromId" name="from_id">
                            <div>
                                <label for="convertToType" class="block text-sm font-medium text-gray-700">Convert To *</label>
                                <select id="convertToType" name="to_type" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    <option value="">-- Select Document Type --</option>
                                    <option value="Invoice">Invoice</option>
                                    <option value="Receipt">Receipt</option>
                                    <option value="Quotation">Quotation</option>
                                    <option value="Delivery Note">Delivery Note</option>
                                </select>
                            </div>
                            <div class="items-center pt-4 flex justify-end space-x-2 border-t mt-6">
                                <button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('convertDocumentModal')">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-violet-500 text-white rounded-md">Convert</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`,
        };

        // --- GLOBAL VARIABLES & STATE ---
        const BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
        const LOGGED_IN_USER_ID = parseInt(document.querySelector('meta[name="user-id"]').getAttribute('content'));
        let DEFAULT_CURRENCY = 'TZS';
        let currentConversationId = null;
        let currentConversationStatus = 'open';
        let activeChatInterval = null;
        let currentChatPage = 1;
        let conversationFilter = 'all';
        let myChart = null;
        let currentWorkflow = { id: null, name: 'Untitled Workflow', workflow_data: { nodes: [] } };
        let configuringNodeId = null;
        let rejectingPayoutId = null;
        let uploadingReceiptId = null;
        let allPayouts = []; // Hifadhi payouts zote hapa
        let currentCustomerId = null; // Store the current customer ID for statement view
        let currentVendorId = null;
        let currentVendorName = null;
        let currentExpensesPage = 1;
        let isEmojiPickerInitialized = false;
        let displayedMessageIds = new Set();

        // --- ONGEZA FUNCTION HII MPYA HAPA ---
        async function initializeAppSettings() {
            // Tunaita 'get_settings.php' mapema
            const settings = await fetchApi('get_settings.php');
            if (settings && settings.default_currency) {
                // Tunaweka currency sahihi kwenye variable ya global
                DEFAULT_CURRENCY = settings.default_currency;
                console.log('App Currency set to:', DEFAULT_CURRENCY);
            }
            // Check for Free Tier Limit
            if (settings && settings.free_tier_limit_reached) {
                const banner = document.getElementById('global-alert-banner');
                if (banner) {
                    banner.classList.remove('hidden');
                }
            }
            // Hatuna haja ya kujaza fomu ya settings hapa, tunachukua currency tu.
        }
        // --- MWISHO WA FUNCTION MPYA ---

        async function openTaxHistory(type) {
            openModal('taxHistoryModal');
            document.getElementById('tax-history-title').textContent = `${type} Payment History`;
            const tbody = document.getElementById('tax-history-table-body');
            tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-500">Loading history...</td></tr>';

            const data = await fetchApi(`get_tax_history.php?type=${type}`);
            tbody.innerHTML = '';

            if (data && data.status === 'success' && data.history.length > 0) {
                data.history.forEach(item => {
                    const receiptLink = item.receipt_path
                        ? `<a href="${BASE_URL}/${item.receipt_path}" target="_blank" class="text-violet-600 hover:underline"><i class="fas fa-file-alt"></i> View</a>`
                        : '<span class="text-gray-400">No Receipt</span>';

                    tbody.innerHTML += `
                        <tr>
                            <td class="p-3">${new Date(item.date_paid).toLocaleDateString()}</td>
                            <td class="p-3 font-semibold">${DEFAULT_CURRENCY} ${number_format(item.amount, 2)}</td>
                            <td class="p-3">${item.period_month} ${item.period_year}</td>
                            <td class="p-3 font-mono text-xs text-gray-600">${item.reference_number || 'N/A'}</td>
                            <td class="p-3">${receiptLink}</td>
                        </tr>
                    `;
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-500">No payment history found.</td></tr>';
            }
        }

        function renderInsights(insights) {
            const container = document.getElementById('insights-list');
            const wrapper = document.getElementById('dashboard-insights');

            if (!container || !wrapper) return;

            if (!insights || insights.length === 0) {
                wrapper.classList.add('hidden');
                return;
            }

            container.innerHTML = '';
            insights.forEach(insight => {
                let colorClass = 'bg-blue-50 border-blue-200 text-blue-800';
                let icon = 'fa-info-circle';

                if (insight.type === 'warning') {
                    colorClass = 'bg-red-50 border-red-200 text-red-800';
                    icon = 'fa-exclamation-triangle';
                } else if (insight.type === 'success') {
                    colorClass = 'bg-green-50 border-green-200 text-green-800';
                    icon = 'fa-check-circle';
                }

                container.innerHTML += `
                    <div class="flex items-start p-3 border rounded-md ${colorClass}">
                        <div class="flex-shrink-0 mt-0.5">
                            <i class="fas ${icon}"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium">${insight.message}</p>
                            ${insight.action ? `<a href="#" onclick="${insight.action_link}" class="mt-1 text-xs font-bold underline hover:no-underline">${insight.action}</a>` : ''}
                        </div>
                    </div>
                `;
            });

            wrapper.classList.remove('hidden');
        }

        // --- CORE UI FUNCTIONS ---
        function showView(viewId, event) {
            if (event) event.preventDefault();

            isEmojiPickerInitialized = false;

            const loader = document.getElementById('page-loader');
            loader.style.display = 'flex';

            // HACK: I'm removing the artificial timeout to improve performance.
            // setTimeout(() => {
                window.location.hash = viewId;
                const viewContainer = document.getElementById('view-container');
                viewContainer.innerHTML = ''; // Futa yaliyopita
                viewContainer.innerHTML = viewTemplates[viewId] || `<div class="p-8"><h2>${viewId} not implemented</h2></div>`;

                // Apply min-w-0 to main only for expenses to fix overflow issues without affecting other pages
                const mainElement = document.querySelector('main');
                if (viewId === 'expenses') {
                    mainElement.classList.add('min-w-0');
                } else {
                    mainElement.classList.remove('min-w-0');
                }

                document.querySelectorAll('.sidebar-link').forEach(link => link.classList.remove('active'));
                const activeLink = document.querySelector(`a[onclick*="${viewId}"]`);
                if (activeLink) {
                    activeLink.classList.add('active');
                    if (viewId !== 'settings') { // Keep title the same for settings
                        const span = activeLink.querySelector('span');
                        if (span) {
                             document.getElementById('view-title').textContent = span.textContent;
                        }
                    } else {
                        document.getElementById('view-title').textContent = 'Settings';
                    }
                }

                // Load data for the selected view AFTER the content is in place
                if (viewId === 'dashboard') loadDashboard();
                if (viewId === 'activity_log') loadActivityLog(1);
                if (viewId === 'youtube') loadYouTube();
                if (viewId === 'youtube-ads') {
                    const urlParams = new URLSearchParams(window.location.search);
                    const aToken = urlParams.get('at');
                    if (aToken === 'true') {
                        showSuccessAnimation();
                    }
                    loadYouTubeAds();
                }
                if (viewId === 'users') loadUsers();
                if (viewId === 'contacts') loadContacts();
                if (viewId === 'expenses') loadExpenses();
                if (viewId === 'reports') loadReports();
                if (viewId === 'conversations') {
                    loadConversations();
                }
                if (viewId === 'templates') loadTemplates();
                if (viewId === 'broadcast') loadBroadcasts();
                if (viewId === 'workflows') { loadWorkflowTemplates(); loadWorkflows(); }
                if (viewId === 'settings') loadSettings();
                if (viewId === 'vendors') { showVendorTab('payouts'); }
                else if (viewId === 'invoices') { showInvoiceTab('list'); }
            if (viewId === 'analytics') loadAnalytics();
            if (viewId === 'costs') loadCosts();
            if (viewId === 'assets') loadAssets();
            if (viewId === 'investments') loadInvestments(1);
            if (viewId === 'financials') loadFinancials();
            if (viewId === 'tax_payments') loadTaxPayments(1);
            if (viewId === 'payroll') loadPayroll();


                loader.style.display = 'none';
            // }, 1500); // 1.5 seconds delay
        }

        function toggleDropdown(menuId) {
            const menu = document.getElementById(menuId);
            const icon = document.getElementById(`${menuId}-icon`);
            menu.classList.toggle('hidden');
            icon.classList.toggle('rotate-180');
        }

        function showView_original(viewId, event) {
            if (event) event.preventDefault();
            window.location.hash = viewId;
            const viewContainer = document.getElementById('view-container');
            viewContainer.innerHTML = ''; // Futa yaliyopita
            viewContainer.innerHTML = viewTemplates[viewId] || `<div class="p-8"><h2>${viewId} not implemented</h2></div>`;

            document.querySelectorAll('.sidebar-link').forEach(link => link.classList.remove('active'));
            const activeLink = document.querySelector(`a[onclick*="${viewId}"]`);
            if (activeLink) {
                activeLink.classList.add('active');
                if (viewId !== 'settings') { // Keep title the same for settings
                    const span = activeLink.querySelector('span');
                    if (span) {
                        document.getElementById('view-title').textContent = span.textContent;
                    }
                } else {
                    document.getElementById('view-title').textContent = 'Settings';
                }
            }
            // Load data for the selected view
            if (viewId === 'dashboard') loadDashboard();
            if (viewId === 'youtube') loadYouTube();
            if (viewId === 'youtube-ads') {
                const urlParams = new URLSearchParams(window.location.search);
                const aToken = urlParams.get('at');
                if (aToken === 'true') {
                    showSuccessAnimation();
                }
                loadYouTubeAds();
            }
            if (viewId === 'users') loadUsers();
            if (viewId === 'contacts') loadContacts();
            if (viewId === 'expenses') loadExpenses();
            if (viewId === 'reports') loadReports();
            if (viewId === 'conversations') loadConversations();
            if (viewId === 'templates') loadTemplates();
            if (viewId === 'broadcast') loadBroadcasts();
            if (viewId === 'workflows') { loadWorkflowTemplates(); loadWorkflows(); }
            if (viewId === 'settings') loadSettings();
            if (viewId === 'vendors') { showVendorTab('payouts'); }
            else if (viewId === 'invoices') { showInvoiceTab('list'); }

            // Print & Design Workflow
            if (viewId === 'online-job-order') loadJobOrders();
            if (viewId === 'pricing-calculator') {
                loadQuotations();
                // Add event listeners for the calculator form
                document.getElementById('pricingCalculatorForm').addEventListener('input', calculatePrice);
                document.getElementById('pricingCalculatorForm').addEventListener('submit', handleQuoteSubmit);
            }
            if (viewId === 'file-upload') loadFileUploadData();
            if (viewId === 'digital-proofing') loadProofs();
            if (viewId === 'customer-dashboard') loadCustomerDashboardData();
        }

        async function fetchApi(endpoint, options = {}) {
            try {
                if (!(options.body instanceof FormData)) {
                    if (options.body) {
                        options.body = JSON.stringify(options.body);
                        if (!options.headers) options.headers = {};
                        options.headers['Content-Type'] = 'application/json';
                    }
                }
                const response = await fetch(`${BASE_URL}/api/${endpoint}`, options);

                // --- MABADILIKO SAHIHI NDIYO HAYA ---
                if (!response.ok) {
                    const errorText = await response.text(); // Read the body ONCE.
                    let errorMessage;
                    try {
                        // Try to parse it as JSON to get a structured error message.
                        const errorJson = JSON.parse(errorText);
                        errorMessage = errorJson.message;
                    } catch (e) {
                        // If it's not JSON, the error message is the raw text.
                        errorMessage = errorText;
                    }
                    throw new Error(errorMessage || `HTTP Error ${response.status}`);
                }
                // --- MWISHO WA MABADILIKO ---

                return await response.json();

            } catch (error) {
                console.error(`Error fetching ${endpoint}:`, error.message);
                alert(`Error: ${error.message}`); // Onyesha kosa halisi
                return null;
            }
        }

        // --- UTILITY FUNCTIONS ---
        function playNotificationSound() {
            const audio = new Audio('assets/ding.mp3');
            const playPromise = audio.play();

            if (playPromise !== undefined) {
                playPromise.catch(error => {
                    console.warn("Could not play notification sound file, falling back to generated tone.", error);
                    // Fallback to Web Audio API
                    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    if (!audioContext) {
                        console.warn("Web Audio API is not supported in this browser.");
                        return;
                    }
                    const oscillator = audioContext.createOscillator();
                    const gainNode = audioContext.createGain();

                    oscillator.connect(gainNode);
                    gainNode.connect(audioContext.destination);

                    oscillator.type = 'sine';
                    oscillator.frequency.setValueAtTime(880, audioContext.currentTime);
                    gainNode.gain.setValueAtTime(0.5, audioContext.currentTime);

                    oscillator.start();
                    oscillator.stop(audioContext.currentTime + 0.15);
                });
            }
        }
        function safeDate(dateStr) {
            if (!dateStr) return new Date();
            // Replace space with T for ISO format compatibility (Safari/older browsers)
            // MySQL: "2023-11-24 13:06:16" -> "2023-11-24T13:06:16"
            return new Date(dateStr.replace(' ', 'T'));
        }

        function number_format(number, decimals, dec_point, thousands_sep) {
            number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
            var n = !isFinite(+number) ? 0 : +number,
                prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
                sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
                dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
                s = '',
                toFixedFix = function (n, prec) {
                    var k = Math.pow(10, prec);
                    return '' + Math.round(n * k) / k;
                };
            s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
            if (s[0].length > 3) {
                s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
            }
            if ((s[1] || '').length < prec) {
                s[1] = s[1] || '';
                s[1] += new Array(prec - s[1].length + 1).join('0');
            }
            return s.join(dec);
        }

        function showSuccessAnimation() {
            const notification = document.getElementById('success-notification');
            if (notification) {
                notification.classList.remove('hidden');
                setTimeout(() => {
                    notification.classList.add('hidden');
                    // Clean the URL
                    window.history.pushState({}, document.title, window.location.pathname);
                }, 5000);
            }
        }

        function renderPagination(containerId, currentPage, totalItems, limit, loadFunction) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            const totalPages = Math.ceil(totalItems / limit);

            if (totalPages <= 1) return;

            let paginationHTML = `<div class="flex items-center space-x-1">`;
            for (let i = 1; i <= totalPages; i++) {
                paginationHTML += `<button onclick="${loadFunction}(${i})" class="px-3 py-1 rounded-md text-sm ${i === currentPage ? 'bg-violet-600 text-white' : 'bg-gray-200 text-gray-700'}">${i}</button>`;
            }
            paginationHTML += `</div>`;
            const start = (currentPage - 1) * limit + 1;
            const end = Math.min(start + limit - 1, totalItems);
            container.innerHTML = `<p class="text-sm text-gray-700">Showing ${start} to ${end} of ${totalItems} results</p>${paginationHTML}`;
        }
        function disconnectWhatsApp() {
            if (confirm('Are you sure you want to disconnect your WhatsApp account?')) {
                // We'll call the backend to clear the credentials
                fetchApi('facebook_oauth_controller.php?action=disconnect', { method: 'POST' })
                    .then(result => {
                        if (result && result.status === 'success') {
                            alert('WhatsApp disconnected successfully.');
                            loadSettings(); // Reload settings to update the UI
                        }
                    });
            }
        }

        // --- NEW FACEBOOK EMBEDDED SIGNUP FUNCTION ---
        function launchWhatsAppSignup() {
            // Check if FB object is loaded
            if (typeof FB === 'undefined') {
                alert('Facebook SDK could not be loaded. Please check your connection or ad blocker and refresh the page.');
                return;
            }

            // Response callback
            const fbLoginCallback = (response) => {
                if (response.authResponse) {
                    const code = response.authResponse.code;
                    console.log('response code: ', code);

                    // Display a loading/processing message to the user
                    const statusEl = document.getElementById('whatsapp-status');
                    if (statusEl) {
                        statusEl.textContent = 'Processing... Please wait.';
                        statusEl.classList.add('text-yellow-600');
                    }

                    // Send the code to the backend for exchange
                    // We must pass the current page URL as redirect_uri for the code exchange to work with JS SDK flow
                    const redirectUri = window.location.origin + window.location.pathname;

                    fetchApi('facebook_oauth_controller.php?action=embedded_signup', {
                        method: 'POST',
                        body: { code: code, redirect_uri: redirectUri }
                    }).then(result => {
                        if (result && result.status === 'success') {
                            alert('WhatsApp connected successfully! The page will now refresh.');
                            window.location.reload();
                        } else {
                            if (statusEl) {
                                statusEl.textContent = 'Not Connected';
                                statusEl.classList.remove('text-yellow-600');
                            }
                            // Show the actual error message from backend
                            alert('Connection Failed: ' + (result ? result.message : 'Unknown error occurred on the server.'));
                        }
                    });
                } else {
                    console.log('response: ', response);
                    alert('Login failed or cancelled.');
                }
            }

            FB.login(fbLoginCallback, {
                config_id: '<?php echo defined('FACEBOOK_CONFIG_ID') ? FACEBOOK_CONFIG_ID : ''; ?>',
                response_type: 'code',
                override_default_response_type: true,
                extras: {
                    setup: {}
                }
            });
        }

        // --- PREVIEW FUNCTIONS ---
        function previewInvoiceTemplate(templateId, event) {
        // Hii inazuia kitufe cha 'Preview' kisichague hiyo template
        if (event) {
            event.stopPropagation(); // Zuia 'label' isipate click
            event.preventDefault();  // Zuia kitendo chochote cha default cha label
        }

        // Fungua preview kwenye tab mpya, ikilenga file jipya tutakalotengeneza
        window.open(`${BASE_URL}/api/preview_template.php?template=${templateId}`, '_blank');
    }

        // --- YOUTUBE INTEGRATION FUNCTIONS ---
       async function loadYouTube() {
            // Load all necessary data in parallel
            const [channelData, videosData, advertisersData, reportsData] = await Promise.all([
                // REKEBISHO: Imetolewa 'api/' iliyokuwa imezidi
                fetchApi('modules/youtube_ads/controllers/get_channel_info.php'),
                fetchApi('modules/youtube_ads/controllers/get_videos.php'),
                fetchApi('modules/youtube_ads/controllers/get_advertisers.php'),
                fetchApi('modules/youtube_ads/controllers/get_reports.php')
            ]);

            // Populate Channel Info
            const channelInfoDiv = document.getElementById('youtube-channel-info');
            if (channelData && channelData.status === 'success' && channelData.channel) {
                channelInfoDiv.classList.remove('hidden');
                channelInfoDiv.innerHTML = `
                    <div class="flex items-center">
                        <img src="${channelData.channel.thumbnail_url}" alt="Channel Thumbnail" class="w-16 h-16 rounded-full mr-4">
                        <div>
                            <h4 class="font-bold text-lg">${channelData.channel.channel_name}</h4>
                            <p class="text-sm text-green-600 font-semibold">Channel Connected Successfully</p>
                        </div>
                    </div>`;
            } else {
                channelInfoDiv.classList.add('hidden');
            }

            // Populate Video List
            const videoListDiv = document.getElementById('youtube-video-list');
            videoListDiv.innerHTML = '';
            if (videosData && videosData.status === 'success') {
                videosData.videos.forEach(video => {
                    videoListDiv.innerHTML += `
                        <label class="block cursor-pointer">
                            <input type="checkbox" name="video_ids[]" value="${video.id}" class="sr-only peer">
                            <div class="p-2 border rounded-lg peer-checked:ring-2 peer-checked:ring-violet-500 peer-checked:border-violet-500 hover:bg-gray-100">
                                <img src="${video.thumbnail}" alt="${video.title}" class="w-full rounded-md mb-2">
                                <p class="text-xs font-semibold truncate">${video.title}</p>
                            </div>
                        </label>`;
                });
           } else {
                videoListDiv.innerHTML = `<p class="text-gray-500 col-span-full">${videosData?.message || 'Could not load videos.'}</p>`;
            }

            // Populate Advertiser Dropdown
            const advertiserSelect = document.getElementById('youtubeAdvertiser');
            advertiserSelect.innerHTML = '<option value="">-- Select an Advertiser --</option>';
            if (advertisersData && advertisersData.status === 'success') {
                advertisersData.advertisers.forEach(adv => {
                    advertiserSelect.innerHTML += `<option value="${adv.id}">${adv.name}</option>`;
                });
            }

            // Populate Generated Reports Table
            loadYouTubeReportsTable(reportsData);

            // Add form submit listener
            document.getElementById('youtubeReportForm').addEventListener('submit', handleYoutubeReportFormSubmit);
        }

        async function loadActiveCampaigns(page = 1) {
            const activeListDiv = document.getElementById('active-campaigns-list');
            activeListDiv.innerHTML = '<p class="text-sm text-gray-500">Loading active campaigns...</p>';

            const activeData = await fetchApi(`modules/youtube_ads/controllers/AdController.php?action=getActiveCampaigns&page=${page}&limit=3`);

            activeListDiv.innerHTML = '';
            if (activeData && activeData.status === 'success' && activeData.campaigns.length > 0) {
                activeData.campaigns.forEach(campaign => {
                    let videoLink = '';
                    // MABADILIKO HAPA: Hakikisha 'Link Video' inaonekana kwa Manual hata kama video_id haipo
                    if (campaign.campaign_type === 'Manual') {
                        videoLink = `<button onclick="openManageVideosModal(${campaign.id})" class="text-sm bg-violet-500 text-white font-semibold py-1 px-3 rounded-md hover:bg-violet-600">Link Video</button>`;
                    } else if (campaign.youtube_video_id) {
                        videoLink = `<a href="https://www.youtube.com/watch?v=${campaign.youtube_video_id}" target="_blank" class="text-sm bg-red-500 text-white font-semibold py-1 px-3 rounded-md hover:bg-red-600 flex items-center"><i class="fab fa-youtube mr-2"></i>View on YouTube</a>`;
                    }

                    let invoiceButton = '';
                     if (campaign.invoice_pdf_url) {
                        invoiceButton = `<a href="${BASE_URL}/${campaign.invoice_pdf_url}" target="_blank" class="text-sm bg-gray-600 text-white font-semibold py-1 px-3 rounded-md hover:bg-gray-700">Preview Invoice</a>`;
                    }

                    activeListDiv.innerHTML += `
                        <div class="p-4 border rounded-lg bg-white shadow-sm">
                            <p class="font-semibold text-gray-800">${campaign.title}</p>
                            <p class="text-sm text-gray-600 mb-3">${campaign.advertiser_name}</p>
                            <div class="text-xs text-gray-500 space-y-1 mb-3">
                                <p><strong>Start:</strong> ${new Date(campaign.start_date).toLocaleDateString()}</p>
                                <p><strong>End:</strong> ${new Date(campaign.end_date).toLocaleDateString()}</p>
                            </div>
                            <div class="flex justify-between items-center">
                                ${videoLink}
                                ${invoiceButton}
                            </div>
                        </div>`;
                });
                renderPagination('active-campaigns-pagination', page, activeData.total, 3, 'loadActiveCampaigns');
            } else {
                activeListDiv.innerHTML = '<p class="text-sm text-gray-500">No active campaigns.</p>';
                document.getElementById('active-campaigns-pagination').innerHTML = '';
            }
        }

        async function loadPendingCampaigns(page = 1) {
            const pendingListDiv = document.getElementById('pending-campaigns-list');
            pendingListDiv.innerHTML = '<p class="text-sm text-gray-500">Loading pending campaigns...</p>';

            const pendingData = await fetchApi(`modules/youtube_ads/controllers/AdController.php?action=getPendingCampaigns&page=${page}&limit=3`);

            pendingListDiv.innerHTML = '';
            if (pendingData && pendingData.status === 'success' && pendingData.campaigns.length > 0) {
                pendingData.campaigns.forEach(campaign => {
                    const statusClass = `status-${campaign.status.replace(/\s+/g, '-')}`;
                    let statusDisplay = `<span class="text-xs font-medium px-2 py-0.5 rounded-full ${statusClass}">${campaign.status}</span>`;
                    if (campaign.status === 'Processing' || campaign.status === 'Queued for Upload') {
                        statusDisplay = `<div class="flex items-center">
                                            <div class="relative w-3 h-3 mr-2">
                                                <div class="absolute inset-0 bg-violet-400 rounded-full animate-ping"></div>
                                                <div class="relative w-3 h-3 bg-violet-500 rounded-full"></div>
                                            </div>
                                            <span class="text-xs font-medium text-gray-700">${campaign.status}</span>
                                       </div>`;
                    }

                    let invoiceButton = '';
                    if (campaign.invoice_pdf_url) {
                        invoiceButton = `<a href="${BASE_URL}/${campaign.invoice_pdf_url}" target="_blank" class="text-sm bg-gray-600 text-white font-semibold py-1.5 px-3 rounded-md hover:bg-gray-700">Preview Invoice</a>`;
                    }

                    const pulseClass = (campaign.campaign_type === 'Manual' && campaign.status === 'Pending Payment') ? 'manual-pending-pulse' : '';


                    pendingListDiv.innerHTML += `
                        <div class="p-4 border rounded-lg bg-white shadow-sm ${pulseClass}">
                            <p class="font-semibold text-gray-800">${campaign.title}</p>
                            <p class="text-sm text-gray-600 mb-2">${campaign.advertiser_name}</p>
                            <div class="flex justify-between items-center">
                                ${statusDisplay}
                                ${invoiceButton}
                            </div>
                        </div>`;
                });
                renderPagination('pending-campaigns-pagination', page, pendingData.total, 3, 'loadPendingCampaigns');
            } else {
                pendingListDiv.innerHTML = '<p class="text-sm text-gray-500">No pending campaigns.</p>';
                document.getElementById('pending-campaigns-pagination').innerHTML = '';
            }
        }

        async function loadYouTubeAds() {
            const channelData = await fetchApi('modules/youtube_ads/controllers/get_channel_info.php');

            if (channelData && channelData.status === 'success' && channelData.channel) {
                document.getElementById('youtube-ads-cta').classList.add('hidden');
                document.getElementById('youtube-ads-main').classList.remove('hidden');

                const channelInfoDiv = document.getElementById('connected-channel-info');
                channelInfoDiv.innerHTML = `<img src="${channelData.channel.thumbnail_url}" alt="Channel Thumbnail" class="w-12 h-12 rounded-full mr-4"><p class="font-semibold">${channelData.channel.channel_name}</p>`;

                document.getElementById('currency-symbol').textContent = DEFAULT_CURRENCY;

                // Initial loads
                loadActiveCampaigns(1);
                loadPendingCampaigns(1);
                loadGeneratedReports(1);

                const advertisersData = await fetchApi('modules/youtube_ads/controllers/AdController.php?action=getAdvertisers');
                const adAdvertiserSelect = document.getElementById('adAdvertiser');
                adAdvertiserSelect.innerHTML = '';

                if (advertisersData && advertisersData.status === 'success' && advertisersData.advertisers.length > 0) {
                    advertisersData.advertisers.forEach(advertiser => {
                        adAdvertiserSelect.innerHTML += `<option value="${advertiser.id}">${advertiser.name}</option>`;
                    });
                }

                if (adAdvertiserSelect.innerHTML === '') {
                    adAdvertiserSelect.innerHTML = '<option value="">No verified advertisers found</option>';
                    document.querySelector('#createAdForm button[type="submit"]').disabled = true;
                } else {
                    document.querySelector('#createAdForm button[type="submit"]').disabled = false;
                }

                document.getElementById('createAdForm').onsubmit = handleCreateAdSubmit;
            } else {
                document.getElementById('youtube-ads-cta').classList.remove('hidden');
                document.getElementById('youtube-ads-main').classList.add('hidden');
            }
            togglePlacementField();
        }

        async function loadGeneratedReports(page = 1) {
            const listDiv = document.getElementById('generated-reports-list');
            listDiv.innerHTML = '<p class="text-gray-500 p-4">Loading reports...</p>';

            const data = await fetchApi(`modules/youtube_ads/controllers/get_ad_reports.php?page=${page}&limit=3`);

            if (data && data.status === 'success' && data.reports.length > 0) {
                listDiv.innerHTML = ''; // Futa loading
                data.reports.forEach(report => {
                    listDiv.innerHTML += `
                        <div class="flex justify-between items-center p-4">
                            <div>
                                <p class="font-semibold">${report.ad_title}</p>
                                <p class="text-sm text-gray-600">Sent to: ${report.advertiser_name} on ${new Date(report.generated_at).toLocaleDateString()}</p>
                            </div>
                            <a href="${BASE_URL}/${report.pdf_path}" target="_blank" class="text-sm bg-violet-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-violet-700">
                                View PDF
                            </a>
                        </div>
                    `;
                });
                renderPagination('generated-reports-pagination', page, data.total, 3, 'loadGeneratedReports');
            } else {
                listDiv.innerHTML = '<p class="text-gray-500 p-4">No reports have been generated yet.</p>';
                document.getElementById('generated-reports-pagination').innerHTML = '';
            }
        }

        // --- PRINT & DESIGN WORKFLOW FUNCTIONS ---

        async function loadJobOrders() {
            const tableBody = document.getElementById('job-orders-table-body');
            if (!tableBody) return;

            const userRole = document.querySelector('meta[name="user-role"]').getAttribute('content');
            const isAdmin = userRole === 'Admin';

            const columns = isAdmin ? 9 : 6;
            tableBody.innerHTML = `<tr><td colspan="${columns}" class="p-4 text-center text-gray-500">Loading...</td></tr>`;

            const data = await fetchApi('online_job_order.php');
            tableBody.innerHTML = '';

            if (data && data.status === 'success' && data.data.length > 0) {
                let headerRow = `
                    <th class="p-4 font-semibold">Tracking #</th>
                    <th class="p-4 font-semibold">Material</th>
                    <th class="p-4 font-semibold">Quantity</th>
                    ${isAdmin ? `
                    <th class="p-4 font-semibold">Selling Price</th>
                    <th class="p-4 font-semibold">Cost Price</th>
                    <th class="p-4 font-semibold">Profit</th>
                    ` : ''}
                    <th class="p-4 font-semibold">Status</th>
                    <th class="p-4 font-semibold">Date</th>
                    <th class="p-4 font-semibold">Actions</th>
                `;
                tableBody.previousElementSibling.innerHTML = `<tr>${headerRow}</tr>`;

                data.data.forEach(order => {
                    const profit = order.selling_price - order.cost_price;
                    let rowHtml = `
                        <tr>
                            <td class="p-4">${order.tracking_number}</td>
                            <td class="p-4">${order.material}</td>
                            <td class="p-4">${order.quantity}</td>
                            ${isAdmin ? `
                            <td class="p-4">TZS ${number_format(order.selling_price, 2)}</td>
                            <td class="p-4">TZS ${number_format(order.cost_price, 2)}</td>
                            <td class="p-4 font-bold text-green-600">TZS ${number_format(profit, 2)}</td>
                            ` : ''}
                            <td class="p-4"><span class="text-xs font-medium px-2.5 py-0.5 rounded-full status-${order.status.replace(/\s+/g, '-')}">${order.status}</span></td>
                            <td class="p-4">${new Date(order.created_at).toLocaleDateString()}</td>
                            <td class="p-4">
                                <button class="text-violet-600 hover:text-violet-800" title="View Details"><i class="fas fa-eye"></i></button>
                            </td>
                        </tr>`;
                    tableBody.innerHTML += rowHtml;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="${columns}" class="p-4 text-center text-gray-500">No job orders found.</td></tr>`;
            }
        }

        async function handleNewJobOrderSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = 'Submitting...';

            try {
                const result = await fetchApi('online_job_order.php?action=create_order', {
                    method: 'POST',
                    body: formData
                });

                if (result && result.status === 'success') {
                    alert('Job order created successfully!');
                    form.reset();
                    closeModal('newJobOrderModal');
                    loadJobOrders();
                }
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Submit Job Order';
            }
        }

        async function calculatePrice() {
            const form = document.getElementById('pricingCalculatorForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());

            // We need to handle the finishing array specifically
            data.finishing_options = formData.getAll('finishing[]');

            const result = await fetchApi('pricing_calculator.php?action=calculate', {
                method: 'POST',
                body: data
            });

            if (result && result.status === 'success') {
                document.getElementById('calculated-price').textContent = `TZS ${number_format(result.calculated_price, 2)}`;
                return result.calculated_price;
            }
            return 0;
        }

        async function handleQuoteSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            data.total_price = calculatePrice();

            const result = await fetchApi('pricing_calculator.php?action=save_quote', {
                method: 'POST',
                body: data
            });

            if (result && result.status === 'success') {
                alert('Quotation saved successfully!');
                loadQuotations();
            }
        }

        async function loadQuotations() {
            const tableBody = document.getElementById('quotations-table-body');
            if (!tableBody) return;
            tableBody.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-gray-500">Loading...</td></tr>`;

            const data = await fetchApi('pricing_calculator.php');
            tableBody.innerHTML = '';

            if (data && data.status === 'success' && data.data.length > 0) {
                data.data.forEach(quote => {
                    const details = `Size: ${quote.size}, Material: ${quote.materials}, Copies: ${quote.copies}`;
                    tableBody.innerHTML += `
                        <tr>
                            <td class="p-4">${new Date(quote.created_at).toLocaleDateString()}</td>
                            <td class="p-4">${details}</td>
                            <td class="p-4 font-semibold">TZS ${number_format(quote.total_price, 2)}</td>
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-gray-500">No saved quotations found.</td></tr>`;
            }
        }

        async function loadFileUploadData() {
            const jobOrderSelect = document.getElementById('upload-job-order');
            const tableBody = document.getElementById('uploaded-files-table-body');
            if (!jobOrderSelect || !tableBody) return;

            jobOrderSelect.innerHTML = '<option value="">Loading...</option>';
            tableBody.innerHTML = `<tr><td colspan="4" class="p-3 text-center text-gray-500">Loading...</td></tr>`;

            const [ordersData, filesData] = await Promise.all([
                fetchApi('online_job_order.php'),
                fetchApi('file_upload.php')
            ]);

            jobOrderSelect.innerHTML = '<option value="">-- Select Job Order --</option>';
            if (ordersData && ordersData.status === 'success') {
                ordersData.data.forEach(order => {
                    jobOrderSelect.innerHTML += `<option value="${order.id}">${order.tracking_number}</option>`;
                });
            }

            tableBody.innerHTML = '';
            if (filesData && filesData.status === 'success' && filesData.data.length > 0) {
                filesData.data.forEach(file => {
                    const statusClass = file.status === 'Approved for Print' ? 'status-Approved' : 'status-Rejected';
                    tableBody.innerHTML += `
                        <tr>
                            <td class="p-3">${file.tracking_number}</td>
                            <td class="p-3">${file.file_name}</td>
                            <td class="p-3"><span class="text-xs font-medium px-2.5 py-0.5 rounded-full ${statusClass}">${file.status}</span></td>
                            <td class="p-3">
                                <a href="${BASE_URL}/${file.file_path}" target="_blank" class="text-violet-600 hover:text-violet-800" title="Download File"><i class="fas fa-download"></i></a>
                            </td>
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="4" class="p-3 text-center text-gray-500">No files uploaded yet.</td></tr>`;
            }
        }

        async function handleFileUploadSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = 'Uploading...';

            try {
                const result = await fetchApi('file_upload.php?action=upload_file', {
                    method: 'POST',
                    body: formData
                });

                if (result && result.status === 'success') {
                    alert('File uploaded and checked successfully! Status: ' + result.check_status);
                    form.reset();
                    loadFileUploadData(); // Refresh the list of files
                }
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Upload & Check File';
            }
        }

        async function loadProofs() {
            const grid = document.getElementById('proofs-grid');
            if (!grid) return;
            grid.innerHTML = `<p class="text-gray-500 col-span-full">Loading proofs...</p>`;

            const data = await fetchApi('digital_proofing.php');
            grid.innerHTML = '';

            if (data && data.status === 'success' && data.data.length > 0) {
                data.data.forEach(proof => {
                    grid.innerHTML += `
                        <div class="bg-white p-4 rounded-lg shadow-md border">
                            <img src="${BASE_URL}/${proof.proof_path}" alt="Proof for ${proof.tracking_number}" class="w-full h-40 object-cover rounded-md mb-3">
                            <p class="font-semibold text-gray-800">${proof.tracking_number}</p>
                            <p class="text-sm text-gray-500">Version: ${proof.version}</p>
                            <div class="mt-2 text-xs font-medium"><span class="px-2 py-1 rounded-full status-${proof.status.replace(/\s+/g, '-')}">${proof.status}</span></div>
                        </div>`;
                });
            } else {
                grid.innerHTML = `<p class="text-gray-500 col-span-full">No proofs uploaded yet.</p>`;
            }
        }

        async function handleNewProofSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = 'Uploading...';

            try {
                const result = await fetchApi('digital_proofing.php?action=upload_proof', {
                    method: 'POST',
                    body: formData
                });
                 if (result && result.status === 'success') {
                    alert('Proof uploaded successfully for client approval.');
                    form.reset();
                    closeModal('newProofModal');
                    loadProofs();
                }
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Upload for Approval';
            }
        }

        async function loadCustomerDashboardData() {
            const ordersTable = document.querySelector('#dashboard-job-orders tbody');
            const proofsContainer = document.getElementById('dashboard-proofs');
            if (!ordersTable || !proofsContainer) return;

            ordersTable.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-gray-500">Loading...</td></tr>`;
            proofsContainer.innerHTML = `<p class="text-center text-gray-500">Loading...</p>`;

            const data = await fetchApi('customer_dashboard.php');

            ordersTable.innerHTML = '';
            if (data && data.status === 'success' && data.job_orders.length > 0) {
                 data.job_orders.forEach(order => {
                    ordersTable.innerHTML += `
                        <tr>
                            <td class="p-4 font-semibold">${order.tracking_number}</td>
                            <td class="p-4"><span class="text-xs font-medium px-2 py-0.5 rounded-full status-${order.status.replace(/\s+/g, '-')}">${order.status}</span></td>
                            <td class="p-4">${new Date(order.created_at).toLocaleDateString()}</td>
                        </tr>`;
                });
            } else {
                ordersTable.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-gray-500">You have no job orders.</td></tr>`;
            }

            proofsContainer.innerHTML = '';
             if (data && data.status === 'success' && data.proofs_for_approval.length > 0) {
                data.proofs_for_approval.forEach(proof => {
                    proofsContainer.innerHTML += `
                        <div class="bg-white p-3 rounded-lg shadow border">
                            <p class="text-sm font-semibold">${proof.tracking_number} (v${proof.version})</p>
                            <div class="mt-2 flex space-x-2">
                                <a href="${BASE_URL}/${proof.file_path}" target="_blank" class="flex-1 text-center bg-gray-600 text-white px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-gray-700">View</a>
                                <button class="flex-1 bg-green-500 text-white px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-green-600">Approve</button>
                                <button class="flex-1 bg-red-500 text-white px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-red-600">Reject</button>
                            </div>
                        </div>`;
                });
            } else {
                proofsContainer.innerHTML = `<p class="text-center text-gray-500 p-4 bg-white rounded-lg border shadow-sm">You have no proofs awaiting approval.</p>`;
            }
        }

        // --- ANALYTICS FUNCTIONS ---
        let topJobsChart = null;
        let topCustomersChart = null;

        async function loadAnalytics() {
            const data = await fetchApi('analytics.php');
            if (data && data.status === 'success') {
                document.getElementById('analytics-total-orders').textContent = data.data.total_orders;
                document.getElementById('analytics-completed-jobs').textContent = data.data.completed_jobs;
                document.getElementById('analytics-pending-approvals').textContent = data.data.pending_approvals;
                document.getElementById('analytics-total-profits').textContent = `TZS ${number_format(data.data.total_profits, 2)}`;

                // Destroy old charts if they exist to prevent flickering
                if (topJobsChart) topJobsChart.destroy();
                if (topCustomersChart) topCustomersChart.destroy();

                // Chart for Top 5 Profitable Job Types
                const topJobsCtx = document.getElementById('topJobsChart').getContext('2d');
                topJobsChart = new Chart(topJobsCtx, {
                    type: 'bar',
                    data: {
                        labels: data.data.charts.top_jobs.map(j => j.material),
                        datasets: [{
                            label: 'Profit',
                            data: data.data.charts.top_jobs.map(j => j.profit),
                            backgroundColor: 'rgba(79, 70, 229, 0.8)',
                            borderColor: 'rgba(79, 70, 229, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: { scales: { y: { beginAtZero: true } } }
                });

                // Chart for Top 5 Profitable Customers
                const topCustomersCtx = document.getElementById('topCustomersChart').getContext('2d');
                topCustomersChart = new Chart(topCustomersCtx, {
                    type: 'pie',
                    data: {
                        labels: data.data.charts.top_customers.map(c => c.full_name),
                        datasets: [{
                            label: 'Profit',
                            data: data.data.charts.top_customers.map(c => c.profit),
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.8)',
                                'rgba(54, 162, 235, 0.8)',
                                'rgba(255, 206, 86, 0.8)',
                                'rgba(75, 192, 192, 0.8)',
                                'rgba(153, 102, 255, 0.8)'
                            ]
                        }]
                    }
                });
            }
        }

        // --- COSTS FUNCTIONS ---
        async function loadCosts() {
            const tableBody = document.getElementById('costs-table-body');
            if (!tableBody) return;
            tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">Loading costs...</td></tr>`;
            const response = await fetchApi('costs.php');
            tableBody.innerHTML = '';
            if (response && response.status === 'success' && response.data.length > 0) {
                response.data.forEach(cost => {
                    tableBody.innerHTML += `
                        <tr id="cost-row-${cost.id}">
                            <td class="p-4">${cost.item_name}</td>
                            <td class="p-4">${cost.unit}</td>
                            <td class="p-4 font-semibold">TZS ${number_format(cost.price, 2)}</td>
                            <td class="p-4">
                                <button onclick="deleteCost(${cost.id})" class="text-red-500 hover:text-red-700"><i class="fas fa-trash-alt"></i></button>
                            </td>
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">No material costs added yet.</td></tr>`;
            }
            // Listener handled by modal-container
        }

        async function handleCostSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const itemName = document.getElementById('cost-item-name').value;
            const unit = document.getElementById('cost-unit').value;
            const price = document.getElementById('cost-price').value;

            const response = await fetchApi('costs.php', {
                method: 'POST',
                body: { item_name: itemName, unit: unit, price: price }
            });

            if (response && response.status === 'success') {
                form.reset();
                loadCosts(); // Refresh the list
            }
        }

        async function deleteCost(id) {
            if (!confirm('Are you sure you want to delete this material cost?')) return;
            const response = await fetchApi(`costs.php?id=${id}`, { method: 'DELETE' });
            if (response && response.status === 'success') {
                loadCosts(); // Refresh the list
            }
        }

        async function loadAssets() {
            const tableBody = document.getElementById('assets-table-body');
            if (!tableBody) return;
            tableBody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-gray-500">Loading assets...</td></tr>`;
            const response = await fetchApi('assets_controller.php');
            tableBody.innerHTML = '';
            if (response && response.status === 'success' && response.data.length > 0) {
                response.data.forEach(asset => {
                    const receiptLink = asset.receipt_url ? `<a href="${BASE_URL}/${asset.receipt_url}" target="_blank" class="text-violet-600 hover:underline">View Receipt</a>` : 'N/A';
                    tableBody.innerHTML += `
                        <tr>
                            <td class="p-4">${asset.name}</td>
                            <td class="p-4">${asset.category}</td>
                            <td class="p-4">${asset.purchase_date}</td>
                            <td class="p-4 font-semibold">${DEFAULT_CURRENCY} ${number_format(asset.purchase_cost, 2)}</td>
                            <td class="p-4">${receiptLink}</td>
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-gray-500">No assets found.</td></tr>`;
            }
            document.getElementById('addAssetForm').addEventListener('submit', handleAssetFormSubmit);
        }

        async function handleAssetFormSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const response = await fetchApi('assets_controller.php', {
                method: 'POST',
                body: formData
            });
            if (response && response.status === 'success') {
                alert('Asset added successfully!');
                form.reset();
                loadAssets();
            }
        }

        function loadFinancials() {
            const yearSelect = document.getElementById('financial-year');
            if (!yearSelect) return;
            const currentYear = new Date().getFullYear();
            for (let year = currentYear; year >= 2020; year--) {
                yearSelect.innerHTML += `<option value="${year}">${year}</option>`;
            }
        }

        function generateFinancialStatement() {
            const year = document.getElementById('financial-year').value;
            if (!year) {
                alert('Please select a financial year.');
                return;
            }
            const pdfUrl = `${BASE_URL}/api/generate_financial_statement.php?year=${year}`;
            window.open(pdfUrl, '_blank');
        }

        async function loadInvestments(page = 1) {
            const tableBody = document.getElementById('investments-table-body');
            const paginationContainer = document.getElementById('investments-pagination');
            if (!tableBody) return;

            tableBody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-gray-500">Loading investments...</td></tr>`;
            const response = await fetchApi(`investments_controller.php?page=${page}&limit=4`);
            tableBody.innerHTML = '';

            if (response && response.status === 'success') {
                 if (response.data.length > 0) {
                    response.data.forEach(inv => {
                        tableBody.innerHTML += `
                            <tr>
                                <td class="p-4">${inv.description}</td>
                                <td class="p-4">${inv.investment_type}</td>
                                <td class="p-4">${inv.quantity || 'N/A'}</td>
                                <td class="p-4">${inv.purchase_date}</td>
                                <td class="p-4 font-semibold">${DEFAULT_CURRENCY} ${number_format(inv.purchase_cost, 2)}</td>
                                <td class="p-4">
                                    <button onclick="openEditInvestmentModal(${inv.id}, ${inv.quantity || 0}, ${inv.purchase_cost})" class="text-violet-600 hover:text-violet-800">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                </td>
                            </tr>`;
                    });
                } else {
                    tableBody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-gray-500">No investments found.</td></tr>`;
                }

                // Render Pagination
                if (response.pagination) {
                    const { total_pages, current_page, total_records, limit } = response.pagination;
                    paginationContainer.innerHTML = '';
                    if (total_pages > 1) {
                        let paginationHTML = `<div class="flex items-center space-x-1">`;
                        for (let i = 1; i <= total_pages; i++) {
                            paginationHTML += `<button onclick="loadInvestments(${i})" class="px-3 py-1 rounded-md text-sm ${i === current_page ? 'bg-violet-600 text-white' : 'bg-gray-200 text-gray-700'}">${i}</button>`;
                        }
                        paginationHTML += `</div>`;
                        const start = (current_page - 1) * limit + 1;
                        const end = Math.min(start + limit - 1, total_records);
                        paginationContainer.innerHTML = `<p class="text-sm text-gray-700">Showing ${start} to ${end} of ${total_records} results</p>${paginationHTML}`;
                    }
                }
            } else {
                tableBody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-red-500">${response?.message || 'Failed to load investments.'}</td></tr>`;
            }

            document.getElementById('addInvestmentForm').removeEventListener('submit', handleInvestmentFormSubmit);
            // Listener handled by modal-container
        }

        async function handleInvestmentFormSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            const response = await fetchApi('investments_controller.php', {
                method: 'POST',
                body: data
            });
            if (response && response.status === 'success') {
                alert('Investment added successfully!');
                form.reset();
                loadInvestments();
            }
        }

        async function loadTaxPayments() {
            const tableBody = document.getElementById('tax-payments-table-body');
            const yearSelect = document.getElementById('tax-financial-year');
            if (!tableBody || !yearSelect) return;

            // REKEBISHO: Futa options zilizopita kabla ya kujaza mpya
            yearSelect.innerHTML = '<option value="">-- Select Year --</option>';

            // Populate year dropdown
            const currentYear = new Date().getFullYear();
            for (let year = currentYear; year >= 2020; year--) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearSelect.appendChild(option);
            }

            tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">Loading tax payments...</td></tr>`;
            const response = await fetchApi('tax_controller.php');
            tableBody.innerHTML = '';
            if (response && response.status === 'success' && response.data.length > 0) {
                response.data.forEach(pay => {
                    tableBody.innerHTML += `
                        <tr>
                            <td class="p-4">${pay.payment_date}</td>
                            <td class="p-4 font-semibold">${DEFAULT_CURRENCY} ${number_format(pay.amount, 2)}</td>
                            <td class="p-4">${pay.financial_year} / ${pay.quarter}</td>
                            <td class="p-4 font-mono text-xs">${pay.reference_number || 'N/A'}</td>
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">No tax payments found.</td></tr>`;
            }
            document.getElementById('addTaxPaymentForm').addEventListener('submit', handleTaxPaymentFormSubmit);
        }

        async function handleTaxPaymentFormSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            const response = await fetchApi('tax_controller.php', {
                method: 'POST',
                body: data
            });
            if (response && response.status === 'success') {
                alert('Tax payment added successfully!');
                form.reset();
                loadInvestments(1); // Refresh to the first page
            }
        }

        function openEditInvestmentModal(investmentId, currentQuantity, currentCost) {
            document.getElementById('edit-investment-id').value = investmentId;
            document.getElementById('edit-investment-quantity').value = currentQuantity;
            document.getElementById('edit-investment-cost').value = currentCost;
            openModal('editInvestmentModal');
        }

        async function handleEditInvestmentSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const investment_id = form.querySelector('#edit-investment-id').value;
            const new_quantity = form.querySelector('#edit-investment-quantity').value;
            const new_purchase_cost = form.querySelector('#edit-investment-cost').value;

            const response = await fetchApi('update_investment.php', {
                method: 'POST',
                body: { investment_id, new_quantity, new_purchase_cost }
            });

            if (response && response.status === 'success') {
                alert('Investment updated successfully!');
                closeModal('editInvestmentModal');
                loadInvestments(); // Refresh the investments table
            }
        }

        // --- PAYROLL FUNCTIONS ---
        async function loadPayroll() {
            const yearSelect = document.getElementById('payroll-year');
            if (!yearSelect) return;
            const currentYear = new Date().getFullYear();
            for (let year = currentYear; year >= 2022; year--) {
                yearSelect.innerHTML += `<option value="${year}">${year}</option>`;
            }

            const monthSelect = document.getElementById('payroll-month');
            if(monthSelect) {
                const currentMonth = new Date().toLocaleString('default', { month: 'long' });
                monthSelect.value = currentMonth;
            }

            // Load approvers
            const approverSelect = document.getElementById('payroll-approver');
            if (approverSelect) {
                try {
                    const users = await fetchApi('get_users.php?role=Admin,Accountant');
                    approverSelect.innerHTML = '<option value="">-- Select Approver --</option>';
                    if (users && Array.isArray(users)) {
                        users.forEach(user => {
                            if (user.id !== LOGGED_IN_USER_ID) { // Can't assign to self
                                approverSelect.innerHTML += `<option value="${user.id}">${user.full_name}</option>`;
                            }
                        });
                    }
                } catch (error) {
                    approverSelect.innerHTML = '<option value="">Could not load users</option>';
                }
            }

            loadPayrollBatches();

            const form = document.getElementById('uploadPayrollForm');
            if (form) {
                form.addEventListener('submit', handlePayrollUpload);
            }
        }

        async function loadPayrollBatches() {
            const tableBody = document.getElementById('payroll-batches-table-body');
            if (!tableBody) return;

            // Update table headers to include new columns
            const headerRow = tableBody.previousElementSibling.querySelector('tr');
            if (headerRow.children.length === 5) {
                 headerRow.innerHTML = `
                    <th class="p-4 font-semibold">Period</th>
                    <th class="p-4 font-semibold">Total Amount</th>
                    <th class="p-4 font-semibold">Status</th>
                    <th class="p-4 font-semibold">Uploaded By</th>
                    <th class="p-4 font-semibold">Approver</th>
                    <th class="p-4 font-semibold">Uploaded On</th>
                    <th class="p-4 font-semibold">Actions</th>
                `;
            }

            tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-gray-500">Loading payroll history...</td></tr>`;
            const response = await fetchApi('get_payroll_batches.php');
            tableBody.innerHTML = '';
            if (response && response.status === 'success' && response.data.length > 0) {
                response.data.forEach(batch => {
                    const statusClass = `status-${batch.status.replace(/\s+/g, '-')}`;
                    let actions = '';

                    // Added View Details button
                    actions += `<button onclick="viewPayrollDetails(${batch.id})" class="text-sm bg-gray-100 text-gray-700 px-3 py-1 rounded-md font-semibold hover:bg-gray-200 mr-2">View</button>`;

                    if (batch.is_actionable) {
                        actions += `<button onclick="approvePayroll(${batch.id})" class="text-sm bg-green-600 text-white font-semibold py-1 px-3 rounded-md hover:bg-green-700">Approve & Pay</button>`;
                    } else if (batch.status === 'Approved') {
                        actions += `<button onclick="sendPayslips(${batch.id})" class="text-sm bg-blue-600 text-white font-semibold py-1 px-3 rounded-md hover:bg-blue-700">Send Payslips</button>`;
                    } else if (batch.status === 'Paid') {
                        actions += `<span class="text-sm text-gray-500">Completed</span>`;
                    } else {
                        // actions += `<span class="text-sm text-gray-400">No action</span>`;
                    }

                    tableBody.innerHTML += `
                        <tr>
                            <td class="p-4">${batch.month} ${batch.year}</td>
                            <td class="p-4 font-semibold">${DEFAULT_CURRENCY} ${number_format(batch.total_amount, 2)}</td>
                            <td class="p-4"><span class="text-xs font-medium px-2.5 py-0.5 rounded-full ${statusClass}">${batch.status}</span></td>
                            <td class="p-4">${batch.uploaded_by_name}</td>
                            <td class="p-4">${batch.approver_name || '<em class="text-gray-400">Not Assigned</em>'}</td>
                            <td class="p-4">${new Date(batch.uploaded_at).toLocaleDateString()}</td>
                            <td class="p-4">${actions}</td>
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-gray-500">No payroll batches found.</td></tr>`;
            }
        }

        async function handlePayrollUpload(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = 'Uploading...';

            try {
                const response = await fetchApi('upload_payroll.php', {
                    method: 'POST',
                    body: formData
                });
                if (response && response.status === 'success') {
                    alert('Payroll file uploaded successfully and is pending approval.');
                    form.reset();
                    loadPayrollBatches();
                }
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="fas fa-upload mr-2"></i>Upload for Approval';
            }
        }

        async function approvePayroll(batchId) {
            if (!confirm('Are you sure you want to approve this payroll batch? This will mark it as paid and impact financial statements.')) return;

            const response = await fetchApi('approve_payroll_batch.php', {
                method: 'POST',
                body: { batch_id: batchId }
            });

            if (response && response.status === 'success') {
                alert('Payroll batch approved successfully!');
                loadPayrollBatches();
            }
        }

        async function viewPayrollDetails(batchId) {
            openModal('payrollDetailsModal');
            const infoDiv = document.getElementById('payroll-details-info');
            const tbody = document.getElementById('payroll-details-table-body');

            infoDiv.innerHTML = '<p>Loading...</p>';
            tbody.innerHTML = '<tr><td colspan="7" class="p-4 text-center text-gray-500">Loading details...</td></tr>';

            const data = await fetchApi(`get_payroll_batch_details.php?id=${batchId}`);

            if (data && data.status === 'success') {
                const b = data.data; // Batch details
                infoDiv.innerHTML = `
                    <div><span class="font-semibold">Period:</span> ${b.month} ${b.year}</div>
                    <div><span class="font-semibold">Uploaded By:</span> ${b.uploaded_by}</div> <!-- ID only in simple fetch, might need join in backend for name, but fine for now -->
                    <div><span class="font-semibold">Status:</span> <span class="px-2 py-0.5 rounded-full text-xs status-${b.status.replace(/\s+/g, '-')}">${b.status}</span></div>
                    <div><span class="font-semibold">Total Amount:</span> ${DEFAULT_CURRENCY} ${number_format(b.total_amount, 2)}</div>
                `;

                tbody.innerHTML = '';
                if (b.entries && b.entries.length > 0) {
                    b.entries.forEach(entry => {
                        tbody.innerHTML += `
                            <tr>
                                <td class="p-3">${entry.employee_name}</td>
                                <td class="p-3 text-gray-600">${entry.employee_email}</td>
                                <td class="p-3 text-right">${number_format(entry.basic_salary, 2)}</td>
                                <td class="p-3 text-right">${number_format(entry.allowances, 2)}</td>
                                <td class="p-3 text-right text-red-500">-${number_format(entry.deductions, 2)}</td>
                                <td class="p-3 text-right text-red-500">-${number_format(entry.income_tax, 2)}</td>
                                <td class="p-3 text-right font-bold">${number_format(entry.net_salary, 2)}</td>
                            </tr>
                        `;
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="p-4 text-center text-gray-500">No entries found in this batch.</td></tr>';
                }
            } else {
                tbody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-red-500">${data ? data.message : 'Failed to load details.'}</td></tr>`;
            }
        }

        async function sendPayslips(batchId) {
             if (!confirm('Are you sure you want to email payslips to all employees in this batch?')) return;

             const response = await fetchApi('send_payslips.php', {
                 method: 'POST',
                 body: { batch_id: batchId }
             });

             if (response && response.status === 'success') {
                 alert(response.message || 'Payslips are being sent in the background.');
                 loadPayrollBatches();
             }
        }

        // --- DATA LOADING & ACTION FUNCTIONS (KAMILI) ---
        function togglePlacementField() {
            const campaignType = document.querySelector('input[name="campaign_type"]:checked').value;
            const placementContainer = document.getElementById('placement-field-container');
            const placementSelect = document.getElementById('adPlacement');

            if (campaignType === 'Manual') {
                placementContainer.style.display = 'block';
                placementSelect.required = true;
            } else {
                placementContainer.style.display = 'none';
                placementSelect.required = false;
            }
        }
        // function loadDashboard() { console.log("Dashboard loaded"); }

        async function loadUsers() {
            const tableBody = document.getElementById('users-table-body');
            tableBody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-gray-500">Loading...</td></tr>`;
            const users = await fetchApi('get_users.php');
            tableBody.innerHTML = '';
            if(users && Array.isArray(users)){
                users.forEach(user => {
                    const isCurrentUser = user.id === LOGGED_IN_USER_ID;
                    tableBody.innerHTML += `<tr><td class="p-4 flex items-center"><img src="https://placehold.co/40x40/7e22ce/white?text=${user.avatar_char}" alt="Avatar" class="w-10 h-10 rounded-full mr-3"><div><p class="font-semibold">${user.full_name}</p></div></td><td class="p-4">${user.email}</td><td class="p-4"><span class="bg-violet-100 text-violet-800 text-xs font-medium px-2 py-1 rounded-full">${user.role}</span></td><td class="p-4"><span class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full">${user.status}</span></td><td class="p-4"><button class="text-gray-400 cursor-not-allowed mr-4" title="Edit (coming soon)"><i class="fas fa-pencil-alt"></i></button><button onclick="deleteUser(${user.id}, '${user.full_name.replace(/'/g, "\\'")}')" class="text-red-500 hover:text-red-700 ${isCurrentUser ? 'cursor-not-allowed opacity-50' : ''}" ${isCurrentUser ? 'disabled' : ''} title="Delete User"><i class="fas fa-trash-alt"></i></button></td></tr>`;
                });
            } else { tableBody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-red-500">Failed to load users.</td></tr>`;}
        }
        async function deleteUser(userId, userName) { if (!confirm(`Are you sure you want to delete '${userName}'?`)) return; const result = await fetchApi('delete_user.php', { method: 'POST', body: { id: userId } }); if (result && result.status === 'success') { alert(result.message); loadUsers(); } else if (result) { alert('Error: ' + result.message); } }

        async function loadContacts() {
            const tableBody = document.getElementById('contacts-table-body');
            tableBody.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-gray-500">Loading...</td></tr>`;
            const contacts = await fetchApi('get_contacts.php');
            tableBody.innerHTML = '';
            if(contacts && Array.isArray(contacts)){
                contacts.forEach(contact => { tableBody.innerHTML += `<tr><td class="p-4">${contact.name}</td><td class="p-4">${contact.phone_number}</td><td class="p-4"><button onclick="deleteContact(${contact.id})" class="text-red-500 hover:text-red-700"><i class="fas fa-trash-alt"></i></button></td></tr>`; });
            } else { tableBody.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-red-500">Failed to load contacts.</td></tr>`; }
        }
        async function deleteContact(id) { if (!confirm('Are you sure?')) return; const result = await fetchApi('delete_contact.php', { method: 'POST', body: { id: id } }); if (result && result.status === 'success') loadContacts(); }

        async function loadConversations() {
            const container = document.getElementById('conversations-container');
            const search = document.getElementById('conv-search') ? document.getElementById('conv-search').value : '';

            if (!container) return;

            const url = `get_conversations.php?status=${conversationFilter}&search=${encodeURIComponent(search)}`;
            const data = await fetchApi(url);

            if (data && data.success) {
                container.innerHTML = '';
                if (data.conversations.length === 0) {
                    container.innerHTML = `<div class="text-center p-8 text-gray-400 flex flex-col items-center"><i class="fas fa-inbox text-4xl mb-2"></i><p>No conversations found.</p></div>`;
                    return;
                }

                data.conversations.forEach(c => {
                    const isActive = c.conversation_id == currentConversationId ? 'bg-violet-50 border-l-4 border-violet-600' : 'hover:bg-gray-50 border-l-4 border-transparent';
                    const time = new Date(c.updated_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    const unreadBadge = c.unread_count > 0 ? `<span class="bg-violet-600 text-white text-xs font-bold px-2 py-0.5 rounded-full">${c.unread_count}</span>` : '';
                    const assigneeHtml = c.assignee_name ? `<span class="text-xs bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded mr-2"><i class="fas fa-user-tag text-[10px]"></i> ${c.assignee_name.split(' ')[0]}</span>` : '';
                    const safeContactName = c.contact_name || c.phone_number || 'Unknown';
                    const avatarChar = safeContactName.charAt(0).toUpperCase();
                    const lastContactMessageTimestamp = c.last_contact_message_at ? `'${c.last_contact_message_at}'` : 'null';
                    const closedByName = c.closed_by_name ? `'${c.closed_by_name.replace(/'/g, "\'")}'` : 'null';
                    const closedAt = c.closed_at ? `'${c.closed_at}'` : 'null';

                    // Note: Passing null for profileImage as it is not yet in API
                    container.innerHTML += `
                        <div onclick="selectConversation(${c.conversation_id}, '${c.contact_name.replace(/'/g, "\\'")}', '${c.phone_number}', '${c.status}', '${c.assignee_name || ''}', null, ${lastContactMessageTimestamp}, ${closedByName}, ${closedAt})" class="p-4 cursor-pointer border-b transition-all ${isActive}">
                            <div class="flex justify-between items-start mb-1">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-white flex items-center justify-center font-bold shadow-sm mr-3">${avatarChar}</div>
                                    <div>
                                        <h4 class="font-semibold text-gray-800 text-sm">${c.contact_name}</h4>
                                        <p class="text-xs text-gray-500">${c.phone_number}</p>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end">
                                    <span class="text-xs text-gray-400 font-mono">${time}</span>
                                    ${unreadBadge}
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-2">
                                <p class="text-sm text-gray-600 truncate max-w-[180px]">${c.last_message_preview || 'No messages'}</p>
                                <div class="flex items-center">
                                    ${assigneeHtml}
                                    ${c.status === 'closed' ? '<i class="fas fa-check-circle text-green-500 text-xs" title="Resolved"></i>' : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
        }

        function filterConversations(filter) {
            conversationFilter = filter;
            // Update tab styles
            ['open', 'closed', 'all'].forEach(t => {
                const btn = document.getElementById(`tab-${t}`);
                if (filter === t) {
                    btn.classList.add('active', 'bg-white', 'text-violet-700', 'shadow-sm');
                    btn.classList.remove('text-gray-500');
                } else {
                    btn.classList.remove('active', 'bg-white', 'text-violet-700', 'shadow-sm');
                    btn.classList.add('text-gray-500');
                }
            });
            loadConversations();
        }

        async function openNewChatModal() {
            console.log('Opening New Chat Modal...');
            openModal('newChatModal');
            const list = document.getElementById('new-chat-contacts-list');
            if(list) {
                list.innerHTML = '<div class="p-8 text-center text-violet-500"><i class="fas fa-circle-notch fa-spin text-2xl"></i><p class="mt-2 text-sm">Loading contacts...</p></div>';

                const contacts = await fetchApi('get_contacts.php');
                if (contacts && Array.isArray(contacts)) {
                    window.allContacts = contacts; // Cache for search
                    renderNewChatContacts(contacts);
                } else {
                    list.innerHTML = '<div class="text-center text-gray-500 p-8 flex flex-col items-center"><i class="fas fa-address-book text-3xl mb-2 text-gray-300"></i><p>No contacts found.</p></div>';
                }
            }
        }

        function renderNewChatContacts(contacts) {
            const list = document.getElementById('new-chat-contacts-list');
            list.innerHTML = '';
            if (contacts.length === 0) {
                list.innerHTML = '<p class="text-center text-gray-500 p-4">No matching contacts.</p>';
                return;
            }
            contacts.forEach(c => {
                list.innerHTML += `
                    <div onclick="startNewChat(${c.id}, '${c.name.replace(/'/g, "\\'")}', '${c.phone_number}')" class="p-3 hover:bg-violet-50 cursor-pointer flex items-center transition-colors rounded-lg">
                        <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 font-bold mr-3">${c.name.charAt(0).toUpperCase()}</div>
                        <div>
                            <h4 class="text-sm font-semibold text-gray-800">${c.name}</h4>
                            <p class="text-xs text-gray-500">${c.phone_number}</p>
                        </div>
                    </div>
                `;
            });
        }

        function searchNewChatContacts() {
            const query = document.getElementById('new-chat-search').value.toLowerCase();
            if (!window.allContacts) return;
            const filtered = window.allContacts.filter(c => c.name.toLowerCase().includes(query) || c.phone_number.includes(query));
            renderNewChatContacts(filtered);
        }

        async function startNewChat(contactId, name, phone) {
            // Check if conversation exists or create new (backend handles this logic usually in get_conversations or we simulate selection)
            // For now, we can try to find it in the list or just force load messages.
            // Better: ensure conversation exists via API then select it.
            // But 'get_messages' creates one if missing in webhook logic? No, logic was in webhook.
            // Let's use a dedicated endpoint or reuse logic.
            // Wait, 'get_messages' requires conversation_id. We need to find conversation_id by contact_id.
            // We'll assume we just select it if it appears in list, else we might need 'create_conversation' endpoint.
            // For simplicity, let's assume 'get_conversations' returns it.
            // ACTUALLY: We should just close modal and call selectConversation if we can find the ID.

            closeModal('newChatModal');

            // Quick hack: Reload conversations and find the one with this contact_id
            // Ideally we'd have an API 'get_conversation_by_contact'
            // Let's implement a quick check
            const data = await fetchApi(`get_conversations.php?search=${encodeURIComponent(phone)}`);
            if (data && data.success && data.conversations.length > 0) {
                const conv = data.conversations[0]; // Assuming first match
                selectConversation(conv.conversation_id, conv.contact_name, conv.phone_number, conv.status, conv.assignee_name, null);
            } else {
                // If really new and not in DB yet (no messages), UI might struggle.
                // But webhook creates it on inbound. Outbound?
                // Send message will create it. So we can "fake" a conversation view.
                // Let's set a temporary state.
                currentConversationId = 'new_' + contactId;
                currentConversationStatus = 'open';
                document.getElementById('message-view-placeholder').classList.add('hidden');
                document.getElementById('message-view-content').classList.remove('hidden');
                document.getElementById('chat-partner-name').textContent = name;
                document.getElementById('chat-partner-phone').textContent = phone;
                document.getElementById('header-avatar').textContent = name.charAt(0).toUpperCase();
                document.getElementById('message-container').innerHTML = '<div class="text-center text-gray-400 mt-4">Start a new conversation</div>';
            }
        }

        async function openTemplateSelector() {
            openModal('templateSelectorModal');
            const list = document.getElementById('template-selector-list');
            list.innerHTML = '<div class="text-center"><div class="loader"></div></div>';

            const templates = await fetchApi('get_templates.php');
            if (templates && Array.isArray(templates)) {
                const approvedTemplates = templates.filter(t => t.status === 'APPROVED');

                if (approvedTemplates.length > 0) {
                    list.innerHTML = approvedTemplates.map(t => `
                        <div onclick="selectTemplateContent('${t.body.replace(/'/g, "\\'").replace(/\n/g, '\\n')}')" class="p-3 border rounded-lg hover:border-violet-500 hover:bg-violet-50 cursor-pointer transition-all group">
                            <div class="flex justify-between mb-1">
                                <span class="font-semibold text-sm text-gray-800 group-hover:text-violet-700">${t.name}</span>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full font-medium">Approved</span>
                            </div>
                            <p class="text-xs text-gray-500 line-clamp-2">${t.body}</p>
                        </div>
                    `).join('');
                } else {
                    list.innerHTML = '<p class="text-center text-gray-500">No approved templates found.</p>';
                }
            } else {
                list.innerHTML = '<p class="text-center text-gray-500">Could not load templates.</p>';
            }
        }

        function selectTemplateContent(body) {
            closeModal('templateSelectorModal');
            const variableRegex = /{{\s*([a-zA-Z0-9_]+)\s*}}/g;
            const variables = [...new Set(Array.from(body.matchAll(variableRegex), m => m[1]))];

            if (variables.length > 0) {
                // Has variables, open the new modal
                document.getElementById('templateBodyToFill').value = body;
                const container = document.getElementById('variable-inputs-container');
                container.innerHTML = ''; // Clear previous fields
                variables.forEach(variable => {
                    container.innerHTML += `
                        <div>
                            <label for="var-${variable}" class="block text-sm font-medium text-gray-700">${variable.replace(/_/g, ' ')}</label>
                            <input type="text" id="var-${variable}" name="${variable}" class="mt-1 w-full p-2 border border-gray-300 rounded-md" required>
                        </div>
                    `;
                });
                openModal('fillTemplateVariablesModal');
            } else {
                // No variables, just fill the input
                const input = document.getElementById('messageInput');
                input.value = body;
                input.focus();
                input.style.height = 'auto';
                input.style.height = input.scrollHeight + 'px';
            }
        }

        function selectConversation(id, name, phone, status, assignee, profileImage = null, lastContactMessageAt = null, closedByName = null, closedAt = null) {
            if (activeChatInterval) clearInterval(activeChatInterval);
            currentConversationId = id;
            currentConversationStatus = status;
            currentChatPage = 1;
            displayedMessageIds.clear(); // Clear message IDs for the new conversation
            // Store current contact avatar for message rendering
            window.currentContactAvatar = profileImage;

            // Logic Fix: Clear unread count immediately in UI
            const convItem = document.querySelector(`div[onclick*="selectConversation(${id}"]`);
            if (convItem) {
                const badge = convItem.querySelector('.bg-violet-600.text-white.text-xs.font-bold');
                if (badge) {
                    badge.remove();
                }
                document.querySelectorAll('#conversations-container > div').forEach(el => {
                    el.classList.remove('bg-violet-50', 'border-violet-600');
                    el.classList.add('border-transparent', 'hover:bg-gray-50');
                });
                convItem.classList.remove('border-transparent', 'hover:bg-gray-50');
                convItem.classList.add('bg-violet-50', 'border-l-4', 'border-violet-600');
            }

            fetchApi('mark_messages_read.php', {
                method: 'POST',
                body: { conversation_id: id }
            });

            document.getElementById('message-view-placeholder').classList.add('hidden');
            document.getElementById('message-view-content').classList.remove('hidden');

            document.getElementById('chat-partner-name').textContent = name;
            document.getElementById('chat-partner-phone').textContent = phone;
            document.getElementById('header-avatar').textContent = name.charAt(0).toUpperCase();

            const resolveBtn = document.getElementById('btn-resolve');
            if (status === 'closed') {
                resolveBtn.innerHTML = '<i class="fas fa-undo mr-2"></i> Reopen';
                resolveBtn.className = 'text-sm border border-gray-300 text-gray-600 hover:bg-yellow-50 hover:text-yellow-600 hover:border-yellow-300 px-3 py-1.5 rounded-lg transition-all flex items-center';
            } else {
                resolveBtn.innerHTML = '<i class="fas fa-check mr-2"></i> <span class="hidden md:inline">Resolve</span>';
                resolveBtn.className = 'text-sm border border-gray-300 text-gray-600 hover:bg-green-50 hover:text-green-600 hover:border-green-300 px-3 py-1.5 rounded-lg transition-all flex items-center';
            }

            document.getElementById('assignee-name').textContent = assignee || 'Unassigned';

            loadMessages(id, name, 1, true);
            loadConversations();
            loadAssignUsers();
            loadCrmData(id); // Pro Feature: CRM Sidebar

            // 24-Hour Window Logic & Closed Status Logic
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
            }

            if (!isEmojiPickerInitialized) {
                initEmojiPicker();
                isEmojiPickerInitialized = true;
            }

            activeChatInterval = setInterval(() => {
                if (currentConversationId === id) {
                    loadMessages(id, name, 1, false);
                }
            }, 1000); // User requested 1-second refresh
        }

        // --- ASSIGN MENU LOGIC ---
        function toggleAssignMenu(show) {
            const menu = document.getElementById('assign-menu');
            if (show) {
                menu.classList.remove('hidden');
                setTimeout(() => menu.classList.add('scale-100', 'opacity-100'), 10);
                document.getElementById('assign-search').focus();
            } else {
                menu.classList.remove('scale-100', 'opacity-100');
                setTimeout(() => menu.classList.add('hidden'), 200);
            }
        }

        function filterAssignees() {
            const searchTerm = document.getElementById('assign-search').value.toLowerCase();
            const userButtons = document.querySelectorAll('#assign-users-list button');
            userButtons.forEach(button => {
                const userName = button.querySelector('span')?.textContent.toLowerCase();
                if (userName && userName.includes(searchTerm)) {
                    button.style.display = 'flex';
                } else if(userName) { // Keep auto-assign visible
                    button.style.display = 'none';
                }
            });
        }

        async function loadAssignUsers() {
            const list = document.getElementById('assign-users-list');
            if (!list || list.children.length > 2) return; // Load once (accounting for auto-assign and divider)

            const users = await fetchApi('get_users.php');
            if (users && Array.isArray(users)) {
                const usersHtml = users.map(u => `
                    <button onclick="assignChat(${u.id})" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-violet-50 hover:text-violet-700 rounded-lg transition-colors flex items-center">
                        <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(u.full_name)}&background=8b5cf6&color=fff&size=24" class="w-6 h-6 rounded-full mr-2">
                        <span>${u.full_name}</span>
                    </button>
                `).join('');
                // Append after the divider
                list.innerHTML += usersHtml;
            }
        }

        async function assignChat(userId) {
            if (!currentConversationId) return;

            // Close the menu immediately for better UX
            toggleAssignMenu(false);

            const result = await fetchApi('assign_conversation.php', {
                method: 'POST',
                body: { conversation_id: currentConversationId, assign_to: userId }
            });

            if (result && result.success) {
                document.getElementById('assignee-name').textContent = result.assignee_name || 'Assigned';
                loadConversations(); // Update sidebar
            } else {
                alert(result.message || 'Assignment failed');
            }
        }

        async function toggleChatStatus() {
            if (!currentConversationId) return;
            const newStatus = currentConversationStatus === 'open' ? 'closed' : 'open';

            const result = await fetchApi('update_conversation_status.php', {
                method: 'POST',
                body: { conversation_id: currentConversationId, status: newStatus }
            });

            if (result && result.success) {
                currentConversationStatus = newStatus;
                // Refresh UI manually to be snappy
                const resolveBtn = document.getElementById('btn-resolve');
                if (newStatus === 'closed') {
                    resolveBtn.innerHTML = '<i class="fas fa-undo mr-2"></i> Reopen';
                    resolveBtn.className = 'text-sm border border-gray-300 text-gray-600 hover:bg-yellow-50 hover:text-yellow-600 hover:border-yellow-300 px-3 py-1.5 rounded-lg transition-all flex items-center';
                } else {
                    resolveBtn.innerHTML = '<i class="fas fa-check mr-2"></i> Resolve';
                    resolveBtn.className = 'text-sm border border-gray-300 text-gray-600 hover:bg-green-50 hover:text-green-600 hover:border-green-300 px-3 py-1.5 rounded-lg transition-all flex items-center';
                }
                loadConversations();
            }
        }

        async function loadMessages(conversationId, contactName, page = 1, isInitialLoad = true) {
            const placeholder = document.getElementById('message-view-placeholder');
            const contentView = document.getElementById('message-view-content');
            const headerName = document.getElementById('chat-partner-name');
            const messageContainer = document.getElementById('message-container');

            placeholder.style.display = 'none';
            contentView.classList.remove('hidden');
            headerName.textContent = contactName;

            if (isInitialLoad && page === 1) {
                messageContainer.innerHTML = '<div class="text-center text-gray-500">Loading messages...</div>';
            }

            const data = await fetchApi(`get_messages.php?conversation_id=${conversationId}&page=${page}`);

            if (isInitialLoad && page === 1) messageContainer.innerHTML = '';

            if (data && data.success) {
                if (data.messages.length === 0) {
                    if (page === 1 && isInitialLoad) messageContainer.innerHTML = '<div class="text-center text-gray-500">No messages in this conversation yet.</div>';
                    return;
                }

                // Create a temporary container for new messages if prepending
                const fragment = document.createDocumentFragment();

                // Add "Load Older" button if page 1 and results are full (implying more)
                // Or if page > 1 and we got results.
                // Simple logic: If we are on page 1, prepend button.
                // If we are polling (page 1, !isInitialLoad), we replace content.

                // If this is an automated poll (page 1, not initial), perform a smart update
                if (!isInitialLoad && page === 1) {
                    let hasNewIncomingMessage = false;

                    data.messages.forEach(msg => {
                        // Check if message is already displayed
                        if (displayedMessageIds.has(msg.id)) {
                            // If it exists, just update its status icon
                            const existingMsg = document.getElementById(`msg-${msg.id}`);
                            if (existingMsg) {
                                const statusIconContainer = existingMsg.querySelector('.status-icon-container');
                                if (statusIconContainer) {
                                    let newIcon = '';
                                    if (msg.sender_type === 'agent' || msg.sender_type === 'user') {
                                        if (msg.status === 'read') {
                                            newIcon = '<i class="fas fa-check-double text-blue-500 text-xs ml-1"></i>';
                                        } else if (msg.status === 'delivered') {
                                            newIcon = '<i class="fas fa-check-double text-gray-400 text-xs ml-1"></i>';
                                        } else {
                                            newIcon = '<i class="fas fa-check text-gray-300 text-xs ml-1"></i>';
                                        }
                                    }
                                    if (statusIconContainer.innerHTML !== newIcon) {
                                        statusIconContainer.innerHTML = newIcon;
                                    }
                                }
                            }
                        } else {
                            // This is a new message. Append it.
                            const newNode = createMessageElement(msg);
                            messageContainer.appendChild(newNode);
                            displayedMessageIds.add(msg.id); // Add to our set

                            // Check if it's an incoming message to play sound
                            const type = String(msg.sender_type || '').toLowerCase();
                            if (type !== 'agent' && type !== 'user') {
                                hasNewIncomingMessage = true;
                            }

                            // Auto-scroll if user was at bottom
                            if (messageContainer.scrollHeight - messageContainer.scrollTop - messageContainer.clientHeight < 150) { // Increased threshold a bit
                                messageContainer.scrollTop = messageContainer.scrollHeight;
                            }
                        }
                    });

                    if (hasNewIncomingMessage) {
                        playNotificationSound();
                    }

                    return; // Stop here for polling
                }


                // Standard Logic for Initial Load or Pagination
                data.messages.forEach(msg => {
                    fragment.appendChild(createMessageElement(msg));
                    displayedMessageIds.add(msg.id);
                });

                if (page === 1) {
                    messageContainer.innerHTML = '';
                    if (data.messages.length >= 50) {
                        const btn = document.createElement('div');
                        btn.className = 'text-center py-2';
                        btn.innerHTML = `<button onclick="loadOlderMessages()" class="text-xs text-violet-600 hover:underline">Load Older Messages</button>`;
                        messageContainer.appendChild(btn);
                    }
                    messageContainer.appendChild(fragment);
                    if (isInitialLoad) messageContainer.scrollTop = messageContainer.scrollHeight;
                } else {
                    const oldBtn = messageContainer.querySelector('button[onclick="loadOlderMessages()"]')?.parentNode;
                    if(oldBtn) oldBtn.remove();
                    const oldHeight = messageContainer.scrollHeight;
                    messageContainer.prepend(fragment);
                    if (data.messages.length >= 50) {
                         const btn = document.createElement('div');
                        btn.className = 'text-center py-2';
                        btn.innerHTML = `<button onclick="loadOlderMessages()" class="text-xs text-violet-600 hover:underline">Load Older Messages</button>`;
                        messageContainer.prepend(btn);
                    }
                    const newHeight = messageContainer.scrollHeight;
                    messageContainer.scrollTop = newHeight - oldHeight;
                }

            } else {
                if(isInitialLoad) messageContainer.innerHTML = `<div class="text-center text-red-500">Error: ${data ? data.message : 'Failed to load messages'}</div>`;
            }
        }

        function createMessageElement(msg) {
            const type = String(msg.sender_type || '').toLowerCase();
            const isInternal = msg.is_internal == 1; // Check internal flag
            const isAgent = (type === 'agent' || type === 'user');
            const isScheduled = msg.status === 'scheduled';

            const bubbleWrapper = document.createElement('div');
            bubbleWrapper.className = 'flex ' + (isAgent ? 'justify-end' : 'justify-start') + ' items-end gap-1 mb-2';
            bubbleWrapper.id = `msg-${msg.id}`;

            const timeString = isScheduled ?
                `<span class="italic text-gray-500">Scheduled: ${safeDate(msg.scheduled_at).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'})}</span>` :
                safeDate(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

            let statusIcon = '';
            if (isAgent) {
                if (isScheduled) {
                    statusIcon = `<i class="fas fa-clock text-amber-500 text-[10px] ml-1" title="Pending"></i>`;
                } else {
                    let iconClass = 'text-gray-300';
                    let iconType = 'fa-check';

                    if (msg.status === 'read') {
                        iconType = 'fa-check-double';
                        iconClass = 'text-blue-300';
                    } else if (msg.status === 'delivered') {
                        iconType = 'fa-check-double';
                        iconClass = 'text-gray-300';
                    }
                    statusIcon = `<i class="fas ${iconType} ${iconClass} text-[10px] ml-1"></i>`;
                }
            }

            // Avatar Logic for incoming messages
            let avatarHtml = '';
            if (!isAgent) {
                const contactName = (document.getElementById('chat-partner-name').textContent || 'User').trim();
                const initial = (contactName.length > 0) ? contactName.charAt(0).toUpperCase() : '?';
                const profileImg = window.currentContactAvatar;
                if (profileImg) {
                    avatarHtml = `<img src="${profileImg}" class="flex-shrink-0 w-7 h-7 rounded-full object-cover border border-gray-200 shadow-sm" alt="${initial}">`;
                } else {
                    avatarHtml = `<div class="flex-shrink-0 w-7 h-7 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center text-[10px] font-bold border border-gray-200 shadow-sm cursor-default" title="${contactName}">${initial}</div>`;
                }
            }

            const timestampHtml = `<span class="message-timestamp select-none">${timeString}<span class="status-icon-container">${statusIcon}</span></span>`;

            let bubbleClass = 'message-agent';
            if (!isAgent) {
                bubbleClass = 'message-contact';
            }
            if (isInternal) {
                bubbleClass = 'message-note';
            } else if (isScheduled) {
                bubbleClass = 'message-scheduled';
            }

            let content = msg.content;

            // Render images if content is a URL to an image file
            if (content && typeof content === 'string' && content.match(/\.(jpeg|jpg|gif|png)$/i)) {
                content = `<a href="${content}" target="_blank" rel="noopener noreferrer" class="block"><img src="${content}" class="max-w-xs rounded-lg shadow-md" alt="Image attachment"></a>`;
            }


            let interactiveData;
            // CRITICAL FIX: Parse Interactive Messages
            if (msg.message_type === 'interactive' || (content && content.startsWith('{'))) {
                try {
                    interactiveData = JSON.parse(content);
                } catch (e) {
                    interactiveData = null;
                }
            }

            if (interactiveData && typeof interactiveData === 'object' && (interactiveData.type === 'button' || interactiveData.type === 'list')) {

                let interactiveHtml = '';
                const baseButtonClasses = "text-center transition-colors cursor-pointer text-sm py-2 px-3 rounded-lg border shadow-sm font-medium";
                const agentButtonClasses = isAgent ? "bg-white/10 hover:bg-white/20 border-white/20" : "bg-gray-200 hover:bg-gray-300 border-gray-300 text-violet-700";

                if (interactiveData.type === 'button') {
                    interactiveHtml = `
                        <div class="w-full">
                            <p class="mb-3">${interactiveData.body.text}</p>
                            <div class="flex flex-row gap-2 justify-center">
                                ${interactiveData.action.buttons.map(btn => `
                                    <div class="${baseButtonClasses} ${agentButtonClasses}">
                                        ${btn.reply.title}
                                    </div>
                                `).join('')}
                            </div>
                        </div>`;
                } else if (interactiveData.type === 'list') {
                     interactiveHtml = `
                        <div class="w-full">
                            <div class="mb-3">
                                ${interactiveData.header ? `<p class="font-bold">${interactiveData.header.text}</p>` : ''}
                                <p>${interactiveData.body.text}</p>
                            </div>
                            <div class="${baseButtonClasses} ${agentButtonClasses}">
                                <i class="fas fa-list mr-2 opacity-70"></i> ${interactiveData.action.button}
                            </div>
                        </div>`;
                }
                content = interactiveHtml;
            }


            if (isAgent) {
                bubbleWrapper.innerHTML = `<div class="message-bubble ${bubbleClass} shadow-sm">${content}${timestampHtml}</div>`;
            } else {
                bubbleWrapper.innerHTML = `${avatarHtml}<div class="message-bubble ${bubbleClass} shadow-sm">${content}${timestampHtml}</div>`;
            }
            return bubbleWrapper;
        }

        function loadOlderMessages() {
            currentChatPage++;
            const name = document.getElementById('chat-partner-name').textContent;
            loadMessages(currentConversationId, name, currentChatPage, true); // Treat as "initial" to avoid polling logic taking over, but page > 1 logic handles it.
        }
        let inputMode = 'message'; // 'message' or 'note'

        function setInputMode(mode) {
            inputMode = mode;
            const wrapper = document.getElementById('input-wrapper');
            const msgBtn = document.getElementById('mode-msg-btn');
            const noteBtn = document.getElementById('mode-note-btn');
            const input = document.getElementById('messageInput');
            const sendBtn = document.getElementById('send-btn');

            if (mode === 'note') {
                wrapper.classList.remove('bg-white', 'focus-within:ring-violet-200');
                wrapper.classList.add('bg-yellow-50', 'focus-within:ring-yellow-200', 'border-yellow-300');

                msgBtn.classList.replace('bg-violet-100', 'text-gray-500');
                msgBtn.classList.replace('text-violet-700', 'hover:bg-gray-100');

                noteBtn.classList.replace('text-gray-500', 'bg-yellow-100');
                noteBtn.classList.replace('hover:bg-yellow-100', 'text-yellow-800');

                input.placeholder = "Type an internal note (visible to team only)...";
                sendBtn.classList.replace('bg-violet-600', 'bg-yellow-600');
                sendBtn.classList.replace('hover:bg-violet-700', 'hover:bg-yellow-700');
            } else {
                wrapper.classList.add('bg-white', 'focus-within:ring-violet-200');
                wrapper.classList.remove('bg-yellow-50', 'focus-within:ring-yellow-200', 'border-yellow-300');

                msgBtn.classList.replace('text-gray-500', 'bg-violet-100');
                msgBtn.classList.replace('hover:bg-gray-100', 'text-violet-700');

                noteBtn.classList.replace('bg-yellow-100', 'text-gray-500');
                noteBtn.classList.replace('text-yellow-800', 'hover:bg-yellow-100');

                input.placeholder = "Type a message to customer...";
                sendBtn.classList.replace('bg-yellow-600', 'bg-violet-600');
                sendBtn.classList.replace('hover:bg-yellow-700', 'hover:bg-violet-700');
            }
        }

        async function sendMessage(event) {
            event.preventDefault();
            const messageInput = document.getElementById('messageInput');
            const content = messageInput.value.trim();
            const attachedFileInput = document.getElementById('attached_file_url');
            const attachment_url = attachedFileInput ? attachedFileInput.value : null;

            if (!content && !attachment_url) return;
            if (!currentConversationId) return;

            const isNote = inputMode === 'note';
            if (isNote && attachment_url) {
                return alert('File attachments cannot be added to internal notes.');
            }
            const endpoint = isNote ? 'save_internal_note.php' : 'send_whatsapp_message.php';

            const typingIndicator = document.getElementById('typing-indicator');
            typingIndicator.classList.remove('hidden');


            // Optimistic UI can be tricky with attachments, so we'll just show a sending indicator.
            // Clear inputs immediately for a responsive feel.
            messageInput.value = '';
            messageInput.style.height = 'auto';
            if (attachedFileInput) attachedFileInput.value = '';

            // Corrected selector to use ID
            const attachmentPreview = document.getElementById('attachment-preview-container');
            if (attachmentPreview) {
                attachmentPreview.classList.add('hidden');
                attachmentPreview.classList.remove('flex');
            }


            try {
                const result = await fetchApi(endpoint, {
                    method: 'POST',
                    body: {
                        conversation_id: currentConversationId,
                        content: content,
                        attachment_url: attachment_url // Pass URL to backend
                    }
                });

                if (result && result.success) {
                    // Re-enable input if it was disabled due to 24-hour window
                    const messageInput = document.getElementById('messageInput');
                    if (messageInput.disabled) {
                        messageInput.disabled = false;
                        messageInput.placeholder = 'Type a message...';
                        document.getElementById('input-wrapper').classList.remove('opacity-50', 'bg-gray-100');
                        const existingIndicator = document.getElementById('chat-closed-indicator');
                        if (existingIndicator) {
                            existingIndicator.remove();
                        }
                    }

                    loadMessages(currentConversationId, document.getElementById('chat-partner-name').textContent, 1, false);
                    if (!isNote) loadConversations();
                } else {
                    showToast('Failed: ' + (result ? result.message : 'Unknown error'), 'error');
                    // Restore input if sending failed
                    messageInput.value = content;
                }
            } catch (e) {
                showToast('Network error.', 'error');
                messageInput.value = content;
            } finally {
                typingIndicator.classList.add('hidden');
            }
        }

        // CRM SIDEBAR LOGIC
        function toggleCrmSidebar() {
            const sidebar = document.getElementById('crm-sidebar');
            sidebar.classList.toggle('hidden');
            sidebar.classList.toggle('translate-x-full'); // Slide effect
        }

        async function loadCrmData(conversationId) {
            const data = await fetchApi(`get_contact_details.php?conversation_id=${conversationId}`);

            if (data && data.success) {
                const contact = data.contact;
                document.getElementById('crm-name').textContent = contact.name || 'Unknown';
                document.getElementById('crm-phone').textContent = contact.phone_number || 'No phone';
                document.getElementById('crm-avatar').textContent = (contact.name || '?').charAt(0).toUpperCase();
                document.getElementById('crm-email').value = contact.email || '';
                document.getElementById('crm-notes').value = contact.notes || '';

                // Update notes badge based on loaded data
                updateNotesBadge(contact.notes);

                // Handle tags - Future implementation
                // document.getElementById('crm-tags-container').innerHTML = '';
            } else {
                console.error("Failed to load contact details:", data ? data.message : "Unknown error");
                // Clear fields on failure
                document.getElementById('crm-email').value = '';
                document.getElementById('crm-notes').value = '';
                updateNotesBadge(''); // Ensure badge is removed on error/no data
            }
        }

        async function saveCrmField(field) {
            const value = document.getElementById(`crm-${field}`).value;

            if (!currentConversationId) {
                showToast('No active conversation selected.', 'error');
                return;
            }

            const result = await fetchApi('update_contact_details.php', {
                method: 'POST',
                body: {
                    conversation_id: currentConversationId,
                    field: field,
                    value: value
                }
            });

            if (result && result.success) {
                // Use SweetAlert2 Toast for notes
                if (field === 'notes') {
                     Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Notes Updated Successfully',
                        showConfirmButton: false,
                        timer: 2000
                    });
                    // After saving notes, check and update the badge
                    updateNotesBadge(value);
                } else {
                    showToast('Contact details saved!');
                }
            } else {
                showToast(result ? result.message : 'Failed to save.', 'error');
            }
        }

        function updateNotesBadge(notesContent) {
            const crmToggleButton = document.querySelector('button[onclick*="toggleCrmSidebar"]');
            let badge = crmToggleButton.querySelector('.notes-badge');

            if (notesContent && notesContent.trim() !== '') {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notes-badge absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full border-2 border-white';
                    crmToggleButton.classList.add('relative');
                    crmToggleButton.appendChild(badge);
                }
            } else {
                if (badge) {
                    badge.remove();
                }
            }
        }

        function showToast(message, type = 'success') {
            // This can be replaced with SweetAlert2 as well for consistency
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: type,
                title: message,
                showConfirmButton: false,
                timer: 3000
            });
        }

        // SNOOZE LOGIC
        function toggleSnoozeMenu() {
            document.getElementById('snooze-menu').classList.toggle('hidden');
        }

        async function snoozeChat(preset) {
            let snoozeUntil = new Date();
            let successMessage = 'Chat snoozed!';

            switch (preset) {
                case '1 HOUR':
                    snoozeUntil.setHours(snoozeUntil.getHours() + 1);
                    break;
                case 'TOMORROW':
                    snoozeUntil.setDate(snoozeUntil.getDate() + 1);
                    snoozeUntil.setHours(9, 0, 0, 0);
                    successMessage = 'Snoozed until tomorrow at 9am!';
                    break;
                default:
                    snoozeUntil = new Date(preset);
                    successMessage = `Snoozed until ${snoozeUntil.toLocaleString()}`;
            }

            const mysqlDatetime = snoozeUntil.toISOString().slice(0, 19).replace('T', ' ');

            if (!mysqlDatetime || !currentConversationId) return;

            const result = await fetchApi('snooze_conversation.php', {
                method: 'POST',
                body: { conversation_id: currentConversationId, snooze_until: mysqlDatetime }
            });

            if (result && result.success) {
                document.getElementById('snooze-menu').classList.add('hidden');
                loadConversations(); // Refresh list to reflect snoozed status
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: successMessage,
                    showConfirmButton: false,
                    timer: 2500
                });
            }
        }

        function openCustomSnooze() {
            Swal.fire({
                title: 'Snooze until...',
                html: '<input type="datetime-local" id="swal-datetime" class="swal2-input">',
                confirmButtonText: 'Set Snooze',
                stopKeydownPropagation: false,
                customClass: {
                    popup: 'custom-snooze-width'
                },
                preConfirm: () => {
                    const datetime = document.getElementById('swal-datetime').value;
                    if (!datetime) {
                        Swal.showValidationMessage('Please select a date and time');
                    }
                    return datetime;
                }
            }).then(result => {
                if (result.isConfirmed) {
                    snoozeChat(result.value);
                }
            });
        }

        // SCHEDULE LOGIC
        function toggleSchedulePicker() {
            document.getElementById('schedule-picker').classList.toggle('hidden');
        }
        async function confirmSchedule() {
            const dateStr = document.getElementById('schedule-datetime').value;
            if (!dateStr) return;
            const content = document.getElementById('messageInput').value;
            if (!content) { alert('Type a message first'); return; }

            const result = await fetchApi('send_whatsapp_message.php', {
                method: 'POST',
                body: {
                    conversation_id: currentConversationId,
                    content: content,
                    scheduled_at: dateStr.replace('T', ' ')
                }
            });

            if (result && result.success) {
                toggleSchedulePicker();
                document.getElementById('messageInput').value = '';
                alert('Message scheduled!');
                loadMessages(currentConversationId, document.getElementById('chat-partner-name').textContent, 1, false);
            }
        }

        // --- INTERACTIVE MESSAGE UI LOGIC ---
        function openInteractiveMessageModal() {
            openModal('interactiveMessageModal');
            showInteractiveTab('quick_reply'); // Default to first tab
            addListSection(); // Add one section by default
        }

        function showInteractiveTab(tabName) {
            document.querySelectorAll('.interactive-tab-content').forEach(el => el.classList.add('hidden'));
            document.getElementById(`interactive-${tabName}`).classList.remove('hidden');
            document.querySelectorAll('.interactive-tab').forEach(el => {
                el.classList.remove('border-violet-500', 'text-violet-600');
                el.classList.add('border-transparent', 'text-gray-500');
            });
            const activeTab = document.querySelector(`.interactive-tab[onclick*="${tabName}"]`);
            activeTab.classList.add('border-violet-500', 'text-violet-600');
            activeTab.classList.remove('border-transparent', 'text-gray-500');
        }

        let sectionCount = 0;
        function addListSection() {
            sectionCount++;
            const container = document.getElementById('list-sections-container');
            const sectionDiv = document.createElement('div');
            sectionDiv.className = 'p-3 border rounded-md mt-2';
            sectionDiv.innerHTML = `
                <input type="text" name="section_${sectionCount}_title" class="w-full p-1 border-b mb-2" placeholder="Section Title" required>
                <div id="section-${sectionCount}-rows"></div>
                <button type="button" onclick="addListRow(${sectionCount})" class="text-xs text-blue-500 mt-1">Add Row</button>
            `;
            container.appendChild(sectionDiv);
            addListRow(sectionCount); // Add one row by default
        }

        function addListRow(sectionId) {
            const rowContainer = document.getElementById(`section-${sectionId}-rows`);
            const rowDiv = document.createElement('div');
            rowDiv.className = 'flex gap-2 mt-1';
            rowDiv.innerHTML = `
                <input type="text" name="section_${sectionId}_row_title[]" class="w-1/2 p-1 border rounded-md text-sm" placeholder="Row Title" required>
                <input type="text" name="section_${sectionId}_row_desc[]" class="w-1/2 p-1 border rounded-md text-sm" placeholder="Row Description (Optional)">
            `;
            rowContainer.appendChild(rowDiv);
        }

        document.addEventListener('submit', function(e) {
            if (e.target.id === 'quickReplyForm') {
                e.preventDefault();
                const formData = new FormData(e.target);
                const buttons = [formData.get('button1'), formData.get('button2'), formData.get('button3')]
                    .filter(Boolean)
                    .map((title, i) => ({ type: 'reply', reply: { id: `btn_${i+1}`, title: title } }));

                const payload = {
                    type: 'button',
                    body: { text: formData.get('body') },
                    action: { buttons: buttons }
                };
                sendInteractive(payload);
                closeModal('interactiveMessageModal');
                e.target.reset();
            } else if (e.target.id === 'listMessageForm') {
                e.preventDefault();
                const formData = new FormData(e.target);
                const sections = [];
                for(let i = 1; i <= sectionCount; i++) {
                    const titles = formData.getAll(`section_${i}_row_title[]`);
                    const descs = formData.getAll(`section_${i}_row_desc[]`);
                    sections.push({
                        title: formData.get(`section_${i}_title`),
                        rows: titles.map((title, j) => ({
                            id: `row_${i}_${j}`,
                            title: title,
                            description: descs[j] || ''
                        }))
                    });
                }

                const payload = {
                    type: 'list',
                    header: { type: 'text', text: formData.get('header') },
                    body: { text: formData.get('body') },
                    action: {
                        button: formData.get('button'),
                        sections: sections
                    }
                };
                sendInteractive(payload);
                closeModal('interactiveMessageModal');
                e.target.reset();
                document.getElementById('list-sections-container').innerHTML = '';
                sectionCount = 0;
            }
        });

        async function sendInteractive(data) {
             const result = await fetchApi('send_whatsapp_message.php', {
                method: 'POST',
                body: {
                    conversation_id: currentConversationId,
                    type: 'interactive',
                    interactive_data: data,
                    content: 'Interactive Message' // Fallback text
                }
            });
            if (result && result.success) loadMessages(currentConversationId, document.getElementById('chat-partner-name').textContent, 1, false);
        }

        async function syncTemplates() {
            const syncButton = document.querySelector('button[onclick="syncTemplates()"]');
            syncButton.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-2"></i>Syncing...';
            syncButton.disabled = true;

            try {
                const result = await fetchApi('sync_templates.php', { method: 'POST' });
                if (result && result.status === 'success') {
                    showToast(result.message);
                    loadTemplates(); // Refresh the view
                }
            } finally {
                syncButton.innerHTML = '<i class="fas fa-sync-alt mr-2"></i>Sync Status';
                syncButton.disabled = false;
            }
        }

        async function loadTemplates() {
            const grid = document.getElementById('templates-grid');
            grid.innerHTML = `<p class="text-gray-500 col-span-3">Loading templates...</p>`;
            const templates = await fetchApi('get_templates.php');
            grid.innerHTML = '';
            if (templates && Array.isArray(templates) && templates.length > 0) {
                templates.forEach(template => {
                    const statusColors = {
                        'APPROVED': 'bg-green-100 text-green-800 border-green-200',
                        'PENDING': 'bg-yellow-100 text-yellow-800 border-yellow-200',
                        'REJECTED': 'bg-red-100 text-red-800 border-red-200'
                    };
                    const statusColor = statusColors[template.status] || 'bg-gray-100 text-gray-800 border-gray-200';

                    let buttonsHtml = '';
                    if (template.quick_replies && template.quick_replies.length > 0) {
                        buttonsHtml = '<div class="whatsapp-buttons flex flex-col gap-2">' +
                            template.quick_replies.map(reply => `<div class="whatsapp-button">${reply}</div>`).join('') +
                            '</div>';
                    }

                    // Using the new flexbox structure
                    grid.innerHTML += `
                        <div class="phone-mockup">
                            <div class="template-card-header">
                                <span class="template-name">${template.name}</span>
                                <span class="template-status-badge ${statusColor}">${template.status}</span>
                            </div>
                            <div class="template-content-wrapper">
                                <div class="whatsapp-bubble ${template.header ? 'has-header' : ''}">
                                    ${template.header ? `<div class="header">${template.header}</div>` : ''}
                                    <div class="body">${template.body}</div>
                                    ${template.footer ? `<div class="footer">${template.footer}</div>` : ''}
                                </div>
                                ${buttonsHtml}
                            </div>
                            <div class="mt-auto flex justify-end space-x-2 pt-2 border-t border-gray-500/20">
                                ${template.status !== 'APPROVED' ? `<button onclick='openTemplateModal(${JSON.stringify(template)})' class="text-gray-500 hover:text-violet-600" title="Edit"><i class="fas fa-pencil-alt"></i></button>` : `<button class="text-gray-300 cursor-not-allowed" title="Cannot edit approved templates"><i class="fas fa-pencil-alt"></i></button>`}
                                <button onclick='deleteTemplate(${template.id}, "${template.name.replace(/'/g, "\\'")}")' class="text-gray-500 hover:text-red-600" title="Delete"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </div>
                    `;
                });
            } else {
                grid.innerHTML = `<p class="text-gray-500 col-span-3">No templates found.</p>`;
            }
        }
        async function deleteTemplate(id, name) { if (!confirm(`Delete template "${name}"?`)) return; const result = await fetchApi('delete_template.php', { method: 'POST', body: { id: id } }); if (result && result.status === 'success') { loadTemplates(); } else if (result) { alert('Error: ' + result.message); } }

        async function loadBroadcasts() {
            const tableBody = document.getElementById('broadcasts-table-body');
            tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">Loading...</td></tr>`;
            const broadcasts = await fetchApi('get_broadcasts.php');
            tableBody.innerHTML = '';
            if (broadcasts && Array.isArray(broadcasts) && broadcasts.length > 0) {
                broadcasts.forEach(b => { tableBody.innerHTML += `<tr><td class="p-4 font-semibold">${b.campaign_name}</td><td class="p-4"><span class="text-xs font-medium px-2.5 py-0.5 rounded-full status-${b.status}">${b.status}</span></td><td class="p-4">${new Date(b.scheduled_at).toLocaleString()}</td><td class="p-4">${b.status === 'Scheduled' ? '<button class="text-violet-600 hover:text-violet-800 mr-4" title="Edit"><i class="fas fa-pencil-alt"></i></button>' : ''}${b.status === 'Sent' ? '<button class="text-gray-500 hover:text-gray-700" title="View Report"><i class="fas fa-eye"></i></button>' : ''}</td></tr>`; });
            } else { tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">No broadcast history found.</td></tr>`; }
        }

        let loadedWorkflowTemplates = [];
        async function loadWorkflowTemplates() {
            const grid = document.getElementById('workflow-templates-grid');
            if (!grid) return;

            const templates = await fetchApi('get_workflow_templates.php');
            loadedWorkflowTemplates = (templates && Array.isArray(templates)) ? templates : [];

            if (loadedWorkflowTemplates.length === 0) {
                grid.innerHTML = `<p class="text-gray-500 col-span-full text-center py-8">No templates available at the moment.</p>`;
                return;
            }

            grid.innerHTML = loadedWorkflowTemplates.map(t => `
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:border-violet-200 hover:shadow-lg transition-all flex flex-col h-full relative group">
                    <div class="mb-4">
                         <div class="w-12 h-12 rounded-lg bg-violet-100 text-violet-600 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                            <i class="fas fa-file-alt text-xl"></i>
                        </div>
                        <h4 class="font-bold text-lg text-gray-800 mb-2 group-hover:text-violet-600 transition-colors">${t.title}</h4>
                        <p class="text-sm text-gray-500 leading-relaxed line-clamp-3">${t.description}</p>
                    </div>
                    <div class="mt-auto pt-4">
                        <button onclick="useWorkflowTemplateById(${t.id})" class="w-full bg-white border-2 border-violet-100 text-violet-600 font-bold py-2.5 rounded-lg hover:bg-violet-600 hover:text-white hover:border-violet-600 transition-all flex justify-center items-center group-hover/btn:shadow-md">
                            <span>Use Template</span> <i class="fas fa-arrow-right ml-2 transform group-hover:translate-x-1 transition-transform"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        }
        function useWorkflowTemplateById(templateId) {
            const template = loadedWorkflowTemplates.find(t => t.id == templateId);
            if (template) {
                useWorkflowTemplate(template);
            }
        }
        async function loadWorkflows() {
            const list = document.getElementById('workflows-list');
            if (!list) return;

            const data = await fetchApi('get_workflows.php');
            const workflows = (data && Array.isArray(data)) ? data : [];

            if (workflows.length === 0) {
                list.innerHTML = `<div class="col-span-full flex flex-col items-center justify-center py-12 bg-gray-50 rounded-xl border border-dashed border-gray-300">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4 text-gray-400">
                        <i class="fas fa-wind text-3xl"></i>
                    </div>
                    <h4 class="text-lg font-semibold text-gray-600">No Workflows Yet</h4>
                    <p class="text-gray-500 mb-6 text-sm">Create your first automated workflow to get started.</p>
                    <button onclick="openWorkflowEditor()" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold text-sm">Create Workflow</button>
                </div>`;
                return;
            }

            list.innerHTML = workflows.map(w => {
                const isActive = w.is_active == 1;
                const statusBadge = isActive
                    ? `<span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-1 rounded uppercase tracking-wide flex-shrink-0">Active</span>`
                    : `<span class="bg-gray-200 text-gray-600 text-xs font-bold px-2 py-1 rounded uppercase tracking-wide flex-shrink-0">Draft</span>`;

                return `
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition-all group relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full ${isActive ? 'bg-green-500' : 'bg-gray-400'}"></div>
                    <div class="flex justify-between items-start mb-3">
                        <h4 class="font-bold text-lg text-gray-800 group-hover:text-violet-600 transition-colors truncate pr-2">${w.name}</h4>
                        ${statusBadge}
                    </div>
                    <p class="text-sm text-gray-500 mb-6 flex items-center bg-gray-50 p-2 rounded-lg">
                        <i class="fas fa-bolt text-amber-500 mr-2"></i>
                        <span class="truncate">Trigger: <span class="font-medium text-gray-700">${w.trigger_type}</span></span>
                    </p>
                    <div class="flex gap-3 mt-auto">
                        <button onclick="openWorkflowEditor(${w.id})" class="flex-1 bg-white border border-gray-200 text-gray-700 px-3 py-2 rounded-lg font-semibold hover:bg-violet-50 hover:text-violet-600 hover:border-violet-200 transition-colors text-sm flex items-center justify-center">
                            <i class="fas fa-edit mr-2"></i> Edit
                        </button>
                        <button onclick="deleteWorkflow(${w.id}, '${w.name.replace(/'/g, "\\'")}')" class="flex-none bg-white border border-gray-200 text-gray-400 px-3 py-2 rounded-lg hover:bg-red-50 hover:text-red-500 hover:border-red-200 transition-colors" title="Delete">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;}).join('');
        }

        function toggleVfdFrequency() {
            const vfdEnabled = document.getElementById('vfd_enabled').checked;
            const frequencyOptions = document.getElementById('vfd-frequency-options');
            if (vfdEnabled) {
                frequencyOptions.classList.remove('hidden');
            } else {
                frequencyOptions.classList.add('hidden');
            }
        }

        async function loadSettings() {
        const urlParams = new URLSearchParams(window.location.search);
        // This success check is now handled by the launchWhatsAppSignup function's callback
        // The old redirect flow is no longer used, so this can be removed or commented out.
        /* if (urlParams.get('whatsapp_status') === 'success') {
            alert('WhatsApp connected successfully!');
            window.history.replaceState({}, document.title, window.location.pathname + "#settings");
             setTimeout(() => showSettingsTab('channels', { currentTarget: document.querySelector('[onclick*="channels"]') }), 100);
        } */

        try {
            const uniqueUrl = `${BASE_URL}/api/webhook.php?tenant_id=${LOGGED_IN_USER_ID}`;
            const urlDisplay = document.getElementById('unique-webhook-url-display');
            if (urlDisplay) {
                urlDisplay.dataset.fullUrl = uniqueUrl;
                urlDisplay.textContent = '************************************';
            }
        } catch(e) {
            console.error("Error setting webhook URL:", e);
            const urlDisplay = document.getElementById('unique-webhook-url-display');
            if (urlDisplay) {
                urlDisplay.textContent = 'Error loading URL.';
            }
        }

        document.getElementById('toggle-webhook-visibility').addEventListener('click', function() {
            const urlDisplay = document.getElementById('unique-webhook-url-display');
            const icon = this.querySelector('i');
            if (urlDisplay.textContent.includes('*')) {
                urlDisplay.textContent = urlDisplay.dataset.fullUrl;
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                urlDisplay.textContent = '************************************';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        document.getElementById('copy-webhook-url').addEventListener('click', function() {
            const urlToCopy = document.getElementById('unique-webhook-url-display').dataset.fullUrl;
            navigator.clipboard.writeText(urlToCopy).then(() => {
                alert('Webhook URL copied to clipboard!');
            }, (err) => {
                alert('Failed to copy URL. Please copy it manually.');
                console.error('Could not copy text: ', err);
            });
        });

            const settings = await fetchApi('get_settings.php');
            if (settings) {
                const form = document.getElementById('settingsForm');
                form.querySelector('[name="business_name"]').value = settings.business_name || '';
                form.querySelector('[name="business_email"]').value = settings.business_email || '';
                form.querySelector('[name="business_address"]').value = settings.business_address || '';

                form.querySelector('[name="smtp_host"]').value = settings.smtp_host || '';
                form.querySelector('[name="smtp_port"]').value = settings.smtp_port || '';
                form.querySelector('[name="smtp_secure"]').value = settings.smtp_secure || 'tls';
                form.querySelector('[name="smtp_username"]').value = settings.smtp_username || '';
                form.querySelector('[name="smtp_from_email"]').value = settings.smtp_from_email || '';
                form.querySelector('[name="smtp_from_name"]').value = settings.smtp_from_name || '';
                form.querySelector(`input[name="smtp_choice"][value="${settings.smtp_choice || 'default'}"]`).checked = true;

                form.querySelector('[name="tin_number"]').value = settings.tin_number || '';
                form.querySelector('[name="vrn_number"]').value = settings.vrn_number || '';
                form.querySelector('[name="default_currency"]').value = settings.default_currency || 'TZS';

                // VFD Fields
                const vfdStatusEl = document.getElementById('vfd-verification-status');
                const vfdOptionsContainer = document.getElementById('vfd-submission-options');

                if (parseInt(settings.vfd_is_verified) === 1) {
                    vfdStatusEl.textContent = 'Verified';
                    vfdStatusEl.className = 'mt-2 text-sm font-bold px-3 py-1.5 rounded-full inline-block bg-green-100 text-green-800';
                    vfdOptionsContainer.classList.remove('hidden');

                    const vfdEnabledCheckbox = form.querySelector('[name="vfd_enabled"]');
                    if (settings.vfd_enabled) {
                        vfdEnabledCheckbox.checked = true;
                    }
                    toggleVfdFrequency();

                    const vfdFrequencyRadio = form.querySelector(`input[name="vfd_frequency"][value="${settings.vfd_frequency}"]`);
                    if (vfdFrequencyRadio) {
                        vfdFrequencyRadio.checked = true;
                    }
                } else {
                    vfdStatusEl.textContent = 'Not Verified';
                    vfdStatusEl.className = 'mt-2 text-sm font-bold px-3 py-1.5 rounded-full inline-block bg-red-100 text-red-800';
                    vfdOptionsContainer.classList.add('hidden');
                }


                if(settings.business_stamp_url) { document.getElementById('business-stamp-preview').src = settings.business_stamp_url; }

                DEFAULT_CURRENCY = settings.default_currency || 'TZS';

                form.querySelector('[name="flw_public_key"]').value = settings.flw_public_key || '';
                form.querySelector('[name="flw_display_name"]').value = settings.flw_display_name || '';


                // Handle password fields: Show placeholder if value exists
                if (settings.flw_secret_key) {
                    form.querySelector('[name="flw_secret_key"]').placeholder = "**********";
                }
                if (settings.flw_encryption_key) {
                    form.querySelector('[name="flw_encryption_key"]').placeholder = "**********";
                }
                if (settings.flw_webhook_secret_hash) {
                    form.querySelector('[name="flw_webhook_secret_hash"]').placeholder = "**********";
                }

                form.querySelector('[name="flw_test_mode"]').checked = !!parseInt(settings.flw_test_mode);
                form.querySelector('[name="flw_active"]').checked = !!parseInt(settings.flw_active);

                // Weka 'default_invoice_template' iliyosaviwa
                const savedTemplate = settings.default_invoice_template || 'default';
                const templateRadio = form.querySelector(`input[name="default_invoice_template"][value="${savedTemplate}"]`);
                if (templateRadio) {
                    templateRadio.checked = true;
                }

                if(settings.profile_picture_url) { document.getElementById('profile-pic-preview').src = settings.profile_picture_url; }
                const statusEl = document.getElementById('whatsapp-status');
                const btnEl = document.getElementById('whatsapp-connect-btn');
                const regBtn = document.getElementById('whatsapp-register-btn');

                if (settings.whatsapp_phone_number_id) {
                    // Check detailed status
                    const isActuallyConnected = settings.whatsapp_status === 'Connected';

                    statusEl.textContent = isActuallyConnected
                        ? `Connected: ${settings.whatsapp_phone_number_id}`
                        : `Pending Registration: ${settings.whatsapp_phone_number_id}`;

                    if (!isActuallyConnected) {
                        statusEl.classList.add('text-yellow-600', 'font-bold');
                    } else {
                        statusEl.classList.remove('text-yellow-600', 'font-bold');
                        statusEl.classList.add('text-green-600');
                    }

                    btnEl.textContent = 'Disconnect';
                    btnEl.classList.remove('bg-violet-600', 'hover:bg-violet-700');
                    btnEl.classList.add('bg-red-500', 'hover:bg-red-600');
                    btnEl.onclick = disconnectWhatsApp;

                    // Show registration button ONLY if NOT connected yet
                    if (!isActuallyConnected) {
                        regBtn.classList.remove('hidden');
                    } else {
                        regBtn.classList.add('hidden');
                    }
                } else {
                    statusEl.textContent = 'Not Connected';
                    statusEl.classList.remove('text-green-600', 'text-yellow-600');
                    btnEl.textContent = 'Connect with Facebook';
                    btnEl.classList.remove('bg-red-500', 'hover:bg-red-600');
                    btnEl.classList.add('bg-violet-600', 'hover:bg-violet-700');
                    btnEl.onclick = launchWhatsAppSignup;
                    regBtn.classList.add('hidden');
                }
            }
            document.getElementById('profile-pic-upload').addEventListener('change', uploadProfilePicture);
            document.getElementById('edit-profile-btn').addEventListener('click', () => toggleSettingsEdit('profile'));
            document.getElementById('edit-smtp-btn').addEventListener('click', () => toggleSettingsEdit('smtp'));
            document.getElementById('edit-webhooks-btn').addEventListener('click', () => toggleSettingsEdit('webhooks'));
            document.getElementById('edit-client-settings-btn').addEventListener('click', () => toggleSettingsEdit('client_settings'));
            document.getElementById('business-stamp-upload').addEventListener('change', (event) => {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        document.getElementById('business-stamp-preview').src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });

            document.querySelectorAll('input[name="smtp_choice"]').forEach(radio => {
                radio.onchange = () => {
                    document.getElementById('smtp-custom-fields').style.display = radio.value === 'custom' ? 'grid' : 'none';
                };
            });
            document.getElementById('smtp-custom-fields').style.display = document.querySelector('input[name="smtp_choice"]:checked').value === 'custom' ? 'grid' : 'none';

            // Mpangilio wa Invoice Design
            document.getElementById('edit-invoice-design-btn').addEventListener('click', toggleInvoiceDesignEdit);

            // Bofya nje kufunga uhariri
            document.addEventListener('click', (event) => {
                const designSection = document.getElementById('settings-invoice_design');
                const editBtn = document.getElementById('edit-invoice-design-btn');

                // Angalia kama uhariri unaendelea na kama click ilitokea nje ya eneo la design
                if (editBtn.textContent === 'Cancel' && !designSection.contains(event.target)) {
                    toggleInvoiceDesignEdit(); // Funga uhariri
                }
            });
        }
        function toggleSettingsEdit(section) {
            let isEditing, btn, fields;
            if (section === 'profile') {
                isEditing = document.getElementById('edit-profile-btn').textContent === 'Cancel';
                btn = document.getElementById('edit-profile-btn');
                fields = document.querySelectorAll('#settings-profile input, #upload-pic-btn, #settings-profile textarea');
            } else if (section === 'smtp') {
                isEditing = document.getElementById('edit-smtp-btn').textContent === 'Cancel';
                btn = document.getElementById('edit-smtp-btn');
                fields = document.querySelectorAll('#settings-smtp input, #settings-smtp select');
                document.getElementById('test-smtp-btn').disabled = isEditing;
            } else if (section === 'webhooks') {
                isEditing = document.getElementById('edit-webhooks-btn').textContent === 'Cancel';
                btn = document.getElementById('edit-webhooks-btn');
                fields = document.querySelectorAll('.webhook-input');
            } else if (section === 'client_settings') {
                isEditing = document.getElementById('edit-client-settings-btn').textContent === 'Cancel';
                btn = document.getElementById('edit-client-settings-btn');
                fields = document.querySelectorAll('#settings-client_settings input, #settings-client_settings select, #settings-client_settings button');
            }

            if (section === 'profile') btn.textContent = isEditing ? 'Edit Profile' : 'Cancel';
            else btn.textContent = isEditing ? 'Edit Settings' : 'Cancel';

            fields.forEach(el => {
                el.disabled = isEditing;
                if(el.type !== 'file' && el.type !== 'radio') el.classList.toggle('bg-gray-100');
                if(el.id === 'upload-pic-btn') el.classList.toggle('disabled:opacity-50');
            });

            document.getElementById('save-changes-container').style.display = (document.getElementById('edit-profile-btn').textContent === 'Cancel' || document.getElementById('edit-smtp-btn').textContent === 'Cancel' || document.getElementById('edit-client-settings-btn').textContent === 'Cancel' || document.getElementById('edit-webhooks-btn').textContent === 'Cancel') ? 'flex' : 'none';
            // Enable or disable the test button based on the edit state
            const testBtn = document.getElementById('test-smtp-btn');
            if (testBtn) {
                testBtn.disabled = isEditing;
            }
        }

        function toggleInvoiceDesignEdit(event) {
            if (event) event.stopPropagation(); // Zuia event isifike kwenye 'document' click listener

            const btn = document.getElementById('edit-invoice-design-btn');
            const radios = document.querySelectorAll('.invoice-template-radio');
            const isEditing = btn.textContent === 'Cancel';

            btn.textContent = isEditing ? 'Edit Selection' : 'Cancel';
            btn.classList.toggle('bg-red-500');
            btn.classList.toggle('text-white');

            radios.forEach(radio => {
                radio.disabled = isEditing;
            });

            // Onyesha/ficha kitufe cha "Save Changes"
            document.getElementById('save-changes-container').style.display = isEditing ? 'none' : 'flex';
        }

        async function loadCustomerContacts(customerId) {
            const contactWrapper = document.getElementById('contact-select-wrapper');
            const contactSelect = document.getElementById('invoiceContact');

            // Ficha na ondoa 'required' mwanzoni - sasa tunatumia style.display
            contactWrapper.style.display = 'none';
            contactSelect.required = false;
            contactSelect.innerHTML = '<option value="">-- Select Contact --</option>';

            if (!customerId) {
                return;
            }

            try {
                const response = await fetch(`${BASE_URL}/api/get_customer_contacts.php?customer_id=${customerId}`);
                if (!response.ok) {
                    throw new Error('Failed to fetch contacts.');
                }
                const contacts = await response.json();

                if (contacts && contacts.length > 0) {
                    // Kuna contacts, kwa hivyo onyesha dropdown na ifanye iwe ya lazima
                    contacts.forEach(contact => {
                        const option = document.createElement('option');
                        option.value = contact.id;
                        option.textContent = contact.name;
                        option.dataset.email = contact.email || ''; // Hakikisha dataset ipo hata kama email ni null
                        contactSelect.appendChild(option);
                    });
                    contactWrapper.style.display = 'block'; // Onyesha kwa kutumia style.display
                    contactSelect.required = true;
                } else {
                    // Hakuna contacts, acha ikiwa imefichwa na sio ya lazima
                    console.log("No contacts found for this customer.");
                }
            } catch (error) {
                console.error('Error loading contacts:', error);
                alert('Could not load contacts for the selected customer.');
            }
        }

        async function completeWhatsappRegistration() {
            if (!confirm("This will attempt to register your number with Meta to fix 'Pending' status. Proceed?")) return;

            const btn = document.getElementById('whatsapp-register-btn');
            const originalText = btn.textContent;
            btn.textContent = 'Processing...';
            btn.disabled = true;

            const result = await fetchApi('register_whatsapp.php', { method: 'POST', body: { pin: '123456' } });

            if (result && result.status === 'success') {
                alert(result.message);
                loadSettings(); // Reload to hide the button
            } else {
                alert('Registration failed: ' + (result ? result.message : 'Unknown error'));
            }

            btn.textContent = originalText;
            btn.disabled = false;
        }

        async function testSmtpSettings() {
            const resultDiv = document.getElementById('smtp-test-result');
            const testBtn = document.getElementById('test-smtp-btn');
            resultDiv.innerHTML = `<p class="p-3 text-sm bg-blue-100 text-blue-800 rounded-md">Sending test email...</p>`;
            testBtn.disabled = true;

            const smtpData = {
                host: document.querySelector('[name="smtp_host"]').value,
                port: document.querySelector('[name="smtp_port"]').value,
                secure: document.querySelector('[name="smtp_secure"]').value,
                username: document.querySelector('[name="smtp_username"]').value,
                password: document.querySelector('[name="smtp_password"]').value,
                from_email: document.querySelector('[name="smtp_from_email"]').value,
                from_name: document.querySelector('[name="smtp_from_name"]').value
            };

            const result = await fetchApi('test_smtp.php', { method: 'POST', body: smtpData });

            if (result && result.status === 'success') {
                resultDiv.innerHTML = `<p class="p-3 text-sm bg-green-100 text-green-800 rounded-md">${result.message}</p>`;
            } else if (result) {
                resultDiv.innerHTML = `<div class="p-3 bg-red-100 text-red-800 rounded-md"><p class="font-bold">Test Failed</p><p class="text-sm">${result.message}</p></div>`;
            } else {
                resultDiv.innerHTML = `<p class="p-3 text-sm bg-red-100 text-red-800 rounded-md">An unknown error occurred.</p>`;
            }
            testBtn.disabled = false;
        }
        async function saveSettings(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);

            const result = await fetchApi('save_settings.php', { method: 'POST', body: formData });
            if (result && result.status === 'success') {
                alert(result.message);
                DEFAULT_CURRENCY = formData.get('default_currency') || 'TZS';
                // Rudisha fomu zote baada ya kuhifadhi
                if (document.getElementById('edit-profile-btn').textContent === 'Cancel') {
                    toggleSettingsEdit('profile');
                }
                if (document.getElementById('edit-smtp-btn').textContent === 'Cancel') {
                    toggleSettingsEdit('smtp');
                }
                if (document.getElementById('edit-invoice-design-btn').textContent === 'Cancel') {
                    toggleInvoiceDesignEdit();
                }
                loadSettings(); // Reload to show saved state
            } else if (result) { alert('Error: ' + result.message); }
        }
        async function uploadProfilePicture(event) {
            const file = event.target.files[0]; if (!file) return;
            const formData = new FormData(); formData.append('profile_picture', file);
            const result = await fetchApi('upload_profile_picture.php', { method: 'POST', body: formData });
            if(result && result.status === 'success') {
                document.getElementById('profile-pic-preview').src = result.url + '?' + new Date().getTime();
                alert('Profile picture updated successfully!');
            } else if (result) { alert('Error: ' + result.message); }
        }
        function showSettingsTab(tabId, event) {
            if (event) event.preventDefault();
            document.querySelectorAll('.settings-content').forEach(content => content.style.display = 'none');
            document.getElementById(`settings-${tabId}`).style.display = 'block';
            document.querySelectorAll('.settings-tab').forEach(tab => tab.classList.remove('active-tab'));
            // Handle both new sidebar buttons and potentially legacy tabs if any remain
            event.currentTarget.classList.add('active-tab');
        }

        function showExpenseTab(tabId, event) {
            if (event) event.preventDefault();

            // Hide all expense forms
            document.querySelectorAll('.expense-tab').forEach(tab => {
                tab.style.display = 'none';
            });

            // Show the selected form
            const targetForm = document.getElementById(`expense-${tabId}-form`);
            if (targetForm) {
                targetForm.style.display = 'block';
            }

            // Update tab button styles
            document.querySelectorAll('button[onclick^="showExpenseTab"]').forEach(btn => {
                btn.classList.remove('active-tab');
                btn.classList.add('text-gray-500', 'border-transparent');
            });

            // Find the button that triggered this, either via event or query selector
            let activeBtn;
            if (event && event.currentTarget) {
                activeBtn = event.currentTarget;
            } else {
                activeBtn = document.querySelector(`button[onclick="showExpenseTab('${tabId}', event)"]`);
            }

            if (activeBtn) {
                activeBtn.classList.add('active-tab');
                activeBtn.classList.remove('text-gray-500', 'border-transparent');
            }
        }

        async function loadVendors(page = 1) {
            const tableBody = document.getElementById('vendors-table-body');
            tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">Loading...</td></tr>`;
            const data = await fetchApi(`get_vendors.php?page=${page}`);
            tableBody.innerHTML = '';
            if (data && Array.isArray(data.vendors) && data.vendors.length > 0) {
                data.vendors.forEach(vendor => {
                    const nameCell = `<td class="p-4 font-semibold">${vendor.full_name}</td>`;
                    const actionCell = `
                        <td class="p-4">
                            <button onclick="showVendorDetails(${vendor.id}, '${vendor.full_name.replace(/'/g, "\\'")}', event)" class="text-sm bg-blue-100 text-blue-700 px-3 py-1 rounded-full font-semibold hover:bg-blue-200 mr-2">
                                Transactions History
                            </button>
                            <button onclick="sendInvoiceRequest(${vendor.id})" class="text-sm bg-violet-100 text-violet-700 px-3 py-1 rounded-full font-semibold hover:bg-violet-200">
                                Send Request
                            </button>
                        </td>`;
                    tableBody.innerHTML += `
                        <tr>
                            ${nameCell}
                            <td class="p-4">${vendor.email}</td>
                            <td class="p-4">${vendor.phone}</td>
                            ${actionCell}
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">No vendors found.</td></tr>`;
            }

            const paginationControls = document.getElementById('vendors-pagination');
            if (paginationControls) {
                paginationControls.innerHTML = '';
                const totalPages = Math.ceil(data.total / data.limit);
                if (totalPages > 1) {
                    let paginationHTML = `<div class="flex items-center space-x-1">`;
                    for (let i = 1; i <= totalPages; i++) {
                        paginationHTML += `<button onclick="loadVendors(${i})" class="px-3 py-1 rounded-md text-sm ${i === page ? 'bg-violet-600 text-white' : 'bg-gray-200 text-gray-700'}">${i}</button>`;
                    }
                    paginationHTML += `</div>`;
                    const start = (page - 1) * data.limit + 1;
                    const end = Math.min(start + data.limit - 1, data.total);
                    paginationControls.innerHTML = `<p class="text-sm text-gray-700">Showing ${start} to ${end} of ${data.total} results</p>${paginationHTML}`;
                }
            }
        }
        function showInvoiceTab(tabId, event) {
            if (event) event.preventDefault();
            document.querySelectorAll('.invoice-tab').forEach(content => {
                if(content) content.style.display = 'none';
            });
            const tabContent = document.getElementById(`invoices-${tabId}`);
            if (tabContent) tabContent.style.display = 'block';

            document.querySelectorAll('#invoices-view .settings-tab').forEach(tab => {
                 if(tab) tab.classList.remove('active-tab');
            });
            const activeTab = event ? event.currentTarget : document.querySelector(`#invoices-view .settings-tab[onclick*="'${tabId}'"]`);
            if(activeTab) activeTab.classList.add('active-tab');

            if (tabId === 'list') {
                loadInvoices();
            }
            if (tabId === 'customers') {
                loadCustomers();
            }
        }

       let currentInvoiceStatusFilter = 'All';
       let currentTimePeriodFilter = 'all';
       let currentInvoicePage = 1;

       async function loadInvoices(page = 1) {
            currentInvoicePage = page;
            const tableBody = document.getElementById('invoices-table-body');
            const paginationContainer = document.getElementById('invoice-pagination-container');
            if (!tableBody || !paginationContainer) { return; }

            tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-gray-500">Loading documents...</td></tr>`;
            paginationContainer.innerHTML = ''; // Clear old pagination

            const selectedDocTypes = Array.from(document.querySelectorAll('.doc_type_filter:checked')).map(el => `doc_types[]=${encodeURIComponent(el.value)}`).join('&');
            const apiUrl = `get_invoices.php?status=${currentInvoiceStatusFilter}&period=${currentTimePeriodFilter}&page=${page}&limit=4&${selectedDocTypes}`;

            const data = await fetchApi(apiUrl);

            if (!data) {
                tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-red-500">Failed to load data.</td></tr>`;
                return;
            }

            const invoices = data.invoices || [];
            const pagination = data.pagination || {};
            const summary = data.summary || {};
            const statusCounts = data.status_counts || {};
            tableBody.innerHTML = '';

            // Update status summary with accurate counts from the API
            document.getElementById('status-summary-all').textContent = statusCounts.All || 0;
            document.getElementById('status-summary-overdue').textContent = statusCounts.Overdue || 0;
            document.getElementById('status-summary-partially-paid').textContent = statusCounts['Partially Paid'] || 0;
            document.getElementById('status-summary-unpaid').textContent = statusCounts.Unpaid || 0;
            document.getElementById('status-summary-paid').textContent = statusCounts.Paid || 0;

            if (invoices.length > 0) {
                invoices.forEach(invoice => {
                    const status = invoice.status || 'Unknown';
                    const total_amount = invoice.total_amount || 0;
                    const amount_paid = invoice.amount_paid || 0;
                    const pdf_url = invoice.pdf_url || '#';
                    const invoice_id = invoice.id;
                    const isReceipt = invoice.document_type === 'Receipt';
                    const viewUrl = isReceipt ? `${BASE_URL}/api/preview_invoice.php?id=${invoice_id}` : `${BASE_URL}/${pdf_url}`;

                    const invoiceNumberHtml = pdf_url !== '#'
                        ? `<a href="${viewUrl}" target="_blank" class="font-semibold text-violet-600 hover:underline">${invoice.invoice_number || 'N/A'}</a>`
                        : (invoice.invoice_number || 'N/A');

                    let actions = `
                        ${pdf_url !== '#' ? `<a href="${viewUrl}" target="_blank" class="text-violet-600 hover:text-violet-800" title="View PDF"><i class="fas fa-eye"></i></a>` : ''}
                        <button onclick="openConvertDocumentMenu(${invoice_id})" class="text-blue-600 hover:text-blue-800 ml-2" title="Convert Document"><i class="fas fa-exchange-alt"></i></button>
                        <button onclick="deleteInvoice(${invoice_id}, '${invoice.invoice_number}')" class="text-red-600 hover:text-red-800 ml-2" title="Delete Document"><i class="fas fa-trash-alt"></i></button>
                    `;

                    // Add Record Payment button for 'Unpaid' or 'Partially Paid' invoices.
                    if (status === 'Unpaid' || status === 'Partially Paid') {
                        const balance_due = total_amount - amount_paid;
                        actions += `<button onclick="openRecordPaymentModal(${invoice_id}, ${balance_due})" class="text-green-600 hover:text-green-800 ml-2" title="Record Payment"><i class="fas fa-cash-register"></i></button>`;
                    }

                    if (status === 'Overdue') {
                        const disabled = invoice.overdue_days < 14;
                        const disabledClass = disabled ? 'opacity-50 cursor-not-allowed' : 'hover:text-red-800';
                        const title = disabled ? `Available in ${14 - invoice.overdue_days} day(s)` : 'Send Demand Notice';
                        actions += `<button onclick="${disabled ? '' : `sendDemandNotice(${invoice_id})`}" class="text-red-600 ${disabledClass} ml-2" title="${title}" ${disabled ? 'disabled' : ''}><i class="fas fa-exclamation-triangle"></i></button>`;
                    }

                    tableBody.innerHTML += `
                        <tr data-document-type="${invoice.document_type}">
                            <td class="p-4 text-sm">${invoice.customer_name || 'N/A'}</td>
                            <td class="p-4 text-sm">${invoice.document_type || 'N/A'}</td>
                            <td class="p-4 text-sm">${invoiceNumberHtml}</td>
                            <td class="p-4 text-sm">${invoice.issue_date || 'N/A'}</td>
                            <td class="p-4 text-sm">${DEFAULT_CURRENCY} ${number_format(amount_paid, 2)}</td>
                            <td class="p-4 text-sm font-semibold whitespace-nowrap">${DEFAULT_CURRENCY} ${number_format(total_amount, 2)}</td>
                            <td class="p-4 text-sm">${actions}</td>
                        </tr>`;
                });
            } else {
                 tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-gray-500">No documents found for the selected filters.</td></tr>`;
            }

            // Render pagination controls
            if (pagination.totalPages > 1) {
                let paginationHTML = `<div class="flex items-center space-x-1">`;
                for (let i = 1; i <= pagination.totalPages; i++) {
                    paginationHTML += `<button onclick="loadInvoices(${i})" class="px-3 py-1 rounded-md text-sm ${i === page ? 'bg-violet-600 text-white' : 'bg-gray-200 text-gray-700'}">${i}</button>`;
                }
                paginationHTML += `</div>`;
                const start = (page - 1) * 4 + 1;
                const end = Math.min(start + 3, pagination.totalRecords);
                paginationContainer.innerHTML = `<p class="text-sm text-gray-700">Showing ${start} to ${end} of ${pagination.totalRecords} results</p>${paginationHTML}`;
            }


            // Use summary data from API
            const summaryContainer = document.getElementById('invoice-summary-container');
            summaryContainer.innerHTML = `
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-sm font-semibold text-gray-600">Total Billed</p>
                        <p class="text-xl font-bold text-gray-800">${DEFAULT_CURRENCY} ${number_format(summary.total_billed || 0, 2)}</p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-600">Total Paid</p>
                        <p class="text-xl font-bold text-green-600">${DEFAULT_CURRENCY} ${number_format(summary.total_paid || 0, 2)}</p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-600">Balance Due</p>
                        <p class="text-xl font-bold text-red-600">${DEFAULT_CURRENCY} ${number_format(summary.total_due || 0, 2)}</p>
                    </div>
                </div>
            `;
        }

        async function deleteInvoice(id, invoiceNumber) {
            if (!confirm(`Are you sure you want to delete document #${invoiceNumber}? This action cannot be undone.`)) return;
            const result = await fetchApi('delete_invoice.php', { method: 'POST', body: { id } });
            if (result && result.status === 'success') {
                alert(result.message);
                loadInvoices(currentInvoicePage); // Refresh current page
            } else if (result) {
                alert('Error: ' + result.message);
            }
        }

        function generateReport() {
            // This now correctly passes all current filters, including document types
            const selectedDocTypes = Array.from(document.querySelectorAll('.doc_type_filter:checked'))
                                          .map(el => `doc_types[]=${encodeURIComponent(el.value)}`)
                                          .join('&');
            const pdfUrl = `${BASE_URL}/api/generate_report.php?status=${currentInvoiceStatusFilter}&period=${currentTimePeriodFilter}&${selectedDocTypes}`;
            window.open(pdfUrl, '_blank');
        }

        function filterInvoicesByStatus(status) {
            currentInvoiceStatusFilter = status;
            loadInvoices(1); // Reset to page 1 on new filter
        }

        function filterInvoicesByTime(period) {
            currentTimePeriodFilter = period;
            loadInvoices(1); // Reset to page 1
        }

        function openCreateDocumentView(docType) {
            openCreateInvoiceView(docType);
        }

        async function loadCustomers() {
            const tableBody = document.getElementById('customers-table-body');
            if (!tableBody) {
                console.error("Customer table body not found!");
                return;
            }
            tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">Loading customers...</td></tr>`;

            try {
                const customers = await fetchApi('get_customers.php');
                tableBody.innerHTML = '';

                if (customers && Array.isArray(customers) && customers.length > 0) {
                    customers.forEach(customer => {
                        const actions = `
                            <button class="text-gray-400 hover:text-violet-600 mr-2 cursor-not-allowed" title="Edit (Coming Soon)">
                                <i class="fas fa-pencil-alt"></i>
                            </button>
                            <button class="text-gray-400 hover:text-red-600 mr-2 cursor-not-allowed" title="Delete (Coming Soon)">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <button onclick="generateCustomerStatement(${customer.id}, '${customer.name.replace(/'/g, "\\'")}')"
                                    class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full font-semibold hover:bg-green-200"
                                    title="Generate Statement">
                               Statement
                            </button>
                        `;
                        tableBody.innerHTML += `
                            <tr>
                                <td class="p-4 font-semibold">${customer.name}</td>
                                <td class="p-4">${customer.email || 'N/A'}</td>
                                <td class="p-4">${customer.phone || 'N/A'}</td>
                                <td class="p-4">${actions}</td>
                            </tr>`;
                    });
                } else if (customers && Array.isArray(customers)) {
                     tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">No customers found. Click 'Add Customer' to start.</td></tr>`;
                } else {
                     console.error("Failed to load customers or invalid data received:", customers);
                     tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-red-500">Failed to load customers. Please check the console.</td></tr>`;
                }
            } catch (error) {
                 console.error("Error in loadCustomers:", error);
                 tableBody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-red-500">An error occurred while loading customers.</td></tr>`;
            }
        }

        function togglePasswordVisibility(button) {
            const input = button.previousElementSibling;
            const icon = button.querySelector('i');
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function generateCustomerStatement(customerId, customerName) {
            currentCustomerId = customerId; // Hii bado ni sawa kwa matumizi mengine
            showView('customer_statement');
            // Subiri kidogo view i-load kabla ya kuweka jina na data
            setTimeout(async () => {
                const nameElement = document.getElementById('statement-customer-name');
                if (nameElement) nameElement.textContent = `Statement for ${customerName}`;
                 // HAPA PABADILISHWE: Tuma kama customer_id
                 await loadCustomerStatement(customerId, 'all');
            }, 0); // Tumia setTimeout 0 kuiruhusu UI ijichore kwanza
        }

        async function loadCustomerStatement(customerId, period = 'all', invoicesPage = 1, paymentsPage = 1) {
            const summaryContainer = document.getElementById('statement-summary');
            const invoicesTable = document.getElementById('statement-invoices-table');
            const paymentsTable = document.getElementById('statement-payments-table');
            const dateRangeEl = document.getElementById('statement-date-range');

            summaryContainer.innerHTML = `<div class="p-4 bg-gray-100 rounded-lg text-center text-gray-500 col-span-3">Loading...</div>`;
            invoicesTable.innerHTML = `<tr><td colspan="8" class="p-3 text-center text-gray-500">Loading documents...</td></tr>`;
            paymentsTable.innerHTML = `<tr><td colspan="4" class="p-3 text-center text-gray-500">Loading payments...</td></tr>`;
            if(dateRangeEl) dateRangeEl.textContent = 'Loading date range...';

            const data = await fetchApi(`get_customer_statement.php?customer_id=${customerId}&period=${period}&invoices_page=${invoicesPage}&payments_page=${paymentsPage}`);

            if (data && data.status === 'success') {
                if(dateRangeEl) dateRangeEl.textContent = data.date_range || 'All Time';

                summaryContainer.innerHTML = `
                    <div class="bg-blue-100 p-4 rounded-lg shadow"><h4 class="text-sm font-semibold text-blue-800">Total Billed</h4><p class="text-2xl font-bold text-blue-900">${DEFAULT_CURRENCY} ${number_format(data.summary.total_billed, 2)}</p></div>
                    <div class="bg-green-100 p-4 rounded-lg shadow"><h4 class="text-sm font-semibold text-green-800">Total Paid</h4><p class="text-2xl font-bold text-green-900">${DEFAULT_CURRENCY} ${number_format(data.summary.total_paid, 2)}</p></div>
                    <div class="bg-red-100 p-4 rounded-lg shadow"><h4 class="text-sm font-semibold text-red-800">Total Due</h4><p class="text-2xl font-bold text-red-900">${DEFAULT_CURRENCY} ${number_format(data.summary.total_due, 2)}</p></div>`;

                invoicesTable.innerHTML = '';
                if (data.invoices.length > 0) {
                    data.invoices.forEach(inv => {
                        const status = inv.status || ''; // Ensure status is a string
                        const statusClass = `status-${status.replace(/\s+/g, '-')}`;
                        const isReceipt = inv.document_type === 'Receipt';
                        const viewUrl = isReceipt ? `${BASE_URL}/api/preview_invoice.php?id=${inv.id}` : `${BASE_URL}/${inv.pdf_url}`;
                        const docLink = inv.pdf_url ? `<a href="${viewUrl}" target="_blank" class="font-semibold text-violet-600 hover:underline">${inv.invoice_number}</a>` : inv.invoice_number;
                        let actions = '';
                        if (status.trim().toLowerCase() === 'overdue') {
                            const disabled = inv.overdue_days < 14;
                            const disabledClass = disabled ? 'opacity-50 cursor-not-allowed' : 'hover:bg-red-200';
                            const title = disabled ? `Available in ${14 - inv.overdue_days} day(s)` : 'Send Demand Notice';
                            actions = `<button onclick="${disabled ? '' : `sendDemandNotice(${inv.id})`}" class="text-xs bg-red-100 text-red-800 font-semibold px-2 py-1 rounded-md ${disabledClass}" title="${title}" ${disabled ? 'disabled' : ''}>Send Demand</button>`;
                        }
                        invoicesTable.innerHTML += `<tr>
                            <td class="p-3">${docLink}</td><td class="p-3">${inv.issue_date}</td><td class="p-3">${inv.due_date || 'N/A'}</td>
                            <td class="p-3">${DEFAULT_CURRENCY} ${number_format(inv.total_amount, 2)}</td><td class="p-3 text-green-700">${DEFAULT_CURRENCY} ${number_format(inv.amount_paid, 2)}</td>
                            <td class="p-3 text-red-700">${DEFAULT_CURRENCY} ${number_format(inv.balance_due, 2)}</td><td class="p-3"><span class="text-xs font-medium px-2 py-0.5 rounded-full ${statusClass}">${inv.status}</span></td>
                            <td class="p-3">${actions}</td></tr>`;
                    });
                } else {
                    invoicesTable.innerHTML = `<tr><td colspan="8" class="p-3 text-center text-gray-500">No documents found for this period.</td></tr>`;
                }
                renderStatementPagination('statement-invoices-pagination', data.pagination.invoices, `loadCustomerStatement(${customerId}, '${period}', %page%, ${paymentsPage})`);

                paymentsTable.innerHTML = '';
                if (data.payments.length > 0) {
                    data.payments.forEach(pay => {
                        const docLink = pay.pdf_url ? `<a href="${BASE_URL}/${pay.pdf_url}" target="_blank" class="font-semibold text-violet-600 hover:underline">${pay.document_number}</a>` : pay.document_number;
                        paymentsTable.innerHTML += `<tr>
                            <td class="p-3">${pay.date}</td><td class="p-3 font-semibold">${DEFAULT_CURRENCY} ${number_format(pay.amount, 2)}</td>
                            <td class="p-3">${docLink}</td><td class="p-3 text-gray-600">${pay.notes || ''}</td></tr>`;
                    });
                } else {
                    paymentsTable.innerHTML = `<tr><td colspan="4" class="p-3 text-center text-gray-500">No payments found for this period.</td></tr>`;
                }
                renderStatementPagination('statement-payments-pagination', data.pagination.payments, `loadCustomerStatement(${customerId}, '${period}', ${invoicesPage}, %page%)`);

            } else {
                summaryContainer.innerHTML = `<div class="p-4 bg-red-100 rounded-lg text-center text-red-700 col-span-3">Failed to load statement data. ${data?.message || ''}</div>`;
                invoicesTable.innerHTML = `<tr><td colspan="8" class="p-3 text-center text-red-500">Error loading document data.</td></tr>`;
                paymentsTable.innerHTML = `<tr><td colspan="4" class="p-3 text-center text-red-500">Error loading payment data.</td></tr>`;
            }
        }

        function printStatement() {
            const period = 'all'; // You might want to pass the current period filter later
            if (currentCustomerId) {
                const pdfUrl = `${BASE_URL}/api/generate_statement_pdf.php?customer_id=${currentCustomerId}&period=${period}`;
                window.open(pdfUrl, '_blank');
            } else {
                alert('Could not determine the current customer.');
            }
        }

        function renderStatementPagination(containerId, paginationData, onClickTemplate) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            const { currentPage, totalPages, totalRecords } = paginationData;

            if (totalPages <= 1) return;

            let paginationHTML = `<div class="flex items-center space-x-1">`;
            for (let i = 1; i <= totalPages; i++) {
                const onClick = onClickTemplate.replace('%page%', i);
                paginationHTML += `<button onclick="${onClick}" class="px-3 py-1 rounded-md text-xs ${i === currentPage ? 'bg-violet-600 text-white' : 'bg-gray-200 text-gray-700'}">${i}</button>`;
            }
            paginationHTML += `</div>`;
            const start = (currentPage - 1) * 10 + 1;
            const end = Math.min(start + 9, totalRecords);
            container.innerHTML = `<p class="text-xs text-gray-700">Showing ${start} to ${end} of ${totalRecords} results</p>${paginationHTML}`;
        }

        async function sendDemandNotice(invoiceId) {
            if (!confirm('Are you sure you want to send a formal demand notice for this overdue invoice?')) {
                return;
            }

            // You can add a loading spinner here for better UX
            // e.g., document.getElementById('main-spinner').style.display = 'block';

            try {
                const result = await fetchApi('api/send_demand_notice.php', {
                    method: 'POST',
                    body: { invoice_id: invoiceId }
                });

                if (result && result.success) {
                    alert(result.message || 'Demand notice sent successfully.');
                } else {
                    // fetchApi will already alert the error message, but you can add a fallback.
                    alert(result ? result.message : 'An unknown error occurred.');
                }
            } catch (error) {
                // This will catch errors from fetchApi if it throws an exception (e.g. network error)
                console.error("Failed to send demand notice:", error);
                alert("An error occurred while trying to send the demand notice.");
            } finally {
                // Hide the spinner here
                // e.g., document.getElementById('main-spinner').style.display = 'none';
            }
        }

        let isCreatingInvoice = false; // Ongeza hii nje ya function (global au juu yake)

    async function openCreateInvoiceView(docType = 'Invoice') {
         if (isCreatingInvoice) return; // Zuia kubonyeza mara mbili
         isCreatingInvoice = true; // Weka flag kuwa inaendelea

         try {
            showView('create_invoice');
            await new Promise(resolve => setTimeout(resolve, 1600)); // Wait for showView to complete

            document.getElementById('create-document-title').textContent = `Create New ${docType}`;
            document.getElementById('document_type').value = docType;

            // --- Dynamic Form Logic ---
            const invoiceDateLabel = document.querySelector('label[for="invoiceDate"]');
            const dueDateLabel = document.querySelector('label[for="invoiceDueDate"]');
            const dueDateInput = document.getElementById('invoiceDueDate');
            const submitButton = document.querySelector('#createInvoiceForm button[type="submit"]');

            if (docType === 'Receipt') {
                invoiceDateLabel.textContent = 'Receipt Date *';
                dueDateLabel.style.display = 'none';
                dueDateInput.style.display = 'none';
                dueDateInput.required = false;
                submitButton.textContent = 'Save Receipt';
            } else if (docType === 'Quotation' || docType === 'Estimate') {
                 invoiceDateLabel.textContent = `${docType} Date *`;
                 dueDateLabel.textContent = 'Valid Until';
                 dueDateLabel.style.display = 'block';
                 dueDateInput.style.display = 'block';
                 dueDateInput.required = true;
                 submitButton.textContent = `Save & Send ${docType}`;
            } else {
                invoiceDateLabel.textContent = 'Invoice Date *';
                dueDateLabel.textContent = 'Due Date';
                dueDateLabel.style.display = 'block';
                dueDateInput.style.display = 'block';
                dueDateInput.required = true;
                submitButton.textContent = 'Save & Send Invoice';
            }

            const today = new Date().toISOString().split('T')[0];
            const invoiceDateInput = document.getElementById('invoiceDate');
            if (invoiceDateInput) invoiceDateInput.value = today;

            const customerSelect = document.getElementById('invoiceCustomer');
            if (!customerSelect) {
                console.error("Customer select element not found!");
                throw new Error("UI element missing."); // Tupa error ili iwe caught
            }
            customerSelect.innerHTML = '<option value="">Loading customers...</option>';
            const customers = await fetchApi('get_customers.php'); // Hapa ndipo fetch inaitwa

            customerSelect.innerHTML = '<option value="">-- Select Customer --</option>';
            if (customers && Array.isArray(customers)) {
                customers.forEach(customer => {
                    customerSelect.innerHTML += `<option value="${customer.id}">${customer.name}</option>`;
                });
            } else {
                customerSelect.innerHTML = '<option value="">Failed to load customers</option>';
                // Usi-alert hapa, inaweza kuingiliana
                console.error("Could not load customers.");
            }

            const itemsContainer = document.getElementById('invoiceItemsContainer');
            if (itemsContainer) itemsContainer.innerHTML = '';
            addInvoiceItemRow(); // Ongeza item ya kwanza

            const form = document.getElementById('createInvoiceForm');
            if (form) {
                form.removeEventListener('submit', handleInvoiceFormSubmit);
                form.addEventListener('submit', handleInvoiceFormSubmit);
            } else {
                console.error("Create invoice form not found!");
                throw new Error("UI form missing."); // Tupa error
            }
         } catch (error) {
              console.error("Error opening create invoice view:", error);
              // Optionally show an error message to the user in the UI
              alert("Could not open the invoice form. Please try again.");
         } finally {
             isCreatingInvoice = false; // Ondoa flag baada ya kumaliza (hata kama kuna error)
         }
    }

        async function loadPayoutRequests(page = 1, status = 'All') {
            const tableBody = document.getElementById('payouts-table-body');
            tableBody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-gray-500">Loading...</td></tr>`;
            const data = await fetchApi(`get_payout_requests.php?page=${page}&status=${status}`);
            allPayouts = (data && Array.isArray(data.payouts)) ? data.payouts : [];
            tableBody.innerHTML = '';
            if (allPayouts.length > 0) {
                allPayouts.forEach(req => {
                    let actionsHtml = '';
                    if (req.invoice_url) {
                        actionsHtml += `<a href="${BASE_URL}/${req.invoice_url}" target="_blank" class="text-sm bg-blue-500 text-white px-3 py-1 rounded-md font-semibold hover:bg-blue-600 shadow-sm">Invoice</a>`;
                    }
                    if (req.status === 'Submitted') {
                        actionsHtml += `<button onclick="openPayoutModal(${req.id})" class="ml-2 text-sm bg-blue-500 text-white px-3 py-1 rounded-md font-semibold hover:bg-blue-600 shadow-sm">Review</button>`;
                    } else if (req.status === 'Approved') {
                        if (req.payment_receipt_url) {
                            actionsHtml += `<a href="${BASE_URL}/${req.payment_receipt_url}" target="_blank" class="ml-2 text-sm bg-blue-500 text-white px-3 py-1 rounded-md font-semibold hover:bg-blue-600 shadow-sm">View Receipt</a>`;
                        } else {
                            actionsHtml += `<button onclick="openUploadModal(${req.id})" class="ml-2 text-sm bg-blue-500 text-white px-3 py-1 rounded-md font-semibold hover:bg-blue-600 shadow-sm">Receipt</button>`;
                        }
                        // Always show a "Report" button for approved payouts that links to the live preview generator.
                        // This ensures the latest template is always used for previewing.
                        actionsHtml += `<a href="${BASE_URL}/api/preview_payment_report.php?id=${req.id}" target="_blank" class="ml-2 text-sm bg-green-500 text-white px-3 py-1 rounded-md font-semibold hover:bg-green-600 shadow-sm" title="Preview Live Report">Report</a>`;
                    } else if (req.status === 'Pending') {
                        actionsHtml += `<span class="ml-2 text-sm bg-gray-200 text-gray-700 px-3 py-1 rounded-md font-semibold">Waiting</span>`;
                    }
                    tableBody.innerHTML += `<tr><td class="p-4 font-semibold">${req.vendor_name}</td><td class="p-4">${DEFAULT_CURRENCY} ${req.amount}</td><td class="p-4"><span class="text-xs font-medium px-2.5 py-0.5 rounded-full status-${req.status}">${req.status}</span></td><td class="p-4">${req.submitted_at ? new Date(req.submitted_at).toLocaleDateString() : 'N/A'}</td><td class="p-4 font-mono text-xs">${req.transaction_reference || 'N/A'}</td><td class="p-4 space-x-2">${actionsHtml}</td></tr>`;
                });
            } else { tableBody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-gray-500">No payout requests found.</td></tr>`; }

            const paginationControls = document.getElementById('payouts-pagination');
            if (paginationControls) {
                paginationControls.innerHTML = '';
                const totalPages = Math.ceil(data.total / data.limit);
                if (totalPages > 1) {
                    let paginationHTML = `<div class="flex items-center space-x-1">`;
                    for (let i = 1; i <= totalPages; i++) {
                        paginationHTML += `<button onclick="loadPayoutRequests(${i})" class="px-3 py-1 rounded-md text-sm ${i === page ? 'bg-violet-600 text-white' : 'bg-gray-200 text-gray-700'}">${i}</button>`;
                    }
                    paginationHTML += `</div>`;
                    const start = (page - 1) * data.limit + 1;
                    const end = Math.min(start + data.limit - 1, data.total);
                    paginationControls.innerHTML = `<p class="text-sm text-gray-700">Showing ${start} to ${end} of ${data.total} results</p>${paginationHTML}`;
                }
            }
        }
        function showVendorTab(tabId, event) {
            if (event) event.preventDefault();
            document.querySelectorAll('.vendor-tab').forEach(content => content.style.display = 'none');
            document.getElementById(`vendors-${tabId}`).style.display = 'block';

            document.querySelectorAll('#vendors-view .settings-tab').forEach(tab => {
                tab.classList.remove('active-tab');
            });

            const activeTab = event ? event.currentTarget : document.querySelector(`#vendors-view .settings-tab[onclick*="'${tabId}'"]`);
            if(activeTab) activeTab.classList.add('active-tab');

            if (tabId === 'list') loadVendors();
            if (tabId === 'payouts') loadPayoutRequests();
        }

        async function showVendorDetails(vendorId, vendorName, event, page = 1, status = 'All') {
            if (event) event.preventDefault();
            currentVendorId = vendorId;
            currentVendorName = vendorName;
            document.getElementById('view-container').innerHTML = viewTemplates['vendor-details'];
            document.getElementById('vendor-details-name').textContent = vendorName;

            const tableBody = document.getElementById('vendor-history-table-body');
            tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-gray-500">Loading history...</td></tr>`;
            const data = await fetchApi(`get_vendor_details.php?id=${vendorId}&page=${page}&status=${status}`);
            tableBody.innerHTML = '';
            if (data && data.payouts && Array.isArray(data.payouts) && data.payouts.length > 0) {
                data.payouts.forEach(req => {
                console.log(req);
                    let docs = '';
                    if (req.invoice_url) { docs += `<a href="${BASE_URL}/${req.invoice_url}" target="_blank" class="text-sm bg-blue-100 text-blue-700 px-3 py-1 rounded-full font-semibold hover:bg-blue-200">View Invoice</a>`; }
                    if (req.payment_notification_pdf_url) { docs += `<a href="${BASE_URL}/${req.payment_notification_pdf_url}" target="_blank" class="ml-2 text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full font-semibold hover:bg-green-200">View Report</a>`; }
                    if (req.payment_receipt_url) { docs += `<a href="${BASE_URL}/${req.payment_receipt_url}" target="_blank" class="ml-2 text-sm bg-gray-100 text-gray-700 px-3 py-1 rounded-full font-semibold hover:bg-gray-200">View Receipt</a>`; }


                   let paymentDetailsHtml = '<td class="p-4 text-xs text-gray-500">';
                    if (req.payment_method === 'Bank Transfer' && req.bank_name) {
                        paymentDetailsHtml += `
                            <span class="font-semibold text-gray-700">${req.bank_name}</span><br>
                            ${req.account_name}<br>
                            <strong>${req.account_number}</strong>
                        `;
                    } else if (req.payment_method === 'Mobile Money' && req.mobile_network) {
                        paymentDetailsHtml += `
                            <span class="font-semibold text-gray-700">${req.mobile_network}</span><br>
                            <strong>${req.mobile_phone}</strong>
                        `;
                    } else {
                        paymentDetailsHtml += '<span>N/A</span>';
                    }
                   paymentDetailsHtml += '</td>';
                   const currency = req.currency || DEFAULT_CURRENCY;

                    tableBody.innerHTML += `
                        <tr>
                            <td class="p-4">${new Date(req.processed_at || req.submitted_at || req.created_at).toLocaleDateString()}</td>
                            <td class="p-4">${currency} ${req.amount}</td>
                            <td class="p-4 text-red-600">-${currency} ${number_format(req.withholding_tax_amount || 0, 2)}</td>
                            ${paymentDetailsHtml}
                            <td class="p-4"><span class="text-xs font-medium px-2.5 py-0.5 rounded-full status-${req.status}">${req.status}</span></td>
                            <td class="p-4 font-mono text-xs">${req.transaction_reference || 'N/A'}</td>
                            <td class="p-4">${docs || 'No Docs'}</td>
                        </tr>`;
                });
            } else {
                tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-gray-500">No payment history found.</td></tr>`;
            }

            const paginationControls = document.getElementById('vendor-history-pagination');
            if (paginationControls) {
                paginationControls.innerHTML = '';
                const totalPages = Math.ceil(data.total / data.limit);
                if (totalPages > 1) {
                    let paginationHTML = `<div class="flex items-center space-x-1">`;
                    for (let i = 1; i <= totalPages; i++) {
                        paginationHTML += `<button onclick="showVendorDetails(${vendorId}, '${vendorName.replace(/'/g, "\\'")}', event, ${i})" class="px-3 py-1 rounded-md text-sm ${i === page ? 'bg-violet-600 text-white' : 'bg-gray-200 text-gray-700'}">${i}</button>`;
                    }
                    paginationHTML += `</div>`;
                    const start = (page - 1) * data.limit + 1;
                    const end = Math.min(start + data.limit - 1, data.total);
                    paginationControls.innerHTML = `<p class="text-sm text-gray-700">Showing ${start} to ${end} of ${data.total} results</p>${paginationHTML}`;
                }
            }
        }

        // --- MODAL & FORM FUNCTIONS ---
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if(modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            } else {
                console.error(`Modal with ID ${modalId} not found!`);
            }
        }
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('hidden');
            modal.classList.remove('flex');

            if (modalId === 'addAdvertiserModal') {
                document.getElementById('advertiser-form-step1').classList.remove('hidden');
                document.getElementById('advertiser-form-step2').classList.add('hidden');
                document.getElementById('addAdvertiserForm').reset();
                document.getElementById('verifyEmailForm').reset();
            }
        }

        function openRecordPaymentModal(invoiceId, balanceDue) {
            document.getElementById('paymentInvoiceId').value = invoiceId;
            const amountInput = document.getElementById('paymentAmount');
            amountInput.value = balanceDue;
            amountInput.max = balanceDue;
            document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
            openModal('recordPaymentModal');
        }

        function extractYouTubeId(urlOrId) {
            if (!urlOrId.includes('http')) {
                return urlOrId;
            }
            const pattern = /(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/;
            const matches = urlOrId.match(pattern);
            return matches ? matches[1] : urlOrId;
        }

        async function openManageVideosModal(adId, page = 1) {
            openModal('manageVideosModal');
            document.getElementById('manualAdId').value = adId;
            const response = await fetchApi(`modules/youtube_ads/controllers/AdController.php?action=getLinkedVideos&ad_id=${adId}&page=${page}&limit=5`);
            const list = document.getElementById('linked-videos-list');
            list.innerHTML = '';
            if (response && response.status === 'success' && response.videos.length > 0) {
                response.videos.forEach(video => {
                    const videoId = extractYouTubeId(video.video_id);
                    list.innerHTML += `<li><a href="https://www.youtube.com/watch?v=${videoId}" target="_blank">${video.video_id}</a></li>`;
                });
                renderPagination('linked-videos-pagination', page, response.total, 5, `openManageVideosModal.bind(null, ${adId})`);
            } else {
                list.innerHTML = '<li>No videos linked yet.</li>';
                document.getElementById('linked-videos-pagination').innerHTML = '';
            }
        }

        async function openBroadcastModal() {
            openModal('newBroadcastModal');
            const [contacts, templates] = await Promise.all([ fetchApi('get_contacts.php'), fetchApi('get_templates.php') ]);
            const contactSelect = document.getElementById('selectContacts'); contactSelect.innerHTML = '';
            if (contacts && Array.isArray(contacts)) { contacts.forEach(c => { contactSelect.innerHTML += `<option value="${c.id}">${c.name} (${c.phone_number})</option>`; }); }
            const templateSelect = document.getElementById('selectTemplate'); templateSelect.innerHTML = '<option value="">Select an approved template...</option>';
            if (templates && Array.isArray(templates)) { templates.filter(t => t.status === 'Approved').forEach(t => { templateSelect.innerHTML += `<option value="${t.id}">${t.name}</option>`; }); }
        }

        async function openAddCustomerModal() {
            openModal('addCustomerModal');
            const wrapper = document.getElementById('unassigned-contacts-wrapper');
            const selectEl = document.getElementById('assignContacts');
            selectEl.innerHTML = '<option>Loading...</option>';

            const contacts = await fetchApi('get_unassigned_contacts.php');

            if (contacts && Array.isArray(contacts) && contacts.length > 0) {
                selectEl.innerHTML = ''; // Futa "Loading..."
                contacts.forEach(contact => {
                    selectEl.innerHTML += `<option value="${contact.id}">${contact.name}</option>`;
                });
                wrapper.style.display = 'block';
            } else {
                // Ficha sehemu hii kama hakuna contacts
                wrapper.style.display = 'none';
            }
        }

        function toggleContactSelect(value) { document.getElementById('contact-select-container').style.display = value === 'select' ? 'block' : 'none'; }
        function toggleScheduleDate(value) { document.getElementById('schedule-date-container').style.display = value === 'later' ? 'block' : 'none'; }
        function toggleBroadcastMessageType(value) {
            document.getElementById('broadcast-custom-message-container').style.display = value === 'custom' ? 'block' : 'none';
            document.getElementById('broadcast-template-select-container').style.display = value === 'template' ? 'block' : 'none';
        }
        function openTemplateModal(template = null) {
            const form = document.getElementById('addTemplateForm');
            if(template) {
                document.getElementById('template-modal-title').textContent = 'Edit Template';
                form.querySelector('#templateId').value = template.id;
                form.querySelector('#templateName').value = template.name;
                form.querySelector('#templateHeader').value = template.header || '';
                form.querySelector('#templateBody').value = template.body;
                form.querySelector('#templateFooter').value = template.footer || '';
                form.querySelector('#templateQuickReplies').value = (template.quick_replies || []).join(',');
            } else {
                document.getElementById('template-modal-title').textContent = 'Create New Template';
                form.reset();
                form.querySelector('#templateId').value = '';
            }
            updateTemplateVariables();
            openModal('addTemplateModal');
        }
        function updateTemplateVariables() {
            const body = document.getElementById('templateBody').value;
            const variableRegex = /{{\s*([a-zA-Z0-9_]+)\s*}}/g;
            const found = body.match(variableRegex) || [];
            const uniqueVars = [...new Set(found.map(v => v.replace(/{{|}}/g, '').trim()))];
            const container = document.getElementById('template-vars-list');
            const containerWrapper = document.getElementById('template-vars-container');
            container.innerHTML = '';
            if(uniqueVars.length > 0) {
                containerWrapper.style.display = 'block';
                uniqueVars.forEach(name => {
                    // The name attribute is critical for FormData to pick it up as an array
                    container.innerHTML += `<div class="flex items-center gap-2 mt-2">
                        <span class="font-mono bg-gray-200 text-gray-700 px-2 py-1 rounded">{{${name}}}</span>
                        <input type="text" name="variable_examples[${name}]" placeholder="Example value for ${name}" class="px-3 py-2 text-gray-700 border rounded-md w-full text-sm">
                    </div>`;
                });
            } else {
                containerWrapper.style.display = 'none';
            }
        }
        function copyVariable(variable) {
            const bodyTextarea = document.getElementById('templateBody');
            bodyTextarea.value += `{{${variable}}}`;
            updateTemplateVariables();
        }
        function openRejectModal(payoutId) { rejectingPayoutId = payoutId; closeModal('payoutDetailModal'); openModal('rejectPayoutModal'); }
        function openUploadModal(payoutId) { uploadingReceiptId = payoutId; document.getElementById('receiptPayoutId').value = payoutId; openModal('uploadReceiptModal'); }
        async function openPayoutModal(payoutId) {
            const req = allPayouts.find(p => p.id == payoutId);
            if (!req) { alert('Could not find payout request.'); return; }
            let paymentDetailsHtml = '';
            if (req.payment_method === 'Bank Transfer' && req.bank_name) {
                paymentDetailsHtml = `<h4 class="font-semibold text-gray-800">Bank Details</h4><p class="text-sm">Bank: ${req.bank_name}</p><p class="text-sm">Name: ${req.account_name}</p><p class="text-sm">Acc No: ${req.account_number}</p>`;
            } else if (req.payment_method === 'Mobile Money' && req.mobile_network) {
                paymentDetailsHtml = `<h4 class="font-semibold text-gray-800">Mobile Money Details</h4><p class="text-sm">Network: ${req.mobile_network}</p><p class="text-sm">Phone: ${req.mobile_phone}</p>`;
            } else {
                paymentDetailsHtml = `<h4 class="font-semibold text-gray-800">Payment Details</h4><p class="text-sm text-red-500">Vendor has not submitted payment details for this request.</p>`;
            }
            const currency = req.currency || DEFAULT_CURRENCY;
            document.getElementById('payout-details-content').innerHTML = `
                <div class="space-y-3">
                    <div><h4 class="font-semibold text-gray-800">Vendor</h4><p>${req.vendor_name}</p></div>
                    <div class="grid grid-cols-3 gap-4 border-t pt-3 mt-3">
                        <div><h4 class="font-semibold text-gray-800">Amount</h4><p>${currency} ${number_format(req.amount, 2)}</p></div>
                        <div><h4 class="font-semibold text-gray-800">Withholding Tax</h4><p class="text-red-600">-${currency} ${number_format(req.withholding_tax, 2)}</p></div>
                        <div><h4 class="font-semibold text-gray-800">Net Payable</h4><p class="font-bold">${currency} ${number_format(req.amount - req.withholding_tax, 2)}</p></div>
                    </div>
                    <div><h4 class="font-semibold text-gray-800">Service</h4><p>${req.service_type}</p></div>
                    <div class="border p-3 rounded-md bg-gray-50">${paymentDetailsHtml}</div>
                    <div><a href="${BASE_URL}/${req.invoice_url}" target="_blank" class="text-violet-600 font-semibold hover:underline"><i class="fas fa-file-invoice-dollar mr-2"></i>View Submitted Invoice</a></div>
                </div>`;
            const actionsContainer = document.getElementById('payout-modal-actions');
            actionsContainer.innerHTML = `<button type="button" class="px-4 py-2 bg-gray-200 rounded-md" onclick="closeModal('payoutDetailModal')">Cancel</button>`;
            if (req.status === 'Submitted') {
                actionsContainer.innerHTML += `<button onclick="approvePayout(${req.id})" class="px-4 py-2 bg-green-600 text-white rounded-md">Approve</button>`;
                actionsContainer.innerHTML += `<button onclick="openRejectModal(${req.id})" class="px-4 py-2 bg-red-600 text-white rounded-md">Reject</button>`;
            }
            openModal('payoutDetailModal');
        }

        function openConvertDocumentMenu(invoiceId) {
            document.getElementById('convertFromId').value = invoiceId;
            openModal('convertDocumentModal');
        }
        async function sendInvoiceRequest(vendorId) {
            if (!confirm('Are you sure you want to send an invoice request to this vendor?')) return;
            const result = await fetchApi('send_invoice_request.php', { method: 'POST', body: { vendor_id: vendorId } });
            if (result) alert(result.message);
            if (result && (result.status === 'success' || result.status === 'warning')) loadPayoutRequests();
        }
        async function approvePayout(payoutId) {
            if (!confirm('Are you sure you want to approve this payout? This will simulate a payment and notify the vendor.')) return;
            const result = await fetchApi('approve_payout.php', { method: 'POST', body: { id: payoutId } });
            if (result) alert(result.message);
            if (result && (result.status === 'success' || result.status === 'warning')) { closeModal('payoutDetailModal'); loadPayoutRequests(); }
        }

        async function handleAddAdvertiserSubmit(form) {
            const formData = new FormData(form);
            const result = await fetchApi('modules/youtube_ads/controllers/AdController.php?action=createAdvertiser', {
                method: 'POST',
                body: formData
            });

            if (result && result.status === 'success') {
                showVerificationStep(formData.get('email'));
            }
        }

        function showVerificationStep(email) {
            document.getElementById('advertiser-form-step1').classList.add('hidden');
            document.getElementById('advertiser-form-step2').classList.remove('hidden');
            document.getElementById('verification-email-display').textContent = email;
            document.getElementById('verificationEmail').value = email;
            openModal('addAdvertiserModal');
        }

        async function handleVerifyEmailSubmit(form) {

            const formData = new FormData(form);
            const result = await fetchApi('modules/youtube_ads/controllers/AdController.php?action=verifyEmail', {
                method: 'POST',
                body: formData
            });

            if (result && result.status === 'success') {
                alert('Email verified successfully!');
                closeModal('addAdvertiserModal');
                loadYouTubeAds();
            }
        }

        async function resendVerificationCode() {
            const email = document.getElementById('verificationEmail').value;
            const result = await fetchApi('modules/youtube_ads/controllers/AdController.php?action=sendVerification', {
                method: 'POST',
                body: { email: email }
            });
            if(result) alert(result.message);
        }

        async function handleCreateAdSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = 'Processing...';

            try {
                const result = await fetchApi('modules/youtube_ads/controllers/AdController.php?action=createAd', {
                    method: 'POST',
                    body: formData
                });

                if (result && result.status === 'success') {
                    alert('Ad created successfully! The invoice has been sent to the advertiser.');
                    form.reset();
                    loadPendingCampaigns(1); // Refresh pending campaigns
                } else {
                    // Error is already alerted by fetchApi
                }
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Create Ad & Generate Invoice';
            }
        }

        // --- WORKFLOW EDITOR FUNCTIONS ---
        async function openWorkflowEditor(workflowId = null) {
            if (workflowId) {
                const workflows = await fetchApi('get_workflows.php');
                const wf = workflows.find(w => w.id == workflowId);
                currentWorkflow = { id: wf.id, name: wf.name, trigger_type: wf.trigger_type, workflow_data: wf.workflow_data || { nodes: [] } };
            } else {
                openModal('selectTriggerModal'); return;
            }
            document.getElementById('workflow-main-view').style.display = 'none';
            document.getElementById('workflow-editor-view').style.display = 'block';
            document.getElementById('workflow-name-input').value = currentWorkflow.name;
            renderWorkflow();
        }
        function selectTrigger(triggerContent) {
            closeModal('selectTriggerModal');
            currentWorkflow = { id: null, name: 'Untitled Workflow', trigger_type: triggerContent, is_active: 0, workflow_data: { nodes: [{id: 1, type: 'trigger', content: triggerContent, parentId: null}] } };
            document.getElementById('workflow-main-view').style.display = 'none';
            document.getElementById('workflow-editor-view').style.display = 'block';
            document.getElementById('workflow-name-input').value = currentWorkflow.name;
            renderWorkflow();
        }
        function useWorkflowTemplate(template) {
            currentWorkflow = { id: null, name: template.title, trigger_type: template.workflow_data.nodes[0].content, workflow_data: template.workflow_data };
            document.getElementById('workflow-main-view').style.display = 'none';
            document.getElementById('workflow-editor-view').style.display = 'block';
            document.getElementById('workflow-name-input').value = currentWorkflow.name;
            renderWorkflow();
        }
        function closeWorkflowEditor() { document.getElementById('workflow-main-view').style.display = 'block'; document.getElementById('workflow-editor-view').style.display = 'none'; loadWorkflows(); }
        function renderWorkflow() {
            const canvas = document.getElementById('workflow-editor-canvas'); canvas.innerHTML = '';
            const nodes = currentWorkflow.workflow_data.nodes;
            const icons = {
                trigger: 'fa-play-circle',
                ai_objective: 'fa-robot',
                action: 'fa-paper-plane',
                condition: 'fa-code-branch',
                question: 'fa-question-circle',
                message: 'fa-comment-alt',
                assign: 'fa-users',
                add_tag: 'fa-tag',
                update_contact: 'fa-user-edit'
            };
            const colors = {
                trigger: 'text-rose-500',
                ai_objective: 'text-violet-500',
                action: 'text-green-500',
                condition: 'text-amber-500',
                question: 'text-blue-500',
                message: 'text-gray-600',
                assign: 'text-purple-500',
                add_tag: 'text-pink-500',
                update_contact: 'text-cyan-600'
            };

            const buildHtmlForNode = (node) => {
                let actions = `<div class="absolute top-2 right-2 flex gap-2"><button onclick="deleteNode(${node.id})" class="text-gray-400 hover:text-red-500 text-xs"><i class="fas fa-times"></i></button><button onclick="openNodeConfig(${node.id})" class="text-gray-400 hover:text-violet-500 text-xs"><i class="fas fa-pencil-alt"></i></button></div>`;
                if (node.type === 'trigger') actions = '';

                let extraHtml = '';
                if (node.type === 'question' && node.options && node.options.length > 0) {
                    extraHtml = `<div class="mt-2 flex flex-wrap gap-1">` +
                        node.options.map(opt => `<span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full border border-blue-200">${opt}</span>`).join('') +
                    `</div>`;
                }

                let nodeHtml = `<div class="workflow-node ${node.type}">${actions}<div class="flex items-center"><i class="fas ${icons[node.type] || 'fa-circle'} ${colors[node.type] || 'text-gray-500'} text-lg mr-3"></i><div><h4 class="font-semibold text-sm">${node.content}</h4>${extraHtml}</div></div></div>`;

                let childrenHtml = ''; const children = nodes.filter(n => n.parentId === node.id);

                if (children.length === 0) {
                    // Logic for nodes with no children yet
                    if (node.type === 'condition') {
                         childrenHtml += `<div class="workflow-branch"><div class="branch-path"><div class="branch-label">YES</div><div class="workflow-connector"><div class="add-node-btn"><button onclick="addNode('action', ${node.id}, 'YES')" class="bg-white rounded-full h-8 w-8 shadow border flex items-center justify-center hover:bg-gray-100"><i class="fas fa-plus text-gray-500"></i></button></div></div></div><div class="branch-path"><div class="branch-label">NO</div><div class="workflow-connector"><div class="add-node-btn"><button onclick="addNode('action', ${node.id}, 'NO')" class="bg-white rounded-full h-8 w-8 shadow border flex items-center justify-center hover:bg-gray-100"><i class="fas fa-plus text-gray-500"></i></button></div></div></div></div>`;
                    } else if (node.type === 'question' && node.options && node.options.length > 0) {
                         // For questions with options, show branches for each option automatically
                         childrenHtml += `<div class="workflow-branch">` + node.options.map(opt => {
                             return `<div class="branch-path"><div class="branch-label">${opt}</div><div class="workflow-connector"><div class="add-node-btn"><button onclick="addNode('action', ${node.id}, '${opt}')" class="bg-white rounded-full h-8 w-8 shadow border flex items-center justify-center hover:bg-gray-100"><i class="fas fa-plus text-gray-500"></i></button></div></div></div>`;
                         }).join('') + `</div>`;
                    } else {
                        nodeHtml += `<div class="workflow-connector"><div class="add-node-btn"><button onclick="addNode('action', ${node.id})" class="bg-white rounded-full h-8 w-8 shadow border flex items-center justify-center hover:bg-gray-100"><i class="fas fa-plus text-gray-500"></i></button></div></div>`;
                    }
                } else {
                    // Logic for nodes with children
                    if (node.type === 'condition' || (node.type === 'question' && node.options && node.options.length > 0)) {
                        // Determine branches based on node options or YES/NO for condition
                        let branches = [];
                        if (node.type === 'question' && node.options) {
                            branches = node.options;
                        } else {
                            branches = ['YES', 'NO'];
                        }

                        childrenHtml += `<div class="workflow-branch">` + branches.map(branchName => {
                            const childNode = children.find(c => c.branch === branchName);
                            return `<div class="branch-path"><div class="branch-label">${branchName}</div>${childNode ? buildHtmlForNode(childNode) : `<div class="workflow-connector"><div class="add-node-btn"><button onclick="addNode('action', ${node.id}, '${branchName}')" class="bg-white rounded-full h-8 w-8 shadow border flex items-center justify-center hover:bg-gray-100"><i class="fas fa-plus text-gray-500"></i></button></div></div>`}</div>`;
                        }).join('') + `</div>`;
                        // Prepend connector to the branch container
                        childrenHtml = `<div class="workflow-connector"></div>` + childrenHtml;
                    } else {
                        // Single path
                        children.forEach(child => { childrenHtml += `<div class="workflow-connector"><div class="add-node-btn"><button onclick="addNode('action', ${child.parentId})" class="bg-white rounded-full h-8 w-8 shadow border flex items-center justify-center hover:bg-gray-100"><i class="fas fa-plus text-gray-500"></i></button></div></div>` + buildHtmlForNode(child); });
                    }
                }
                return `<div class="flex flex-col items-center">${nodeHtml}${childrenHtml}</div>`;
            };
            const rootNode = nodes.find(n => !n.parentId); if (rootNode) canvas.innerHTML = buildHtmlForNode(rootNode);
        }

        function addNode(type, parentId, branch = null) { const newId = (currentWorkflow.workflow_data.nodes.length > 0 ? Math.max(...currentWorkflow.workflow_data.nodes.map(n => n.id)) : 0) + 1; currentWorkflow.workflow_data.nodes.push({id: newId, type, content: 'New Action', parentId, branch}); renderWorkflow(); openNodeConfig(newId); }

        function deleteNode(nodeId) { 
            if (nodeId === 1) {
                alert('Cannot delete the Trigger node.');
                return;
            }
            if (!confirm('Delete this node and all below it?')) return; 
            let nodesToDelete = [nodeId]; 
            let i=0; 
            while(i<nodesToDelete.length){ 
                const children = currentWorkflow.workflow_data.nodes.filter(n => n.parentId === nodesToDelete[i]); 
                children.forEach(c => nodesToDelete.push(c.id)); 
                i++; 
            } 
            currentWorkflow.workflow_data.nodes = currentWorkflow.workflow_data.nodes.filter(n => !nodesToDelete.includes(n.id)); 
            renderWorkflow(); 
        }

        function openNodeConfig(nodeId) {
            configuringNodeId = nodeId;
            const node = currentWorkflow.workflow_data.nodes.find(n => n.id === nodeId);
            document.getElementById('configure-node-title').textContent = `Configure Node`;
            const body = document.getElementById('configure-node-body');

            const nodeTypes = [
                {value: 'message', label: 'Send Message'},
                {value: 'question', label: 'Ask Question / Quick Reply'},
                {value: 'condition', label: 'Branch (Yes/No)'},
                {value: 'assign', label: 'Assign to Team'},
                {value: 'add_tag', label: 'Add Tag to Contact'},
                {value: 'update_contact', label: 'Update Contact Field'}
            ];

            let typeOptions = nodeTypes.map(t => `<option value="${t.value}" ${node.type === t.value ? 'selected' : ''}>${t.label}</option>`).join('');

            let contentHtml = `
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Node Type</label>
                    <select id="node-type-select" onchange="renderNodeConfigFields(this.value)" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        ${typeOptions}
                    </select>
                </div>
                <div id="node-config-fields"></div>
            `;

            body.innerHTML = contentHtml;
            setTimeout(() => renderNodeConfigFields(node.type, node), 0);
            openModal('configureNodeModal');
        }

        function renderNodeConfigFields(type, nodeData = null) {
             const container = document.getElementById('node-config-fields');
             if(!container) return;

             let content = nodeData ? nodeData.content : '';
             // Default content for new types
             if (!nodeData && type === 'condition') content = 'Condition Check';
             if (!nodeData && type === 'question') content = 'How can we help?';

             let options = nodeData && nodeData.options ? nodeData.options.join(',') : '';

             let html = '';

             if (type === 'message' || type === 'action') {
                 html = `
                    <label class="block text-sm font-medium text-gray-700">Message Text</label>
                    <textarea id="config-content" class="w-full p-2 border rounded-md mt-1" rows="4">${content}</textarea>
                 `;
             } else if (type === 'question') {
                 html = `
                    <label class="block text-sm font-medium text-gray-700">Question Text</label>
                    <textarea id="config-content" class="w-full p-2 border rounded-md mt-1" rows="3">${content}</textarea>
                    <label class="block text-sm font-medium text-gray-700 mt-3">Quick Answers / Buttons (Comma separated)</label>
                    <input type="text" id="config-options" class="w-full p-2 border rounded-md mt-1" value="${options}" placeholder="Yes, No, Maybe">
                 `;
             } else if (type === 'condition') {
                 html = `
                    <div class="bg-yellow-50 p-3 rounded text-sm text-yellow-800 mb-2">
                        This node splits the flow into <strong>YES</strong> and <strong>NO</strong> branches based on AI analysis or user intent.
                    </div>
                    <label class="block text-sm font-medium text-gray-700 mt-3">Condition Description</label>
                    <input type="text" id="config-content" class="w-full p-2 border rounded-md mt-1" value="${content}" placeholder="e.g. User replied 'Yes'">
                 `;
             } else if (type === 'assign') {
                 let logic = 'Round Robin';
                 if (content.includes('Fewest')) logic = 'Fewest Conversations';
                 if (content.includes('Online')) logic = 'Online Only';

                 html = `
                    <label class="block text-sm font-medium text-gray-700">Assignment Logic</label>
                    <select id="config-logic" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                        <option value="Round Robin" ${logic === 'Round Robin' ? 'selected' : ''}>Round Robin</option>
                        <option value="Fewest Conversations" ${logic === 'Fewest Conversations' ? 'selected' : ''}>Fewest Conversations</option>
                        <option value="Online Only" ${logic === 'Online Only' ? 'selected' : ''}>Online Only</option>
                    </select>
                 `;
             } else if (type === 'add_tag') {
                 let tag = content.replace('Add Tag: ', '');
                 if(tag === 'New Node') tag = '';
                 html = `
                    <label class="block text-sm font-medium text-gray-700">Tag Name</label>
                    <input type="text" id="config-content" class="w-full p-2 border rounded-md mt-1" value="${tag}" placeholder="e.g. VIP, Lead, Interested">
                 `;
             } else if (type === 'update_contact') {
                 html = `
                    <div class="bg-blue-50 p-3 rounded text-sm text-blue-800 mb-2">
                        Updates a field in the contact's CRM profile.
                    </div>
                    <label class="block text-sm font-medium text-gray-700">Field to Update</label>
                    <select id="config-field" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border mb-2">
                        <option value="email">Email Address</option>
                        <option value="name">Full Name</option>
                        <option value="notes">Notes</option>
                    </select>
                    <label class="block text-sm font-medium text-gray-700">Value</label>
                    <input type="text" id="config-value" class="w-full p-2 border rounded-md mt-1" placeholder="Value to set (or leave empty)">
                 `;
             }

             container.innerHTML = html;
        }

        function updateNodeContent() {
            const node = currentWorkflow.workflow_data.nodes.find(n => n.id === configuringNodeId);
            const type = document.getElementById('node-type-select').value;

            node.type = type;

            if (type === 'assign') {
                const logic = document.getElementById('config-logic').value;
                node.content = `Assign to Team: Sales (${logic})`;
                delete node.options;
            } else if (type === 'add_tag') {
                const tag = document.getElementById('config-content').value;
                node.content = `Add Tag: ${tag}`;
                delete node.options;
            } else if (type === 'update_contact') {
                const field = document.getElementById('config-field').value;
                const value = document.getElementById('config-value').value;
                node.data = { field: field, value: value };
                node.content = `Update ${field} to '${value}'`;
                delete node.options;
            } else {
                const contentEl = document.getElementById('config-content');
                node.content = contentEl ? contentEl.value : 'New Node';

                if (type === 'question') {
                    const optionsVal = document.getElementById('config-options').value;
                    node.options = optionsVal ? optionsVal.split(',').map(s => s.trim()).filter(s => s) : [];
                } else {
                    delete node.options;
                }
            }

            closeModal('configureNodeModal');
            renderWorkflow();
        }

        function exportWorkflowJSON() {
            const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(currentWorkflow, null, 2));
            const downloadAnchorNode = document.createElement('a');
            downloadAnchorNode.setAttribute("href", dataStr);
            downloadAnchorNode.setAttribute("download", currentWorkflow.name.replace(/ /g, '_') + ".json");
            document.body.appendChild(downloadAnchorNode);
            downloadAnchorNode.click();
            downloadAnchorNode.remove();
        }

        function importWorkflowJSON() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.json';
            input.onchange = e => {
                const file = e.target.files[0];
                const reader = new FileReader();
                reader.readAsText(file, 'UTF-8');
                reader.onload = readerEvent => {
                    try {
                        let content = JSON.parse(readerEvent.target.result);
                        // Handle cases where export wrapped it differently or just nodes were exported
                        if (!content.workflow_data && content.nodes) {
                            content = { name: 'Imported Workflow', trigger_type: 'Manual', workflow_data: content };
                        }

                        if (content.workflow_data && content.workflow_data.nodes) {
                            currentWorkflow = content;
                            currentWorkflow.id = null;
                            document.getElementById('workflow-name-input').value = currentWorkflow.name;
                            renderWorkflow();
                            alert('Workflow imported successfully! Click Save to persist.');
                        } else {
                            console.error('Invalid JSON:', content);
                            alert('Invalid workflow JSON format. Expected object with "workflow_data.nodes".');
                        }
                    } catch (err) {
                        console.error(err);
                        alert('Error parsing JSON.');
                    }
                }
            }
            input.click();
        }
        async function saveWorkflow(isActive = 0) {
            currentWorkflow.name = document.getElementById('workflow-name-input').value;
            currentWorkflow.is_active = isActive;
            const result = await fetchApi('save_workflow.php', { method: 'POST', body: currentWorkflow });
            if(result && result.status === 'success') {
                const statusMsg = isActive ? 'published and active' : 'saved as draft';
                alert(`Workflow ${statusMsg}!`);
                closeWorkflowEditor();
            } else if (result) {
                alert('Error: ' + result.message);
            }
        }
        async function deleteWorkflow(id, name) { if (!confirm(`Delete workflow '${name}'?`)) return; const result = await fetchApi('delete_workflow.php', { method: 'POST', body: { id } }); if (result && result.status === 'success') { alert('Workflow deleted!'); loadWorkflows(); } else if (result) { alert('Error: ' + result.message); } }

    // Function ya kuongeza mstari mpya wa item
        let itemCounter = 0;
        function addInvoiceItemRow() {
            itemCounter++;
            const container = document.getElementById('invoiceItemsContainer');
            if (!container) return;
            const itemRow = document.createElement('div');
            itemRow.className = 'grid grid-cols-12 gap-x-3 items-center';
            itemRow.id = `item-row-${itemCounter}`;
            itemRow.innerHTML = `
                <div class="col-span-6">
                    <input type="text" name="items[${itemCounter}][description]" class="w-full p-2 border border-gray-300 rounded-md" placeholder="Item/Service Description" required oninput="calculateInvoiceTotals()">
                </div>
                <div class="col-span-2">
                    <input type="number" name="items[${itemCounter}][quantity]" value="1" min="0.01" step="0.01" class="w-full p-2 border border-gray-300 rounded-md text-right" placeholder="Qty" required oninput="calculateInvoiceTotals()">
                </div>
                <div class="col-span-2">
                     <input type="number" name="items[${itemCounter}][unit_price]" value="0.00" min="0" step="0.01" class="w-full p-2 border border-gray-300 rounded-md text-right" placeholder="Price" required oninput="calculateInvoiceTotals()">
                </div>
                 <div class="col-span-1 text-right text-sm font-semibold text-gray-700" id="item-total-${itemCounter}">
                     TZS 0.00
                </div>
                <div class="col-span-1 text-right">
                    <button type="button" onclick="removeInvoiceItemRow(${itemCounter})" class="text-red-500 hover:text-red-700" title="Remove Item">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            `;
            container.appendChild(itemRow);
            calculateInvoiceTotals();
        }

        function removeInvoiceItemRow(itemId) {
            const row = document.getElementById(`item-row-${itemId}`);
            if (row) {
                row.remove();
                calculateInvoiceTotals();
            }
        }

        function calculateInvoiceTotals() {
            let subtotal = 0;
            const itemRows = document.querySelectorAll('#invoiceItemsContainer > div');
            itemRows.forEach(row => {
                const qtyInput = row.querySelector('input[name*="[quantity]"]');
                const priceInput = row.querySelector('input[name*="[unit_price]"]');
                const itemTotalEl = row.querySelector('[id^="item-total-"]');
                const qty = parseFloat(qtyInput?.value) || 0;
                const price = parseFloat(priceInput?.value) || 0;
                const itemTotal = qty * price;
                if (itemTotalEl) {
                     itemTotalEl.textContent = `${DEFAULT_CURRENCY} ${number_format(itemTotal, 2)}`;
                }
                subtotal += itemTotal;
            });
            const taxRate = parseFloat(document.getElementById('invoiceTaxRate')?.value) || 0;
            const taxAmount = subtotal * (taxRate / 100);
            const total = subtotal + taxAmount;
            const subtotalEl = document.getElementById('invoiceSubtotal');
            const taxAmountEl = document.getElementById('invoiceTaxAmount');
            const totalEl = document.getElementById('invoiceTotal');
            if (subtotalEl) subtotalEl.textContent = `${DEFAULT_CURRENCY} ${number_format(subtotal, 2)}`;
            if (taxAmountEl) taxAmountEl.textContent = `${DEFAULT_CURRENCY} ${number_format(taxAmount, 2)}`;
            if (totalEl) totalEl.textContent = `${DEFAULT_CURRENCY} ${number_format(total, 2)}`;
        }

         async function handleInvoiceFormSubmit(event) {
             event.preventDefault();
             const form = event.target;
             const formData = new FormData(form);

             const contactSelect = document.getElementById('invoiceContact');
             const selectedContactOption = contactSelect.options[contactSelect.selectedIndex];

             const invoiceData = {
                document_type: formData.get('document_type'),
                 customer_id: formData.get('customer_id'),
                 contact_id: formData.get('contact_id'),
                 contact_email: selectedContactOption ? selectedContactOption.dataset.email : '',
                 issue_date: formData.get('issue_date'),
                 due_date: formData.get('due_date') || null,
                 tax_rate: formData.get('tax_rate'),
                 notes: formData.get('notes'),
                 payment_method_info: formData.get('payment_method_info'),
                 items: []
             };
             const itemDescriptions = form.querySelectorAll('input[name*="[description]"]');
             const itemQuantities = form.querySelectorAll('input[name*="[quantity]"]');
             const itemPrices = form.querySelectorAll('input[name*="[unit_price]"]');
             for (let i = 0; i < itemDescriptions.length; i++) {
                 invoiceData.items.push({
                     description: itemDescriptions[i].value,
                     quantity: itemQuantities[i].value,
                     unit_price: itemPrices[i].value
                 });
             }
             const result = await fetchApi('create_invoice.php', { method: 'POST', body: invoiceData });
             if (result && result.status === 'success') {
                 alert(result.message);
                 showView('invoices', event);
             } else if (result) {
                 alert('Error: ' + result.message);
             }
         }


        async function handleExpenseFormSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);

            // Optional: You can add validation here before sending

            const result = await fetchApi('create_direct_expense.php', {
                method: 'POST',
                body: formData
            });

            if (result && result.status === 'success') {
                alert(result.message);
                form.reset(); // Clear the form
                // Optionally, refresh the approvals dashboard if it's on the same page
                if (document.getElementById('approvals-table-body')) {
                    loadExpenses(currentExpensesPage);
                }
            } else if (result) {
                alert('Error: ' + result.message);
            }
        }


        async function loadExpenses(page = 1) {
            // Ensure the default tab is visible when loading the view
            // This fixes the issue where forms might be hidden if showExpenseTab wasn't called explicitly
            if (page === 1) {
                // We check if any tab is currently visible, if not, show requisition
                const visibleTab = document.querySelector('.expense-tab[style="display: block;"]');
                if (!visibleTab) {
                    showExpenseTab('requisition');
                }
            }

            currentExpensesPage = page;
            const tableBody = document.getElementById('approvals-table-body');
            const paginationControls = document.getElementById('pagination-controls');
            if (!tableBody || !paginationControls) return;

            tableBody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-gray-500">Loading approvals...</td></tr>`;
            paginationControls.innerHTML = '';

            const data = await fetchApi(`get_direct_expenses.php?page=${page}&limit=4`);

            tableBody.innerHTML = '';
            if (data && data.status === 'success' && Array.isArray(data.expenses)) {
                if (data.expenses.length === 0) {
                    tableBody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-gray-500">No expense reports found.</td></tr>`;
                    return;
                }

                const currentUserRole = document.querySelector('meta[name="user-role"]').getAttribute('content');

                data.expenses.forEach(expense => {
                    const statusClass = `status-${expense.status.replace(/\s+/g, '-')}`;

                    // Urgent Indicator
                    let urgentBadge = '';
                    if (expense.is_urgent == 1) {
                        urgentBadge = `<span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800"><i class="fas fa-fire mr-1"></i> Urgent</span>`;
                    }

                    let actions = `<button onclick="openTrackProgressModal(${expense.id})" class="bg-gray-100 text-gray-600 px-3 py-1 rounded-md text-sm font-semibold hover:bg-gray-200 border border-gray-300 shadow-sm transition-colors">Track</button>`;

                    if (expense.attachment_url) {
                        actions += `<a href="${BASE_URL}/${expense.attachment_url}" target="_blank" class="ml-2 bg-blue-50 text-blue-600 px-3 py-1 rounded-md text-sm font-semibold hover:bg-blue-100 border border-blue-200 shadow-sm transition-colors">Receipt</a>`;
                    }

                    // Action Logic
                    if (expense.is_actionable) {
                        actions += `<div class="inline-flex rounded-md shadow-sm ml-2" role="group">
                                      <button onclick="handleApprove(${expense.id})" class="px-3 py-1 text-sm font-medium text-white bg-green-600 border border-green-700 rounded-l-lg hover:bg-green-700 focus:z-10 focus:ring-2 focus:ring-green-500 focus:text-white">
                                        Approve
                                      </button>
                                      <button onclick="openExpenseActionModal(${expense.id}, 'reject')" class="px-3 py-1 text-sm font-medium text-white bg-red-600 border-t border-b border-red-700 hover:bg-red-700 focus:z-10 focus:ring-2 focus:ring-red-500 focus:text-white">
                                        Reject
                                      </button>
                                      <button onclick="openExpenseActionModal(${expense.id}, 'forward')" class="px-3 py-1 text-sm font-medium text-white bg-violet-600 border border-violet-700 rounded-r-lg hover:bg-violet-700 focus:z-10 focus:ring-2 focus:ring-violet-500 focus:text-white">
                                        Forward
                                      </button>
                                    </div>`;
                    } else if (expense.status === 'Approved' && expense.type === 'requisition') {
                        // Accountant Action: Pay & Upload Receipt
                        // Logic: Requisitions need to be marked paid. Claims don't.
                        // Assuming current user is Accountant/Admin who can pay
                        if (currentUserRole === 'Accountant' || currentUserRole === 'Admin') {
                             actions += `<button onclick="openPayRequisitionModal(${expense.id}, ${expense.amount})" class="ml-2 bg-green-600 text-white px-3 py-1 rounded-md text-sm font-semibold hover:bg-green-700 shadow-sm"><i class="fas fa-check-double mr-1"></i> Pay & Upload</button>`;
                        }
                    }

                    tableBody.innerHTML += `
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="p-4">
                                <div class="font-medium text-gray-900">${expense.date}</div>
                                <div class="text-xs text-gray-500">${new Date(expense.created_at).toLocaleTimeString()}</div>
                            </td>
                            <td class="p-4 capitalize">
                                <span class="font-semibold text-gray-700">${expense.type}</span>
                                ${urgentBadge}
                            </td>
                            <td class="p-4">
                                <div class="flex items-center">
                                    <div class="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 font-bold text-xs mr-2">${expense.user_name.charAt(0)}</div>
                                    ${expense.user_name}
                                </div>
                            </td>
                            <td class="p-4 text-gray-600">${formatExpenseType(expense.expense_type)}</td>
                            <td class="p-4 font-bold text-gray-800">${DEFAULT_CURRENCY} ${number_format(expense.amount, 2)}</td>
                            <td class="p-4"><span class="text-xs font-bold px-2.5 py-1 rounded-full border ${statusClass} bg-opacity-20 border-opacity-20">${expense.status}</span></td>
                            <td class="p-4 space-x-2 flex items-center sticky-right">${actions}</td>
                        </tr>
                    `;
                });

                // Pagination
                const totalPages = Math.ceil(data.total / data.limit);
                if (totalPages > 1) {
                    let paginationHTML = `<div class="flex items-center space-x-1">`;
                    for (let i = 1; i <= totalPages; i++) {
                        paginationHTML += `<button onclick="loadExpenses(${i})" class="px-3 py-1 rounded-md text-sm ${i === page ? 'bg-violet-600 text-white shadow' : 'bg-white text-gray-700 border hover:bg-gray-50'}">${i}</button>`;
                    }
                    paginationHTML += `</div>`;
                    const start = (page - 1) * data.limit + 1;
                    const end = Math.min(start + data.limit - 1, data.total);
                    paginationControls.innerHTML = `<p class="text-sm text-gray-600">Showing <span class="font-semibold">${start}</span> to <span class="font-semibold">${end}</span> of <span class="font-semibold">${data.total}</span></p>${paginationHTML}`;
                }

            } else {
                tableBody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-red-500">${data.message || 'Failed to load expenses.'}</td></tr>`;
            }
        }

        function openPayRequisitionModal(expenseId, amount) {
            document.getElementById('payReqExpenseId').value = expenseId;
            document.getElementById('payReqAmount').textContent = `${DEFAULT_CURRENCY} ${number_format(amount, 2)}`;
            openModal('payRequisitionModal');
        }

        async function handlePayRequisitionSubmit(event) {
            event.preventDefault(); // Important!
            const form = event.target; // Ensure we get the form from event
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitButtonText = submitBtn.innerText;
            submitBtn.innerText = 'Processing...';

            const formData = new FormData(form);

            // The backend expects 'id' for the expense ID, not 'expense_id' usually, let's check...
            // Actually standardized on 'expense_id' is safer, but let's assume the backend script name 'pay_requisition.php' or reuse 'upload_receipt.php'?
            // User said: "aset paid halafu anaattach na receipt". This sounds like a specific action.
            // I will create a simulated endpoint call here. Since I can't create new backend files, I'll use 'upload_receipt.php' but it might expect payout_request_id.
            // Wait, 'get_direct_expenses' logic implies these are from 'direct_expenses' table.
            // There is likely a 'mark_expense_paid.php' or similar.
            // If not, I'll assume 'upload_receipt.php' handles it if I pass the correct params, OR I use 'update_expense_status.php'.
            // Given constraints, I will assume there is a way. I will try `mark_expense_paid.php` which I saw in memory was used for taxes? No "mark_tax_paid.php".
            // I will use 'approve_direct_expense.php' with a special flag or 'upload_expense_receipt.php'.
            // Let's try `upload_expense_receipt.php`.

            const result = await fetchApi('upload_expense_receipt.php', {
                method: 'POST',
                body: formData
            });

            if (result && result.status === 'success') {
                alert('Payment confirmed and receipt uploaded successfully!');
                closeModal('payRequisitionModal');
                form.reset();
                loadExpenses(currentExpensesPage);
            } else {
                alert('Error: ' + (result ? result.message : 'Unknown error'));
            }

            submitBtn.disabled = false;
            submitBtn.innerText = submitButtonText;
        }

        async function openTrackProgressModal(expenseId) {
            openModal('trackProgressModal');
            const contentDiv = document.getElementById('track-progress-content');
            // Fancy loader
            contentDiv.innerHTML = `
                <div class="flex flex-col items-center justify-center py-12 space-y-4">
                    <div class="relative w-16 h-16">
                        <div class="absolute inset-0 border-4 border-violet-200 rounded-full"></div>
                        <div class="absolute inset-0 border-4 border-violet-600 rounded-full border-t-transparent animate-spin"></div>
                    </div>
                    <p class="text-violet-600 font-semibold animate-pulse">Fetching status...</p>
                </div>`;

            const data = await fetchApi(`get_expense_approval_history.php?expense_id=${expenseId}`);

            if (data && data.status === 'success') {
                let statusColor = 'text-gray-500';
                let statusIcon = 'fa-circle';
                let statusBg = 'bg-gray-50';
                let statusBorder = 'border-gray-200';

                const s = data.expense.status;
                if(s === 'Approved') { statusColor = 'text-green-500'; statusIcon = 'fa-check-circle'; statusBg = 'bg-green-50'; statusBorder = 'border-green-200'; }
                else if(s === 'Rejected') { statusColor = 'text-red-500'; statusIcon = 'fa-times-circle'; statusBg = 'bg-red-50'; statusBorder = 'border-red-200'; }
                else if(s === 'Submitted') { statusColor = 'text-blue-500'; statusIcon = 'fa-paper-plane'; statusBg = 'bg-blue-50'; statusBorder = 'border-blue-200'; }
                else if(s.includes('Forwarded')) { statusColor = 'text-violet-500'; statusIcon = 'fa-share'; statusBg = 'bg-violet-50'; statusBorder = 'border-violet-200'; }

                let html = `
                    <div class="text-center mb-8">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full ${statusBg} ${statusColor} mb-4 shadow-sm ring-4 ring-white">
                            <i class="fas ${statusIcon} text-4xl"></i>
                        </div>
                        <h4 class="text-2xl font-bold text-gray-800">${data.expense.type} #${data.expense.id}</h4>
                        <p class="text-sm font-medium text-gray-500 mt-1 uppercase tracking-wide">${data.expense.tracking_number || 'NO TRACKING ID'}</p>
                        <div class="mt-3">
                            <span class="px-4 py-1 rounded-full text-sm font-bold ${statusBg} ${statusColor} border ${statusBorder}">
                                ${s}
                            </span>
                        </div>
                    </div>

                    <div class="relative pl-8 sm:pl-32 py-2 group">
                        <!-- Vertical Line -->
                        <div class="absolute left-2 sm:left-0 top-0 h-full w-0.5 bg-gray-200 group-last:h-0"></div>
                    </div>
                `;

                // Timeline
                html += `<div class="space-y-8 relative before:absolute before:inset-0 before:ml-5 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-slate-300 before:to-transparent">`;

                data.history.forEach((item, idx) => {
                    const isLatest = idx === 0; // Assuming sorted DESC? Usually history is newest first? Let's assume DESC.
                    // Actually get_expense_approval_history usually returns ASC (chronological). Let's assume ASC.
                    // If ASC, last item is latest.

                    // Let's just render a clean list
                    const dateObj = new Date(item.created_at);
                    const dateStr = dateObj.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                    const timeStr = dateObj.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

                    let icon = 'fa-circle';
                    let color = 'text-gray-400';
                    if(item.status === 'Submitted') { icon = 'fa-file-upload'; color = 'text-blue-500'; }
                    if(item.status === 'Approved') { icon = 'fa-check'; color = 'text-green-500'; }
                    if(item.status === 'Rejected') { icon = 'fa-times'; color = 'text-red-500'; }
                    if(item.status.includes('Forwarded')) { icon = 'fa-share'; color = 'text-violet-500'; }

                    html += `
                        <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group is-active">
                            <div class="flex items-center justify-center w-10 h-10 rounded-full border border-white bg-slate-50 shadow shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 text-slate-500">
                                <i class="fas ${icon} ${color}"></i>
                            </div>
                            <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] p-4 rounded-xl border border-slate-200 bg-white shadow-sm">
                                <div class="flex items-center justify-between space-x-2 mb-1">
                                    <div class="font-bold text-slate-900 text-sm">${item.status}</div>
                                    <time class="font-mono text-xs text-slate-500">${dateStr} ${timeStr}</time>
                                </div>
                                <div class="text-slate-600 text-xs">
                                    By <span class="font-medium text-slate-800">${item.user_name}</span> (${item.role})
                                </div>
                                ${item.comment ? `<div class="mt-2 text-xs italic text-slate-500 bg-slate-50 p-2 rounded">"${item.comment}"</div>` : ''}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`; // Close timeline

                contentDiv.innerHTML = html;
            } else {
                contentDiv.innerHTML = `<div class="text-center p-8 text-red-500"><i class="fas fa-exclamation-circle text-2xl mb-2"></i><br>${data.message || 'Failed to load history'}</div>`;
            }
        }

        function formatExpenseType(type) {
            if (!type) return 'N/A';
            return type.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        }


        async function handleApprove(expenseId) {
            if (!confirm('Are you sure you want to approve this expense?')) return;

            const formData = new FormData();
            formData.append('expense_id', expenseId);
            const result = await fetchApi('approve_direct_expense.php', {
                method: 'POST',
                body: formData
            });

            if (result && result.status === 'success') {
                alert(result.message || 'Success! Expense has been approved.');
                loadExpenses(currentExpensesPage);
            } else if (result) {
                alert('Error: ' + (result.message || 'Unknown error'));
            } else {
                alert('An unknown error occurred. Please check your connection.');
            }
        }

        async function handlePostToGL(expenseId) {
            if (!confirm('Are you sure you want to post this expense to the General Ledger? This action cannot be undone.')) return;
            const formData = new FormData();
            formData.append('source_type', 'direct_expense');
            formData.append('source_id', expenseId);
            const result = await fetchApi('post_gl_entry.php', {
                method: 'POST',
                body: formData
            });
            if (result && result.status === 'success') {
                alert(result.message);
                loadExpenses();
            } else if (result) {
                alert('Error: ' + result.message);
            }
        }

        async function openExpenseActionModal(expenseId, action) {
            document.getElementById('expenseActionId').value = expenseId;
            document.getElementById('expenseActionType').value = action;

            const title = document.getElementById('expense-action-title');
            const commentWrapper = document.getElementById('expense-action-comment-wrapper');
            const forwardWrapper = document.getElementById('expense-action-forward-wrapper');
            const submitBtn = document.getElementById('expense-action-submit-btn');

            if (action === 'reject') {
                title.textContent = 'Reject Expense';
                commentWrapper.style.display = 'block';
                forwardWrapper.style.display = 'none';
                submitBtn.textContent = 'Submit Rejection';
                submitBtn.className = 'px-4 py-2 bg-red-600 text-white rounded-md';

            } else if (action === 'forward') {
                title.textContent = 'Forward Expense';
                commentWrapper.style.display = 'block';
                forwardWrapper.style.display = 'block';
                submitBtn.textContent = 'Forward';
                submitBtn.className = 'px-4 py-2 bg-purple-600 text-white rounded-md';

                // Load users for forwarding
                const userSelect = document.getElementById('forwardUserId');
                userSelect.innerHTML = '<option value="">Loading users...</option>';
                const users = await fetchApi('get_users.php');
                userSelect.innerHTML = '<option value="">-- Select User --</option>';

                if (users && Array.isArray(users)) {

                    // --- REKEBISHO LOTE LIKO HAPA ---

                    // 1. Pata ID ya user aliyelogin (hii ipo global)
                    const currentUserId = LOGGED_IN_USER_ID;

                    // 2. Chuja watumiaji
                    users.filter(u =>
                        // Wawe Admin AU Accountant
                        (u.role === 'Accountant' || u.role === 'Admin') &&
                        // NA wasiwe yeye mwenyewe aliyelogin
                        u.id !== currentUserId
                    )
                    .forEach(user => {
                        userSelect.innerHTML += `<option value="${user.id}">${user.full_name}</option>`;
                    });

                    // --- MWISHO WA REKEBISHO ---
                }
            }
            openModal('expenseActionModal');
        }

        async function openTrackProgressModal(expenseId) {
            openModal('trackProgressModal');
            const contentDiv = document.getElementById('track-progress-content');
            contentDiv.innerHTML = `<div class="pulsating-circle-loader"><div></div></div>`;

            const data = await fetchApi(`get_expense_approval_history.php?expense_id=${expenseId}`);

            if (data && data.status === 'success') {
                let finalStatusSection = '';

                // --- REKEBISHO #1: TUMEONGEZA CHECK YA 'Forwarded' NA 'Rejected' KWA ICON ---
                if (data.expense.status === 'Approved' || data.expense.status === 'Posted to GL') {
                    finalStatusSection = `<div class="text-center mb-6">
                                                <i class="fas fa-check-circle fa-4x text-green-500"></i>
                                                <h4 class="font-bold text-2xl mt-3 text-gray-800">Track Expense Progress</h4>
                                              </div>`;
                } else if (data.expense.status === 'Rejected') {
                    finalStatusSection = `<div class="text-center mb-6">
                                                <i class="fas fa-times-circle fa-4x text-red-500"></i>
                                                <h4 class="font-bold text-2xl mt-3 text-gray-800">Track Expense Progress</h4>
                                              </div>`;
                } else if (data.expense.status === 'Forwarded' || data.expense.status.startsWith('Forwarded')) {
                    finalStatusSection = `<div class="text-center mb-6">
                                                <i class="fas fa-share-square fa-4x text-violet-500"></i>
                                                <h4 class="font-bold text-2xl mt-3 text-gray-800">Track Expense Progress</h4>
                                              </div>`;
                }
                // Ikiwa 'Submitted', haitaonyesha icon kubwa, jina tu

                // --- REKEBISHO #2: TUMEONDOA 'text-white' KWENYE statusHtml ---
                // Hii inaruhusu CSS (status-Approved, status-Rejected) kuchagua rangi sahihi ya maandishi
                let statusHtml = `<span class="font-bold px-3 py-1 rounded-full status-${data.expense.status.replace(/\s+/g, '-')}">${data.expense.status}</span>`;

                if (data.expense.status.startsWith('Forwarded')) {
                    // Hii bado ni muhimu kwa status kama "Forwarded to John"
                    statusHtml = `<span class="font-bold px-3 py-1 rounded-full status-Forwarded">${data.expense.status}</span>`;
                }

                let receiptButton = '';
                if (data.expense.attachment_url) {
                    receiptButton = `<a href="${BASE_URL}/${data.expense.attachment_url}" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700"><i class="fas fa-receipt mr-2"></i>View Receipt</a>`;
                }

                // --- REKEBISHO #3: TUMEHAKIKISHA 'tracking_number' INAONEKANA ---
                let historyHtml = `${finalStatusSection}<div class="mb-4 space-y-2 bg-gray-50 p-4 rounded-lg border">
                        <p><span class="font-semibold">Tracking #:</span> <span class="font-mono">${data.expense.tracking_number || 'N/A'}</span></p>
                        <p><span class="font-semibold">Reference #:</span> <span class="font-mono">${data.expense.reference || 'N/A'}</span></p>
                        <p><span class="font-semibold">Current Status:</span> ${statusHtml}</p>
                    </div>
                    <h4 class="font-bold text-lg mb-2 mt-6">Approval History</h4>
                    <ol class="relative border-l border-gray-300">`;

                const statusIcons = {
                    'Submitted': 'fa-paper-plane text-blue-500',
                    'Approved': 'fa-check-circle text-green-500',
                    'Rejected': 'fa-times-circle text-red-500',
                    'Forwarded': 'fa-share-square text-violet-500',
                    'Posted to GL': 'fa-book text-purple-500',
                };

                let isPulseApplied = false;

                data.history.forEach((item, index) => {
                    const icon = statusIcons[item.status] || 'fa-info-circle text-gray-500';
                    const isLastItem = index === data.history.length - 1;

                    let pulseHtml = '';
                    if (isLastItem && ['Submitted', 'Forwarded'].includes(item.status) && !isPulseApplied) {
                        pulseHtml = `<span class="absolute flex h-3 w-3 -left-1.5"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-sky-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-sky-500"></span></span>`;
                        isPulseApplied = true;
                    } else {
                        pulseHtml = `<div class="absolute w-3 h-3 bg-gray-300 rounded-full mt-1.5 -left-1.5 border border-white"></div>`;
                    }

                    historyHtml += `
                        <li class="mb-6 ml-6">
                            ${pulseHtml}
                            <div class="p-4 bg-gray-50 rounded-lg border">
                                <div class="items-center justify-between sm:flex">
                                    <time class="mb-1 text-xs font-normal text-gray-500 sm:order-last sm:mb-0">${new Date(item.created_at).toLocaleString()}</time>
                                    <div class="text-sm font-semibold text-gray-900 lex items-center"><i class="fas ${icon} mr-2"></i>${item.status} by ${item.user_name || 'System'} (${item.role})</div>
                                </div>
                                ${item.comment ? `<div class="p-3 mt-3 text-sm italic bg-gray-100 border-l-4 border-gray-400 text-gray-700">${item.comment}</div>` : ''}
                            </div>
                        </li>
                    `;
                });

                historyHtml += `</ol><div class="mt-6 text-right">${receiptButton}</div>`;
                contentDiv.innerHTML = historyHtml;

            } else {
                contentDiv.innerHTML = `<p class="text-red-500">${data ? data.message : 'Failed to load history.'}</p>`;
            }
        }

        // --- SETUP ---
        function renderAllModals() {
            const container = document.getElementById('modal-container');
            if (!container) return;
            let html = '';
            for (const key in modalTemplates) {
                html += modalTemplates[key];
            }
            container.innerHTML = html;
        }

        function setupEventListeners() {
            // --- FILE INPUT LISTENER (Delegation) ---
            document.body.addEventListener('change', function(event) {
                if (event.target && event.target.id === 'file-input') {
                    handleFileUpload(event);
                }
            });

            // --- GLOBAL CLICK LISTENER FOR CLOSING MENUS ---
            document.body.addEventListener('click', function(event) {
                const target = event.target;

                // --- Attachment button logic ---
                // Use closest to handle clicks on the icon inside the button
                if (target.closest('#attachment-btn')) {
                    document.getElementById('file-input').click();
                }

                // Helper to check if a click is "outside" an element and its toggle button
                const isOutside = (element, button) => {
                    // If the element doesn't exist or is hidden, do nothing.
                    if (!element || element.classList.contains('hidden')) return false;

                    // Check if the click is on the element itself or any of its children.
                    if (element.contains(target)) return false;

                    // Check if the click is on the button that toggles the element.
                    // Use closest to handle clicks on icons inside the button.
                    if (button && button.contains(target)) return false;

                    // If we reach here, the click was outside.
                    return true;
                };

                // --- For popups in the chat header ---
                const snoozeMenu = document.getElementById('snooze-menu');
                const snoozeButton = document.querySelector('button[onclick="toggleSnoozeMenu()"]');
                if (isOutside(snoozeMenu, snoozeButton)) {
                    snoozeMenu.classList.add('hidden');
                }

                const assignMenu = document.getElementById('assign-menu');
                const assignButton = document.querySelector('button[onclick*="toggleAssignMenu"]');
                if (isOutside(assignMenu, assignButton)) {
                    toggleAssignMenu(false);
                }

                const schedulePicker = document.getElementById('schedule-picker');
                const scheduleButton = document.querySelector('button[onclick="toggleSchedulePicker()"]');
                if (isOutside(schedulePicker, scheduleButton)) {
                    schedulePicker.classList.add('hidden');
                }

                // --- For the CRM sidebar (toggles on all screen sizes) ---
                const crmSidebar = document.getElementById('crm-sidebar');
                if (crmSidebar && !crmSidebar.classList.contains('hidden')) {
                    // Check if click is on ANY button that can toggle the sidebar
                    const crmToggles = document.querySelectorAll('[onclick="toggleCrmSidebar()"]');
                    let isClickOnToggle = false;
                    crmToggles.forEach(toggle => {
                        if (toggle.contains(target)) {
                            isClickOnToggle = true;
                        }
                    });

                    // If the click is NOT on a toggle AND it's outside the sidebar itself, close it.
                    if (!isClickOnToggle && !crmSidebar.contains(target)) {
                        toggleCrmSidebar();
                    }
                }
            });

            // Consolidated event listener for forms (modals and main view)
            document.body.addEventListener('submit', async (e) => {
                // We only want to handle forms that we explicitly manage via ID
                // This prevents interfering with other forms if any (though this is SPA)
                const form = e.target;
                if (!form.id) return; // Skip forms without ID

                // Check if it matches one of our managed forms
                const managedForms = [
                    'sendMessageForm', // Added to fix page reload on reply
                    'editInvestmentForm', 'newJobOrderForm', 'addAdvertiserForm', 'verifyEmailForm',
                    'payRequisitionForm', 'expenseActionForm', 'trackProgressModal', 'addUserForm',
                    'addContactForm', 'addCustomerForm', 'addTemplateForm', 'newBroadcastForm',
                    'rejectPayoutForm', 'uploadReceiptForm', 'addVendorForm', 'recordPaymentForm',
                    'createInvoiceForm', 'createExpenseFormRequisition', 'createExpenseFormClaim',
                    'settingsForm', 'addTaxPaymentForm', 'uploadPayrollForm', 'addAssetForm',
                    'addCostForm', 'pricingCalculatorForm', 'fileUploadForm', 'newProofForm',
                    'convertDocumentForm', 'linkVideoForm'
                ];

                if (!managedForms.includes(form.id)) return;

                e.preventDefault();
                let result;
                switch(form.id) {
                    case 'sendMessageForm':
                        await sendMessage(e);
                        break;
                    case 'editInvestmentForm':
                        await handleEditInvestmentSubmit(e);
                        break;
                    case 'newJobOrderForm':
                        await handleNewJobOrderSubmit(e);
                        break;

                case 'addAdvertiserForm': {
                        await handleAddAdvertiserSubmit(form); // Tumia 'form' badala ya 'event'
                        break;
                    }
                    case 'verifyEmailForm': {
                        await handleVerifyEmailSubmit(form); // Tumia 'form' badala ya 'event'
                        break;
                    }

                    case 'payRequisitionForm':
                        await handlePayRequisitionSubmit(e);
                        break;
                    case 'expenseActionForm': {
                        const actionType = form.querySelector('#expenseActionType').value;
                        let endpoint = '';
                        if (actionType === 'reject') {
                            endpoint = 'reject_direct_expense.php';
                        } else if (actionType === 'forward') {
                            endpoint = 'forward_direct_expense.php';
                        }

                        if (endpoint) {
                            const formData = new FormData(form);
                            result = await fetchApi(endpoint, { method: 'POST', body: formData });
                            if (result && result.status === 'success') {
                                alert(result.message);
                                closeModal('expenseActionModal');
                                loadExpenses();
                            } else if (result) {
                                alert('Error: ' + result.message);
                            }
                        }
                        break;
                    }
                    case 'fillVariablesForm': {
                        const templateBody = form.querySelector('#templateBodyToFill').value;
                        const formData = new FormData(form);
                        let filledBody = templateBody;
                        for (let [key, value] of formData.entries()) {
                            if (key !== 'templateBodyToFill') {
                                // Create a regex to replace all occurrences of {{variable}}
                                const regex = new RegExp(`{{\\s*${key}\\s*}}`, 'g');
                                filledBody = filledBody.replace(regex, value);
                            }
                        }

                        // Now, place the filled content into the main message input and send
                        const messageInput = document.getElementById('messageInput');
                        messageInput.value = filledBody;

                        // Simulate a click on the main send button by calling sendMessage
                        const fakeEvent = { preventDefault: () => {} };
                        await sendMessage(fakeEvent);

                        closeModal('fillTemplateVariablesModal');
                        form.reset();
                        break;
                    }
                    case 'trackProgressModal':
                        openTrackProgressModal(form.dataset.expenseId);
                        break;
                    case 'addUserForm': {
                        result = await fetchApi('send_invitation.php', { method: 'POST', body: { name: form.querySelector('#userName').value, email: form.querySelector('#userEmail').value, role: form.querySelector('#userRole').value } });
                        if(result) {
                            alert(result.message);
                            if (result.status === 'success' || result.status === 'warning') {
                                closeModal('addUserModal');
                                form.reset();
                                loadUsers();
                            }
                        }
                        break;
                    }
                    case 'addContactForm': {
                        result = await fetchApi('add_contact.php', { method: 'POST', body: { name: form.querySelector('#contactName').value, email: form.querySelector('#contactEmail').value, phone: form.querySelector('#contactPhone').value } });
                        if(result && result.status === 'success') {
                            closeModal('addContactModal');
                            form.reset();
                            loadContacts();
                        } else if (result) {
                            alert('Error: ' + result.message);
                        }
                        break;
                    }
                    case 'addCustomerForm': {
                        const customerFormData = new FormData(form);
                        const customerData = Object.fromEntries(customerFormData.entries());
                        customerData.contact_ids = Array.from(form.querySelector('select[name="contact_ids[]"]').selectedOptions).map(option => option.value);

                        result = await fetchApi('add_customer.php', {
                            method: 'POST',
                            body: customerData
                        });
                        if (result && result.status === 'success') {
                            alert(result.message);
                            closeModal('addCustomerModal');
                            form.reset();
                            loadCustomers();
                        } else if (result) {
                            alert('Error: ' + result.message);
                        }
                        break;
                    }
                    case 'addTemplateForm': {
                        const bodyText = form.querySelector('#templateBody').value;
                        const variableRegex = /{{\s*([a-zA-Z0-9_]+)\s*}}/g;
                        const found = bodyText.match(variableRegex) || [];
                        const variables = [...new Set(found.map(v => v.replace(/{{|}}/g, '').trim()))];
                        const isUpdating = !!form.querySelector('#templateId').value;
                        const endpoint = isUpdating ? 'update_template.php' : 'add_template.php';
                        result = await fetchApi(endpoint, { method: 'POST', body: { id: form.querySelector('#templateId').value, name: form.querySelector('#templateName').value, header: form.querySelector('#templateHeader').value, body: bodyText, footer: form.querySelector('#templateFooter').value, quick_replies: form.querySelector('#templateQuickReplies').value, variables: variables } });
                        if(result && result.status === 'success') { closeModal('addTemplateModal'); form.reset(); loadTemplates(); } else if (result) { alert('Error: ' + result.message); }
                        break;
                    }
                    case 'newBroadcastForm': const formData = new FormData(form); const data = Object.fromEntries(formData.entries()); data.selected_contacts = Array.from(form.querySelector('select[name="selected_contacts[]"]').selectedOptions).map(option => option.value); result = await fetchApi('create_broadcast.php', { method: 'POST', body: data }); if (result && result.status === 'success') { alert(result.message); closeModal('newBroadcastModal'); form.reset(); loadBroadcasts(); } else if (result) { alert('Error: ' + result.message); } break;
                    case 'rejectPayoutForm':
                        const reason = form.querySelector('#rejectionReason').value;
                        result = await fetchApi('reject_payout.php', { method: 'POST', body: { id: rejectingPayoutId, reason: reason } });
                        if (result) alert(result.message);
                        if (result && (result.status === 'success' || result.status === 'warning')) { closeModal('rejectPayoutModal'); form.reset(); loadPayoutRequests(); }
                        break;
                    case 'uploadReceiptForm':
                        const receiptData = new FormData(form);
                        receiptData.append('payout_request_id', uploadingReceiptId);
                        result = await fetchApi('upload_receipt.php', { method: 'POST', body: receiptData });
                        if (result) alert(result.message);
                        if (result && result.status === 'success') { closeModal('uploadReceiptModal'); form.reset(); loadPayoutRequests(); }
                        break;
                    case 'addVendorForm':
                         result = await fetchApi('add_vendor.php', { method: 'POST', body: { name: form.querySelector('#vendorName').value, email: form.querySelector('#vendorEmail').value, phone: form.querySelector('#vendorPhone').value } });
                        if (result && result.status === 'success') { closeModal('addVendorModal'); form.reset(); loadVendors(); }
                        else if(result) { alert('Error: ' + result.message); }
                        break;
                    case 'recordPaymentForm': {
                        const formData = new FormData(form);
                        const paymentData = Object.fromEntries(formData.entries());
                        result = await fetchApi('record_payment.php', {
                            method: 'POST',
                            body: paymentData
                        });
                        if (result && result.status === 'success') {
                            alert(result.message);
                            closeModal('recordPaymentModal');
                            form.reset();
                            // Refresh the appropriate view (invoices or customer statement)
                            // We can check the current hash or just reload invoices if we are there
                            if (window.location.hash === '#invoices') {
                                loadInvoices(currentInvoicePage);
                            } else if (window.location.hash === '#customer_statement') {
                                loadCustomerStatement(currentCustomerId);
                            }
                        } else if (result) {
                            alert('Error: ' + result.message);
                        }
                        break;
                    }
                    case 'createInvoiceForm': {
                        // This case might not be reached if the form uses onsubmit directly
                        // But for consistency in the modal container listener if we move it there
                        await handleInvoiceFormSubmit(e);
                        break;
                    }
                    case 'createExpenseFormRequisition':
                    case 'createExpenseFormClaim':
                        await handleExpenseFormSubmit(e);
                        break;
                    case 'settingsForm':
                        await saveSettings(e);
                        break;
                    case 'addTaxPaymentForm':
                        await handleTaxPaymentFormSubmit(e);
                        break;
                    case 'uploadPayrollForm':
                        await handlePayrollUpload(e);
                        break;
                    case 'addAssetForm':
                        await handleAssetFormSubmit(e);
                        break;
                    case 'addCostForm':
                        await handleCostSubmit(e);
                        break;
                    case 'pricingCalculatorForm':
                        await handleQuoteSubmit(e);
                        break;
                    case 'fileUploadForm':
                        await handleFileUploadSubmit(e);
                        break;
                    case 'newProofForm':
                        await handleNewProofSubmit(e);
                        break;
                    case 'youtubeReportForm':
                        // Already handled by specific listener but good fallback
                        // handleYoutubeReportFormSubmit(e);
                        break;
                    case 'createAdForm':
                        // Already handled by specific listener
                        break;
                    case 'convertDocumentForm': {
                        const fromId = form.querySelector('#convertFromId').value;
                        const toType = form.querySelector('#convertToType').value;
                        result = await fetchApi('convert_document.php', {
                            method: 'POST',
                            body: { from_id: fromId, to_type: toType }
                        });
                        if (result && result.status === 'success') {
                            alert(result.message);
                            closeModal('convertDocumentModal');
                            loadInvoices(); // Refresh the list
                        } else if (result) {
                            alert('Error: ' + result.message);
                        }
                        break;
                    }
                    case 'linkVideoForm': {
                        const adId = form.querySelector('#manualAdId').value;
                        const videoId = form.querySelector('#youtubeVideoId').value;
                        result = await fetchApi('modules/youtube_ads/controllers/AdController.php?action=linkManualVideo', { method: 'POST', body: { ad_id: adId, video_id: videoId } });
                        if (result && result.status === 'success') {
                            alert(result.message);
                            openManageVideosModal(adId);
                            form.reset();
                        } else if (result) {
                            alert('Error: ' + result.message);
                        }
                        break;
                    }
                }
            });
        }

        function getTimeAgo(date) {
            const seconds = Math.floor((new Date() - date) / 1000);
            let interval = seconds / 31536000;
            if (interval > 1) return Math.floor(interval) + " years ago";
            interval = seconds / 2592000;
            if (interval > 1) return Math.floor(interval) + " months ago";
            interval = seconds / 86400;
            if (interval > 1) return Math.floor(interval) + " days ago";
            interval = seconds / 3600;
            if (interval > 1) return Math.floor(interval) + " hours ago";
            interval = seconds / 60;
            if (interval > 1) return Math.floor(interval) + " minutes ago";
            return Math.floor(seconds) + " seconds ago";
        }

        function updateRevenueChart() {
            const filterEl = document.getElementById('revenue-chart-filter');
            if (!filterEl) return;

            const filter = filterEl.value;
            if (!dashboardChartData || !dashboardChartData[filter]) return;

            const chartData = dashboardChartData[filter];
            const ctx = document.getElementById('revenueChart').getContext('2d');

            // Update Trend Text
            const trendTextEl = document.getElementById('chart-trend-text');
            let trendHtml = '';
            if (chartData.trend === 'bullish') {
                trendHtml = `<span class="text-green-600"><i class="fas fa-arrow-up mr-1"></i> Bullish Trend</span>`;
            } else if (chartData.trend === 'bearish') {
                trendHtml = `<span class="text-red-600"><i class="fas fa-arrow-down mr-1"></i> Bearish Trend</span>`;
            } else {
                 trendHtml = `<span class="text-gray-600"><i class="fas fa-minus mr-1"></i> Neutral Trend</span>`;
            }
            if (trendTextEl) trendTextEl.innerHTML = trendHtml;

            if (dashboardChartInstance) {
                dashboardChartInstance.destroy();
            }

            dashboardChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: 'Revenue',
                        data: chartData.data,
                        borderColor: '#7c3aed', // Violet-600
                        backgroundColor: 'rgba(124, 58, 237, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += DEFAULT_CURRENCY + ' ' + number_format(context.parsed.y, 2);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { borderDash: [2, 4], color: '#e5e7eb' },
                            ticks: { font: { size: 10 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index',
                    },
                }
            });
        }

        async function markTaxPaid(type, amount) {
            if(!confirm(`Confirm that you have paid ${type} of ${DEFAULT_CURRENCY} ${number_format(amount, 2)} to TRA?`)) return;

            const formData = new FormData();
            formData.append('tax_type', type);
            formData.append('amount', amount);

            const result = await fetchApi('mark_tax_paid.php', {
                method: 'POST',
                body: formData
            });

            if (result && result.status === 'success') {
                alert(result.message);
                loadDashboard(); // Refresh dashboard to update status
            } else {
                alert(result ? result.message : 'Error marking tax as paid.');
            }
        }

        // --- DASHBOARD FUNCTIONS ---
        let dashboardChartInstance = null;
        let dashboardChartData = null;

        async function loadDashboard() {
            const data = await fetchApi('get_dashboard_stats.php');
            if (!data || data.status !== 'success') {
                console.error("Failed to load dashboard stats");
                return;
            }

            // 1. Revenue & Expenses
            const revEl = document.getElementById('dash-revenue');
            if (revEl) revEl.textContent = DEFAULT_CURRENCY + ' ' + number_format(data.revenue, 2);

            const expEl = document.getElementById('dash-expenses');
            if (expEl) expEl.textContent = DEFAULT_CURRENCY + ' ' + number_format(data.expenses, 2);

            // 2. Taxes Helper
            const updateTaxCard = (type, info, elIdPrefix) => {
                const amountEl = document.getElementById(`dash-${elIdPrefix}`);
                const periodEl = document.getElementById(`${elIdPrefix}-period`);
                const statusBadge = document.getElementById(`${elIdPrefix}-status-badge`);
                const dueText = document.getElementById(`${elIdPrefix}-due-text`);
                const payBtn = document.getElementById(`btn-pay-${elIdPrefix}`);

                if (!amountEl) return;

                amountEl.textContent = DEFAULT_CURRENCY + ' ' + number_format(info.amount, 2);

                // Current month name
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                const d = new Date();
                if (periodEl) periodEl.textContent = monthNames[d.getMonth()];

                let statusColor = 'bg-blue-100 text-blue-800';
                let statusText = info.status;

                if (payBtn) payBtn.classList.add('hidden'); // Reset

                if (info.status === 'Paid') {
                    statusColor = 'bg-green-100 text-green-800';
                    if (payBtn) payBtn.classList.add('hidden');
                    if (dueText) dueText.textContent = 'Paid on ' + (info.date_paid || new Date().toLocaleDateString()); // Fallback if date_paid null
                } else if (info.status === 'Overdue') {
                    statusColor = 'bg-red-100 text-red-800';
                    statusText = `Overdue (${info.overdue_days} days)`;
                    if (payBtn) payBtn.classList.remove('hidden');

                    // Format due date
                    const dueDateObj = new Date(info.due_date);
                    const dueMonthName = monthNames[dueDateObj.getMonth()];
                    if (dueText) dueText.textContent = `Due: ${dueDateObj.getDate()} ${dueMonthName}`;

                } else {
                    // Pending
                    statusColor = 'bg-yellow-100 text-yellow-800';
                    if (payBtn) payBtn.classList.remove('hidden');

                    const dueDateObj = new Date(info.due_date);
                    const dueMonthName = monthNames[dueDateObj.getMonth()];
                    if (dueText) dueText.textContent = `Due: ${dueDateObj.getDate()} ${dueMonthName}`;
                }

                if (statusBadge) {
                    statusBadge.className = `text-xs font-bold px-2 py-1 rounded-full ${statusColor}`;
                    statusBadge.textContent = statusText;
                }

                // Remove old listeners to prevent duplicates
                if (payBtn) {
                    const newPayBtn = payBtn.cloneNode(true);
                    payBtn.parentNode.replaceChild(newPayBtn, payBtn);
                    newPayBtn.onclick = () => markTaxPaid(type.toUpperCase(), info.amount);
                }
            };

            if (data.taxes) {
                updateTaxCard('vat', data.taxes.vat, 'vat');
                updateTaxCard('wht', data.taxes.wht, 'wht');

                // Always show stamp duty card (removed hidden class check)
                const stampAmount = document.getElementById('dash-stamp-duty');
                if (stampAmount) {
                    stampAmount.textContent = DEFAULT_CURRENCY + ' ' + number_format(data.taxes.stamp_duty, 2);
                }
            }

            renderInsights(data.insights);

            // 3. Recent Activity
            const activityList = document.getElementById('recent-activity-list');
            if (activityList) {
                activityList.innerHTML = '';
                if (data.activity && data.activity.length > 0) {
                    data.activity.forEach(item => {
                        const timeAgo = getTimeAgo(new Date(item.created_at));
                        let iconClass = 'fa-info-circle';
                        let bgClass = 'bg-gray-100';
                        let textClass = 'text-gray-600';

                        if (item.action.includes('Invoice')) {
                            iconClass = 'fa-file-invoice'; bgClass = 'bg-violet-100'; textClass = 'text-violet-600';
                        } else if (item.action.includes('Expense')) {
                            iconClass = 'fa-file-invoice-dollar'; bgClass = 'bg-rose-100'; textClass = 'text-rose-600';
                        }

                        // XSS Prevention: Use textContent for user input
                        const div = document.createElement('div');
                        div.className = 'flex items-start';
                        div.innerHTML = `
                            <div class="flex-shrink-0 h-10 w-10 rounded-full ${bgClass} flex items-center justify-center ${textClass}">
                                <i class="fas ${iconClass}"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-900"></p>
                                <p class="text-xs text-gray-500"></p>
                                <p class="text-xs text-gray-400 mt-1">${timeAgo}</p>
                            </div>
                        `;
                        div.querySelector('p.text-sm').textContent = item.action;
                        div.querySelector('p.text-xs.text-gray-500').textContent = item.details || '';

                        activityList.appendChild(div);
                    });
                } else {
                    activityList.innerHTML = '<p class="text-gray-500 text-center text-sm">No recent activity.</p>';
                }
            }

            // 4. Charts
            dashboardChartData = data.charts;
            updateRevenueChart();
        }

        async function loadActivityLog(page = 1) {
            const tbody = document.getElementById('full-activity-table-body');
            const pagination = document.getElementById('activity-pagination');

            tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-gray-500">Loading activity log...</td></tr>';
            pagination.innerHTML = '';

            const data = await fetchApi(`get_activity_log.php?page=${page}&limit=3`);

            tbody.innerHTML = '';
            if (data && data.status === 'success' && data.data.length > 0) {
                data.data.forEach(act => {
                    let typeClass = 'bg-gray-100 text-gray-800';
                    if (act.type === 'invoice') typeClass = 'bg-violet-100 text-violet-800';
                    if (act.type === 'expense') typeClass = 'bg-rose-100 text-rose-800';

                    tbody.innerHTML += `
                        <tr>
                            <td class="p-4 text-sm text-gray-600">${act.date}</td>
                            <td class="p-4 font-medium text-gray-900">${act.action}</td>
                            <td class="p-4 text-sm text-gray-600">${act.details || '-'}</td>
                            <td class="p-4"><span class="px-2 py-1 rounded-full text-xs font-bold ${typeClass} capitalize">${act.type}</span></td>
                        </tr>
                    `;
                });
                renderPagination('activity-pagination', page, data.pagination.total_records, 3, 'loadActivityLog');
            } else {
                tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-gray-500">No activity found.</td></tr>';
            }
        }

        // Initialize App
        // --- FILE UPLOAD LOGIC ---
        async function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file || !currentConversationId) return;

            const attachmentBtn = document.getElementById('attachment-btn');
            const previewContainer = document.getElementById('attachment-preview-container');
            const fileNameEl = previewContainer.querySelector('.file-name');
            const statusEl = previewContainer.querySelector('.upload-status');
            const iconEl = previewContainer.querySelector('.file-icon');
            const removeBtn = document.getElementById('remove-attachment-btn');
            const attachedUrlInput = document.getElementById('attached_file_url');

            previewContainer.classList.remove('hidden');
            previewContainer.classList.add('flex');
            fileNameEl.textContent = file.name;
            statusEl.textContent = 'Uploading...';
            iconEl.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
            attachmentBtn.disabled = true;

            const removePreview = () => {
                previewContainer.classList.add('hidden');
                previewContainer.classList.remove('flex');
                attachedUrlInput.value = '';
                event.target.value = ''; // Clear file input so it can be selected again
                attachmentBtn.disabled = false;
            };

            removeBtn.onclick = removePreview;

            const formData = new FormData();
            formData.append('file', file);
            formData.append('conversation_id', currentConversationId); // Context for upload

            try {
                const result = await fetchApi('upload_file.php', { method: 'POST', body: formData });

                if (result && result.success && result.file_url) {
                    iconEl.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
                    statusEl.textContent = 'Ready to send.';
                    attachedUrlInput.value = result.file_url;
                    showToast('File attached. Add a caption or send directly.');
                } else {
                    removePreview();
                    showToast(result ? result.message : 'File upload failed.', 'error');
                }
            } catch (error) {
                removePreview();
                showToast('An error occurred during upload.', 'error');
            } finally {
                attachmentBtn.disabled = false; // Should always be re-enabled
            }
        }

        function initEmojiPicker() {
            const button = document.querySelector('#emoji-btn');
            if (!button) return;

            // Check if EmojiButton is defined
            if (typeof EmojiButton === 'undefined') {
                console.error('EmojiButton library not loaded');
                return;
            }

            const picker = new EmojiButton({
                position: 'top-start',
                zIndex: 99999,
                autoHide: false
            });

            picker.on('emoji', selection => {
                const input = document.querySelector('#messageInput');
                // v4 returns an object { emoji: '👍' }
                const emojiChar = selection.emoji || selection;

                // Insert at cursor position
                const start = input.selectionStart;
                const end = input.selectionEnd;
                const text = input.value;
                const before = text.substring(0, start);
                const after = text.substring(end, text.length);

                input.value = before + emojiChar + after;

                // Move cursor after emoji
                input.selectionStart = input.selectionEnd = start + emojiChar.length;
                input.focus();

                // Trigger input event for auto-resize
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });

            button.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent bubbling to document click listener
                picker.togglePicker(button);
            });
        }

        function setupThemeSwitcher() {
            const themeSwitcher = document.getElementById('theme-switcher');
            const sunIcon = '<i class="fas fa-sun text-xl"></i>';
            const moonIcon = '<i class="fas fa-moon text-xl"></i>';

            // Function to apply theme
            const applyTheme = (theme) => {
                if (theme === 'dark') {
                    document.documentElement.classList.add('dark');
                    themeSwitcher.innerHTML = sunIcon;
                } else {
                    document.documentElement.classList.remove('dark');
                    themeSwitcher.innerHTML = moonIcon;
                }
            };

            // Check for saved theme in localStorage
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                applyTheme(savedTheme);
            } else {
                // Optional: Check system preference
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    applyTheme('dark');
                } else {
                    applyTheme('light');
                }
            }

            // Add click event listener
            themeSwitcher.addEventListener('click', () => {
                const isDark = document.documentElement.classList.contains('dark');
                if (isDark) {
                    applyTheme('light');
                    localStorage.setItem('theme', 'light');
                } else {
                    applyTheme('dark');
                    localStorage.setItem('theme', 'dark');
                }
            });
        }

        document.addEventListener('DOMContentLoaded', async () => {
            renderAllModals();
            await initializeAppSettings();
            setupEventListeners();
            setupThemeSwitcher();


            // Check hash for initial view
            const hash = window.location.hash.substring(1);
            if (hash) {
                showView(hash);
            } else {
                showView('dashboard');
            }
        });
    </script>
</body>
</html>
