import re

with open('index.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Strip PHP tags
content = re.sub(r'<\?php.*?\?>', '', content, flags=re.DOTALL)

# Inject mock data for variables
content = content.replace('<?php echo ; ?>', 'http://localhost:8000')
content = content.replace('<?php echo ; ?>', '1')
content = content.replace('<?php echo ; ?>', 'Admin')
content = content.replace('<?php echo htmlspecialchars(, ENT_QUOTES); ?>', 'Test User')
content = content.replace('<?php echo defined(\'FACEBOOK_CONFIG_ID\') ? FACEBOOK_CONFIG_ID : \'\'; ?>', '123456')
content = content.replace('<?php echo defined(\'FACEBOOK_APP_ID\') ? FACEBOOK_APP_ID : \'\'; ?>', '123456')
content = content.replace('<?php if ( === \'Admin\' ||  === \'Accountant\'): ?>', '')
content = content.replace('<?php endif; ?>', '')
content = content.replace('<?php if (FEATURE_ENHANCED_EXPENSE_WORKFLOW): ?>', '')
content = content.replace('<?php if ( === \'Admin\' ||  === \'Staff\' ||  === \'Accountant\'): ?>', '')
content = content.replace('<?php if ( === \'Client\'): ?>', '<div style="display:none">')

# Mock loadDashboard logic to immediately render the stats
mock_js = """
<script>
    // Mock fetchApi
    window.fetchApi = async function(endpoint) {
        console.log('Mock fetch:', endpoint);
        if (endpoint.includes('get_dashboard_stats.php')) {
            return {
                status: 'success',
                revenue: 1500000,
                expenses: 500000,
                taxes: {
                    vat: { amount: 100000, status: 'Due', overdue_days: 0, period: 'Sep', is_paid: false },
                    wht: { amount: 50000, status: 'Overdue', overdue_days: 5, period: 'Sep', is_paid: false },
                    stamp_duty: { amount: 25000, status: 'Accruing', overdue_days: 0, period: 'Oct', is_paid: false }
                },
                insights: [],
                activity: [
                    { type: 'Invoice', reference: 'INV-001', amount: 100000, created_at: new Date().toISOString() },
                    { type: 'Expense', reference: 'Fuel', amount: 50000, created_at: new Date().toISOString() },
                    { type: 'Receipt', reference: 'REC-001', amount: 100000, created_at: new Date().toISOString() }
                ],
                charts: { week: { labels: [], data: [], trend: 'neutral' } }
            };
        }
        return { status: 'success' };
    };

    // Force load dashboard on init
    window.addEventListener('load', () => {
        showView('dashboard');
    });
</script>
"""
content = content.replace('</body>', mock_js + '</body>')

with open('verification/index.html', 'w', encoding='utf-8') as f:
    f.write(content)
