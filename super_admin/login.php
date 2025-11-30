<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Admin Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-900 flex items-center justify-center h-screen font-['Inter']">
    <div class="bg-gray-800 p-8 rounded-xl shadow-2xl w-96 border border-gray-700">
        <h2 class="text-2xl font-bold text-white mb-6 text-center">Super Admin Login</h2>
        <form id="loginForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Username</label>
                <input type="text" id="username" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg text-white focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none transition-all" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Password</label>
                <input type="password" id="password" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg text-white focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none transition-all" required>
            </div>
            <button type="submit" class="w-full bg-violet-600 hover:bg-violet-700 text-white font-bold py-2.5 rounded-lg transition-all shadow-lg mt-2">Login</button>
        </form>
        <div id="message" class="mt-4 text-center text-sm font-semibold hidden"></div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = e.target.querySelector('button');
            const msg = document.getElementById('message');
            btn.disabled = true;
            btn.textContent = 'Verifying...';
            msg.classList.add('hidden');

            try {
                const res = await fetch('../api/super_admin/login_process.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value
                    })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    msg.textContent = 'Success! Redirecting...';
                    msg.className = 'mt-4 text-center text-sm font-semibold text-green-400';
                    msg.classList.remove('hidden');
                    setTimeout(() => window.location.href = data.redirect, 1000);
                } else {
                    throw new Error(data.message);
                }
            } catch (err) {
                msg.textContent = err.message || 'Login failed';
                msg.className = 'mt-4 text-center text-sm font-semibold text-red-400';
                msg.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'Login';
            }
        });
    </script>
</body>
</html>
