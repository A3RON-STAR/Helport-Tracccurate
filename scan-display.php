<?php
// ============================================================================
// ⚠️ API ENDPOINTS MUST BE AT THE VERY TOP (Before ANY HTML output)
// ============================================================================

// Database Configuration
$host = 'localhost';
$dbname = 'helport_attendance';
$username = 'root';
$password = '';

// Database Connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $pdo = null;
}

// === API: Get Latest Scan Timestamp (FINGERPRINT ONLY) ===
if (isset($_GET['action']) && $_GET['action'] === 'get_scan_timestamp') {
    header('Content-Type: application/json');
    $latestScanTime = 0;
    $latestScanId = 0;
    if ($pdo) {
        // ✅ FIXED: Only get fingerprint scans
        $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(check_in) as ts, id FROM attendance 
                              WHERE scan_method = 'fingerprint' 
                              ORDER BY check_in DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $latestScanTime = $result ? (int)$result['ts'] : 0;
        $latestScanId = $result ? (int)$result['id'] : 0;
    }
    echo json_encode([
        'timestamp' => $latestScanTime,
        'scan_id' => $latestScanId
    ]);
    exit;
}

// === API: Get Last Scan Details (FINGERPRINT ONLY) ===
if (isset($_GET['action']) && $_GET['action'] === 'get_last_scan') {
    header('Content-Type: application/json');
    if ($pdo) {
        // ✅ FIXED: Only get fingerprint scans with scan_method filter
        $stmt = $pdo->prepare("SELECT a.*, e.name, e.department, e.photo_path, e.employee_id, e.position
                              FROM attendance a
                              JOIN employees e ON a.employee_id = e.id
                              WHERE a.scan_method = 'fingerprint'
                              ORDER BY a.check_in DESC LIMIT 1");
        $stmt->execute();
        $scan = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($scan) {
            $timeDiff = time() - strtotime($scan['check_in']);
            // Only show if scan happened in last 15 seconds
            if ($timeDiff < 15) {
                $action = isset($scan['check_out']) && $scan['check_out'] !== null ? 'checked_out' : 'checked_in';
                echo json_encode([
                    'success' => true,
                    'scan_id' => (int)$scan['id'],
                    'name' => $scan['name'],
                    'department' => $scan['department'] ?? 'N/A',
                    'photo' => $scan['photo_path'] ?? '',
                    'employee_id' => $scan['employee_id'],
                    'position' => $scan['position'] ?? 'Employee',
                    'action' => $action,
                    'time' => date('g:i:s A', strtotime($scan['check_in'])),
                    'is_late' => $scan['is_late'] ?? 0,
                    'check_in_timestamp' => strtotime($scan['check_in'])
                ]);
                exit;
            }
        }
    }
    echo json_encode(['status' => 'no_recent_scan']);
    exit;
}

// === API: Get Stats (FINGERPRINT ONLY) ===
if (isset($_GET['action']) && $_GET['action'] === 'get_stats') {
    header('Content-Type: application/json');
    if ($pdo) {
        $today = date('Y-m-d');
        // ✅ FIXED: Only count fingerprint scans
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND scan_method = 'fingerprint'");
        $stmt->execute([$today]);
        $todayScans = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND is_late = 0 AND scan_method = 'fingerprint'");
        $stmt->execute([$today]);
        $presentCount = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND is_late = 1 AND scan_method = 'fingerprint'");
        $stmt->execute([$today]);
        $lateCount = $stmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'today_scans' => $todayScans,
            'present_count' => $presentCount,
            'late_count' => $lateCount
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// === HANDLE FINGERPRINT SCAN FROM NODEMCU ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['FingerID'])) {
    header('Content-Type: application/json');
    if (ob_get_level()) ob_end_clean();
    
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    $fingerID = intval($_POST['FingerID']);
    $timestamp = date('Y-m-d H:i:s');
    
    try {
        $stmt = $pdo->prepare("SELECT id, name, department, photo_path, position FROM employees WHERE finger_id = ? AND status = 'active'");
        $stmt->execute([$fingerID]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($emp) {
            $empId = $emp['id'];
            $today = date('Y-m-d');
            
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND DATE(check_in) = ?");
            $stmt->execute([$empId, $today]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                $isLate = (date('H') >= 9) ? 1 : 0;
                // ✅ FIXED: Explicitly set scan_method to 'fingerprint'
                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, finger_id, check_in, is_late, scan_method) VALUES (?, ?, ?, ?, 'fingerprint')");
                $stmt->execute([$empId, $fingerID, $timestamp, $isLate]);
                $action = 'checked_in';
            } elseif (!$existing['check_out']) {
                $stmt = $pdo->prepare("UPDATE attendance SET check_out = ?, scan_method = 'fingerprint' WHERE id = ?");
                $stmt->execute([$timestamp, $existing['id']]);
                $action = 'checked_out';
            } else {
                $action = 'already_completed';
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Scan recorded',
                'finger_id' => $fingerID,
                'employee_name' => $emp['name'],
                'action' => $action,
                'timestamp' => $timestamp
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fingerprint not registered']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// 📊 GET INITIAL DATA FOR PAGE LOAD
// ============================================================================

$latestScan = null;
$lastScanTimestamp = 0;
$lastScanId = 0;
$todayScans = 0;
$presentCount = 0;
$lateCount = 0;

if ($pdo) {
    // ✅ FIXED: Only get fingerprint scans
    $stmt = $pdo->prepare("SELECT a.*, e.name, e.employee_id, e.department, e.photo_path, e.position
                          FROM attendance a
                          JOIN employees e ON a.employee_id = e.id
                          WHERE a.scan_method = 'fingerprint'
                          ORDER BY a.check_in DESC
                          LIMIT 1");
    $stmt->execute();
    $latestScan = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastScanTimestamp = $latestScan ? strtotime($latestScan['check_in']) : 0;
    $lastScanId = $latestScan ? (int)$latestScan['id'] : 0;
    
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND scan_method = 'fingerprint'");
    $stmt->execute([$today]);
    $todayScans = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND is_late = 0 AND scan_method = 'fingerprint'");
    $stmt->execute([$today]);
    $presentCount = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND is_late = 1 AND scan_method = 'fingerprint'");
    $stmt->execute([$today]);
    $lateCount = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HELPORT - Fingerprint Kiosk</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-primary: #0a0f0d;
            --bg-secondary: #111827;
            --accent: #00d4aa;
            --accent-glow: rgba(0, 212, 170, 0.4);
            --text-primary: #f9fafb;
            --text-secondary: #9ca3af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a0f0d 0%, #1a1f2e 100%);
            color: var(--text-primary);
            min-height: 100vh;
            overflow: hidden;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(0, 212, 170, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(0, 212, 170, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
            animation: bgPulse 15s ease-in-out infinite;
        }
        
        @keyframes bgPulse {
            0%, 100% { opacity: 0.5; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.1); }
        }
        
        .kiosk-header {
            position: relative;
            z-index: 10;
            padding: 30px 50px;
            background: rgba(17, 24, 39, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 212, 170, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .kiosk-logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .kiosk-logo i {
            font-size: 32px;
            color: var(--accent);
        }
        
        .kiosk-logo h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent), #fff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .kiosk-stats {
            display: flex;
            gap: 30px;
        }
        
        .stat-box {
            background: rgba(0, 212, 170, 0.1);
            border: 1px solid rgba(0, 212, 170, 0.3);
            border-radius: 12px;
            padding: 15px 25px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--accent);
            font-family: 'Courier New', monospace;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 5px;
        }
        
        .kiosk-main {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 140px);
            padding: 40px;
        }
        
        .scanner-container {
            text-align: center;
            margin-bottom: 50px;
        }
        
        .scanner-title {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--accent), #fff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: titleGlow 2s ease-in-out infinite;
        }
        
        @keyframes titleGlow {
            0%, 100% { filter: brightness(1); }
            50% { filter: brightness(1.3); }
        }
        
        .scanner-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 50px;
        }
        
        .fingerprint-scanner {
            position: relative;
            width: 280px;
            height: 280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .scanner-base {
            position: absolute;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0, 212, 170, 0.15) 0%, transparent 70%);
            border: 3px solid rgba(0, 212, 170, 0.4);
            box-shadow: 
                0 0 60px rgba(0, 212, 170, 0.3),
                inset 0 0 60px rgba(0, 212, 170, 0.1);
            overflow: hidden;
        }
        
        .ripple-ring {
            position: absolute;
            border-radius: 50%;
            border: 3px solid var(--accent);
            opacity: 0;
            animation: ripple-expand 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        .ripple-ring:nth-child(1) {
            width: 80px;
            height: 80px;
            animation-delay: 0s;
        }
        
        .ripple-ring:nth-child(2) {
            width: 140px;
            height: 140px;
            animation-delay: 0.6s;
        }
        
        .ripple-ring:nth-child(3) {
            width: 200px;
            height: 200px;
            animation-delay: 1.2s;
        }
        
        .ripple-ring:nth-child(4) {
            width: 260px;
            height: 260px;
            animation-delay: 1.8s;
        }
        
        @keyframes ripple-expand {
            0% {
                transform: scale(0.5);
                opacity: 0.8;
                border-width: 4px;
            }
            100% {
                transform: scale(1.5);
                opacity: 0;
                border-width: 0px;
            }
        }
        
        .fingerprint-icon {
            position: relative;
            z-index: 10;
            font-size: 120px;
            color: var(--accent);
            animation: fingerprint-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
            filter: drop-shadow(0 0 40px rgba(0, 212, 170, 0.6));
        }
        
        @keyframes fingerprint-pulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
        }
        
        .scanner-text {
            position: absolute;
            bottom: -40px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 16px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 3px;
            white-space: nowrap;
        }
        
        .scan-popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(10, 15, 13, 0.95);
            backdrop-filter: blur(20px);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        
        .scan-popup-overlay.active {
            display: flex;
            opacity: 1;
        }
        
        .scan-popup {
            background: linear-gradient(145deg, rgba(17, 24, 39, 0.98), rgba(10, 15, 25, 0.98));
            border: 3px solid var(--accent);
            border-radius: 32px;
            padding: 60px 50px;
            text-align: center;
            box-shadow: 
                0 25px 100px rgba(0, 0, 0, 0.8),
                0 0 80px rgba(0, 212, 170, 0.4);
            transform: scale(0.8) translateY(30px);
            transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            max-width: 700px;
            width: 90%;
        }
        
        .scan-popup-overlay.active .scan-popup {
            transform: scale(1) translateY(0);
        }
        
        .popup-icon {
            width: 140px;
            height: 140px;
            margin: 0 auto 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 70px;
            background: linear-gradient(135deg, rgba(0, 212, 170, 0.2), rgba(0, 212, 170, 0.1));
            color: var(--accent);
            border: 4px solid var(--accent);
            box-shadow: 0 0 60px rgba(0, 212, 170, 0.5);
            animation: popIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        @keyframes popIn {
            0% { transform: scale(0) rotate(-45deg); opacity: 0; }
            50% { transform: scale(1.3) rotate(10deg); }
            100% { transform: scale(1) rotate(0deg); opacity: 1; }
        }
        
        .popup-title {
            font-size: 48px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--accent), #fff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .popup-subtitle {
            font-size: 20px;
            color: var(--text-secondary);
            margin-bottom: 40px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .popup-profile {
            background: rgba(17, 24, 39, 0.6);
            border: 2px solid rgba(0, 212, 170, 0.3);
            border-radius: 20px;
            padding: 30px;
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .popup-profile-pic {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--accent);
            box-shadow: 0 0 40px rgba(0, 212, 170, 0.4);
        }
        
        .popup-profile-details h3 {
            color: var(--text-primary);
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .popup-profile-details p {
            color: var(--text-secondary);
            font-size: 15px;
            margin-bottom: 6px;
        }
        
        .popup-role-tag {
            display: inline-block;
            font-size: 12px;
            padding: 6px 15px;
            background: linear-gradient(135deg, var(--accent), #00a884);
            color: #fff;
            border-radius: 8px;
            margin-top: 12px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        .popup-time {
            font-family: 'Courier New', monospace;
            font-size: 56px;
            font-weight: 800;
            color: var(--accent);
            text-shadow: 0 0 30px rgba(0, 212, 170, 0.6);
            margin-bottom: 10px;
        }
        
        .popup-status {
            font-size: 18px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .live-indicator {
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(0, 212, 170, 0.1);
            border: 1px solid rgba(0, 212, 170, 0.3);
            border-radius: 50px;
            padding: 12px 25px;
            z-index: 100;
        }
        
        .live-dot {
            width: 12px;
            height: 12px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse-live 1.5s infinite;
        }
        
        @keyframes pulse-live {
            0%, 100% { 
                transform: scale(1); 
                opacity: 1; 
                box-shadow: 0 0 0 0 rgba(0, 212, 170, 0.7); 
            }
            50% { 
                transform: scale(1.3); 
                opacity: 0.8; 
                box-shadow: 0 0 20px rgba(0, 212, 170, 0.5); 
            }
        }
        
        .live-text {
            font-size: 14px;
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <header class="kiosk-header">
        <div class="kiosk-logo">
            <i class="fas fa-fingerprint"></i>
            <h1>HELPORT</h1>
        </div>
        <div class="kiosk-stats">
            <div class="stat-box">
                <div class="stat-value" id="todayScans"><?= $todayScans ?></div>
                <div class="stat-label">Today's Scans</div>
            </div>
            <div class="stat-box">
                <div class="stat-value" style="color: var(--success);"><?= $presentCount ?></div>
                <div class="stat-label">On Time</div>
            </div>
            <div class="stat-box">
                <div class="stat-value" style="color: var(--warning);"><?= $lateCount ?></div>
                <div class="stat-label">Late</div>
            </div>
        </div>
    </header>
    
    <main class="kiosk-main">
        <div class="scanner-container">
            <h2 class="scanner-title">Place Your Finger on Scanner</h2>
            <p class="scanner-subtitle">Fingerprint attendance system - Check in/out automatically</p>
            
            <div class="fingerprint-scanner">
                <div class="scanner-base">
                    <div class="ripple-ring"></div>
                    <div class="ripple-ring"></div>
                    <div class="ripple-ring"></div>
                    <div class="ripple-ring"></div>
                </div>
                <i class="fas fa-fingerprint fingerprint-icon"></i>
                <div class="scanner-text">Scanning...</div>
            </div>
        </div>
    </main>
    
    <div class="live-indicator">
        <div class="live-dot"></div>
        <span class="live-text">Live System</span>
    </div>
    
    <div class="scan-popup-overlay" id="scanPopup">
        <div class="scan-popup">
            <div class="popup-icon" id="popupIcon">
                <i class="fas fa-check"></i>
            </div>
            <h2 class="popup-title" id="popupTitle">ACCESS GRANTED</h2>
            <p class="popup-subtitle" id="popupAction">Check-In Successful</p>
            
            <div class="popup-profile">
                <img src="" alt="Employee" class="popup-profile-pic" id="popupPhoto">
                <div class="popup-profile-details">
                    <h3 id="popupName">Employee Name</h3>
                    <p id="popupDept">Department</p>
                    <p id="popupId" style="font-size: 13px; opacity: 0.7;">ID: 0000</p>
                    <span class="popup-role-tag" id="popupRole">Employee</span>
                </div>
            </div>
            
            <div class="popup-time" id="popupTime">00:00:00</div>
            <div class="popup-status" id="popupStatus">On Time</div>
        </div>
    </div>
    
    <script>
        // ✅ FIXED: Track both timestamp AND scan ID to prevent duplicates
        let lastScanTimestamp = <?= $lastScanTimestamp ?>;
        let lastScanId = <?= $lastScanId ?>;
        let isPopupShowing = false;
        
        function pollForScans() {
            // Don't poll if popup is already showing
            if (isPopupShowing) return;
            
            fetch('scan-display.php?action=get_scan_timestamp&t=' + Date.now())
                .then(response => response.json())
                .then(timestampData => {
                    // ✅ FIXED: Check BOTH timestamp AND scan ID
                    if (timestampData.scan_id && timestampData.scan_id > lastScanId) {
                        // New scan detected! Get the details
                        lastScanId = timestampData.scan_id;
                        lastScanTimestamp = timestampData.timestamp;
                        
                        // Fetch full scan details
                        return fetch('scan-display.php?action=get_last_scan&t=' + Date.now());
                    }
                    return null;
                })
                .then(response => {
                    if (response) {
                        return response.json();
                    }
                    return null;
                })
                .then(data => {
                    if (data && data.success) {
                        // ✅ FIXED: Use scan's check_in_timestamp, not current time
                        if (data.check_in_timestamp > lastScanTimestamp) {
                            lastScanTimestamp = data.check_in_timestamp;
                            showScanPopup(data);
                            updateStats();
                        }
                    }
                })
                .catch(error => console.log('Polling error:', error));
        }
        
        function showScanPopup(scan) {
            isPopupShowing = true;
            
            const popup = document.getElementById('scanPopup');
            const icon = document.getElementById('popupIcon');
            const title = document.getElementById('popupTitle');
            const action = document.getElementById('popupAction');
            
            document.getElementById('popupName').textContent = scan.name;
            document.getElementById('popupDept').textContent = scan.department || 'N/A';
            document.getElementById('popupId').textContent = 'ID: ' + scan.employee_id;
            document.getElementById('popupRole').textContent = scan.position || 'Employee';
            
            const photoEl = document.getElementById('popupPhoto');
            if (scan.photo && scan.photo !== '') {
                photoEl.src = scan.photo;
            } else {
                photoEl.src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(scan.name) + '&background=00d4aa&color=fff&size=200';
            }
            
            document.getElementById('popupTime').textContent = scan.time;
            
            const isCheckOut = scan.action === 'checked_out';
            if (isCheckOut) {
                icon.innerHTML = '<i class="fas fa-sign-out-alt"></i>';
                icon.style.color = 'var(--success)';
                icon.style.borderColor = 'var(--success)';
                title.textContent = 'CHECK-OUT SUCCESSFUL';
                action.textContent = 'Have a great day!';
                document.getElementById('popupStatus').textContent = 'Checked Out';
                document.getElementById('popupStatus').style.color = 'var(--success)';
            } else {
                icon.innerHTML = '<i class="fas fa-check"></i>';
                icon.style.color = 'var(--accent)';
                icon.style.borderColor = 'var(--accent)';
                title.textContent = 'ACCESS GRANTED';
                action.textContent = scan.is_late == 1 ? 'Late Check-In' : 'Check-In Successful';
                document.getElementById('popupStatus').textContent = scan.is_late == 1 ? 'Late' : 'On Time';
                document.getElementById('popupStatus').style.color = scan.is_late == 1 ? 'var(--warning)' : 'var(--success)';
            }
            
            popup.classList.add('active');
            
            // Hide popup after 5 seconds
            setTimeout(() => {
                popup.classList.remove('active');
                isPopupShowing = false;
            }, 5000);
        }
        
        function updateStats() {
            fetch('scan-display.php?action=get_stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('todayScans').textContent = data.today_scans || 0;
                    }
                })
                .catch(error => console.log('Stats error:', error));
        }
        
        // Poll every 1 second
        setInterval(pollForScans, 1000);
        
        // Initial poll
        pollForScans();
    </script>
</body>
</html>