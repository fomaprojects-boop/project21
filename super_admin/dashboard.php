<?php
session_start();
if (!isset($_SESSION['super_admin_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - ChatMe SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">

    <!-- Header -->
    <header class="bg-gray-800 border-b border-gray-700 p-4 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-violet-600 rounded-lg flex items-center justify-center text-white font-bold text-xl">
                    S
                </div>
                <h1 class="text-xl font-bold tracking-tight">Super Admin Portal</h1>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-gray-400">Logged in as Super Admin</span>
                <button onclick="logout()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                    Logout
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto p-6">

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-gray-800 p-6 rounded-xl border border-gray-700 shadow-lg">
                <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider mb-1">Total Tenants</h3>
                <p id="total-tenants" class="text-3xl font-bold text-white">Loading...</p>
            </div>
            <div class="bg-gray-800 p-6 rounded-xl border border-gray-700 shadow-lg">
                <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider mb-1">Active Subscriptions</h3>
                <p id="active-tenants" class="text-3xl font-bold text-green-400">Loading...</p>
            </div>
            <div class="bg-gray-800 p-6 rounded-xl border border-gray-700 shadow-lg">
                <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider mb-1">Suspended</h3>
                <p id="suspended-tenants" class="text-3xl font-bold text-red-400">Loading...</p>
            </div>
        </div>

        <!-- Support PIN Verification -->
        <div class="mb-8">
            <div class="bg-gray-800 p-6 rounded-xl border border-gray-700 shadow-xl">
                <h2 class="text-xl font-bold text-white mb-4 flex items-center gap-2"><i class="fas fa-headset text-violet-500"></i> Verify Support PIN</h2>
                <div class="flex gap-4">
                    <input type="text" id="support-pin-input" placeholder="Enter Tenant Support PIN (e.g. 7X9-B2A)" class="bg-gray-900 border border-gray-600 text-white text-lg rounded-lg focus:ring-violet-500 focus:border-violet-500 block w-full md:w-1/3 p-3 font-mono tracking-widest uppercase">
                    <button onclick="verifyPin()" class="bg-violet-600 hover:bg-violet-700 text-white px-6 py-3 rounded-lg font-bold transition-all shadow-lg flex items-center gap-2">
                        <i class="fas fa-check-circle"></i> Verify & Access
                    </button>
                </div>
            </div>
        </div>

        <!-- Tenant List -->
        <div class="bg-gray-800 rounded-xl border border-gray-700 shadow-xl overflow-hidden">
            <div class="p-6 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-xl font-bold text-white">Tenants Management</h2>
                <div class="relative">
                    <input type="text" id="search-tenant" placeholder="Search tenants..." class="bg-gray-900 border border-gray-600 text-white text-sm rounded-lg focus:ring-violet-500 focus:border-violet-500 block w-64 pl-10 p-2.5">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                        <i class="fas fa-search text-gray-500"></i>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-400">
                    <thead class="text-xs text-gray-400 uppercase bg-gray-900/50">
                        <tr>
                            <th scope="col" class="px-6 py-3">ID</th>
                            <th scope="col" class="px-6 py-3">Business Name</th>
                            <th scope="col" class="px-6 py-3">Created At</th>
                            <th scope="col" class="px-6 py-3">Remote Access</th>
                            <th scope="col" class="px-6 py-3">Status</th>
                            <th scope="col" class="px-6 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tenants-table-body" class="divide-y divide-gray-700">
                        <!-- Rows injected here -->
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <script>
        const BASE_API = '../api/super_admin';

        async function verifyPin() {
            const pin = document.getElementById('support-pin-input').value.trim();
            if (!pin) {
                Swal.fire('Error', 'Please enter a PIN', 'error');
                return;
            }

            try {
                const res = await fetch(`${BASE_API}/get_tenant_by_pin.php`, {
                    method: 'POST',
                    body: JSON.stringify({ pin: pin })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    showTenantModal(data.tenant);
                } else {
                    Swal.fire('Verification Failed', data.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error', 'Network error', 'error');
            }
        }

        function showTenantModal(tenant) {
            const isAccessOpen = tenant.remote_access_enabled == 1;
            const statusHtml = isAccessOpen
                ? `<span class="bg-green-900 text-green-300 px-3 py-1 rounded-full text-sm font-bold flex items-center gap-2 w-fit"><i class="fas fa-lock-open"></i> OPEN</span>`
                : `<span class="bg-red-900 text-red-300 px-3 py-1 rounded-full text-sm font-bold flex items-center gap-2 w-fit"><i class="fas fa-lock"></i> LOCKED</span>`;

            const btnHtml = isAccessOpen
                ? `<button onclick="loginAsTenant(${tenant.id})" class="w-full bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 rounded-lg shadow-lg transition-all flex justify-center items-center gap-2"><i class="fas fa-user-secret"></i> Login as Tenant Admin</button>`
                : `<button disabled class="w-full bg-gray-600 text-gray-400 font-bold py-3 rounded-lg cursor-not-allowed flex justify-center items-center gap-2"><i class="fas fa-ban"></i> Access Denied</button>`;

            Swal.fire({
                title: `<span class="text-gray-100">${tenant.business_name}</span>`,
                html: `
                    <div class="text-left space-y-4 bg-gray-800 p-4 rounded-lg border border-gray-700">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-gray-500 text-xs uppercase tracking-widest">Owner Email</p>
                                <p class="text-white font-medium break-all">${tenant.owner_email || 'N/A'}</p>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs uppercase tracking-widest">Plan Status</p>
                                <p class="text-white font-medium uppercase">${tenant.subscription_status}</p>
                            </div>
                        </div>
                        <div class="border-t border-gray-700 pt-4">
                            <p class="text-gray-500 text-xs uppercase tracking-widest mb-2">Invoice Summary</p>
                            <div class="flex gap-4">
                                <div class="bg-gray-900 p-2 rounded flex-1">
                                    <span class="text-green-400 font-bold block">Paid</span>
                                    <span class="text-white text-sm">${new Intl.NumberFormat().format(tenant.invoice_summary.paid)}</span>
                                </div>
                                <div class="bg-gray-900 p-2 rounded flex-1">
                                    <span class="text-red-400 font-bold block">Due</span>
                                    <span class="text-white text-sm">${new Intl.NumberFormat().format(tenant.invoice_summary.due)}</span>
                                </div>
                            </div>
                        </div>
                        <div class="border-t border-gray-700 pt-4 flex justify-between items-center">
                            <span class="text-gray-400 font-medium">Remote Access:</span>
                            ${statusHtml}
                        </div>
                        <div class="pt-2">
                            ${btnHtml}
                        </div>
                    </div>
                `,
                background: '#1f2937',
                showConfirmButton: false,
                showCloseButton: true,
                customClass: {
                    popup: 'border border-gray-700 rounded-xl shadow-2xl'
                }
            });
        }

        async function fetchDashboardData() {
            try {
                const res = await fetch(`${BASE_API}/get_dashboard_data.php`);
                const data = await res.json();

                if (data.status === 'success') {
                    // KPI
                    document.getElementById('total-tenants').textContent = data.stats.total_tenants;
                    document.getElementById('active-tenants').textContent = data.stats.active_tenants;
                    document.getElementById('suspended-tenants').textContent = data.stats.suspended_tenants;

                    // Table
                    const tbody = document.getElementById('tenants-table-body');
                    tbody.innerHTML = '';

                    data.tenants.forEach(tenant => {
                        const statusColor = tenant.subscription_status === 'active' ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300';
                        const remoteAccessHtml = tenant.remote_access_enabled == 1
                            ? `<span class="flex items-center text-green-400 gap-1" title="Access Granted"><i class="fas fa-lock-open"></i> <span class="font-mono text-xs bg-gray-900 px-1 rounded">${tenant.support_pin || 'NO PIN'}</span></span>`
                            : `<span class="text-gray-500" title="Access Disabled"><i class="fas fa-lock"></i></span>`;

                        const row = `
                            <tr class="hover:bg-gray-750 transition-colors">
                                <td class="px-6 py-4 font-mono text-xs">${tenant.id}</td>
                                <td class="px-6 py-4 font-medium text-white text-base">${tenant.business_name}</td>
                                <td class="px-6 py-4">${new Date(tenant.created_at).toLocaleDateString()}</td>
                                <td class="px-6 py-4">${remoteAccessHtml}</td>
                                <td class="px-6 py-4">
                                    <span class="${statusColor} text-xs font-medium px-2.5 py-0.5 rounded uppercase">${tenant.subscription_status}</span>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button onclick="loginAsTenant(${tenant.id})" class="text-violet-400 hover:text-violet-300 font-medium text-sm border border-violet-800 hover:bg-violet-900 px-3 py-1.5 rounded transition-all" ${tenant.remote_access_enabled != 1 ? 'disabled style="opacity:0.5; cursor:not-allowed;" title="Remote Access Disabled"' : ''}>
                                        <i class="fas fa-user-secret mr-1"></i> Ghost Login
                                    </button>
                                    <button onclick="toggleStatus(${tenant.id}, '${tenant.subscription_status}')" class="text-gray-400 hover:text-white font-medium text-sm px-3 py-1.5 rounded hover:bg-gray-700 transition-all">
                                        ${tenant.subscription_status === 'active' ? 'Suspend' : 'Activate'}
                                    </button>
                                </td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (err) {
                console.error(err);
                Swal.fire('Error', 'Failed to load dashboard data', 'error');
            }
        }

        async function loginAsTenant(tenantId) {
            try {
                const res = await fetch(`${BASE_API}/login_as_tenant.php`, {
                    method: 'POST',
                    body: JSON.stringify({ tenant_id: tenantId })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    window.location.href = data.redirect;
                } else {
                    Swal.fire('Login Failed', data.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error', 'Network error', 'error');
            }
        }

        async function toggleStatus(tenantId, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'suspended' : 'active';
            const action = newStatus === 'active' ? 'Activate' : 'Suspend';

            const result = await Swal.fire({
                title: `Confirm ${action}?`,
                text: `Are you sure you want to ${action.toLowerCase()} this tenant?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: newStatus === 'active' ? '#10b981' : '#ef4444',
                confirmButtonText: `Yes, ${action}`
            });

            if (result.isConfirmed) {
                const res = await fetch(`${BASE_API}/update_tenant_status.php`, {
                    method: 'POST',
                    body: JSON.stringify({ tenant_id: tenantId, status: newStatus })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    Swal.fire('Updated!', `Tenant has been ${newStatus}.`, 'success');
                    fetchDashboardData();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            }
        }

        function logout() {
            // Simple session clear could be an endpoint, but for now redirect to login which should handle it or make a logout endpoint.
            // Assuming standard logout exists? No, Super Admin usually needs specific logout.
            // Let's just create a quick client-side redirect to a logout script if exists, or just clear session.
            // For now, redirect to login page.
            window.location.href = 'login.php';
        }

        document.addEventListener('DOMContentLoaded', fetchDashboardData);
    </script>
</body>
</html>
