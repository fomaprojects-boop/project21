<?php
session_start(); // Anzisha session

// Kama mtumiaji tayari ameshaingia, mpeleke kwenye ukurasa mkuu
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ChatMe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome haihitajiki tena kwa logo, lakini nimeiacha kama unaitumia kwingine -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<!-- RANGI YA BACKGROUND IMEBADILISHWA HAPA -->
<body class="bg-gradient-to-br from-purple-50 to-blue-50 flex items-center justify-center h-screen">
    <div class="w-full max-w-md p-8 space-y-6 bg-white rounded-lg shadow-md">
        <div class="text-center">
            
            <!-- LOGO MPYA IMEWEKWA HAPA BADALA YA ICON -->
            <!-- UKUBWA WA LOGO UMEONGEZWA KUTOKA h-16 KUWA h-20 -->
            <img src="https://app.chatme.co.tz/uploads/LOGO_Chatme1.png" alt="ChatMe Logo" class="mx-auto h-30 w-auto">
            
            <!-- RANGI YA KICHWA CHA HABARI IMEBADILISHWA -->
            <h2 class="mt-4 text-3xl font-bold text-purple-800">
                Welcome to ChatMe
            </h2>
            <p class="mt-2 text-sm text-gray-600">
                Sign in to continue to your dashboard
            </p>
        </div>

        <div id="login-message" class="text-sm text-center p-2 rounded-md"></div>

        <form id="login-form" class="space-y-6">
            <div>
                <label for="email" class="text-sm font-medium text-gray-700">Email Address</label>
                <!-- RANGI YA FOCUS IMEBADILISHWA KUWA BLUU -->
                <input id="email" name="email" type="email" autocomplete="email" required
                       class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label for="password" class="text-sm font-medium text-gray-700">Password</label>
                <!-- RANGI YA FOCUS IMEBADILISHWA KUWA BLUU -->
                <input id="password" name="password" type="password" autocomplete="current-password" required
                       class="w-full px-3 py-2 mt-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <!-- RANGI YA KITUFE (BUTTON) IMEBADILISHWA KUWA GRADIENT (PURPLE & BLUU) -->
                <button type="submit"
                        class="w-full px-4 py-2 text-lg font-semibold text-white bg-gradient-to-r from-purple-700 to-blue-600 hover:from-purple-800 hover:to-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Sign In
                </button>
            </div>
        </form>
    </div>

    <script>
    document.getElementById('login-form').addEventListener('submit', function(event) {
        event.preventDefault();

        const formData = new FormData(this);
        const data = Object.fromEntries(formData.entries());
        const messageDiv = document.getElementById('login-message');
        
        messageDiv.textContent = 'Checking credentials...';
        messageDiv.className = 'text-sm text-center p-2 rounded-md bg-yellow-100 text-yellow-800';

        fetch('./api/login_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success') {
                messageDiv.textContent = result.message;
                messageDiv.className = 'text-sm text-center p-2 rounded-md bg-green-100 text-green-800';
                // Redirect to the main page after successful login
                window.location.href = 'index.php';
            } else {
                messageDiv.textContent = result.message;
                messageDiv.className = 'text-sm text-center p-2 rounded-md bg-red-100 text-red-800';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            messageDiv.textContent = 'An unexpected error occurred.';
            messageDiv.className = 'text-sm text-center p-2 rounded-md bg-red-100 text-red-800';
        });
    });
    </script>

</body>
</html>