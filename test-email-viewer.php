<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Calendar Invite Test Viewer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        .dashboard {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
        }
        .panel {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .panel h2 {
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .email-list {
            max-height: 600px;
            overflow-y: auto;
        }
        .email-item {
            padding: 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .email-item:hover {
            background: #f8f9fa;
            border-color: #667eea;
            transform: translateX(5px);
        }
        .email-item.selected {
            background: #e8eaf6;
            border-color: #667eea;
            border-width: 2px;
        }
        .email-to { color: #666; font-size: 12px; }
        .email-subject { font-weight: bold; color: #333; margin: 5px 0; }
        .email-time { color: #999; font-size: 11px; }
        .email-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 10px;
        }
        .type-invite { background: #4caf50; color: white; }
        .type-update { background: #ff9800; color: white; }
        .type-cancel { background: #f44336; color: white; }
        iframe {
            width: 100%;
            height: 600px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }
        .actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-danger {
            background: #f44336;
            color: white;
        }
        .btn-success {
            background: #4caf50;
            color: white;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .status {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .status-info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        .status-success {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
        }
        .test-controls {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .control-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .no-emails {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
        }
        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Calendar Invite Test Viewer</h1>

        <div class="test-controls">
            <div class="status status-info">
                <strong>Test Mode Active:</strong> All emails are being captured locally instead of being sent.
            </div>

            <div class="control-group">
                <button class="btn-success" onclick="enableTestMode()">Enable Test Mode</button>
                <button class="btn-primary" onclick="refreshEmails()">🔄 Refresh</button>
                <button class="btn-danger" onclick="clearEmails()">🗑️ Clear All</button>
                <span id="status"></span>
            </div>
        </div>

        <div class="stats" id="stats">
            <div class="stat-card">
                <div class="stat-number" id="total-count">0</div>
                <div class="stat-label">Total Emails</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="invite-count">0</div>
                <div class="stat-label">Invites Sent</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="update-count">0</div>
                <div class="stat-label">Updates Sent</div>
            </div>
        </div>

        <div class="dashboard">
            <div class="panel">
                <h2>📬 Captured Emails</h2>
                <div class="email-list" id="email-list">
                    <div class="no-emails">No emails captured yet. Create an event with calendar invites enabled to see them here.</div>
                </div>
            </div>

            <div class="panel">
                <h2>👁️ Email Preview</h2>
                <div id="preview-area">
                    <iframe id="preview-frame" src="about:blank"></iframe>
                    <div class="actions">
                        <button class="btn-primary" onclick="downloadICS()">📥 Download .ics</button>
                        <button class="btn-primary" onclick="viewRaw()">View Raw iCal</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentEmail = null;
        let emails = [];

        async function loadEmails() {
            try {
                const response = await fetch('test-email-api.php?action=list');
                const data = await response.json();

                if (data.success && data.emails) {
                    emails = data.emails.reverse(); // Newest first
                    displayEmails(emails);
                    updateStats(emails);
                }
            } catch (error) {
                console.error('Error loading emails:', error);
            }
        }

        function displayEmails(emailList) {
            const container = document.getElementById('email-list');

            if (emailList.length === 0) {
                container.innerHTML = '<div class="no-emails">No emails captured yet. Create an event with calendar invites enabled to see them here.</div>';
                return;
            }

            container.innerHTML = emailList.map((email, index) => `
                <div class="email-item" onclick="selectEmail(${index})">
                    <div class="email-to">To: ${email.to}</div>
                    <div class="email-subject">
                        ${email.subject}
                        <span class="email-type type-${email.type}">${email.type.toUpperCase()}</span>
                    </div>
                    <div class="email-time">${email.timestamp}</div>
                </div>
            `).join('');
        }

        function selectEmail(index) {
            currentEmail = emails[index];

            // Update selection UI
            document.querySelectorAll('.email-item').forEach((el, i) => {
                el.classList.toggle('selected', i === index);
            });

            // Load preview
            const frame = document.getElementById('preview-frame');
            frame.src = 'test-output/emails/' + currentEmail.html_file;
        }

        function updateStats(emailList) {
            const total = emailList.length;
            const invites = emailList.filter(e => e.type === 'invite').length;
            const updates = emailList.filter(e => e.type === 'update').length;

            document.getElementById('total-count').textContent = total;
            document.getElementById('invite-count').textContent = invites;
            document.getElementById('update-count').textContent = updates;
        }

        async function clearEmails() {
            if (!confirm('Clear all test emails?')) return;

            try {
                const response = await fetch('test-email-api.php?action=clear', { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    loadEmails();
                    document.getElementById('preview-frame').src = 'about:blank';
                    showStatus('All emails cleared', 'success');
                }
            } catch (error) {
                showStatus('Error clearing emails', 'error');
            }
        }

        async function enableTestMode() {
            try {
                const response = await fetch('test-email-api.php?action=enable_test', { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    showStatus('Test mode enabled - emails will be captured locally', 'success');
                }
            } catch (error) {
                showStatus('Error enabling test mode', 'error');
            }
        }

        function downloadICS() {
            if (!currentEmail) {
                alert('Please select an email first');
                return;
            }
            window.open('test-output/emails/' + currentEmail.ical_file);
        }

        function viewRaw() {
            if (!currentEmail) {
                alert('Please select an email first');
                return;
            }
            window.open('test-output/emails/' + currentEmail.ical_file, '_blank');
        }

        function refreshEmails() {
            loadEmails();
            showStatus('Refreshed', 'success');
        }

        function showStatus(message, type = 'info') {
            const status = document.getElementById('status');
            status.textContent = message;
            status.style.color = type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : '#2196f3';
            setTimeout(() => {
                status.textContent = '';
            }, 3000);
        }

        // Auto-refresh every 5 seconds
        setInterval(loadEmails, 5000);

        // Initial load
        loadEmails();
    </script>
</body>
</html>