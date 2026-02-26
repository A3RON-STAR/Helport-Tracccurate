<?php
// === SET PHILIPPINE TIMEZONE GLOBALLY ===
date_default_timezone_set('Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 0);
// ... rest of your existing code
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
ini_set('display_startup_errors', 0);
// Start session
session_start();
// === CONFIG: Office start time for late detection ===
$office_start_time = '08:00:00';
$grace_period_minutes = 0; // FORCE ZERO GRACE PERIOD GLOBALLY

// === SHIFT START TIME CONFIGURATION ===
// Map shift names to specific start times
$shift_start_times = [
    'morning shift' => '08:00:00',
    'day shift'     => '08:00:00',
    'early shift'   => '07:00:00',
    'mid shift'     => '09:00:00',
    'afternoon shift' => '14:00:00',
    'night shift'   => '22:00:00',
    'flexible'      => '00:00:00',
    'off'           => null
];
// === DATABASE CONNECTION ===
$host = 'localhost';
$dbname = 'helport_attendance';
$username = 'root';
$password = '';
$pdo = null;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // === FORCE MYSQL SESSION TO PHILIPPINE TIME ===
    $pdo->exec("SET time_zone = '+08:00'");
    
} catch (PDOException $e) {
    $db_error = "Database connection failed: " . $e->getMessage();
}
// === HELPER FUNCTIONS ===
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}
function calculatePayrollHours($checkIn, $checkOut) {
    $in = new DateTime($checkIn);
    $out = new DateTime($checkOut);
    $interval = $in->diff($out);
    $totalHours = $interval->h + ($interval->i / 60) + ($interval->s / 3600);
    
    // Only count overtime for hours beyond 8 hours
    $regular = min(8, $totalHours);
    $overtime = max(0, $totalHours - 8);
    
    // Night Diff Calculation (10 PM to 6 AM)
    $nightStart = clone $in;
    $nightStart->setTime(22, 0, 0);
    $nightEnd = clone $in;
    $nightEnd->setTime(6, 0, 0);
    if ($nightEnd < $in) $nightEnd->modify('+1 day');
    
    $nightDiff = 0;
    if ($in < $nightEnd && $out > $nightStart) {
        $nightIn = max($in, $nightStart);
        $nightOut = min($out, $nightEnd);
        if ($nightOut > $nightIn) {
            $nightInterval = $nightIn->diff($nightOut);
            $nightDiff = $nightInterval->h + ($nightInterval->i / 60) + ($nightInterval->s / 3600);
        }
    }
    
    return [
        'regular' => round($regular, 2),
        'overtime' => round($overtime, 2),
        'night_diff' => round($nightDiff, 2)
    ];
}

function isLateCheckIn($checkInTime, $officeStartTime = '08:00:00', $gracePeriodMinutes = 5) {
    $tz = new DateTimeZone('Asia/Manila');
    $checkIn = new DateTime($checkInTime, $tz);
    $officeStart = new DateTime(date('Y-m-d') . ' ' . $officeStartTime, $tz);
    $officeStart->setTime(...explode(':', $officeStartTime));
    $gracePeriod = new DateInterval("PT{$gracePeriodMinutes}M");
    $officeStart->add($gracePeriod);
    return $checkIn > $officeStart;
}
function logAudit($pdo, $action, $user = null, $tableName = null, $recordId = null, $beforeValue = null, $afterValue = null) {
    if (!$pdo) return;
    if (!$user && isset($_SESSION['employee_id'])) {
        $user = $_SESSION['employee_id'];
    } elseif (!$user) {
        $user = 'System';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $pdo->prepare("INSERT INTO audit_trail (action, user, table_name, record_id, before_value, after_value, ip_address, timestamp)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$action, $user, $tableName, $recordId,
        $beforeValue ? json_encode($beforeValue) : null,
        $afterValue ? json_encode($afterValue) : null,
        $ip]);
}
function createNotification($pdo, $userId, $title, $message, $link = null, $type = 'info') {
    if (!$pdo) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $title, $message, $link, $type]);
    } catch (Exception $e) {}
}
function initializeStreak($pdo, $userId) {
    if (!$pdo) return;
    $stmt = $pdo->prepare("SELECT id FROM attendance_streaks WHERE employee_id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO attendance_streaks (employee_id, current_streak, longest_streak) VALUES (?, 0, 0)")
            ->execute([$userId]);
    }
}
// === GET EMPLOYEE BY ID (for live updates) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_employee_by_id' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    ob_end_clean(); // Clear any buffered output
    try {
        $employeeId = (int)($_GET['id'] ?? 0);
        if ($employeeId <= 0) throw new Exception('Invalid employee ID');
        
        $stmt = $pdo->prepare("SELECT id, name, employee_id, position, department, role, birthday, finger_id, photo_path FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee) {
            echo json_encode(['success' => true, 'employee' => $employee]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Employee not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit; // CRITICAL: Stop execution here
}
// === GET ATTENDANCE SUMMARY ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_attendance_summary' && isLoggedIn()) {
    header('Content-Type: application/json');
    
    // Get date from request, default to today
    $date = $_GET['date'] ?? date('Y-m-d');
    
    try {
        // 1. Present: Anyone who has checked in today (regardless of check-out status)
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.employee_id) FROM attendance a
            JOIN employees e ON a.employee_id = e.id
            WHERE DATE(a.check_in) = ?");
        $stmt->execute([$date]);
        $present = (int)$stmt->fetchColumn();
        
        // 2. Late: Anyone who checked in after 08:00 AM today
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.employee_id) FROM attendance a
            JOIN employees e ON a.employee_id = e.id
            WHERE DATE(a.check_in) = ? AND a.is_late = 1");
        $stmt->execute([$date]);
        $late = (int)$stmt->fetchColumn();
        
        // 3. Absent: Active employees who have NO attendance record today
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees e
            WHERE e.status = 'active'
            AND e.id NOT IN (SELECT employee_id FROM attendance WHERE DATE(check_in) = ?)");
        $stmt->execute([$date]);
        $absent = (int)$stmt->fetchColumn();
        
        // 4. Total: Sum of the above
        $total = $present + $absent;
        
        echo json_encode([
            'success' => true,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'total' => $total,
            'debug_date' => $date
        ]);
    } catch (Exception $e) {
        error_log("Attendance Summary Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0
        ]);
    }
    exit;
}
// === GET EMPLOYEE SHIFT START TIME ===
function getEmployeeShiftStartTime($pdo, $employeeId, $date) {
    $dayName = strtolower(date('l', strtotime($date))); // e.g., 'monday'
    try {
        $stmt = $pdo->prepare("SELECT $dayName FROM employee_schedules WHERE employee_id = ? AND is_active = 1");
        $stmt->execute([$employeeId]);
        $shiftName = $stmt->fetchColumn();
        
        if (!$shiftName) return '08:00:00'; // Default fallback

        // Map shift name to time (Case Insensitive)
     $shiftMap = [
    'morning shift' => '08:00:00',
    'day shift'     => '08:00:00',
    'early shift'   => '07:00:00',
    'mid shift'     => '09:00:00',
    'afternoon shift' => '14:00:00',
    'night shift'   => '22:00:00',
    'flexible'      => '00:00:00',
    'off'           => null
];
        
        return $shiftMap[strtolower($shiftName)] ?? '08:00:00';
    } catch (Exception $e) {
        return '08:00:00';
    }
}
// === GET ALL DEPARTMENTS WITH DETAILS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_all_departments' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
     
        
        // Get departments with employee counts and attendance
        $stmt = $pdo->query("
            SELECT 
                d.id,
                d.name,
                d.head,
                d.description,
                d.status,
                d.created_at,
                COUNT(DISTINCT e.id) as total_employees,
COUNT(DISTINCT CASE WHEN DATE(a.check_in) = CURDATE() THEN e.id END) as present_today,
                ROUND((COUNT(DISTINCT CASE WHEN DATE(a.check_in) BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE() THEN a.id END) / GREATEST(COUNT(DISTINCT e.id) * 30, 1)) * 100, 2) as attendance_rate
            FROM departments d
            LEFT JOIN employees e ON e.department = d.name AND e.status = 'active'
            LEFT JOIN attendance a ON e.id = a.employee_id
            GROUP BY d.id
            ORDER BY d.name ASC
        ");
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get employees for each department (for avatar display)
        foreach ($departments as &$dept) {
            $empStmt = $pdo->prepare("
                SELECT id, name, position, photo_path
                FROM employees
                WHERE department = ? AND status = 'active'
                LIMIT 5
            ");
            $empStmt->execute([$dept['name']]);
            $dept['employees'] = $empStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get today's attendance status for each employee
            $todayStmt = $pdo->prepare("
                SELECT e.id, e.name,
                       CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NULL THEN 'present'
                            WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL THEN 'present'
                            WHEN a.is_late = 1 THEN 'late'
                            ELSE 'absent' END as status
                FROM employees e
                LEFT JOIN attendance a ON e.id = a.employee_id AND DATE(a.check_in) = CURDATE()
                WHERE e.department = ? AND e.status = 'active'
            ");
            $todayStmt->execute([$dept['name']]);
            $dept['today_status'] = $todayStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Calculate totals
        $totalDepts = count($departments);
        $totalEmployees = array_sum(array_column($departments, 'total_employees'));
        $totalPresent = array_sum(array_column($departments, 'present_today'));
        $avgAttendance = $totalDepts > 0 ? round(array_sum(array_column($departments, 'attendance_rate')) / $totalDepts) : 0;
        
        echo json_encode([
            'success' => true,
            'departments' => $departments,
            'stats' => [
                'total_departments' => $totalDepts,
                'total_employees' => $totalEmployees,
                'total_present' => $totalPresent,
                'avg_attendance' => $avgAttendance
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'error_code' => $e->getCode()
        ]);
    }
    exit;
}

// === ADD/UPDATE DEPARTMENT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_department' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }
        
        $id = $_POST['id'] ?? null;
        $name = trim($_POST['name']);
        $head = trim($_POST['head'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            throw new Exception('Department name is required');
        }
        
        // Check for duplicates (if adding new)
        if (!$id) {
            $check = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                throw new Exception('Department name already exists');
            }
            
            // Insert new department
            $stmt = $pdo->prepare("INSERT INTO departments (name, head, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $head, $description, $status]);
            $deptId = $pdo->lastInsertId();
            
            logAudit($pdo, "Added new department: {$name}", $_SESSION['employee_id'], 'departments', $deptId);
            echo json_encode(['success' => true, 'message' => 'Department added successfully', 'id' => $deptId]);
        } else {
            // Update existing department
            $stmt = $pdo->prepare("UPDATE departments SET name = ?, head = ?, description = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $head, $description, $status, $id]);
            
            logAudit($pdo, "Updated department: {$name}", $_SESSION['employee_id'], 'departments', $id);
            echo json_encode(['success' => true, 'message' => 'Department updated successfully']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === DELETE DEPARTMENT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_department' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    ob_end_clean(); // Clear any buffered output
    try {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }
        $id = (int)$_POST['id'];
        $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $deptStmt->execute([$id]);
        $dept = $deptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dept) {
            throw new Exception('Department not found');
        }
        $check = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE department = ?");
        $check->execute([$dept['name']]);
        $employeeCount = $check->fetchColumn();
        if ($employeeCount > 0) {
            throw new Exception("Cannot delete department with {$employeeCount} employees. Reassign or remove employees first.");
        }
        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($pdo, "Deleted department: {$dept['name']}", $_SESSION['employee_id'], 'departments', $id);
        echo json_encode(['success' => true, 'message' => 'Department deleted successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit; // CRITICAL: Stop execution here
}

// === EXPORT DEPARTMENT EMPLOYEES (CSV) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'export_department_employees' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    $departmentId = (int)$_GET['department_id'];
    
    // Get Department Name
    $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
    $deptStmt->execute([$departmentId]);
    $deptName = $deptStmt->fetchColumn();
    
    // Get Employees with LIVE attendance data
    $empStmt = $pdo->prepare("
        SELECT e.name, e.employee_id, e.position, e.department,
        CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NULL THEN 'Present (Active)'
             WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL THEN 'Present (Completed)'
             WHEN a.is_late = 1 THEN 'Late'
             ELSE 'Absent' END as status,
        TIME(a.check_in) as check_in,
        TIME(a.check_out) as check_out
        FROM employees e
        LEFT JOIN attendance a ON e.id = a.employee_id AND DATE(a.check_in) = CURDATE()
        WHERE e.department = (SELECT name FROM departments WHERE id = ?) AND e.status = 'active'
        ORDER BY e.name ASC
    ");
    $empStmt->execute([$departmentId]);
    $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Send CSV Headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Department_Export_' . preg_replace('/[^A-Za-z0-9]/', '_', $deptName) . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Employee Name', 'Employee ID', 'Position', 'Department', 'Today\'s Status', 'Check In', 'Check Out']);
    
    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['name'],
            $emp['employee_id'],
            $emp['position'],
            $emp['department'],
            $emp['status'],
            $emp['check_in'] ?? '-',
            $emp['check_out'] ?? '-'
        ]);
    }
    fclose($output);
    exit;
}

// === GET DEPARTMENT EMPLOYEES (JSON for Modal) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_department_employees' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        $departmentId = (int)$_GET['department_id'];
        
        // Get department info
        $deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
        $deptStmt->execute([$departmentId]);
        $department = $deptStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$department) {
            throw new Exception('Department not found');
        }
        
        // Get all employees in department with LIVE status
        $empStmt = $pdo->prepare("
            SELECT e.*,
            CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NULL THEN 'present'
                 WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL THEN 'present'
                 WHEN a.is_late = 1 THEN 'late'
                 ELSE 'absent' END as today_status
            FROM employees e
            LEFT JOIN attendance a ON e.id = a.employee_id AND DATE(a.check_in) = CURDATE()
            WHERE e.department = ? AND e.status = 'active'
            ORDER BY e.name ASC
        ");
        $empStmt->execute([$department['name']]);
        $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'department' => $department,
            'employees' => $employees,
            'count' => count($employees)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// === GET ATTENDANCE HISTORY ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_attendance_history' && isLoggedIn()) {
    header('Content-Type: application/json');
    
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, e.name, e.employee_id, e.department, e.photo_path
            FROM attendance a
            JOIN employees e ON a.employee_id = e.id
            WHERE DATE(a.check_in) BETWEEN ? AND ?
            ORDER BY a.check_in DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'records' => $records
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
function updateAttendanceStreak($pdo, $userId, $isPresent) {
    if (!$pdo) return;
    initializeStreak($pdo, $userId);
    if ($isPresent) {
        $stmt = $pdo->prepare("UPDATE attendance_streaks SET current_streak = current_streak + 1,
        longest_streak = GREATEST(longest_streak, current_streak + 1),
        updated_at = NOW() WHERE employee_id = ?");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("UPDATE attendance_streaks SET current_streak = 0, updated_at = NOW() WHERE employee_id = ?");
        $stmt->execute([$userId]);
    }
}
function getAttendanceStreak($pdo, $userId) {
    if (!$pdo) return ['current_streak' => 0, 'longest_streak' => 0];
    initializeStreak($pdo, $userId);
    $stmt = $pdo->prepare("SELECT current_streak, longest_streak FROM attendance_streaks WHERE employee_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function getUserInfo($pdo, $userId) {
    if (!$pdo) return null;
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function getTodayAttendance($pdo, $userId, $officeStartTime = '08:00:00', $gracePeriodMinutes = 5) {
    if (!$pdo) return null;
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT *, TIME(check_in) as check_in_time FROM attendance WHERE employee_id = ? AND DATE(check_in) = ?");
    $stmt->execute([$userId, $today]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record && !$record['is_late']) {
        $isLate = isLateCheckIn($record['check_in'], $officeStartTime, $gracePeriodMinutes) ? 1 : 0;
        if ($isLate) {
            $update = $pdo->prepare("UPDATE attendance SET is_late = 1 WHERE id = ?");
            $update->execute([$record['id']]);
            $record['is_late'] = 1;
        }
    }
    return $record;
}
function wasSystemDown($pdo, $checkInTime) {
    if (!$pdo) return false;
    $stmt = $pdo->prepare("SELECT id FROM system_health
    WHERE status = 'offline'
    AND downtime_start <= ?
    AND (downtime_end IS NULL OR downtime_end >= ?)");
    $stmt->execute([$checkInTime, $checkInTime]);
    return $stmt->fetch() !== false;
}

// === HANDLE FINGERPRINT REQUESTS (NodeMCU API) ===
// This endpoint must be accessible by the hardware (no login required)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['FingerID'])) {
    // Clear any previous output to ensure clean JSON
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    $fingerID = intval($_POST['FingerID']);
    $deviceID = $_POST['DeviceID'] ?? 'scanner_01';
    $timestamp = date('Y-m-d H:i:s');
    // Already uses Philippine time due to date_default_timezone_set, 
// but for extra safety with DateTime:
$timestampObj = new DateTime('now', new DateTimeZone('Asia/Manila'));
$timestamp = $timestampObj->format('Y-m-d H:i:s');
    $empId = null;
    $employeeName = 'Unknown';
    $department = 'Unknown';
    $photoPath = '';
    $action = '';
    $checkInTime = null;
    $checkOutTime = null;
    $isLate = 0;

    try {
        // 1. Find Employee by Fingerprint ID
        $stmt = $pdo->prepare("SELECT id, name, department, photo_path FROM employees WHERE finger_id = ? AND status = 'active'");
        $stmt->execute([$fingerID]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($emp) {
            $empId = $emp['id'];
            $employeeName = $emp['name'];
            $department = $emp['department'] ?? 'Unknown';
            $photoPath = $emp['photo_path'] ?? '';
            
            $today = date('Y-m-d');
            
            // 2. Check Existing Attendance for Today
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND DATE(check_in) = ?");
            $stmt->execute([$empId, $today]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {

               // === CHECK IN ===
$checkIn = new DateTime($timestamp);
$checkInTimeStr = formatTime12Hour($checkIn->format('H:i:s'))
;

// 1. Get Employee's Specific Shift Start Time for Today
$requiredStartTime = getEmployeeShiftStartTime($pdo, $empId, $today);

// 2. Calculate Lateness (NO GRACE PERIOD)
$isLate = 0;
if ($requiredStartTime && $requiredStartTime !== '00:00:00') {
    // Strict comparison: If Check In > Shift Start, they are Late
    if ($checkInTimeStr > $requiredStartTime) {
        $isLate = 1;
    }
}

$stmt = $pdo->prepare("INSERT INTO attendance (employee_id, finger_id, check_in, is_late, scan_method) VALUES (?, ?, ?, ?, 'fingerprint')");
$stmt->execute([$empId, $fingerID, $timestamp, $isLate]);
                
                $action = 'checked_in';
                $checkInTime = $timestamp;
                
                // Update Streak
                updateAttendanceStreak($pdo, $empId, true);

} elseif (!$existing['check_out']) {
    // === CHECK OUT ===
    $stmt = $pdo->prepare("UPDATE attendance SET check_out = ?, scan_method = 'fingerprint' WHERE id = ?");
    $stmt->execute([$timestamp, $existing['id']]);
    
    // ... hour calculations ...
    
    $updateStmt = $pdo->prepare("UPDATE attendance SET overtime_hours = ?, night_diff_hours = ? WHERE id = ?");
    $updateStmt->execute([$overtimeHours, $nightDiffHours ?? 0, $existing['id']]);
    
    // Auto-create overtime request
    if ($overtimeHours > 1.0) {
        $checkOtStmt = $pdo->prepare("SELECT id FROM overtime_requests WHERE attendance_id = ?");
        $checkOtStmt->execute([$existing['id']]);
        if (!$checkOtStmt->fetch()) {
            $otStmt = $pdo->prepare("INSERT INTO overtime_requests (employee_id, attendance_id, overtime_hours, overtime_type, status, created_at) VALUES (?, ?, ?, 'Regular', 'Pending', NOW())");
            $otStmt->execute([$empId, $existing['id'], $overtimeHours]);
            createNotification($pdo, $empId, 'Overtime Detected',
                "You worked {$overtimeHours} hours of overtime today. Auto-submitted for admin approval.",
                '/?view=overtime', 'info');
        }
    }
    
    $action = 'checked_out';
    $checkOutTime = $timestamp;
    
} else {
    $action = 'already_completed';
}

// Send response to hardware...
        } else {
            $action = 'unknown_fingerprint';
        }

        // 3. Send Response to Hardware
        echo json_encode([
            'success' => true,
            'message' => 'Scan recorded',
            'finger_id' => $fingerID,
            'employee_name' => $employeeName,
            'action' => $action,
            'timestamp' => $timestamp,
            'is_late' => $isLate
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit; // CRITICAL: Stop script here so no HTML is sent
}
// === 1. GET LATEST SCAN TIMESTAMP (DATABASE ONLY) ===
// This allows the Browser to see what the NodeMCU just sent
if (isset($_GET['action']) && $_GET['action'] === 'get_scan_timestamp') {
    header('Content-Type: application/json');
    $latestScanTime = 0;
    if ($pdo) {
        // Query the attendance table directly for the absolute latest check_in
        $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(check_in) as ts FROM attendance ORDER BY check_in DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $latestScanTime = $result ? (int)$result['ts'] : 0;
    }
    echo json_encode(['timestamp' => $latestScanTime]);
    exit;
}


// Fetches the actual employee info for the popup
if (isset($_GET['action']) && $_GET['action'] === 'get_last_scan') {
    header('Content-Type: application/json');
    if ($pdo) {
        // Join attendance and employees to get photo, name, etc. of the LATEST scan
        $stmt = $pdo->prepare("SELECT a.*, e.name, e.department, e.photo_path, e.employee_id 
                               FROM attendance a 
                               JOIN employees e ON a.employee_id = e.id 
                               ORDER BY a.check_in DESC LIMIT 1");
        $stmt->execute();
        $scan = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($scan) {
            // Check if this scan happened in the last 15 seconds
            $timeDiff = time() - strtotime($scan['check_in']);
            if ($timeDiff < 15) {
                $action = isset($scan['check_out']) && $scan['check_out'] !== null ? 'checked_out' : 'checked_in';
                echo json_encode([
                    'success' => true,
                    'name' => $scan['name'],
                    'department' => $scan['department'],
                    'photo' => $scan['photo_path'],
                    'employee_id' => $scan['employee_id'],
                    'action' => $action,
                    'time' => date('g:i:s A', strtotime($scan['check_in']))
                ]);
                exit;
            }
        }
    }
    echo json_encode(['status' => 'no_recent_scan']);
    exit;
}


// === GET ATTENDANCE TREND ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_attendance_trend' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean();
    
    $labels = [];
    $presentData = [];
    $absentData = [];
    
    // Loop last 7 days to ensure chart always has 7 points
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $dayName = date('D', strtotime("-{$i} days")); // Mon, Tue, etc.
        
        $labels[] = $dayName;
        
        // Count Present (checked in and out)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND check_out IS NOT NULL");
        $stmt->execute([$date]);
        $presentData[] = (int)$stmt->fetchColumn();
        
        // Count Absent (active employees who didn't check in)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE e.status = 'active' AND e.id NOT IN (SELECT employee_id FROM attendance WHERE DATE(check_in) = ?)");
        $stmt->execute([$date]);
        $absentData[] = (int)$stmt->fetchColumn();
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'present' => $presentData,
        'absent' => $absentData
    ]);
    exit;
}
// === GET WEEKLY BREAKDOWN ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_weekly_breakdown' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean();
    
    $data = [];
    // Loop last 7 days
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        // Count present
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(check_in) = ? AND check_out IS NOT NULL");
        $stmt->execute([$date]);
        $present = $stmt->fetchColumn();
        
        // Count total active employees
        $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'");
        $total = $stmt->fetchColumn();
        
        // Calculate %
        $rate = $total > 0 ? round(($present / $total) * 100) : 0;
        $data[] = $rate;
    }
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// === GET DAY OFF HISTORY (LAST 30 DAYS) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_day_off_history' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        // Fetch day off requests created within the last 30 days
        $stmt = $pdo->prepare("SELECT d.*, e.name, e.employee_id, e.department, e.photo_path
            FROM day_off_requests d
            JOIN employees e ON d.employee_id = e.id
            WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY d.created_at DESC");
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === PURGE OLD DAY OFF RECORDS (OLDER THAN 30 DAYS) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'purge_old_day_off_records' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        // Delete day off records older than 30 days
        $stmt = $pdo->prepare("DELETE FROM day_off_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute();
        $count = $stmt->rowCount();
        
        logAudit($pdo, "Purged $count old day off records", $_SESSION['employee_id']);
        
        echo json_encode(['success' => true, 'message' => "$count old day off records cleared successfully."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === GET DEPARTMENT STATS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_department_stats' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean();
    
    try {
        $stmt = $pdo->query("
            SELECT 
                e.department,
                COUNT(DISTINCT e.id) as total,
                COUNT(DISTINCT CASE WHEN DATE(a.check_in) = CURDATE() AND a.check_out IS NOT NULL THEN e.id END) as present
            FROM employees e
            LEFT JOIN attendance a ON e.id = a.employee_id
            WHERE e.status = 'active' AND e.department IS NOT NULL
            GROUP BY e.department
        ");
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $labels = [];
        $values = [];
        foreach ($departments as $dept) {
            $labels[] = $dept['department'];
            $values[] = $dept['total'];
        }
        
        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'values' => $values,
            'totals' => array_column($departments, 'total'),
            'present' => array_column($departments, 'present')
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === GET TOP PERFORMERS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_top_performers' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean();
    
    try {
        $stmt = $pdo->query("
            SELECT e.name, e.department, e.employee_id,
            COUNT(DISTINCT DATE(a.check_in)) as days_present,
            ROUND((COUNT(DISTINCT DATE(a.check_in)) / 22) * 100) as attendance_rate
            FROM employees e
            LEFT JOIN attendance a ON e.id = a.employee_id
            WHERE e.status = 'active'
            GROUP BY e.id
            ORDER BY attendance_rate DESC
            LIMIT 5
        ");
        $performers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // FIX: Send as 'data' to match JavaScript expectation
        echo json_encode(['success' => true, 'data' => $performers]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === GET DETAILED METRICS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_detailed_metrics' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean();
    
    try {
        $stmt = $pdo->query("
            SELECT 
                e.name, 
                e.department, 
                e.employee_id,
                COUNT(DISTINCT DATE(a.check_in)) as days_present,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, a.check_in, a.check_out)), 1) as avg_hours,
                SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_arrivals,
                e.status
            FROM employees e
            LEFT JOIN attendance a ON e.id = a.employee_id
            WHERE e.status = 'active'
            GROUP BY e.id
            ORDER BY days_present DESC
        ");
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $metrics]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === GET ANALYTICS STATS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_analytics_stats' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean(); // <--- SAFE CLEANING
    
    $range = $_GET['range'] ?? 'month';
    $today = date('Y-m-d');
    
// === GET WEEKLY BREAKDOWN ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_weekly_breakdown' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean();
    
    $data = [];
    // Loop last 7 days
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        // Count present
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(check_in) = ? AND check_out IS NOT NULL");
        $stmt->execute([$date]);
        $present = $stmt->fetchColumn();
        
        // Count total active employees
        $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'");
        $total = $stmt->fetchColumn();
        
        // Calculate %
        $rate = $total > 0 ? round(($present / $total) * 100) : 0;
        $data[] = $rate;
    }
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}
    // Calculate date range
    switch($range) {
        case 'today':
            $startDate = $today;
            $endDate = $today;
            break;
        case 'week':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate = $today;
            break;
        case 'month':
            $startDate = date('Y-m-01');
            $endDate = $today;
            break;
        case 'year':
            $startDate = date('Y-01-01');
            $endDate = $today;
            break;
        default:
            $startDate = date('Y-m-01');
            $endDate = $today;
    }
    
try {
        // 1. Active Now (Checked in but not out)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND check_out IS NULL");
        $stmt->execute([$today]);
        $activeNow = (int)$stmt->fetchColumn();

        // 2. Attendance Rate
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(check_in) BETWEEN ? AND ? AND check_out IS NOT NULL");
        $stmt->execute([$startDate, $endDate]);
        $presentCount = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'");
        $totalEmployees = (int)$stmt->fetchColumn();
        $attendanceRate = $totalEmployees > 0 ? round(($presentCount / $totalEmployees) * 100) : 0;

        // 3. Avg Hours
        $stmt = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(HOUR, check_in, check_out)) FROM attendance WHERE DATE(check_in) BETWEEN ? AND ? AND check_out IS NOT NULL");
        $stmt->execute([$startDate, $endDate]);
        $avgHours = round($stmt->fetchColumn() ?? 0, 1);

        // 4. Late Count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND is_late = 1");
        $stmt->execute([$today]);
        $lateCount = (int)$stmt->fetchColumn();
        $latePercentage = $presentCount > 0 ? round(($lateCount / $presentCount) * 100) : 0;

        // 5. Patterns (Early, On Time, Overtime)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND TIME(check_in) < '08:00:00'");
        $stmt->execute([$today]);
        $earlyBirds = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND TIME(check_in) BETWEEN '08:00:00' AND '09:00:00' AND is_late = 0");
        $stmt->execute([$today]);
        $onTime = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) BETWEEN ? AND ? AND TIMESTAMPDIFF(HOUR, check_in, check_out) > 8");
        $stmt->execute([$startDate, $endDate]);
        $overtimeWorkers = (int)$stmt->fetchColumn();

        // SEND JSON
        echo json_encode([
            'success' => true,
            'active_now' => $activeNow,
            'attendance_rate' => $attendanceRate,
            'avg_hours' => $avgHours,
            'late_count' => $lateCount,
            'late_percentage' => $latePercentage,
            'early_birds' => $earlyBirds,
            'on_time' => $onTime,
            'overtime_workers' => $overtimeWorkers
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


// === CREATE DATABASE TABLES ===

// === CREATE OVERTIME REQUESTS TABLE ===
$pdo->exec("CREATE TABLE IF NOT EXISTS overtime_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_id INT,
    overtime_hours DECIMAL(5,2) NOT NULL,
    overtime_type VARCHAR(50) DEFAULT 'Regular',
    status VARCHAR(20) DEFAULT 'Pending',
    admin_response TEXT,
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee (employee_id),
    INDEX idx_attendance (attendance_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Add Settings Table for Manual Check-in Toggle
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Insert default value if not exists
$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('allow_manual_checkin', '0') ON DUPLICATE KEY UPDATE setting_value = setting_value");
$stmt->execute();
if ($pdo) {
    try {
// Create departments table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    head VARCHAR(100),
    description TEXT,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) UNIQUE NOT NULL,
        attempts INT DEFAULT 1,
        last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) DEFAULT 'info',
        link VARCHAR(255) DEFAULT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_disputes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attendance_id INT,
        dispute_type VARCHAR(50) NOT NULL,
        reason TEXT NOT NULL,
        evidence_path VARCHAR(255),
        status VARCHAR(20) DEFAULT 'Pending',
        admin_response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS break_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attendance_id INT,
        break_type VARCHAR(50) NOT NULL,
        break_start DATETIME,
        break_end DATETIME,
        duration_minutes INT DEFAULT 0,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        monday VARCHAR(50) DEFAULT 'Day Shift',
        tuesday VARCHAR(50) DEFAULT 'Day Shift',
        wednesday VARCHAR(50) DEFAULT 'Day Shift',
        thursday VARCHAR(50) DEFAULT 'Day Shift',
        friday VARCHAR(50) DEFAULT 'Day Shift',
        saturday VARCHAR(50) DEFAULT 'Off',
        sunday VARCHAR(50) DEFAULT 'Off',
        is_active BOOLEAN DEFAULT TRUE,
        effective_from DATE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_trail (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(255) NOT NULL,
        user VARCHAR(100),
        table_name VARCHAR(100),
        record_id INT,
        before_value TEXT,
        after_value TEXT,
        ip_address VARCHAR(45),
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        employee_id VARCHAR(50) UNIQUE NOT NULL,
        position VARCHAR(100),
        department VARCHAR(100),
        role VARCHAR(20) DEFAULT 'Employee',
        password VARCHAR(255),
        finger_id INT NULL,
        birthday DATE NULL,
        status VARCHAR(20) DEFAULT 'active',
        office_gps VARCHAR(100),
        permissions TEXT,
        photo_path VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Add birthday column if not exists
        try {
            $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS birthday DATE NULL AFTER finger_id");
        } catch (Exception $e) {}
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        finger_id INT,
        check_in DATETIME,
        check_out DATETIME,
        is_late BOOLEAN DEFAULT FALSE,
        scan_method VARCHAR(50) DEFAULT 'manual',
        system_exempt BOOLEAN DEFAULT FALSE,
        overtime_hours DECIMAL(5,2) DEFAULT 0,
        night_diff_hours DECIMAL(5,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS day_off_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        reason TEXT,
        status VARCHAR(20) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_streaks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        current_streak INT DEFAULT 0,
        longest_streak INT DEFAULT 0,
        last_reset_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// === CREATE HOLIDAYS TABLE ===
$pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
date DATE NOT NULL UNIQUE,
type ENUM('regular', 'special_non_working', 'special_working') NOT NULL,
description TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
INDEX idx_date (date),
INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// === CREATE HOLIDAY WORK ASSIGNMENTS TABLE ===
$pdo->exec("CREATE TABLE IF NOT EXISTS holiday_work_assignments (
id INT AUTO_INCREMENT PRIMARY KEY,
holiday_id INT NOT NULL,
employee_id INT NOT NULL,
assigned_by INT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (holiday_id) REFERENCES holidays(id) ON DELETE CASCADE,
FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
UNIQUE KEY unique_assignment (holiday_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// === INSERT DEFAULT PHILIPPINE HOLIDAYS (2026) ===
$defaultHolidays = [
    // Regular Holidays
    ['2026-01-01', 'New Year\'s Day', 'regular', 'Start of the new year'],
    ['2026-04-02', 'Maundy Thursday', 'regular', 'Holy Week'],
    ['2026-04-03', 'Good Friday', 'regular', 'Holy Week'],
    ['2026-04-09', 'Araw ng Kagitingan', 'regular', 'Day of Valor'],
    ['2026-05-01', 'Labor Day', 'regular', 'International Workers\' Day'],
    ['2026-06-12', 'Independence Day', 'regular', 'Philippine Independence'],
    ['2026-08-31', 'National Heroes Day', 'regular', 'Last Monday of August'],
    ['2026-11-30', 'Bonifacio Day', 'regular', 'Andres Bonifacio\'s birthday'],
    ['2026-12-25', 'Christmas Day', 'regular', 'Celebration of Christmas'],
    ['2026-12-30', 'Rizal Day', 'regular', 'Jose Rizal\'s execution anniversary'],
    // Special Non-Working Days
    ['2026-08-21', 'Ninoy Aquino Day', 'special_non_working', 'In honor of Benigno Aquino Jr.'],
    ['2026-11-01', 'All Saints\' Day', 'special_non_working', 'Undas'],
    ['2026-12-08', 'Feast of the Immaculate Conception', 'special_non_working', 'Religious Holiday'],
    ['2026-12-31', 'Last Day of the Year', 'special_non_working', 'New Year\'s Eve'],
    // Additional Special Non-Working Days
    ['2026-01-17', 'Chinese New Year', 'special_non_working', 'Year of the Horse'],
    ['2026-04-04', 'Black Saturday', 'special_non_working', 'Day before Easter'],
    ['2026-11-02', 'All Souls\' Day', 'special_non_working', 'Day after Undas'],
    ['2026-12-24', 'Christmas Eve', 'special_non_working', 'Day before Christmas'],
    // Special Working Day
    ['2026-02-25', 'EDSA People Power Revolution', 'special_working', 'People Power Revolution Anniversary'],
];

// Prepare the statement ONCE
$stmt = $pdo->prepare("INSERT IGNORE INTO holidays (date, name, type, description) VALUES (?, ?, ?, ?)");

// Execute the loop ONCE
foreach ($defaultHolidays as $holiday) {
    $stmt->execute($holiday);
}
        $pdo->exec("INSERT INTO employee_schedules (employee_id, monday, tuesday, wednesday, thursday, friday, saturday, sunday, is_active, effective_from)
        SELECT id, 'Day Shift', 'Day Shift', 'Day Shift', 'Day Shift', 'Day Shift', 'Off', 'Off', 1, CURDATE()
        FROM employees
        WHERE id NOT IN (SELECT employee_id FROM employee_schedules)
        ON DUPLICATE KEY UPDATE updated_at = NOW()");
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE role = 'Admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("INSERT INTO employees (name, employee_id, position, department, role, password) VALUES ('System Admin', 'ADMIN001', 'Administrator', 'IT', 'Admin', 'admin123')");
        }
    } catch (Exception $e) {
        error_log("Table creation error: " . $e->getMessage());
    }
}

// === RATE LIMITING ===
$ip = $_SERVER['REMOTE_ADDR'];
$attempt = null;
if ($pdo) {
    $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?");
    $stmt->execute([$ip]);
    $attempt = $stmt->fetch();
    if ($attempt && $attempt['attempts'] >= 10 && (time() - strtotime($attempt['last_attempt'])) < 1800) {
        die("Too many login attempts. Try again later.");
    }
}
// === INITIALIZE SESSION ===
if (!isset($_SESSION['last_seen_notification'])) {
    $_SESSION['last_seen_notification'] = time();
}
// === HANDLE LOGIN ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // If NOT AJAX, we will redirect normally instead of outputting JSON
    if (!$isAjax) {
        // Perform standard login logic for non-AJAX fallback
        $employeeId = trim($_POST['employeeId'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        
        if ($pdo && !empty($employeeId) && !empty($password) && !empty($role)) {
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ? AND password = ? AND role = ?");
            $stmt->execute([$employeeId, $password, $role]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['employee_id'] = $user['employee_id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                // Redirect to dashboard
                header("Location: " . basename($_SERVER['PHP_SELF']));
                exit;
            }
        }
        // If login failed in non-AJAX mode, just reload the page (user will see login form again)
        header("Location: " . basename($_SERVER['PHP_SELF']));
        exit;
    }

    // === AJAX HANDLING (Original Logic) ===
    header('Content-Type: application/json');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(["success" => false, "error" => "Invalid request."]);
    } else {
        $employeeId = trim($_POST['employeeId']);
        $password = $_POST['password'];
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        if (empty($employeeId) || empty($password) || empty($role)) {
            echo json_encode(["success" => false, "error" => "Please fill all fields."]);
        } else {
            if ($attempt && $attempt['attempts'] >= 10) {
                echo json_encode(["success" => false, "error" => "Too many attempts. Try again later."]);
            } else {
                if ($pdo) {
                    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ? AND password = ? AND role = ?");
                    $stmt->execute([$employeeId, $password, $role]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($user) {
                        $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['employee_id'] = $user['employee_id'];
                        $_SESSION['name'] = $user['name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['position'] = $user['position'] ?? 'Not Specified';  // ✅ Add fallback
    $_SESSION['department'] = $user['department'] ?? 'Not Specified';  // ✅ Add fallback
                        $_SESSION['permissions'] = json_decode($user['permissions'] ?? '{}', true);
                        $_SESSION['office_gps'] = $user['office_gps'] ?? null;
                        logAudit($pdo, "Logged in", $user['employee_id']);
                        echo json_encode([
                            "success" => true,
                            "redirect" => basename($_SERVER['PHP_SELF']),
                            "role" => $user['role']
                        ]);
                    } else {
                        if ($pdo) {
                            if ($attempt) {
                                $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1 WHERE ip = ?")->execute([$ip]);
                            } else {
                                $pdo->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([$ip]);
                            }
                        }
                        echo json_encode(["success" => false, "error" => "Invalid credentials or role."]);
                    }
                } else {
                    echo json_encode(["success" => false, "error" => "Database connection failed."]);
                }
            }
        }
    }
    exit;
}
// === HANDLE LOGOUT ===
if (isset($_GET['logout'])) {
    if (isset($_SESSION['employee_id']) && $pdo) {
        logAudit($pdo, "Logged out", $_SESSION['employee_id']);
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
// === HANDLE ATTENDANCE SUBMISSION ===
if (isset($_POST['submit_attendance']) && isLoggedIn()) {
    $action = $_POST['action'];
    $userId = $_SESSION['user_id'];
    $empId = $_SESSION['employee_id'];
    if ($action === 'IN') {
        $officeGps = $_SESSION['office_gps'] ?? null;
        $userLat = $_POST['lat'] ?? null;
        $userLng = $_POST['lng'] ?? null;
        if ($officeGps && $userLat && $userLng) {
            [$officeLat, $officeLng] = explode(',', $officeGps);
            $distance = calculateDistance((float)$userLat, (float)$userLng, (float)$officeLat, (float)$officeLng);
            if ($distance > 100) {
                echo json_encode(['error' => 'You must be within 100m of the office to check in.']);
                exit;
            }
        }
        $checkInTime = date('Y-m-d H:i:s');
        $isLate = isLateCheckIn($checkInTime, $office_start_time, $grace_period_minutes) ? 1 : 0;
        $systemExempt = wasSystemDown($pdo, $checkInTime) ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, is_late, system_exempt) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $checkInTime, $isLate, $systemExempt]);
        $attendanceId = $pdo->lastInsertId();
        updateAttendanceStreak($pdo, $userId, true);
        logAudit($pdo, "Checked in (manual)", $empId, 'attendance', $attendanceId);
    
} elseif ($action === 'OUT') {
    $stmt = $pdo->prepare("UPDATE attendance SET check_out = NOW() WHERE employee_id = ? AND check_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $attendance = getTodayAttendance($pdo, $userId, $office_start_time, $grace_period_minutes);
    if ($attendance && $attendance['check_in'] && $attendance['check_out']) {
        $hours = calculatePayrollHours($attendance['check_in'], $attendance['check_out']);
        $updateStmt = $pdo->prepare("UPDATE attendance SET overtime_hours = ?, night_diff_hours = ? WHERE id = ?");
        $updateStmt->execute([$hours['overtime'], $hours['night_diff'], $attendance['id']]);
        

        // === AUTO-CREATE OVERTIME REQUEST IF > 1 HOUR ===
        if ($hours['overtime'] > 1.0) {
            // Check if overtime request already exists for this attendance
            $checkOtStmt = $pdo->prepare("SELECT id FROM overtime_requests WHERE attendance_id = ?");
            $checkOtStmt->execute([$attendance['id']]);
            
            if (!$checkOtStmt->fetch()) {
                $otStmt = $pdo->prepare("INSERT INTO overtime_requests (employee_id, attendance_id, overtime_hours, overtime_type, status, created_at) VALUES (?, ?, ?, 'Regular', 'Pending', NOW())");
                $otStmt->execute([$userId, $attendance['id'], $hours['overtime']]);
                
                // Create notification for employee
                createNotification($pdo, $userId, 'Overtime Detected', 
                    "You worked {$hours['overtime']} hours of overtime today. Auto-submitted for admin approval.", 
                    '/?view=overtime', 'info');
            }
        }
    }
    logAudit($pdo, "Checked out (manual)", $empId, 'attendance', $attendance['id'] ?? null);
}
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
// === HANDLE DAY OFF REQUEST ===
if (isset($_POST['submit_day_off']) && isLoggedIn()) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $dayOffError = "Invalid request.";
    } else {
        $startDate = $_POST['startDate'];
        $endDate = $_POST['endDate'];
        $reason = $_POST['reason'];
        $userId = $_SESSION['user_id'];
        $empId = $_SESSION['employee_id'];
        if (empty($startDate) || empty($endDate) || empty($reason)) {
            $dayOffError = "Please fill in all fields.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO day_off_requests (employee_id, start_date, end_date, reason, status, created_at) VALUES (?, ?, ?, ?, 'Pending', NOW())");
            $stmt->execute([$userId, $startDate, $endDate, $reason]);
            logAudit($pdo, "Submitted day-off request ({$startDate} to {$endDate})", $empId);
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}
/// === SUBMIT DAY OFF REQUEST (EMPLOYEE) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_day_off' && isLoggedIn()) {
    ob_start(); // 1. Start buffering to catch/discards ANY stray HTML/whitespace
    header('Content-Type: application/json'); // 2. Force JSON header
    
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }
        
        $startDate = $_POST['date'] ?? '';
        $endDate = $_POST['end_date'] ?? $startDate;
        $type = $_POST['type'] ?? 'Full Day';
        $reason = $_POST['reason'] ?? '';
$empId = $_SESSION['user_id']; // Use the numeric Database ID

        if (empty($startDate)) {
            throw new Exception('Please select a start date');
        }
        
        $stmt = $pdo->prepare("INSERT INTO day_off_requests (employee_id, start_date, end_date, type, reason, status, created_at) VALUES (?, ?, ?, ?, ?, 'Pending', NOW())");
        $stmt->execute([$empId, $startDate, $endDate, $type, $reason]);
        
        $response = ['success' => true, 'message' => 'Day off request submitted successfully!'];
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    ob_end_clean(); // 3. Clean buffer (discard any HTML/Warnings generated before)
    echo json_encode($response); // 4. Send ONLY JSON
    exit; // 5. STOP SCRIPT IMMEDIATELY
}

// === GET DAY OFF REQUESTS (EMPLOYEE) ===
if (isset($_GET['action']) && $_GET['action'] === 'get_day_off_requests' && isLoggedIn()) {
    ob_start(); // 1. Start buffering
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'requests' => []];
    
    try {
$empId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT * FROM day_off_requests WHERE employee_id = ? ORDER BY created_at DESC");
        $stmt->execute([$empId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response = ['success' => true, 'requests' => $requests];
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    ob_end_clean(); // 2. Clean buffer
    echo json_encode($response); // 3. Send ONLY JSON
    exit; // 4. STOP SCRIPT
}

// === DAY OFF REQUEST ADMIN ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_day_off', 'reject_day_off']) && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }
        
        $requestId = (int)$_POST['request_id'];
        $adminNote = trim($_POST['admin_note'] ?? '');
        $action = $_POST['action'];
        
        // Get current request details
        $stmt = $pdo->prepare("SELECT * FROM day_off_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        $newStatus = ($action === 'approve_day_off') ? 'Approved' : 'Rejected';
        
        // Update request
        $stmt = $pdo->prepare("UPDATE day_off_requests SET status = ?, admin_note = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
        $stmt->execute([$newStatus, $adminNote, $_SESSION['user_id'], $requestId]);
        
        // Create notification for employee
        $message = ($action === 'approve_day_off') 
            ? "Your day off request ({$request['start_date']} to {$request['end_date']}) has been APPROVED."
            : "Your day off request ({$request['start_date']} to {$request['end_date']}) has been REJECTED.";
        
        if ($adminNote) {
            $message .= " Admin Note: {$adminNote}";
        }
        
        createNotification($pdo, $request['employee_id'], 'Day Off Request Update', $message, '/?emp_view=leaves', ($action === 'approve_day_off') ? 'success' : 'error');
        
        // Audit log
        logAudit($pdo, ($action === 'approve_day_off' ? 'Approved' : 'Rejected') . " day off request #{$requestId}", $_SESSION['employee_id'], 'day_off_requests', $requestId);
        
        echo json_encode(['success' => true, 'message' => "Day off request {$newStatus} successfully!"]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}


// === GET DAY OFF REQUESTS FOR ADMIN ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_day_off_requests_admin' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        $statusFilter = $_GET['status'] ?? 'Pending';
        
        $sql = "SELECT d.*, e.name, e.employee_id, e.department, e.position, e.photo_path,
                reviewer.name as reviewed_by_name
                FROM day_off_requests d
                JOIN employees e ON d.employee_id = e.id
                LEFT JOIN employees reviewer ON d.reviewed_by = reviewer.id
                WHERE 1=1";
        
        $params = [];
        if ($statusFilter !== 'All') {
            $sql .= " AND d.status = ?";
            $params[] = $statusFilter;
        }
        
        $sql .= " ORDER BY d.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get stats
        $stmt = $pdo->query("SELECT COUNT(*) FROM day_off_requests WHERE status = 'Pending'");
        $pendingCount = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM day_off_requests WHERE status = 'Approved' AND MONTH(created_at) = MONTH(CURRENT_DATE())");
        $approvedCount = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM day_off_requests WHERE status = 'Rejected' AND MONTH(created_at) = MONTH(CURRENT_DATE())");
        $rejectedCount = $stmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'requests' => $requests,
            'stats' => [
                'pending' => $pendingCount,
                'approved' => $approvedCount,
                'rejected' => $rejectedCount
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// === HANDLE TOGGLE MANUAL CHECKIN (ADMIN) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_manual_checkin' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $status = $_POST['status'] ?? '0';
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('allow_manual_checkin', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$status, $status]);
        logAudit($pdo, "Toggled manual check-in to: " . ($status == '1' ? 'Enabled' : 'Disabled'), $_SESSION['employee_id']);
        echo json_encode(['success' => true, 'message' => 'Setting updated successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// === HANDLE MANUAL ATTENDANCE (EMPLOYEE) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_attendance' && isLoggedIn()) {
    // TEMPORARY DEBUG - Remove after fixing
    error_log("Manual attendance request received: " . print_r($_POST, true));
    
    header('Content-Type: application/json');
        
    // Check if feature is enabled
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_manual_checkin'");
    $isAllowed = $stmt->fetchColumn();
    
    if ($isAllowed != '1') {
        echo json_encode(['success' => false, 'message' => 'Manual check-in is currently disabled by admin.']);
        exit;
    }

    $type = $_POST['type'] ?? ''; // 'IN' or 'OUT'
    $userId = $_SESSION['user_id'];
    $empId = $_SESSION['employee_id'];
    $now = date('Y-m-d H:i:s');

    try {
        if ($type === 'IN') {
            // Check if already checked in
            // UPDATED: Use DATE(?) to ensure PHP and SQL agree on "Today"
            $check = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND DATE(check_in) = DATE(?) AND check_out IS NULL");
            $check->execute([$userId, $now]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You have already checked in today.']);
                exit;
            }
            
            // 1. Get Employee's Specific Shift Start Time for Today
$requiredStartTime = getEmployeeShiftStartTime($pdo, $userId, $today);

// 2. Calculate Lateness (NO GRACE PERIOD)
$isLate = 0;
$nowTimeStr = date('H:i:s');
if ($requiredStartTime && $requiredStartTime !== '00:00:00') {
    // Strict comparison: If Check In > Shift Start, they are Late
    if ($nowTimeStr > $requiredStartTime) {
        $isLate = 1;
    }
}
            
            // Insert Record
            $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, is_late, scan_method) VALUES (?, ?, ?, 'manual')");
            $stmt->execute([$userId, $now, $isLate]);
            
            // Update Streak
            updateAttendanceStreak($pdo, $userId, true);
            
            // Log Audit
            logAudit($pdo, "Manual Check In", $empId, 'attendance');
            
echo json_encode(['success' => true, 'message' => 'Checked in successfully!', 'time' => date('g:i:s A')]);            
} elseif ($type === 'OUT') {
    // UPDATED: Use DATE(?) instead of CURDATE() to match PHP time exactly
    $stmt = $pdo->prepare("UPDATE attendance SET check_out = ?, scan_method = 'manual' WHERE employee_id = ? AND DATE(check_in) = DATE(?) AND check_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$now, $userId, $now]);
    
    if ($stmt->rowCount() > 0) {
        // Calculate Payroll Hours (Same logic as Fingerprint)
        $attendance = getTodayAttendance($pdo, $userId, $office_start_time, $grace_period_minutes);
        if ($attendance && $attendance['check_in'] && $now) {
            $hours = calculatePayrollHours($attendance['check_in'], $now);
            $updateStmt = $pdo->prepare("UPDATE attendance SET overtime_hours = ?, night_diff_hours = ? WHERE id = ?");
            $updateStmt->execute([$hours['overtime'], $hours['night_diff'], $attendance['id']]);
            
            // === AUTO-CREATE OVERTIME REQUEST IF OVERTIME > 0 ===
            if ($hours['overtime'] > 0) {
                // Check if overtime request already exists
                $checkOT = $pdo->prepare("SELECT id FROM overtime_requests WHERE attendance_id = ?");
                $checkOT->execute([$attendance['id']]);
                
                if (!$checkOT->fetch()) {
                    // Create overtime request
                    $otStmt = $pdo->prepare("INSERT INTO overtime_requests 
                        (employee_id, attendance_id, overtime_hours, overtime_type, status, created_at) 
                        VALUES (?, ?, ?, 'Regular', 'Pending', NOW())");
                    $otStmt->execute([$userId, $attendance['id'], $hours['overtime']]);
                    
                    // Create notification for employee
                    createNotification($pdo, $userId, 'Overtime Detected', 
                        "You worked {$hours['overtime']} hours of overtime today. Admin approval required.", 
                        '/?view=overtime', 'info');
                }
            }
            // === END OVERTIME REQUEST LOGIC ===
        }
        
        logAudit($pdo, "Manual Check Out", $empId, 'attendance');
        echo json_encode(['success' => true, 'message' => 'Checked out successfully!', 'time' => date('H:i:s')]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active check-in found to check out.']);
    }
}
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// === 12-HOUR TIME FORMAT HELPERS ===
function formatTime12Hour($time24, $includeSeconds = false) {
    if (empty($time24)) return '';
    $datetime = DateTime::createFromFormat('H:i:s', $time24) ?: 
                DateTime::createFromFormat('H:i', $time24);
    if (!$datetime) return $time24;
    return $includeSeconds 
        ? $datetime->format('g:i:s A') 
        : $datetime->format('g:i A');
}

function formatDateTime12Hour($datetime, $showDate = true) {
    if (empty($datetime)) return '';
    $dt = new DateTime($datetime);
    return $showDate 
        ? $dt->format('M j, Y g:i A') 
        : $dt->format('g:i A');
}

// === GET DAY OFF REQUESTS (AJAX) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_day_off_requests' && isLoggedIn()) {
    header('Content-Type: application/json');
    $userId = $_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM day_off_requests WHERE employee_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


// === HANDLE CHANGE PASSWORD (EMPLOYEE) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password' && isLoggedIn()) {
    header('Content-Type: application/json');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $userId = $_SESSION['user_id'];
    $empId = $_SESSION['employee_id'];
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all password fields.']);
        exit;
    }
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
        exit;
    }
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT password FROM employees WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
        if ($user['password'] !== $currentPassword) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }
        $updateStmt = $pdo->prepare("UPDATE employees SET password = ? WHERE id = ?");
        $updateStmt->execute([$newPassword, $userId]);
        logAudit($pdo, "Password changed", $empId, 'employees', $userId);
        createNotification($pdo, $userId, 'Password Changed', 'Your password has been successfully updated.', null, 'success');
        echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
// === HANDLE MARK NOTIFICATIONS READ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read' && isLoggedIn()) {
    $notifId = $_POST['notification_id'] ?? null;
    if($notifId) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $_SESSION['user_id']]);
    } else {
        $_SESSION['last_seen_notification'] = time();
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
   // Fetch the updated employee data
$stmt = $pdo->prepare("SELECT id, name, employee_id, position, department, role, birthday, finger_id, photo_path FROM employees WHERE id = ?");
$stmt->execute([$id]);
$updatedEmp = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true, 
    'employee' => $updatedEmp
]);
exit;
}
// === GET NOTIFICATIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_notifications' && isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$userId]);
    $unreadCount = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'count' => $unreadCount, 'notifications' => $notifications]);
    exit;
}
// === HANDLE APPROVE/REJECT LEAVE ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_leave', 'reject_leave']) && isLoggedIn() && $_SESSION['role'] === 'Admin') {
header('Content-Type: application/json');  // ⚠️ MUST BE HERE
$requestId = (int)$_POST['request_id'];
$status = ($_POST['action'] === 'approve_leave') ? 'Approved' : 'Rejected';
$reqStmt = $pdo->prepare("SELECT employee_id FROM day_off_requests WHERE id = ?");
$reqStmt->execute([$requestId]);
$empId = $reqStmt->fetchColumn();
$beforeStmt = $pdo->prepare("SELECT status FROM day_off_requests WHERE id = ?");
$beforeStmt->execute([$requestId]);
$beforeValue = $beforeStmt->fetch(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("UPDATE day_off_requests SET status = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$status, $requestId]);
logAudit($pdo, "Day-off request #{$requestId} {$status}", $_SESSION['employee_id'], 'day_off_requests', $requestId, $beforeValue, ['status' => $status]);
createNotification($pdo, $empId, 'Leave Request Update', "Your leave request has been {$status}.", '/?tab=leave', $status === 'Approved' ? 'success' : 'error');
echo json_encode(['success' => true]);
exit;  // ⚠️ MUST BE HERE
}
// === HANDLE OVERTIME APPROVAL/REJECTION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_overtime', 'reject_overtime']) && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $requestId = (int)$_POST['request_id'];
    $status = ($_POST['action'] === 'approve_overtime') ? 'Approved' : 'Rejected';
    $adminResponse = $_POST['admin_response'] ?? '';
    
    try {
        $beforeStmt = $pdo->prepare("SELECT status, employee_id, overtime_hours FROM overtime_requests WHERE id = ?");
        $beforeStmt->execute([$requestId]);
        $beforeValue = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("UPDATE overtime_requests SET status = ?, admin_response = ?, approved_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $adminResponse, $_SESSION['user_id'], $requestId]);
        
        logAudit($pdo, "Overtime request #{$requestId} {$status}", $_SESSION['employee_id'], 'overtime_requests', $requestId, $beforeValue, ['status' => $status]);
        
        if ($beforeValue) {
            createNotification($pdo, $beforeValue['employee_id'], 'Overtime Request Update', "Your overtime request for {$beforeValue['overtime_hours']} hours has been {$status}.", '/?view=overtime', $status === 'Approved' ? 'success' : 'error');
        }
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


// === GET OVERTIME REQUESTS (ADMIN) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_overtime_requests' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $statusFilter = $_GET['status'] ?? 'all';
    try {
        $sql = "SELECT o.*, e.name, e.employee_id, e.department, e.photo_path, a.check_in, a.check_out
                FROM overtime_requests o
                JOIN employees e ON o.employee_id = e.id
                LEFT JOIN attendance a ON o.attendance_id = a.id
                WHERE 1=1";
        $params = [];
        if ($statusFilter !== 'all') {
            $sql .= " AND o.status = ?";
            $params[] = $statusFilter;
        }
        $sql .= " ORDER BY o.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === HANDLE OVERTIME APPROVAL/REJECTION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_overtime', 'reject_overtime']) && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $requestId = (int)$_POST['request_id'];
    $status = ($_POST['action'] === 'approve_overtime') ? 'Approved' : 'Rejected';
    try {
        // Get employee info for notification
        $reqStmt = $pdo->prepare("SELECT employee_id, overtime_hours FROM overtime_requests WHERE id = ?");
        $reqStmt->execute([$requestId]);
        $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);
        
        // Update request
        $stmt = $pdo->prepare("UPDATE overtime_requests SET status = ?, approved_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $_SESSION['user_id'], $requestId]);
        
        // Create notification for employee
        if ($reqData) {
            createNotification($pdo, $reqData['employee_id'], 'Overtime Request Update',
                "Your overtime request for {$reqData['overtime_hours']} hours has been {$status}.",
                '/?view=overtime', $status === 'Approved' ? 'success' : 'error');
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// === GET LEAVE HISTORY (LAST 30 DAYS) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_leave_history' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        // Fetch requests created within the last 30 days
        $stmt = $pdo->prepare("SELECT d.*, e.name, e.employee_id, e.department, e.photo_path 
                               FROM day_off_requests d 
                               JOIN employees e ON d.employee_id = e.id 
                               WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                               ORDER BY d.created_at DESC");
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === PURGE OLD LEAVE RECORDS (OLDER THAN 30 DAYS) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'purge_old_leave_records' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        // Delete records older than 30 days
        $stmt = $pdo->prepare("DELETE FROM day_off_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute();
        $count = $stmt->rowCount();
        logAudit($pdo, "Purged $count old leave records", $_SESSION['employee_id']);
        echo json_encode(['success' => true, 'message' => "$count old records cleared successfully."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === GET EMPLOYEE SCHEDULE ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_employee_schedule' && isLoggedIn()) {
    header('Content-Type: application/json');
    try {
        // Use Session User ID
        $userId = $_SESSION['user_id'];
        
        // Security: Employees can only see their own schedule
        if ($_SESSION['role'] === 'Employee') {
            // Verify the session user matches the requested schedule (implicit)
        }

        // Fetch Schedule
        $stmt = $pdo->prepare("SELECT * FROM employee_schedules WHERE employee_id = ? AND is_active = 1 ORDER BY effective_from DESC LIMIT 1");
        $stmt->execute([$userId]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        // Create Default Schedule if None Exists
        if (!$schedule) {
            $pdo->prepare("INSERT INTO employee_schedules (employee_id, monday, tuesday, wednesday, thursday, friday, saturday, sunday, is_active, effective_from) VALUES (?, 'Day Shift', 'Day Shift', 'Day Shift', 'Day Shift', 'Day Shift', 'Off', 'Off', 1, CURDATE())")->execute([$userId]);
            
            // Fetch the newly created schedule
            $stmt->execute([$userId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Fetch Approved Leaves for Current Month (to show on calendar)
        $today = new DateTime();
        $startOfMonth = $today->format('Y-m-01');
        $endOfMonth = $today->format('Y-m-t');
        
        $leaveStmt = $pdo->prepare("SELECT start_date, end_date, status FROM day_off_requests WHERE employee_id = ? AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?)) AND status = 'Approved'");
        $leaveStmt->execute([$userId, $startOfMonth, $endOfMonth, $startOfMonth, $endOfMonth]);
        $leaves = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true, 
            'schedule' => $schedule, 
            'leaves' => $leaves
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// === ADD EMPLOYEE ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_employee' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    // ✅ CRITICAL: Clear ALL output buffers
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    
    try {
        $upload_dir = 'uploads/';
        if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }
        
        $name = trim($_POST['name'] ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $password = !empty($_POST['password']) ? $_POST['password'] : 'password123';
        $birthday = !empty($_POST['birthday']) ? $_POST['birthday'] : null;
        $finger_id = !empty($_POST['finger_id']) ? intval($_POST['finger_id']) : null;
        $photo_path = '';
        
        // Handle photo upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['photo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $new_filename = uniqid() . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                    $photo_path = $upload_path;
                }
            }
        }
        
        // Validation
        if (empty($name) || empty($employee_id) || empty($position) || empty($department) || empty($role)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }
        
        // Check duplicate
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Employee ID already exists.']);
            exit;
        }
        
        // Insert
        $stmt = $pdo->prepare("INSERT INTO employees (name, employee_id, position, department, role, password, birthday, finger_id, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $employee_id, $position, $department, $role, $password, $birthday, $finger_id, $photo_path]);
        $newId = $pdo->lastInsertId();
        
        logAudit($pdo, "Added new employee: {$name} ({$employee_id})", $_SESSION['employee_id'], 'employees', $newId);
        
        echo json_encode([
            'success' => true,
            'employee' => [
                'id' => $newId,
                'name' => $name,
                'employee_id' => $employee_id,
                'position' => $position,
                'department' => $department,
                'role' => $role,
                'birthday' => $birthday,
                'finger_id' => $finger_id,
                'photo_path' => $photo_path
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// === UPDATE EMPLOYEE ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_employee' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    $upload_dir = 'uploads/';
    if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $employee_id = trim($_POST['employee_id']);
    $position = trim($_POST['position']);
    $department = trim($_POST['department']);
    $role = trim($_POST['role']);
    $password = !empty($_POST['password']) ? $_POST['password'] : null;
    $birthday = !empty($_POST['birthday']) ? $_POST['birthday'] : null;
    $finger_id = !empty($_POST['finger_id']) ? intval($_POST['finger_id']) : null;
    $photo_path = null;
    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['photo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                $photo_path = $upload_path;




// === GET EMPLOYEE SCHEDULE (ADMIN) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_employee_schedule_admin' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $employeeId = (int)$_GET['employee_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM employee_schedules WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1");
        $stmt->execute([$employeeId]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'schedule' => $schedule]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

                // Delete old photo
                $oldStmt = $pdo->prepare("SELECT photo_path FROM employees WHERE id = ?");
                $oldStmt->execute([$id]);
                $old = $oldStmt->fetchColumn();
                if ($old && file_exists($old)) { unlink($old); }
            }
        }
    }
    // Also handle base64 photo data
    if (empty($photo_path) && isset($_POST['photo_data']) && !empty($_POST['photo_data'])) {
        $photoData = $_POST['photo_data'];
        if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $type)) {
            $photoData = substr($photoData, strpos($photoData, ',') + 1);
            $type = strtolower($type[1]);
            $photo_path = $upload_dir . uniqid() . '.' . $type;
            file_put_contents($photo_path, base64_decode($photoData));
            // Delete old photo
            $oldStmt = $pdo->prepare("SELECT photo_path FROM employees WHERE id = ?");
            $oldStmt->execute([$id]);
            $old = $oldStmt->fetchColumn();
            if ($old && file_exists($old)) { unlink($old); }
        }
    }
    if (empty($name) || empty($employee_id) || empty($position) || empty($department) || empty($role)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ? AND id != ?");
    $stmt->execute([$employee_id, $id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Employee ID already in use.']);
        exit;
    }
    if ($photo_path && $password) {
        $stmt = $pdo->prepare("UPDATE employees SET name = ?, employee_id = ?, position = ?, department = ?, role = ?, password = ?, birthday = ?, finger_id = ?, photo_path = ? WHERE id = ?");
        $stmt->execute([$name, $employee_id, $position, $department, $role, $password, $birthday, $finger_id, $photo_path, $id]);
    } elseif ($photo_path) {
        $stmt = $pdo->prepare("UPDATE employees SET name = ?, employee_id = ?, position = ?, department = ?, role = ?, birthday = ?, finger_id = ?, photo_path = ? WHERE id = ?");
        $stmt->execute([$name, $employee_id, $position, $department, $role, $birthday, $finger_id, $photo_path, $id]);
    } elseif ($password) {
        $stmt = $pdo->prepare("UPDATE employees SET name = ?, employee_id = ?, position = ?, department = ?, role = ?, password = ?, birthday = ?, finger_id = ? WHERE id = ?");
        $stmt->execute([$name, $employee_id, $position, $department, $role, $password, $birthday, $finger_id, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE employees SET name = ?, employee_id = ?, position = ?, department = ?, role = ?, birthday = ?, finger_id = ? WHERE id = ?");
        $stmt->execute([$name, $employee_id, $position, $department, $role, $birthday, $finger_id, $id]);
    }
    logAudit($pdo, "Updated employee ID: {$employee_id}", $_SESSION['employee_id'], 'employees', $id);
    echo json_encode(['success' => true]);
    exit;
}
// === DELETE EMPLOYEE (MOVE TO ARCHIVE) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_employee' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    
    try {
        // 1. Fetch Employee Data first
        $empStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $empStmt->execute([$id]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if ($emp) {
            // 2. Insert into Archives
            $archiveStmt = $pdo->prepare("INSERT INTO employee_archives 
                (original_id, name, employee_id, position, department, role, password, finger_id, birthday, status, photo_path, archived_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'archived', ?, ?)");
            
            $archiveStmt->execute([
                $emp['id'], 
                $emp['name'], 
                $emp['employee_id'], 
                $emp['position'], 
                $emp['department'], 
                $emp['role'], 
                $emp['password'], 
                $emp['finger_id'], 
                $emp['birthday'], 
                $emp['photo_path'],
                $_SESSION['employee_id'] // Who deleted it
            ]);

            // 3. Delete Photo (Optional: Keep it or delete it. Here we keep it for archive integrity)
            // if ($emp['photo_path'] && file_exists($emp['photo_path'])) { unlink($emp['photo_path']); }

            // 4. Delete from Active Employees
            $deleteStmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
            $deleteStmt->execute([$id]);

            // 5. Log Audit
            logAudit($pdo, "Archived employee: {$emp['employee_id']}", $_SESSION['employee_id'], 'employees', $id);

            echo json_encode(['success' => true, 'message' => 'Employee moved to archives successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Employee not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}
// === GET ATTENDANCE LIST ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_attendance_list' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    $type = $_GET['type'] ?? '';
    // Use the date from GET parameter, fallback to today
    $date = $_GET['date'] ?? date('Y-m-d'); 
    
    if (!strtotime($date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date.']);
        exit;
    }
    try {
        if ($type === 'present') {
            $stmt = $pdo->prepare("SELECT e.name, e.employee_id, e.department FROM employees e INNER JOIN attendance a ON e.id = a.employee_id WHERE DATE(a.check_in) = ? AND a.check_out IS NOT NULL ORDER BY e.name");
            $stmt->execute([$date]);
        } elseif ($type === 'absent') {
            $stmt = $pdo->prepare("SELECT e.name, e.employee_id, e.department FROM employees e LEFT JOIN attendance a ON e.id = a.employee_id AND DATE(a.check_in) = ? WHERE a.id IS NULL ORDER BY e.name");
            $stmt->execute([$date]);
        } elseif ($type === 'late') {
            $stmt = $pdo->prepare("SELECT e.name, e.employee_id, e.department FROM employees e INNER JOIN attendance a ON e.id = a.employee_id WHERE DATE(a.check_in) = ? AND a.is_late = 1 ORDER BY e.name");
            $stmt->execute([$date]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid type.']);
            exit;
        }
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'employees' => $employees]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}
// === GET AUDIT TRAIL ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_audit_trail' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    $dateFilter = $_GET['date'] ?? '';
    try {
        if (!empty($dateFilter)) {
            $stmt = $pdo->prepare("SELECT at.*, e.name as user_name FROM audit_trail at LEFT JOIN employees e ON (at.user = e.employee_id OR at.user = e.id) WHERE DATE(at.timestamp) = ? ORDER BY at.timestamp DESC LIMIT 100");
            $stmt->execute([$dateFilter]);
        } else {
            $stmt = $pdo->query("SELECT at.*, e.name as user_name FROM audit_trail at LEFT JOIN employees e ON (at.user = e.employee_id OR at.user = e.id) ORDER BY at.timestamp DESC LIMIT 100");
        }
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'logs' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}
// === GET ALL EMPLOYEES FOR FINGERPRINT MODULE ===
if (isset($_GET['action']) && $_GET['action'] === 'get_all_employees_fp' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
header('Content-Type: application/json');
try {
$stmt = $pdo->query("SELECT id, name, employee_id, department, position, finger_id, photo_path FROM employees ORDER BY name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($employees);
$enrolled = count(array_filter($employees, fn($e) => $e['finger_id'] !== null));
$pending = $total - $enrolled;
echo json_encode([
'success' => true,
'employees' => $employees,
'total' => $total,
'enrolled' => $enrolled,
'pending' => $pending
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}

// === GET FINGERPRINT SCANS (ENHANCED FOR LIVE STATS) ===
if (isset($_GET['action']) && $_GET['action'] === 'get_fingerprint_scans' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
header('Content-Type: application/json');
try {
// Get recent scans
$stmt = $pdo->prepare("SELECT a.*, e.name, e.employee_id, e.department FROM attendance a JOIN employees e ON a.employee_id = e.id WHERE a.finger_id IS NOT NULL ORDER BY a.check_in DESC LIMIT 20");
$stmt->execute();
$scans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Today's Scans Count specifically
$today = date('Y-m-d');
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = ? AND finger_id IS NOT NULL");
$stmtCount->execute([$today]);
$todayCount = $stmtCount->fetchColumn();

echo json_encode([
'success' => true,
'scans' => $scans,
'today_count' => $todayCount // Send this specifically for the card
]);
} catch (Exception $e) {
echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
}

// === GET REALTIME STATS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_realtime_stats' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $today = date('Y-m-d');
    
    try {
        // 1. Present Today (checked in & out)
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.employee_id) FROM attendance a 
                               JOIN employees e ON a.employee_id = e.id 
                               WHERE DATE(a.check_in) = ? AND a.check_out IS NOT NULL");
        $stmt->execute([$today]);
        $present = (int)$stmt->fetchColumn();
        
        // 2. Absent Today (active employees with no record)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees e 
                               WHERE e.status = 'active' 
                               AND e.id NOT IN (SELECT employee_id FROM attendance WHERE DATE(check_in) = ?)");
        $stmt->execute([$today]);
        $absent = (int)$stmt->fetchColumn();
        
        // 3. Late Arrivals
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a 
                               JOIN employees e ON a.employee_id = e.id 
                               WHERE DATE(a.check_in) = ? AND a.is_late = 1");
        $stmt->execute([$today]);
        $late = (int)$stmt->fetchColumn();
        
        // 4. Total Active Employees
        $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'");
        $total = (int)$stmt->fetchColumn();
        
        // 5. Live Headcount (currently checked in)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance 
                               WHERE DATE(check_in) = ? AND check_out IS NULL");
        $stmt->execute([$today]);
        $liveTotal = (int)$stmt->fetchColumn();
        
        // 6. Live Breakdown by shift
        $stmt = $pdo->prepare("SELECT TIME(check_in) as check_in_time FROM attendance 
                               WHERE DATE(check_in) = ? AND check_out IS NULL");
        $stmt->execute([$today]);
        $liveScans = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $liveMorning = 0;
        $liveAfternoon = 0;
        $liveNight = 0;
        $liveFlexible = 0;
        
        foreach ($liveScans as $time) {
            $hour = (int)substr($time, 0, 2);
            if ($hour >= 6 && $hour < 12) {
                $liveMorning++;
            } elseif ($hour >= 12 && $hour < 18) {
                $liveAfternoon++;
            } elseif ($hour >= 18 || $hour < 6) {
                $liveNight++;
            } else {
                $liveFlexible++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'total' => $total,
            'live_total' => $liveTotal,
            'live_morning' => $liveMorning,
            'live_afternoon' => $liveAfternoon,
            'live_night' => $liveNight,
            'live_flexible' => $liveFlexible
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_export_data' && isLoggedIn()) {
    $type = $_GET['type'] ?? 'attendance';
    $userId = $_SESSION['user_id'];
    $role = $_SESSION['role'];
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    try {
        if ($type === 'attendance') {
            // 1. Get Scope and Filter Values
            $scope = $_GET['scope'] ?? 'all';
            $filterValue = $_GET['filter_value'] ?? '';
            $searchTerm = $_GET['search'] ?? '';
            // 2. Base Query
            $sql = "SELECT a.*, e.name, e.employee_id, e.department,
            CASE WHEN a.check_out IS NULL THEN 'Active' WHEN a.is_late = 1 THEN 'Late' ELSE 'Present' END as status
            FROM attendance a
            JOIN employees e ON a.employee_id = e.id
            WHERE DATE(a.check_in) BETWEEN ? AND ?";
            $params = [$start_date, $end_date];
            // 3. Apply Scope Filters
            if ($scope === 'department' && !empty($filterValue)) {
                $sql .= " AND e.department = ?";
                $params[] = $filterValue;
            } elseif ($scope === 'employee' && !empty($filterValue)) {
                $sql .= " AND e.employee_id = ?";
                $params[] = $filterValue;
            }
            // 4. Apply Search Filter
            if (!empty($searchTerm)) {
                $sql .= " AND (e.name LIKE ? OR e.employee_id LIKE ?)";
                $params[] = "%$searchTerm%";
                $params[] = "%$searchTerm%";
            }
            // 5. Role Restrictions
            if ($role !== 'Admin') {
                $sql = "SELECT a.*, e.name, e.employee_id, e.department,
                CASE WHEN a.check_out IS NULL THEN 'Active' WHEN a.is_late = 1 THEN 'Late' ELSE 'Present' END as status
                FROM attendance a
                JOIN employees e ON a.employee_id = e.id
                WHERE a.employee_id = ? AND DATE(a.check_in) BETWEEN ? AND ?";
                $params = [$userId, $start_date, $end_date];
            }
            $sql .= " ORDER BY a.check_in DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

            
   // === HOLIDAY PAY CALCULATION LOGIC ===
            $data = [];
            foreach ($records as $record) {
                $hours = ['regular' => 0, 'overtime' => 0, 'night_diff' => 0, 'holiday_pay' => 0, 'holiday_type' => 'None'];
                if ($record['check_in'] && $record['check_out']) {
                    $hours = calculatePayrollHours($record['check_in'], $record['check_out']);
                    
                    // Check if date is a holiday
                    $checkDate = date('Y-m-d', strtotime($record['check_in']));
                    $holStmt = $pdo->prepare("SELECT type FROM holidays WHERE date = ?");
                    $holStmt->execute([$checkDate]);
                    $holiday = $holStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($holiday) {
                        $hours['holiday_type'] = $holiday['type'];
                        // Check if employee was assigned to work this holiday
                        $assignStmt = $pdo->prepare("SELECT id FROM holiday_work_assignments WHERE holiday_id = (SELECT id FROM holidays WHERE date = ?) AND employee_id = ?");
                        $assignStmt->execute([$checkDate, $record['employee_id']]);
                        $isAssigned = $assignStmt->fetch();
                        
                        // Apply Rules
                        if ($holiday['type'] === 'regular') {
                            if ($isAssigned) {
                                // Worked Regular Holiday = 2x Regular Hours
                                $hours['holiday_pay'] = $hours['regular'] * 2; 
                            } else {
                                // Did Not Work Regular Holiday = 8hrs Paid (Credit)
                                $hours['holiday_pay'] = 8.0; 
                            }
                        } elseif ($holiday['type'] === 'special_non_working') {
                            if ($isAssigned) {
                                // Worked Special Non-Working = Regular + 2.4hrs (30% of 8)
                                $hours['holiday_pay'] = $hours['regular'] + 2.4; 
                            } else {
                                // Did Not Work = 0
                                $hours['holiday_pay'] = 0; 
                            }
                        } elseif ($holiday['type'] === 'special_working') {
                            if ($isAssigned) {
                                // Worked Special Working = Normal
                                $hours['holiday_pay'] = $hours['regular']; 
                            } else {
                                // Did Not Work = 0
                                $hours['holiday_pay'] = 0; 
                            }
                        }
                    }
                }
                $data[] = [
                    'employee_id' => $record['employee_id'],
                    'name' => $record['name'],
                    'department' => $record['department'],
                    'check_in' => $record['check_in'],
                    'check_out' => $record['check_out'],
                    'regular_hours' => $hours['regular'],
                    'overtime_hours' => $hours['overtime'],
                    'night_diff_hours' => $hours['night_diff'],
                    'holiday_pay_hours' => $hours['holiday_pay'],
                    'holiday_type' => $hours['holiday_type'],
                    'status' => $record['status']
                ];
            }
            echo json_encode(['success' => true, 'data' => $data]);
        } elseif ($type === 'leave') {
            // ... [KEEP EXISTING LEAVE LOGIC] ...
            $stmt = $pdo->prepare("SELECT d.*, e.name, e.employee_id, e.department FROM day_off_requests d JOIN employees e ON d.employee_id = e.id WHERE DATE(d.start_date) BETWEEN ? AND ? ORDER BY d.created_at DESC");
            $stmt->execute([$start_date, $end_date]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid export type']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


// === GET HOLIDAYS (ADMIN & EMPLOYEE) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_holidays' && isLoggedIn()) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT * FROM holidays WHERE YEAR(date) = YEAR(CURDATE()) ORDER BY date ASC");
        $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'holidays' => $holidays]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'holidays' => []]);
    }
    exit;
}


// === GET MY HOLIDAY ASSIGNMENTS (EMPLOYEE) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_my_holiday_assignments' && isLoggedIn()) {
    header('Content-Type: application/json');
    try {
        $employeeId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT holiday_id FROM holiday_work_assignments WHERE employee_id = ?");
        $stmt->execute([$employeeId]);
        $assignments = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'assignments' => $assignments]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'assignments' => []]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_holiday_work' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    try {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) throw new Exception('Invalid token');
        $holidayId = $_POST['holiday_id'];
        $employeeIds = $_POST['employee_ids']; // Array
        // Clear existing assignments for this holiday
        $stmt = $pdo->prepare("DELETE FROM holiday_work_assignments WHERE holiday_id = ?");
        $stmt->execute([$holidayId]);
        // Insert new assignments
        if (!empty($employeeIds)) {
            $stmt = $pdo->prepare("INSERT INTO holiday_work_assignments (holiday_id, employee_id, assigned_by) VALUES (?, ?, ?)");
            foreach ($employeeIds as $empId) {
                $stmt->execute([$holidayId, $empId, $_SESSION['user_id']]);
            }
        }
        logAudit($pdo, "Updated holiday work assignments", $_SESSION['employee_id'], 'holiday_work_assignments', $holidayId);
        echo json_encode(['success' => true, 'message' => 'Assignments updated successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_holiday_assignments' && isLoggedIn()) {
    header('Content-Type: application/json');
    try {
        $holidayId = $_GET['holiday_id'];
        $stmt = $pdo->prepare("SELECT employee_id FROM holiday_work_assignments WHERE holiday_id = ?");
        $stmt->execute([$holidayId]);
        $assignments = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'assignments' => $assignments]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// === REMOVE FINGERPRINT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_fingerprint' && isLoggedIn() && $_SESSION['role'] === 'Admin') {
    header('Content-Type: application/json');
    $employeeId = (int)$_POST['employee_id'];
    try {
        $stmt = $pdo->prepare("UPDATE employees SET finger_id = NULL WHERE id = ?");
        $stmt->execute([$employeeId]);
        logAudit($pdo, "Removed fingerprint enrollment", $_SESSION['employee_id'], 'employees', $employeeId);
        echo json_encode(['success' => true, 'message' => 'Fingerprint enrollment removed']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === GET ATTENDANCE SUMMARY ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_attendance_summary' && isLoggedIn()) {
    header('Content-Type: application/json');
    // Get date from request, default to today
    $date = $_GET['date'] ?? date('Y-m-d');
    
    try {
        // 1. Present: Anyone who has checked in today (regardless of check-out status)
        // This ensures people currently at work show up as Present
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.employee_id) FROM attendance a 
                               JOIN employees e ON a.employee_id = e.id 
                               WHERE DATE(a.check_in) = ?");
        $stmt->execute([$date]);
        $present = (int)$stmt->fetchColumn();
        
        // 2. Late: Anyone who checked in after 08:00 AM today
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.employee_id) FROM attendance a 
                               JOIN employees e ON a.employee_id = e.id 
                               WHERE DATE(a.check_in) = ? AND a.is_late = 1");
        $stmt->execute([$date]);
        $late = (int)$stmt->fetchColumn();
        
        // 3. Absent: Active employees who have NO attendance record today
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees e 
                               WHERE e.status = 'active' 
                               AND e.id NOT IN (SELECT employee_id FROM attendance WHERE DATE(check_in) = ?)");
        $stmt->execute([$date]);
        $absent = (int)$stmt->fetchColumn();
        
        // 4. Total: Sum of the above
        $total = $present + $absent; // Total usually implies Present + Absent
        
        echo json_encode([
            'success' => true,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'total' => $total,
            'debug_date' => $date // Helps debugging
        ]);
    } catch (Exception $e) {
        // Log error to PHP error log
        error_log("Attendance Summary Error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0
        ]);
    }
    exit;
}


// === GET LEAVE REQUESTS (EMPLOYEE) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_leave_requests' && isLoggedIn()) {
    header('Content-Type: application/json');
    $userId = $_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// === SUBMIT LEAVE REQUEST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_leave_request' && isLoggedIn()) {
    header('Content-Type: application/json');
    try {
        $userId = $_SESSION['user_id'];
        $leaveType = $_POST['leave_type'];
        $startDate = $_POST['start_date'];
        $days = $_POST['days'];
        $reason = $_POST['reason'];
        
        $stmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, days, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$userId, $leaveType, $startDate, $days, $reason]);
        
        echo json_encode(['success' => true, 'message' => 'Leave request submitted']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// === HANDLE AI QUERY (LIVE ATTENDANCE DATA) ===
if (isset($_GET['action']) && $_GET['action'] === 'ai_query' && isLoggedIn()) {
    header('Content-Type: application/json');
    $message = strtolower($_POST['message'] ?? $_GET['message'] ?? '');
    $responseText = "";
    $responseData = null;
    $responseType = 'text'; // text, list, stat

    try {
        // 1. Check for Absentees
        if (strpos($message, 'absent') !== false || strpos($message, 'missing') !== false) {
            $stmt = $pdo->prepare("SELECT e.name, e.employee_id, e.department FROM employees e 
                                   LEFT JOIN attendance a ON e.id = a.employee_id AND DATE(a.check_in) = CURDATE() 
                                   WHERE a.id IS NULL AND e.status = 'active' LIMIT 10");
            $stmt->execute();
            $absent = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
           // ... inside the 'absent' check ...
if (count($absent) > 0) {
    $responseText = "I found <strong>" . count($absent) . " employees</strong> absent today:";
    
    // Generate the styled HTML list
    $htmlList = '<div class="ai-data-card">';
    foreach ($absent as $person) {
        $dept = $person['department'] ?? 'Unknown';
        $name = $person['name'];
        $htmlList .= "
        <div class='ai-list-row'>
            <div class='ai-name'><i class='fas fa-user'></i> {$name}</div>
            <div class='ai-dept'>{$dept}</div>
        </div>";
    }
    $htmlList .= '</div>';
    
    // We will append this HTML to the message in the JS step
    $responseData = ['html' => $htmlList]; 
    $responseType = 'custom_list';
} 
// ...
        } 
        // 2. Check for Late Arrivals
        elseif (strpos($message, 'late') !== false) {
            $stmt = $pdo->prepare("SELECT e.name, TIME(a.check_in) as time FROM employees e 
                                   JOIN attendance a ON e.id = a.employee_id AND DATE(a.check_in) = CURDATE() 
                                   WHERE a.is_late = 1 LIMIT 10");
            $stmt->execute();
            $late = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($late) > 0) {
                $responseText = "These employees arrived late today:";
                $responseData = $late;
                $responseType = 'list_late';
            } else {
                $responseText = "No late arrivals recorded today. Everyone is on time!";
            }
        }
        // 3. Check Total Present
        elseif (strpos($message, 'present') !== false || strpos($message, 'how many') !== false) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = CURDATE() AND check_out IS NOT NULL");
            $count = $stmt->fetchColumn();
            $responseText = "Currently, <strong>{$count}</strong> employees have completed their shift today.";
            $responseType = 'stat';
        }
        // 4. Check Who is currently IN (Active)
        elseif (strpos($message, 'current') !== false || strpos($message, 'now') !== false) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(check_in) = CURDATE() AND check_out IS NULL");
            $count = $stmt->fetchColumn();
            $responseText = "There are <strong>{$count}</strong> employees currently clocked in right now.";
            $responseType = 'stat';
        }
        // 5. Default Fallback
        else {
            $responseText = "I can help you with live attendance data. Try asking: <br>• 'Who is absent?' <br>• 'Who is late?' <br>• 'How many are present?'";
        }

        echo json_encode([
            'success' => true, 
            'text' => $responseText, 
            'data' => $responseData,
            'type' => $responseType
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'text' => "Error connecting to database: " . $e->getMessage()]);
    }
    exit;
}
// === GET USER DATA ===
$manualCheckinAllowed = false;
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_manual_checkin'");
    $manualCheckinAllowed = ($stmt->fetchColumn() == '1');
} catch (Exception $e) {}

$userInfo = null;
// ... rest of the code
$userInfo = null;
$attendanceInfo = null;
$streakInfo = null;
if (isLoggedIn()) {
    $userInfo = getUserInfo($pdo, $_SESSION['user_id']);
    $attendanceInfo = getTodayAttendance($pdo, $_SESSION['user_id'], $office_start_time, $grace_period_minutes);
    $streakInfo = getAttendanceStreak($pdo, $_SESSION['user_id']);

    // === GET TODAY'S ASSIGNED SHIFT ===
    $todayShiftName = 'Not Assigned';
    $todayShiftTime = '--:-- - --:--';
    $todayShiftColor = '#6c757d'; // Default Gray
    
    try {
        $dayName = strtolower(date('l')); // e.g., 'monday'
        $stmt = $pdo->prepare("SELECT * FROM employee_schedules WHERE employee_id = ? AND is_active = 1 ORDER BY effective_from DESC LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($schedule && isset($schedule[$dayName])) {
            $shiftType = $schedule[$dayName];
            $todayShiftName = $shiftType;
            
            // Map Shift Names to Times & Colors
            if (stripos($shiftType, 'Morning') !== false || stripos($shiftType, 'Day') !== false) {
                $todayShiftTime = '08:00 - 17:00';
                $todayShiftColor = '#28a745'; // Green
            } elseif (stripos($shiftType, 'Afternoon') !== false || stripos($shiftType, 'Mid') !== false) {
                $todayShiftTime = '14:00 - 23:00';
                $todayShiftColor = '#fd7e14'; // Orange
            } elseif (stripos($shiftType, 'Night') !== false) {
                $todayShiftTime = '22:00 - 07:00';
                $todayShiftColor = '#6f42c1'; // Purple
            } elseif (stripos($shiftType, 'Flexible') !== false) {
                $todayShiftTime = 'Flexible Hours';
                $todayShiftColor = '#17a2b8'; // Blue
            } elseif ($shiftType === 'Off') {
                $todayShiftName = 'Day Off';
                $todayShiftTime = 'No Shift';
                $todayShiftColor = '#dc3545'; // Red
            }
        }
    } catch (Exception $e) {}
}
$pendingCount = 0;
$pendingDisputesCount = 0;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin') {
    $stmt = $pdo->query("SELECT COUNT(*) FROM day_off_requests WHERE status = 'Pending'");
    $pendingCount = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM attendance_disputes WHERE status = 'Pending'");
    $pendingDisputesCount = $stmt->fetchColumn();
}
$notifications = [];
$unreadCount = 0;
if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$userId]);
    $unreadCount = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// === DARK MODE ===
if (!isset($_COOKIE['dark_mode'])) {
    setcookie('dark_mode', 'light', time() + 31536000, '/');
    $darkMode = false;
} else {
    $darkMode = $_COOKIE['dark_mode'] === 'dark';
}
?>
<!DOCTYPE html>
<html lang="en" <?= $darkMode ? 'data-theme="dark"' : '' ?>>
<head>
    <meta name="csrf-token" content="<?= generateCSRFToken() ?>">
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>HELPORT - Employee Attendance System</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<style>

    @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.live-timer {
    animation: pulse 2s infinite;
}

.loading-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top: 2px solid var(--primary-accent);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
/* Compact Refresh Button - Replace or add this CSS */
.refresh-btn {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border: none;
  border-radius: 8px;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: white;
  font-size: 14px;
  box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
  padding: 0;
}

.refresh-btn::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
  transition: left 0.5s;
}

.refresh-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 15px rgba(102, 126, 234, 0.5);
}

.refresh-btn:hover::before {
  left: 100%;
}

.refresh-btn:active {
  transform: translateY(0) scale(0.95);
}

.refresh-btn.spinning i {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 768px) {
  .refresh-btn {
    width: 32px;
    height: 32px;
    font-size: 12px;
  }
}

/* Compact Filter Controls */
.filter-select {
    padding: 8px 12px !important;
    font-size: 13px !important;
    border-radius: 8px !important;
    border: 1px solid var(--border-color) !important;
    background: rgba(255,255,255,0.05) !important;
    color: var(--text-primary) !important;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-select:hover {
    border-color: var(--primary-accent) !important;
    background: rgba(25, 211, 197, 0.1) !important;
}

.filter-select:focus {
    outline: none;
    border-color: var(--primary-accent) !important;
    box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.1) !important;
}

.search-input {
    padding: 8px 12px !important;
    font-size: 13px !important;
    border-radius: 8px !important;
    border: 1px solid var(--border-color) !important;
    background: rgba(255,255,255,0.05) !important;
    color: var(--text-primary) !important;
    transition: all 0.3s ease;
}

.search-input:focus {
    outline: none;
    border-color: var(--primary-accent) !important;
    box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.1) !important;
    background: rgba(255,255,255,0.08) !important;
}

.search-input::placeholder {
    color: var(--text-muted);
    opacity: 0.7;
}

.action-button.secondary {
    padding: 8px 12px !important;
    font-size: 13px !important;
    border-radius: 8px !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.action-button.secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(25, 211, 197, 0.2);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .section-header > div:last-child {
        flex-direction: column;
        width: 100%;
        gap: 8px;
    }
    
    .filter-select, .search-input {
        width: 100% !important;
    }
}

    /* Live Timer Animation */
.live-timer {
    font-family: 'Courier New', monospace;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.7;
    }
}

    /* === HELPORT SCHEDULE CALENDAR STYLES === */
.schedule-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr); /* 7 Days */
    gap: 15px;
    margin: 20px 0;
    width: 100%;
}

.schedule-day-card {
    background: var(--panel-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 15px 10px;
    text-align: center;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 140px;
    position: relative;
    overflow: hidden;
}

.schedule-day-card:hover {
    transform: translateY(-3px);
    border-color: var(--primary-accent);
    box-shadow: 0 5px 15px rgba(25, 211, 197, 0.15);
}
/* Department Card Styles */
.dept-card {
    background: var(--panel-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.dept-card:hover {
    border-color: var(--primary-accent);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}
.dept-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.dept-icon {
    width: 40px;
    height: 40px;
    background: rgba(25, 211, 197, 0.1);
    color: var(--primary-accent);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.dept-name {
    flex: 1;
    min-width: 0; /* Important for text truncation */
    display: flex;
    align-items: center;
    gap: 12px;
    overflow: hidden;
}
/* Fix department name text */
.dept-name > div:last-child,
.dept-name .department-name {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    min-width: 0;
    align-items: center; /* <--- ADD THIS LINE */
}
.dept-name-wrap {
    word-break: break-word;
    line-height: 1.3;
    max-width: calc(100% - 60px); /* Leave space for icon and buttons */
}

.dept-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}
.dept-stat-item {
    text-align: center;
    background: rgba(255,255,255,0.02);
    padding: 10px;
    border-radius: 8px;
}
.dept-stat-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    display: block;
}
.dept-stat-label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.dept-attendance-rate {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
}
.dept-progress {
    height: 6px;
    background: rgba(255,255,255,0.05);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 10px;
}
.dept-progress-bar {
    height: 100%;
    background: var(--gradient-primary);
    border-radius: 3px;
    transition: width 1s ease-in-out;
}

/* Calendar Navigation Styles */
.calendar-nav-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 25px;
    background: linear-gradient(135deg, var(--primary-accent), var(--secondary-accent));
    border-radius: 16px 16px 0 0;
    box-shadow: 0 8px 30px rgba(25,211,197,0.3);
}

.calendar-nav-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: #00110f;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
    font-weight: bold;
}

.calendar-nav-btn:hover {
    background: rgba(255,255,255,0.3);
    transform: scale(1.1);
}

.calendar-nav-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.calendar-month-year {
    font-size: 28px;
    font-weight: 800;
    color: #00110f;
    letter-spacing: 2px;
    text-align: center;
    flex: 1;
}

.calendar-subtitle {
    font-size: 13px;
    opacity: 0.9;
    text-align: center;
    margin-top: 4px;
}

.calendar-header-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
}

/* Status Colors */
.schedule-day-card.working { border-bottom: 4px solid #28a745; }
.schedule-day-card.off { border-bottom: 4px solid #6c757d; opacity: 0.7; }
.schedule-day-card.leave { border-bottom: 4px solid #ffc107; background: rgba(255, 193, 7, 0.05); }
.schedule-day-card.today { 
    border: 2px solid var(--primary-accent); 
    box-shadow: 0 0 15px rgba(25, 211, 197, 0.2);
}

.schedule-day-label {
    font-size: 12px;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 700;
    letter-spacing: 1px;
    margin-bottom: 10px;
}

.schedule-day-type {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 5px;
}

.schedule-day-time {
    font-size: 12px;
    color: var(--text-secondary);
    font-family: 'Courier New', monospace;
    margin-bottom: 10px;
}


.schedule-status-badge.working { background: rgba(40, 167, 69, 0.15); color: #28a745; }
.schedule-status-badge.off { background: rgba(108, 117, 125, 0.15); color: #6c757d; }
.schedule-status-badge.leave { background: rgba(255, 193, 7, 0.15); color: #ffc107; }

/* Mobile Responsive */
@media (max-width: 900px) {
    .schedule-calendar-grid { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 600px) {
    .schedule-calendar-grid { grid-template-columns: repeat(2, 1fr); }
    .schedule-day-card { min-height: 120px; }
}
    /* === COOL SCAN MODAL STYLES === */
.scan-modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(10px);
    z-index: 9999; display: none; justify-content: center; align-items: center;
    opacity: 0; transition: opacity 0.3s ease;
}
.scan-modal-overlay.active { display: flex; opacity: 1; }
.scan-modal-card {
    background: linear-gradient(145deg, rgba(6, 26, 31, 0.98), rgba(0, 0, 0, 0.98));
    border: 2px solid var(--primary-accent);
    width: 90%; max-width: 550px; border-radius: 24px; padding: 40px;
    text-align: center; box-shadow: 0 0 50px rgba(25, 211, 197, 0.3);
    transform: scale(0.9); transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative; overflow: hidden;
}
.scan-modal-overlay.active .scan-modal-card { transform: scale(1); }
.scan-modal-icon {
    width: 100px; height: 100px; margin: 0 auto 25px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; font-size: 45px;
    background: rgba(25, 211, 197, 0.1); color: var(--primary-accent);
    border: 2px solid var(--primary-accent);
    box-shadow: 0 0 40px rgba(25, 211, 197, 0.3);
}
.scan-modal-title { font-size: 28px; font-weight: 800; color: #fff; margin-bottom: 5px; }
.scan-modal-subtitle { font-size: 16px; color: var(--text-muted); margin-bottom: 30px; }
.scan-modal-profile {
    background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color);
    border-radius: 16px; padding: 20px; display: flex; align-items: center; gap: 20px;
    margin-bottom: 25px; text-align: left;
}
.scan-modal-pic {
    width: 100px; height: 100px; border-radius: 50%; object-fit: cover;
    border: 3px solid var(--primary-accent); box-shadow: 0 0 20px rgba(25, 211, 197, 0.3);
}
.scan-modal-details h3 { color: #fff; font-size: 24px; margin-bottom: 5px; }
.scan-modal-details p { color: var(--text-muted); font-size: 14px; margin-bottom: 2px; }
.scan-modal-time {
    font-family: 'Courier New', monospace; font-size: 48px; font-weight: 700;
    color: var(--primary-accent); text-shadow: 0 0 20px rgba(25, 211, 197, 0.3);
    letter-spacing: 4px;
}
    /* === LEAVE MANAGEMENT SECTION STYLES === */
.leave-request-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    margin-bottom: 16px;
}

.leave-request-card:hover {
    border-color: rgba(25, 211, 197, 0.3);
    box-shadow: 0 4px 20px rgba(25, 211, 197, 0.1);
    transform: translateY(-2px);
}

.employee-info {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
}

.employee-avatar {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #19D3C5 0%, #00E0D0 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #00110f;
    font-weight: 700;
    font-size: 18px;
    flex-shrink: 0;
}

.employee-details {
    flex: 1;
    min-width: 0;
}

.employee-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.employee-meta {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 8px;
}

.leave-reason {
    font-size: 14px;
    color: var(--text-secondary);
    background: rgba(0,0,0,0.2);
    padding: 8px 12px;
    border-radius: 8px;
    margin-top: 8px;
    border-left: 3px solid var(--primary-accent);
}

.leave-reason-label {
    font-weight: 600;
    color: var(--primary-accent);
    margin-right: 6px;
}

.action-buttons {
    display: flex;
    gap: 12px;
    flex-shrink: 0;
}

.btn-approve, .btn-reject {
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-approve {
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
}

.btn-approve:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
}

.btn-reject {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-reject:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

@media (max-width: 768px) {
    .leave-request-card {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .action-buttons {
        width: 100%;
        justify-content: flex-end;
    }
}
/* === DAY OFF SECTION STYLES === */
.modern-request-card {
    animation: slideIn 0.3s ease;
}
/* === OVERTIME TRACKER STYLES === */
.overtime-request-card {
    background: var(--panel-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    animation: slideIn 0.3s ease;
}
.overtime-request-card:hover {
    border-color: var(--primary-accent);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}
.overtime-request-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px;
    height: 100%;
    background: var(--primary-accent);
}
.ot-hours-badge {
    background: rgba(25, 211, 197, 0.1);
    color: var(--primary-accent);
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 12px;
    display: inline-block;
}
.action-btn-approve, .action-btn-reject {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.action-btn-approve {
    background: rgba(40,167,69,0.15);
    color: #28a745;
    border: 1px solid #28a745;
}
.action-btn-approve:hover {
    background: rgba(40,167,69,0.25);
    transform: translateY(-1px);
}
.action-btn-reject {
    background: rgba(220,53,69,0.15);
    color: #dc3545;
    border: 1px solid #dc3545;
}
.action-btn-reject:hover {
    background: rgba(220,53,69,0.25);
    transform: translateY(-1px);
}
.modern-request-card:hover {
    border-color: var(--primary-accent);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}

.status-badge-modern {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.action-btn-approve:hover {
    background: rgba(40,167,69,0.25) !important;
    transform: translateY(-1px);
}

.action-btn-reject:hover {
    background: rgba(220,53,69,0.25) !important;
    transform: translateY(-1px);
}

.modern-filter-bar {
    background: rgba(255,255,255,0.02);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    border: 1px solid var(--border-color);
}

.modern-select, .modern-input {
    width: 100%;
    padding: 10px 12px;
    background: rgba(0,0,0,0.2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 13px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.stat-card-mini {
    padding: 15px;
    border-radius: 10px;
    border-left: 4px solid;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.overtime-request-card:hover {
    border-color: var(--primary-accent);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}

.overtime-request-card .action-btn-approve:hover {
    background: rgba(40,167,69,0.25) !important;
    transform: translateY(-1px);
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modern-request-card {
    animation: slideIn 0.3s ease;
}
/* === ATTENDANCE MODULE STYLES === */
.attendance-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.2); }
}

.live-indicator {
    animation: pulse 2s infinite;
}
.attendance-stat-card {
    background: var(--panel-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: transform 0.2s, box-shadow 0.2s;
}

.attendance-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    border-color: var(--primary-accent);
}

.stat-icon-box {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.stat-icon-box.present { background: rgba(40, 167, 69, 0.1); color: #28a745; }
.stat-icon-box.late { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
.stat-icon-box.absent { background: rgba(220, 53, 69, 0.1); color: #dc3545; }

.stat-info h4 {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0 0 5px 0;
    font-weight: 500;
}

.stat-info .count {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1;
}

.attendance-controls {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
    flex-wrap: wrap;
    background: rgba(255,255,255,0.02);
    padding: 15px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
}

.modern-search-input {
    flex: 1;
    min-width: 250px;
    background: rgba(0,0,0,0.2);
    border: 1px solid var(--border-color);
    padding: 10px 15px;
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
}

.modern-search-input:focus {
    outline: none;
    border-color: var(--primary-accent);
}

.attendance-list-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.modern-attendance-card {
    background: var(--panel-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 15px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.2s;
}

.modern-attendance-card:hover {
    border-color: var(--primary-accent);
    background: rgba(25, 211, 197, 0.03);
}

.emp-identity {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.emp-avatar-small {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary-accent);
    color: #00110f;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}

.emp-details h5 {
    margin: 0;
    font-size: 15px;
    color: var(--text-primary);
}

.emp-details span {
    font-size: 12px;
    color: var(--text-muted);
}

.attendance-meta {
    display: flex;
    align-items: center;
    gap: 20px;
}

.time-badge {
    font-size: 13px;
    color: var(--text-secondary);
    font-family: 'Courier New', monospace;
    font-weight: 600;
}

.status-pill {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 100px;
    justify-content: center;
}

.status-pill.present { background: rgba(40, 167, 69, 0.15); color: #28a745; }
.status-pill.late { background: rgba(255, 193, 7, 0.15); color: #ffc107; }
.status-pill.absent { background: rgba(220, 53, 69, 0.15); color: #dc3545; }

@media (max-width: 768px) {
    .modern-attendance-card { flex-direction: column; align-items: flex-start; gap: 15px; }
    .attendance-meta { width: 100%; justify-content: space-between; }
}
/* === MODERN LANDING PAGE STYLES === */
body.landing-page {
    background: #0f172a;
    background-image: 
        radial-gradient(at 0% 0%, rgba(25, 211, 197, 0.15) 0px, transparent 50%),
        radial-gradient(at 100% 0%, rgba(37, 99, 235, 0.15) 0px, transparent 50%),
        radial-gradient(at 100% 100%, rgba(25, 211, 197, 0.15) 0px, transparent 50%),
        radial-gradient(at 0% 100%, rgba(37, 99, 235, 0.15) 0px, transparent 50%);
    background-attachment: fixed;
    color: #ffffff;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    overflow-x: hidden;
}

/* Animated Background Orbs */
.bg-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    z-index: 0;
    opacity: 0.4;
    animation: floatOrb 20s infinite ease-in-out;
}
.orb-1 { width: 400px; height: 400px; background: #19D3C5; top: -100px; left: -100px; }
.orb-2 { width: 300px; height: 300px; background: #2563EB; bottom: -50px; right: -50px; animation-delay: -5s; }
.orb-3 { width: 200px; height: 200px; background: #7C3AED; top: 40%; left: 60%; animation-delay: -10s; }

@keyframes floatOrb {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(30px, 50px); }
}

/* Glassmorphism Container */
.landing-container {
    position: relative;
    z-index: 10;
    max-width: 1200px;
    margin: 0 auto;
    padding: 40px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
}

/* Hero Section */
.hero-section {
    text-align: center;
    max-width: 800px;
    margin-bottom: 60px;
    animation: fadeInUp 0.8s ease-out;
}

.hero-title {
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 20px;
    background: linear-gradient(135deg, #ffffff 0%, #19D3C5 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: -1px;
    line-height: 1.2;
}

.hero-subtitle {
    font-size: 1.25rem;
    color: #94a3b8;
    margin-bottom: 40px;
    line-height: 1.6;
}

/* Feature Cards Grid */
.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    width: 100%;
    margin-bottom: 60px;
    animation: fadeInUp 1s ease-out 0.2s backwards;
}

.feature-card {
    background: rgba(255, 255, 255, 0.03);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 20px;
    padding: 30px;
    transition: all 0.3s ease;
    text-align: left;
}

.feature-card:hover {
    background: rgba(255, 255, 255, 0.06);
    border-color: rgba(25, 211, 197, 0.3);
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.feature-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #19D3C5 0%, #0d9488 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #0f172a;
    margin-bottom: 20px;
}

.feature-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #ffffff;
    margin-bottom: 10px;
}

.feature-desc {
    font-size: 0.95rem;
    color: #94a3b8;
    line-height: 1.5;
}

/* === REPLACE YOUR EXISTING LOGIN STYLES WITH THIS === */

/* === MODERN LOGIN MODAL STYLES === */
.login-modal-container {
position: fixed;
top: 0; left: 0; width: 100%; height: 100%;
background: rgba(15, 23, 42, 0.85);
backdrop-filter: blur(12px);
z-index: 2000;
display: none;
align-items: center;
justify-content: center;
animation: fadeIn 0.3s ease;
}

.login-box {
background: rgba(30, 41, 59, 0.7);
border: 1px solid rgba(255, 255, 255, 0.1);
border-radius: 24px;
padding: 40px;
width: 100%;
max-width: 420px;
box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
transform: translateY(0) scale(1);
opacity: 1;
transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
position: relative;
overflow: hidden;
}

.login-box::before {
content: '';
position: absolute;
top: -50%; left: -50%;
width: 200%; height: 200%;
background: radial-gradient(circle, rgba(25,211,197,0.08) 0%, transparent 70%);
animation: rotateGlow 10s linear infinite;
z-index: -1;
pointer-events: none;
}

@keyframes rotateGlow {
0% { transform: rotate(0deg); }
100% { transform: rotate(360deg); }
}

.form-group-modern {
margin-bottom: 24px;
position: relative;
text-align: left;
}

.form-input-modern {
width: 100%;
background: rgba(15, 23, 42, 0.6);
border: 1px solid rgba(255, 255, 255, 0.1);
border-radius: 12px;
padding: 16px;
color: #ffffff;
font-size: 15px;
transition: all 0.3s ease;
box-sizing: border-box;
outline: none;
}

.form-input-modern:focus {
border-color: #19D3C5;
background: rgba(15, 23, 42, 0.8);
box-shadow: 0 0 0 4px rgba(25, 211, 197, 0.1);
transform: translateY(-2px);
}

.form-label-modern {
position: absolute;
left: 16px;
top: 16px;
color: #94a3b8;
font-size: 15px;
pointer-events: none;
transition: all 0.3s ease;
background: transparent;
padding: 0 4px;
}

.form-input-modern:focus ~ .form-label-modern,
.form-input-modern:not(:placeholder-shown) ~ .form-label-modern,
.form-input-modern.has-value ~ .form-label-modern {
top: -10px;
left: 12px;
font-size: 12px;
background: #1e293b;
color: #19D3C5;
border-radius: 4px;
font-weight: 600;
}

.btn-primary-glow {
width: 100%;
background: linear-gradient(135deg, #19D3C5 0%, #0d9488 100%);
color: #0f172a;
border: none;
border-radius: 12px;
padding: 16px;
font-size: 16px;
font-weight: 700;
cursor: pointer;
transition: all 0.3s ease;
box-shadow: 0 4px 15px rgba(25, 211, 197, 0.3);
margin-top: 10px;
display: flex;
align-items: center;
justify-content: center;
}

.btn-primary-glow:hover {
transform: translateY(-3px);
box-shadow: 0 8px 25px rgba(25, 211, 197, 0.5);
}

.close-modal-btn {
position: absolute;
top: 20px;
right: 20px;
background: transparent;
border: none;
color: #94a3b8;
font-size: 24px;
cursor: pointer;
transition: color 0.3s;
}

.close-modal-btn:hover {
color: #ffffff;
}

@keyframes fadeIn {
from { opacity: 0; }
to { opacity: 1; }
}

/* Floating Input Groups */
.form-group-modern {
    margin-bottom: 24px;
    position: relative;
    text-align: left;
}

.form-input-modern {
    width: 100%;
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 16px 16px 16px 16px;
    color: #ffffff;
    font-size: 15px;
    transition: all 0.3s ease;
    box-sizing: border-box;
    outline: none;
}

.form-input-modern:focus {
    border-color: #19D3C5;
    background: rgba(15, 23, 42, 0.8);
    box-shadow: 0 0 0 4px rgba(25, 211, 197, 0.1);
    transform: translateY(-2px);
}

.form-label-modern {
    position: absolute;
    left: 16px;
    top: 16px;
    color: #94a3b8;
    font-size: 15px;
    pointer-events: none;
    transition: all 0.3s ease;
    background: transparent;
    padding: 0 4px;
}

.form-input-modern:focus ~ .form-label-modern,
.form-input-modern:not(:placeholder-shown) ~ .form-label-modern,
.form-input-modern.has-value ~ .form-label-modern {
    top: -10px;
    left: 12px;
    font-size: 12px;
    background: #1e293b;
    color: #19D3C5;
    border-radius: 4px;
    font-weight: 600;
}

.btn-primary-glow {
    width: 100%;
    background: linear-gradient(135deg, #19D3C5 0%, #0d9488 100%);
    color: #0f172a;
    border: none;
    border-radius: 12px;
    padding: 16px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(25, 211, 197, 0.3);
    margin-top: 10px;
}

.btn-primary-glow:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(25, 211, 197, 0.5);
}

.close-modal-btn {
    position: absolute;
    top: 20px;
    right: 20px;
    background: transparent;
    border: none;
    color: #94a3b8;
    font-size: 24px;
    cursor: pointer;
    transition: color 0.3s;
}

.close-modal-btn:hover {
    color: #ffffff;
}

/* Animations */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Responsive */
@media (max-width: 768px) {
    .hero-title { font-size: 2.5rem; }
    .features-grid { grid-template-columns: 1fr; }
    .login-box { padding: 30px 20px; margin: 20px; }
}
/* Ensure all backgrounds become WHITE in light mode */
[data-theme="light"] .sidebar,
[data-theme="light"] .main-content,
[data-theme="light"] .top-bar,
[data-theme="light"] .stat-card,
[data-theme="light"] .feed-section,
[data-theme="light"] .dept-card,
[data-theme="light"] .alert-card,
[data-theme="light"] .modal-container,
[data-theme="light"] .scan-item,
[data-theme="light"] .emp-table-container,
[data-theme="light"] .card,
[data-theme="light"] .admin-section,
[data-theme="light"] .record-item,
[data-theme="light"] .modal-content,
[data-theme="light"] .employee-table,
[data-theme="light"] .attendance-card,
[data-theme="light"] .requests-card,
[data-theme="light"] .quick-info-card {
background: #ffffff !important;
border-color: rgba(0, 0, 0, 0.12) !important;
}

/* Ensure text is DARK in light mode for proper contrast */
[data-theme="light"] .stat-value,
[data-theme="light"] .scan-name,
[data-theme="light"] .modal-title,
[data-theme="light"] .section-title,
[data-theme="light"] .section-subtitle,
[data-theme="light"] .info-value,
[data-theme="light"] .info-label,
[data-theme="light"] .record-name,
[data-theme="light"] .employee-name,
[data-theme="light"] .time-value,
[data-theme="light"] .date-value {
color: #0f172a !important;
}

[data-theme="light"] .text-muted,
[data-theme="light"] .info-title,
[data-theme="light"] .record-id {
color: #64748b !important;
}
/* === GLOBAL LIGHT MODE OVERRIDES === */
[data-theme="light"] body {
    background: var(--gradient-bg) !important;
    color: var(--text-primary) !important;
}


[data-theme="light"] .admin-nav,
[data-theme="light"] .header,
[data-theme="light"] .admin-header {
background: #ffffff !important;
border-bottom: 1px solid rgba(0, 0, 0, 0.12) !important;
box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
}

[data-theme="light"] input,
[data-theme="light"] select,
[data-theme="light"] textarea {
background: #f8fafc !important;
border: 1px solid rgba(0, 0, 0, 0.15) !important;
color: #0f172a !important;
}

[data-theme="light"] input:focus,
[data-theme="light"] select:focus,
[data-theme="light"] textarea:focus {
background: #ffffff !important;
border-color: #0ea5e9 !important;
}

[data-theme="light"] .nav-tab {
background: #f8fafc !important;
color: #334155 !important;
}

[data-theme="light"] .nav-tab.active {
background: #0ea5e9 !important;
color: #ffffff !important;
}

[data-theme="light"] .nav-tab:hover {
background: #f1f5f9 !important;
}

[data-theme="light"] .stat-card {
background: #ffffff !important;
border: 1px solid rgba(0, 0, 0, 0.12) !important;
box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
}

[data-theme="light"] .record-item {
background: #ffffff !important;
border: 1px solid rgba(0, 0, 0, 0.12) !important;
}

[data-theme="light"] .record-item:hover {
border-color: #0ea5e9 !important;
box-shadow: 0 4px 12px rgba(14, 165, 233, 0.15) !important;
}

[data-theme="light"] .modal-content {
background: #ffffff !important;
border: 1px solid rgba(0, 0, 0, 0.15) !important;
box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12) !important;
}

[data-theme="light"] .employee-table th {
background: #f8fafc !important;
color: #334155 !important;
border-bottom: 2px solid rgba(0, 0, 0, 0.12) !important;
}

[data-theme="light"] .employee-table td {
background: #ffffff !important;
color: #0f172a !important;
border-bottom: 1px solid rgba(0, 0, 0, 0.08) !important;
}

[data-theme="light"] .employee-table tr:hover {
background: #f8fafc !important;
}

[data-theme="light"] .filter-select,
[data-theme="light"] .search-input,
[data-theme="light"] .modern-input,
[data-theme="light"] .modern-select {
background: #f8fafc !important;
border: 1px solid rgba(0, 0, 0, 0.15) !important;
color: #0f172a !important;
}

[data-theme="light"] .filter-select:focus,
[data-theme="light"] .search-input:focus,
[data-theme="light"] .modern-input:focus,
[data-theme="light"] .modern-select:focus {
background: #ffffff !important;
border-color: #0ea5e9 !important;
}

[data-theme="light"] .action-button.secondary {
background: #f8fafc !important;
border: 1px solid rgba(0, 0, 0, 0.15) !important;
color: #0ea5e9 !important;
}

[data-theme="light"] .action-button.secondary:hover {
background: #f1f5f9 !important;
border-color: #0ea5e9 !important;
}

[data-theme="light"] .modern-filter-bar {
background: #f8fafc !important;
border: 1px solid rgba(0, 0, 0, 0.12) !important;
}

[data-theme="light"] .department-card,
[data-theme="light"] .dept-card,
[data-theme="light"] .overtime-request-card,
[data-theme="light"] .leave-request-card,
[data-theme="light"] .modern-request-card,
[data-theme="light"] .historical-record-card {
background: #ffffff !important;
border: 1px solid rgba(0, 0, 0, 0.12) !important;
box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
}

[data-theme="light"] .schedule-day-card {
background: #ffffff !important;
border: 1px solid rgba(0, 0, 0, 0.12) !important;
}

[data-theme="light"] .ai-panel {
background: #ffffff !important;
border: 1px solid rgba(0, 0, 0, 0.15) !important;
box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12) !important;
}

[data-theme="light"] .chat-messages {
background: #ffffff !important;
}

[data-theme="light"] .message-assistant {
background: #f1f5f9 !important;
border: 1px solid rgba(0, 0, 0, 0.08) !important;
color: #0f172a !important;
}

[data-theme="light"] .message-user {
background: #0ea5e9 !important;
color: #ffffff !important;
}

[data-theme="light"] .system-footer {
background: #ffffff !important;
border-top: 1px solid rgba(0, 0, 0, 0.12) !important;
}

[data-theme="light"] .footer-info {
color: #64748b !important;
}

[data-theme="light"] .footer-btn {
border-color: #0ea5e9 !important;
color: #0ea5e9 !important;
}

[data-theme="light"] .footer-btn:hover {
background: #0ea5e9 !important;
color: #ffffff !important;
}
:root {
--bg-dark: #000000;
--bg-teal-overlay: #061A1F;
--primary-accent: #19D3C5;
--secondary-accent: #00E0D0;
--text-primary: #FFFFFF;
--text-secondary: #C9D1D9;
--text-muted: #9AA4AF;
--gradient-primary: linear-gradient(135deg, #19D3C5 0%, #00E0D0 100%);
--gradient-bg: linear-gradient(135deg, #000000 0%, #061A1F 100%);
--panel-bg: rgba(6, 26, 31, 0.5);
--border-color: rgba(25, 211, 197, 0.2);
--shadow-glow: 0 0 20px rgba(25, 211, 197, 0.3);
--shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.3);
--font-stack: 'Segoe UI Variable', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}


/* ========================================
   COMPLETE LIGHT MODE THEME - ALL MODULES
   ======================================== */
[data-theme="light"] {
    --bg-dark: #f8fafc;
    --bg-teal-overlay: #ffffff;
    --primary-accent: #0891b2;
    --secondary-accent: #0e7490;
    --text-primary: #0f172a;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --gradient-primary: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
    --gradient-bg: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    --panel-bg: #ffffff;
    --border-color: rgba(0, 0, 0, 0.12);
    --shadow-glow: 0 0 20px rgba(8, 145, 178, 0.15);
    --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
    --card-border: 1px solid rgba(0, 0, 0, 0.1);
    --hover-bg: rgba(8, 145, 178, 0.06);
}

body.landing-page, body.dashboard-mode {
background: var(--gradient-bg);
color: var(--text-primary);
min-height: 100vh;
overflow-x: hidden;
transition: background 0.3s ease, color 0.3s ease;
}
* { margin: 0; padding: 0; box-sizing: border-box; font-family: var(--font-stack); }
body {
background: var(--gradient-bg);
min-height: 100vh;
padding: 0;
margin: 0;
color: var(--text-primary);
font-family: var(--font-stack);
-webkit-font-smoothing: antialiased;
transition: background 0.3s ease, color 0.3s ease;
}
body.dashboard-mode {
display: flex;
justify-content: center;
align-items: center;
padding: 20px;
}
#employeeDropdown {
display: none;
position: absolute;
top: 100%;
right: 0;
background: var(--panel-bg);
border: 1px solid var(--border-color);
border-radius: 8px;
z-index: 1000;
min-width: 220px;
box-shadow: var(--shadow-sm);
max-height: none !important;
height: auto !important;
overflow: visible !important;
padding: 5px 0;
margin-top: 5px;
}
#employeeDropdown .nav-tab {
justify-content: center; /* Changed from flex-start to center */
text-align: center; /* Add this to ensure text is centered */
border-radius: 0;
border-bottom: 1px solid rgba(255,255,255,0.05);
padding: 12px 20px;
white-space: nowrap;
}

#employeeDropdown .nav-tab:last-child {
border-bottom: none;
}
#employeeDropdown .nav-tab:hover {
background: rgba(25, 211, 197, 0.1);
}
.admin-nav div[style*="position: relative"] {
overflow: visible !important;
}
#loginPage, #employeePortal, #adminDashboard {
width: 100%;
max-width: 1200px;
margin: 0 auto;
text-align: left;
}
[data-theme="dark"] {
background: #0f0f1e !important;
color: #e8eaf0;
}
[data-theme="dark"] .login-container,
[data-theme="dark"] .card,
[data-theme="dark"] .modal-content,
[data-theme="dark"] .admin-section,
[data-theme="dark"] input,
[data-theme="dark"] select,
[data-theme="dark"] textarea,
[data-theme="dark"] .btn {
background: #1a1a2e !important;
color: #e8eaf0 !important;
border-color: #404060 !important;
}
[data-theme="dark"] .record-item {
background: linear-gradient(135deg, #2d2d44 0%, #1a1a2e 100%) !important;
border-color: #404060 !important;
}
[data-theme="dark"] .employee-table th,
[data-theme="dark"] .employee-table td {
background: #1a1a2e !important;
color: #e8eaf0 !important;
border-color: #404060 !important;
}
[data-theme="dark"] .header,
[data-theme="dark"] .admin-header,
[data-theme="dark"] .nav-tab {
background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%) !important;
box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4) !important;
}
[data-theme="dark"] .notification-dropdown,
[data-theme="dark"] .modal-content {
background: #1a1a2e !important;
border: 1px solid #404060 !important;
}
[data-theme="dark"] .record-status.present {
background: #1e4620 !important;
color: #7fff7f !important;
}
[data-theme="dark"] .record-status.absent {
background: #4d1a1a !important;
color: #ff9999 !important;
}
[data-theme="dark"] .record-status.late {
background: #4d4d1a !important;
color: #ffff99 !important;
}
.landing-nav {
display: flex;
justify-content: space-between;
align-items: center;
padding: 20px 40px;
background: rgba(6, 26, 31, 0.4);
backdrop-filter: blur(10px);
border-bottom: 1px solid var(--border-color);
position: sticky;
top: 0;
z-index: 1000;
}
.landing-nav-brand {
font-size: 24px;
font-weight: 700;
background: var(--gradient-primary);
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
background-clip: text;
letter-spacing: -1px;
}
.landing-nav-menu {
display: flex;
gap: 30px;
align-items: center;
}
.landing-nav-menu a {
color: var(--text-secondary);
text-decoration: none;
font-size: 14px;
font-weight: 500;
transition: color 0.3s ease;
}
.landing-nav-menu a:hover {
color: var(--primary-accent);
}
.landing-hero {
text-align: center;
padding: 100px 40px;
position: relative;
overflow: hidden;
}
.landing-hero::before {
content: '';
position: absolute;
top: -50%;
left: -50%;
width: 200%;
height: 200%;
background: radial-gradient(circle at 50% 50%, rgba(25, 211, 197, 0.05) 0%, transparent 70%);
animation: float 20s ease-in-out infinite;
z-index: -1;
}
@keyframes float {
0%, 100% { transform: translate(0, 0); }
50% { transform: translate(-30px, 30px); }
}
.landing-hero-heading {
font-size: 48px;
font-weight: 800;
line-height: 1.2;
margin-bottom: 20px;
letter-spacing: -1px;
}
.landing-hero-heading span {
background: var(--gradient-primary);
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
background-clip: text;
}
.landing-hero-subheading {
font-size: 18px;
color: var(--text-secondary);
margin-bottom: 40px;
max-width: 600px;
margin-left: auto;
margin-right: auto;
line-height: 1.6;
}
.landing-hero-buttons {
display: flex;
gap: 20px;
justify-content: center;
flex-wrap: wrap;
}
.btn-primary-saas {
background: var(--gradient-primary);
color: #00110f;
padding: 14px 28px;
border: none;
border-radius: 10px;
font-weight: 700;
font-size: 14px;
cursor: pointer;
transition: all 0.3s ease;
box-shadow: 0 0 30px rgba(25, 211, 197, 0.4);
text-decoration: none;
display: inline-block;
}
.btn-primary-saas:hover {
transform: translateY(-2px);
box-shadow: 0 0 40px rgba(25, 211, 197, 0.6);
}
.btn-outline-saas {
background: transparent;
color: var(--primary-accent);
padding: 14px 28px;
border: 2px solid var(--primary-accent);
border-radius: 10px;
font-weight: 700;
font-size: 14px;
cursor: pointer;
transition: all 0.3s ease;
text-decoration: none;
display: inline-block;
}
.btn-outline-saas:hover {
background: rgba(25, 211, 197, 0.1);
box-shadow: 0 0 20px rgba(25, 211, 197, 0.3);
transform: translateY(-2px);
}
.landing-features {
padding: 100px 40px;
max-width: 1200px;
margin: 0 auto;
}
.landing-features-title {
font-size: 36px;
font-weight: 800;
text-align: center;
margin-bottom: 60px;
letter-spacing: -1px;
}
.landing-features-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
gap: 30px;
}
.feature-card {
background: var(--panel-bg);
border: 1px solid var(--border-color);
border-radius: 12px;
padding: 30px;
transition: all 0.3s ease;
position: relative;
overflow: hidden;
}
.feature-card::before {
content: '';
position: absolute;
top: 0;
left: 0;
right: 0;
height: 1px;
background: var(--gradient-primary);
opacity: 0;
transition: opacity 0.3s ease;
}
.feature-card:hover {
border-color: var(--primary-accent);
background: rgba(25, 211, 197, 0.05);
box-shadow: var(--shadow-glow);
transform: translateY(-5px);
}
.feature-card:hover::before {
opacity: 1;
}
.feature-icon {
font-size: 32px;
margin-bottom: 15px;
background: var(--gradient-primary);
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
background-clip: text;
}
.feature-title {
font-size: 18px;
font-weight: 700;
margin-bottom: 12px;
color: var(--text-primary);
}
.feature-description {
font-size: 14px;
color: var(--text-secondary);
line-height: 1.6;
}
.landing-cta {
background: rgba(25, 211, 197, 0.05);
border: 1px solid var(--border-color);
border-radius: 16px;
padding: 60px 40px;
text-align: center;
margin: 60px 40px;
max-width: 1200px;
margin-left: auto;
margin-right: auto;
}
.landing-cta-heading {
font-size: 32px;
font-weight: 800;
margin-bottom: 20px;
}
.landing-cta-text {
font-size: 16px;
color: var(--text-secondary);
margin-bottom: 30px;
}
.landing-footer {
background: rgba(6, 26, 31, 0.8);
border-top: 1px solid var(--border-color);
padding: 40px;
text-align: center;
color: var(--text-muted);
font-size: 14px;
}
.landing-footer a {
color: var(--primary-accent);
text-decoration: none;
transition: color 0.3s ease;
}
.landing-footer a:hover {
color: var(--secondary-accent);
}
@media (max-width: 768px) {
.landing-nav { padding: 15px 20px; }
.landing-nav-menu { gap: 15px; }
.landing-hero { padding: 50px 20px; }
.landing-hero-heading { font-size: 32px; }
.landing-hero-subheading { font-size: 16px; }
.landing-features { padding: 50px 20px; }
.landing-cta { margin: 40px 20px; padding: 40px 20px; }
}
.login-container input,
.login-container select,
.login-container textarea {
background: rgba(255,255,255,0.08) !important;
border: 1px solid var(--border-color) !important;
color: var(--text-primary) !important;
border-radius: 8px;
padding: 10px 14px;
font-size: 14px;
transition: all 0.3s ease;
}
.login-container input::placeholder,
.login-container textarea::placeholder {
color: var(--text-muted);
}
.login-container input:focus,
.login-container select:focus,
.login-container textarea:focus {
border-color: var(--primary-accent) !important;
background: rgba(25, 211, 197, 0.1) !important;
box-shadow: 0 0 20px rgba(25, 211, 197, 0.2);
outline: none;
}
.login-container label {
color: var(--text-secondary);
font-size: 13px;
font-weight: 600;
margin-bottom: 6px;
display: block;
}
.login-container .form-group {
margin-bottom: 16px;
}
.login-container .demo-btn {
background: rgba(25, 211, 197, 0.15) !important;
border: 1px solid var(--border-color) !important;
color: var(--primary-accent) !important;
border-radius: 8px;
padding: 8px 16px;
font-size: 12px;
font-weight: 600;
cursor: pointer;
transition: all 0.3s ease;
}
.login-container .demo-btn:hover {
background: rgba(25, 211, 197, 0.25) !important;
box-shadow: 0 0 20px rgba(25, 211, 197, 0.2);
}
.notification-bell {
position: relative;
cursor: pointer;
font-size: 18px;
color: var(--text-muted);
margin-right: 12px;
}
[data-theme="dark"] .notification-bell {
color: var(--text-muted);
}
.notification-badge {
position: absolute;
top: -5px;
right: -5px;
background: #ff4757;
color: var(--text-primary);
font-size: 10px;
font-weight: bold;
width: 18px;
height: 18px;
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
animation: pulse 1.5s infinite;
}
@keyframes pulse {
0% { box-shadow: 0 0 0 0 rgba(255, 71, 87, 0.7); }
70% { box-shadow: 0 0 0 8px rgba(255, 71, 87, 0); }
100% { box-shadow: 0 0 0 0 rgba(255, 71, 87, 0); }
}
.notification-dropdown {
position: absolute;
top: 40px;
right: 0;
width: 320px;
background: var(--panel-bg);
border-radius: 12px;
box-shadow: var(--shadow-sm);
z-index: 1000;
display: none;
max-height: 400px;
overflow-y: auto;
}
.notification-header {
padding: 12px 16px;
border-bottom: 1px solid var(--border-color);
font-weight: 600;
color: var(--text-primary);
}
.notification-item {
padding: 12px 16px;
border-bottom: 1px solid rgba(255,255,255,0.03);
font-size: 14px;
line-height: 1.4;
cursor: pointer;
transition: background 0.2s;
}
.notification-item:hover {
background: rgba(255,255,255,0.05);
}
.notification-item:last-child {
border-bottom: none;
}
.notification-empty {
padding: 20px;
text-align: center;
color: var(--text-muted);
font-style: italic;
}
#toast-container {
position: fixed;
top: 20px;
right: 20px;
z-index: 9999;
display: flex;
flex-direction: column;
gap: 10px;
}
.toast {
min-width: 300px;
padding: 16px;
border-radius: 8px;
background: #1a1a2e;
color: #fff;
box-shadow: 0 4px 12px rgba(0,0,0,0.3);
border-left: 4px solid var(--primary-accent);
display: flex;
align-items: center;
justify-content: space-between;
animation: slideIn 0.3s ease-out;
}
.toast.success { border-left-color: #28a745; }
.toast.error { border-left-color: #dc3545; }
.toast.warning { border-left-color: #ffc107; }
@keyframes slideIn {
from { transform: translateX(100%); opacity: 0; }
to { transform: translateX(0); opacity: 1; }
}
.toast-close {
background: none;
border: none;
color: #fff;
cursor: pointer;
font-size: 18px;
margin-left: 10px;
}
.login-container {
background: var(--panel-bg);
border-radius: 16px;
box-shadow: var(--shadow-sm);
padding: 20px;
text-align: center;
margin: 0 auto;
}
.logo {
color: var(--primary-accent);
font-size: 32px;
font-weight: bold;
letter-spacing: 2px;
margin-bottom: 10px;
}
.app-title {
font-size: 20px;
color: var(--text-primary);
margin-bottom: 5px;
}
.app-subtitle {
font-size: 14px;
color: var(--text-muted);
margin-bottom: 20px;
}
.form-group {
margin-bottom: 20px;
text-align: left;
}
label {
display: block;
margin-bottom: 5px;
color: #555;
font-weight: 500;
}
input, select, textarea {
width: 100%;
padding: 12px;
border: 1px solid var(--border-color);
border-radius: 8px;
font-size: 14px;
}
input:focus, select:focus, textarea:focus {
outline: none;
border-color: #a5f7e2;
box-shadow: 0 0 0 2px rgba(0, 200, 151, 0.1);
}
.btn {
width: 100%;
padding: 12px;
border: none;
border-radius: 8px;
cursor: pointer;
font-size: 16px;
font-weight: 600;
transition: background-color 0.3s;
}
.btn-primary {
background: var(--primary-gradient);
color: var(--text-primary);
box-shadow: 0 6px 18px var(--shadow-prim);
}
.btn-primary:hover {
background: linear-gradient(135deg, #29b66a 0%, #bbffea 100%);
}
.demo-section {
margin-top: 20px;
padding-top: 20px;
border-top: 1px solid var(--border-color);
}
.demo-title {
font-size: 14px;
color: var(--text-muted);
margin-bottom: 10px;
}
.demo-buttons {
display: flex;
gap: 10px;
justify-content: center;
}
.demo-btn {
padding: 6px 12px;
border: 1px solid var(--primary-accent);
border-radius: 6px;
font-size: 12px;
color: var(--primary-accent);
background: var(--panel-bg);
cursor: pointer;
}
.demo-btn:hover {
background: rgba(25,211,197,0.06);
}
.dashboard, .admin-dashboard {
display: none;
width: 100%;
max-width: 1200px;
margin: 0 auto;
padding: 20px;
background: var(--page-bg);
border-radius: 16px;
}
[data-theme="dark"] .dashboard,
[data-theme="dark"] .admin-dashboard {
background: linear-gradient(135deg, #0f0f1e 0%, #1a1a2e 100%);
}
.header, .admin-header {
display: flex;
justify-content: space-between;
align-items: center;
padding: 20px 25px;
background: var(--primary-gradient);
border-radius: 16px;
box-shadow: 0 10px 30px var(--shadow-prim);
margin-bottom: 25px;
flex-wrap: wrap;
gap: 20px;
color: var(--text-primary);
}
.header-left, .admin-header-left {
display: flex;
align-items: center;
gap: 18px;
flex-wrap: wrap;
}
.header-logo, .admin-header-logo {
color: var(--text-primary);
font-size: 28px;
font-weight: 700;
margin-right: 0;
filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
}
.header-title, .admin-header-title {
font-size: 22px;
font-weight: 700;
color: var(--text-primary);
letter-spacing: -0.5px;
}
.admin-header-subtitle {
font-size: 13px;
color: rgba(255,255,255,0.85);
font-weight: 500;
margin-top: 4px;
}
.header-user, .admin-header-user {
display: flex;
align-items: center;
gap: 12px;
}
[data-theme="dark"] .header,
[data-theme="dark"] .admin-header {
background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
}
.user-avatar, .admin-user-avatar {
width: 50px;  /* Increased from 30px */
    height: 50px; /* Increased from 30px */
    border-radius: 50%;
    background: #00c897;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    overflow: hidden;
}
/* === AI TYPING INDICATOR === */
.typing-indicator {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 12px 16px;
}
.typing-indicator .dot {
    width: 6px;
    height: 6px;
    background: var(--text-muted);
    border-radius: 50%;
    animation: bounce 1.4s infinite ease-in-out both;
}
.typing-indicator .dot:nth-child(1) { animation-delay: -0.32s; }
.typing-indicator .dot:nth-child(2) { animation-delay: -0.16s; }

@keyframes bounce {
    0%, 80%, 100% { transform: scale(0); }
    40% { transform: scale(1); }
}
/* === AI CHAT DATA CARD STYLES === */
.ai-data-card {
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    padding: 10px;
    margin-top: 8px;
    width: 100%;
    box-sizing: border-box;
}

.ai-list-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 13px;
}

.ai-list-row:last-child {
    border-bottom: none;
}

.ai-name {
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.ai-name i {
    font-size: 10px;
    color: var(--primary-accent);
}

.ai-dept {
    font-size: 11px;
    color: var(--text-muted);
    background: rgba(255, 255, 255, 0.05);
    padding: 2px 8px;
    border-radius: 4px;
}
.user-info, .admin-user-info {
text-align: left;
}
.user-name, .admin-user-name {
font-weight: 600;
color: var(--text-primary);
}
.user-role, .admin-user-role {
font-size: 12px;
color: var(--text-muted);
}
.logout-btn {
padding: 8px 16px;
border: 2px solid var(--primary-accent);
border-radius: 10px;
font-size: 12px;
font-weight: 600;
cursor: pointer;
background: transparent;
color: var(--primary-accent);
transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
display: inline-flex;
align-items: center;
gap: 6px;
}
.logout-btn:hover {
background: var(--primary-accent);
color: #00110f;
transform: translateY(-2px);
}
#moduleSelector {
padding: 10px 10px;
border-radius: 8px;
font-weight: 600;
background: var(--panel-bg);
border: 1px solid var(--border-color);
min-width: 140px;
appearance: none;
background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
background-repeat: no-repeat;
background-position: right 10px center;
background-size: 12px;
padding-right: 36px;
font-size: 14px;
color: var(--text-primary) !important;
 min-width: 150px
}
#moduleSelector option {
    background: var(--panel-bg);
    color: var(--text-primary);
}
.main-content {
display: grid;
grid-template-columns: 1fr;
gap: 20px;
}
.card {
background: var(--panel-bg);
border-radius: 12px;
padding: 20px;
box-shadow: var(--shadow-sm);
}
.time-card {
text-align: center;
padding: 25px;
background: linear-gradient(135deg, rgba(25,211,197,0.04) 0%, rgba(0,224,208,0.02) 100%);
border-radius: 12px;
box-shadow: var(--shadow-sm);
}
.time-icon {
width: 40px;
height: 40px;
border-radius: 50%;
background: rgba(25,211,197,0.08);
}
.time-label {
font-size: 16px;
color: var(--text-secondary);
margin-bottom: 10px;
}
.time-value {
font-size: 36px;
font-weight: bold;
color: var(--primary-accent);
margin-bottom: 5px;
font-family: 'Courier New', monospace;
letter-spacing: 2px;
}
.date-value {
font-size: 14px;
color: var(--text-muted);
font-weight: 500;
}
.info-card {
display: grid;
grid-template-columns: repeat(2, 1fr);
gap: 20px;
align-items: start;
}
.info-section {
background: var(--panel-bg);
padding: 15px;
border-radius: 8px;
display: flex;
flex-direction: column;
height: 100%;
}
.info-title {
font-size: 14px;
color: var(--text-muted);
margin-bottom: 10px;
}
.info-label {
font-size: 12px;
color: var(--text-muted);
margin-bottom: 5px;
}
.info-value {
font-size: 14px;
font-weight: 600;
color: var(--text-primary);
}
.status-badge {
padding: 2px 6px;
border-radius: 4px;
font-size: 10px;
font-weight: 600;
background: rgba(255,255,255,0.03);
color: var(--text-secondary);
}
.attendance-card {
padding: 20px;
}
.attendance-header {
display: flex;
justify-content: space-between;
align-items: flex-start;
margin-bottom: 15px;
flex-wrap: wrap;
gap: 10px;
}
.attendance-title {
font-size: 18px;
font-weight: 600;
color: var(--text-primary);
}
.attendance-subtitle {
font-size: 12px;
color: var(--text-muted);
}
.attendance-buttons {
display: flex;
gap: 10px;
margin-bottom: 20px;
flex-wrap: wrap;
}
.attendance-btn {
flex: 1;
min-width: 120px;
padding: 12px;
border: 1px solid var(--border-color);
border-radius: 8px;
font-size: 14px;
display: flex;
align-items: center;
justify-content: center;
gap: 8px;
cursor: pointer;
}
.attendance-btn.disabled {
background: var(--panel-bg);
color: var(--text-muted);
cursor: not-allowed;
}
.attendance-btn.active {
background: var(--primary-accent);
color: #00110f;
border-color: var(--primary-accent);
}
.day-off-card {
display: flex;
justify-content: space-between;
align-items: center;
padding: 15px;
background: var(--panel-bg);
border-radius: 8px;
margin-top: 20px;
flex-wrap: nowrap;
}
.day-off-info {
display: flex;
align-items: center;
gap: 10px;
}
.day-off-icon {
width: 24px;
height: 24px;
border-radius: 4px;
background: var(--primary-accent);
color: var(--text-primary);
display: flex;
align-items: center;
justify-content: center;
font-size: 14px;
}
.day-off-text {
text-align: left;
}
.day-off-title {
font-size: 14px;
font-weight: 600;
color: var(--text-primary);
}
.day-off-subtitle {
font-size: 12px;
color: var(--text-muted);
}
.new-request-btn {
padding: 8px 16px;
background: var(--primary-accent);
color: var(--text-primary);
border: none;
border-radius: 6px;
font-size: 12px;
font-weight: 600;
cursor: pointer;
}
.new-request-btn:hover {
background: rgba(25,211,197,0.9);
}
.requests-card, .quick-info-card {
padding: 20px;
border: 1px solid var(--border-color);
border-radius: 12px;
background: var(--panel-bg);
}
.requests-header, .quick-info-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 15px;
}
.requests-title, .quick-info-title {
font-size: 18px;
font-weight: 600;
color: var(--text-primary);
}
.quick-info-icon {
width: 24px;
height: 24px;
border-radius: 4px;
background: var(--primary-accent);
color: var(--text-primary);
display: flex;
align-items: center;
justify-content: center;
font-size: 14px;
margin-right: 10px;
}
.requests-subtitle, .quick-info-content {
font-size: 14px;
color: var(--text-primary);
line-height: 1.5;
}
.quick-info-content {
text-align: center;
}
/* === SMOOTH REFRESH TRANSITIONS === */
.smooth-content {
    transition: opacity 0.3s ease-in-out, transform 0.3s ease;
}
.is-refreshing {
    opacity: 0.6;
    pointer-events: none;
}
.request-item {
background: var(--panel-bg);
padding: 15px;
border-radius: 8px;
margin-bottom: 15px;
border: 1px solid var(--border-color);
}
.request-date {
font-size: 16px;
font-weight: 600;
color: var(--text-primary);
margin-bottom: 5px;
}
.request-duration, .request-status {
font-size: 12px;
color: var(--text-muted);
margin-bottom: 10px;
}
.request-reason {
font-size: 14px;
color: var(--text-primary);
margin-bottom: 5px;
}
.request-badge {
padding: 4px 8px;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
background: rgba(255,179,25,0.12);
color: #FFD580;
display: inline-block;
}
.form-row {
margin-bottom: 15px;
text-align: left;
}
.form-label {
display: block;
margin-bottom: 5px;
color: var(--text-secondary);
font-weight: 500;
font-size: 14px;
}
.form-select, .form-textarea {
width: 100%;
padding: 12px;
border: 1px solid var(--border-color);
border-radius: 8px;
font-size: 14px;
}
.form-textarea {
min-height: 80px;
resize: vertical;
}
.form-select:focus, .form-textarea:focus {
outline: none;
border-color: var(--primary-accent);
box-shadow: 0 0 0 2px rgba(25,211,197,0.08);
}
.admin-nav {
    display: flex;
    background: var(--panel-bg);
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    padding: 12px 20px; /* Slightly reduced horizontal padding */
    margin-bottom: 24px;
    overflow: visible !important;
    gap: 16px; /* Increased gap for better spacing */
    justify-content: space-between; /* Distribute evenly across full width */
    border: 1px solid var(--border-color);
}

.nav-tab {
    padding: 14px 28px; /* Increased padding - bigger tabs */
    border-radius: 10px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    min-width: fit-content;
    flex: 1; /* Allow tabs to expand evenly */
    max-width: 280px; /* Prevent them from getting too wide */
    justify-content: center; /* Center the content */
    border: 1px solid transparent;
}

.nav-tab:hover {
    background: rgba(255,255,255,0.05);
    border-color: rgba(25, 211, 197, 0.3);
    transform: translateY(-1px);
}

.nav-tab.active {
    background: var(--primary-accent);
    color: #eeeeee;
    box-shadow: 0 4px 12px rgba(25, 211, 197, 0.3);
    border-color: var(--primary-accent);
}

/* Icon sizing */
.nav-tab i {
    font-size: 16px;
}
.dashboard-stat-grid {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
gap: 20px;
margin-bottom: 30px;
}
.stat-card {
padding: 20px;
border-radius: 16px;
position: relative;
overflow: hidden;
display: flex;
flex-direction: column;
justify-content: space-between;
min-height: 140px;
box-shadow: 0 4px 15px rgba(0,0,0,0.05);
transition: transform 0.2s;
}
.stat-card:hover {
transform: translateY(-3px);
}
.stat-card.present {
background: #f0fdf4;
border-left: 5px solid #22c55e;
color: #166534;
}
.stat-card.absent {
background: #fef2f2;
border-left: 5px solid #ef4444;
color: #991b1b;
}
.stat-card.late {
background: #fffbeb;
border-left: 5px solid #f59e0b;
color: #92400e;
}
.stat-card.live {
background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
color: white;
border: none;
}
#employeesSection {
background: #15152b;
border-radius: 12px;
padding: 25px;
color: #e8eaf0;
}
.employees-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 25px;
border-bottom: 1px solid rgba(255,255,255,0.1);
padding-bottom: 15px;
}
.employees-title h2 {
font-size: 20px;
font-weight: 700;
margin: 0;
color: #fff;
}
.employees-title p {
font-size: 13px;
color: #94a3b8;
margin: 5px 0 0;
}
.add-employee-btn {
background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
color: white;
border: none;
padding: 10px 20px;
border-radius: 8px;
font-size: 13px;
font-weight: 600;
cursor: pointer;
display: flex;
align-items: center;
gap: 8px;
transition: opacity 0.2s;
}
.add-employee-btn:hover {
opacity: 0.9;
}
.employee-search-container {
margin-bottom: 20px;
position: relative;
}
.employee-search-input {
width: 100%;
background: #1e1e38;
border: 1px solid rgba(255,255,255,0.1);
padding: 12px 15px 12px 40px;
border-radius: 8px;
color: #fff;
font-size: 14px;
outline: none;
}
.employee-search-input:focus {
border-color: #6366f1;
}
.search-icon {
position: absolute;
left: 12px;
top: 50%;
transform: translateY(-50%);
color: #94a3b8;
font-size: 14px;
}
.modern-employee-table {
width: 100%;
border-collapse: separate;
border-spacing: 0;
font-size: 14px;
table-layout: fixed;
}
.modern-employee-table th {
text-align: left;
padding: 15px 10px;
color: #94a3b8;
font-weight: 600;
font-size: 12px;
text-transform: uppercase;
letter-spacing: 0.5px;
border-bottom: 1px solid rgba(255,255,255,0.1);
}
.modern-employee-table td {
    padding: 16px 10px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    color: #e8eaf0;
    vertical-align: middle; /* Ensures content is centered vertically */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
/* Specific Actions Column Styling */
.col-actions { 
    width: 100px; /* Slightly tighter width for better fit */
    text-align: center; /* Centers the group horizontally */
    vertical-align: middle; /* Ensures vertical centering in the row */
    padding: 0 5px; /* Small padding to prevent icons from touching edges */
}
.col-id { width: 120px; font-family: monospace; color: #cbd5e1; }
.col-name { width: 200px; }
.col-position { width: 200px; }
.col-dept { width: 180px; }
.col-role { width: 100px; }
.col-birthday { width: 120px; }
.col-finger { width: 100px; }
.col-actions { width: 120px; text-align: right; }


/* Update Avatar Wrapper for Photo Display */
.emp-avatar-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
    overflow: hidden;
}

.emp-avatar {
    width: 80px;   /* Increased from 36px */
    height: 80px;  /* Increased from 36px */
    background: #19D3C5;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #00110f;
    font-size: 32px; /* Increased font size */
    flex-shrink: 0;
    overflow: hidden;
}

/* Ensure Icon is centered if no photo */
.emp-avatar i {
    z-index: 2;
}
.emp-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.emp-name-text {
font-weight: 500;
color: #fff;
display: block;
white-space: nowrap;
overflow: hidden;
text-overflow: ellipsis;
max-width: 100%;
}
.action-btn-group {
    display: flex;
    justify-content: center; /* Centers icons within the cell */
    gap: 8px; /* Consistent spacing between icons */
    align-items: center; /* Vertically centers icons */
    height: 100%; /* Ensures it takes full row height for alignment */
    margin: 0 auto; /* Centers the group if width is constrained */
}
.action-icon-btn {
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 8px; /* Slightly larger touch area */
    border-radius: 6px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px; /* Fixed width for uniformity */
    height: 32px; /* Fixed height for uniformity */
    color: #94a3b8; /* Default icon color */
}
.action-icon-btn.edit {
color: #94a3b8;
}
.action-icon-btn.edit:hover {
    background: rgba(25, 211, 197, 0.15); /* Teal glow */
    color: #19D3C5;
    transform: translateY(-1px);
}
.action-icon-btn.delete {
color: #ef4444;
}
.action-icon-btn.delete:hover {
    background: rgba(239, 68, 68, 0.15); /* Red glow */
    color: #ef4444;
    transform: translateY(-1px);
}
.stat-card-header {
display: flex;
justify-content: space-between;
align-items: flex-start;
margin-bottom: 15px;
}
.stat-card-title {
font-size: 14px;
font-weight: 600;
opacity: 0.9;
}
.stat-card-icon {
font-size: 18px;
opacity: 0.8;
}
.stat-card-value {
font-size: 36px;
font-weight: 700;
line-height: 1;
margin-bottom: 5px;
}
.stat-card-desc {
font-size: 12px;
opacity: 0.8;
margin-bottom: 10px;
}
.stat-card-breakdown {
display: flex;
gap: 15px;
font-size: 11px;
opacity: 0.9;
flex-wrap: wrap;
margin-top: auto;
}
.stat-card-breakdown span {
display: flex;
align-items: center;
gap: 4px;
}
.admin-section {
background: var(--panel-bg);
border-radius: 12px;
padding: 20px;
box-shadow: var(--shadow-sm);
min-height: 600px;
}
.section-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 22px;
padding-bottom: 16px;
border-bottom: 2px solid #f0f1f5;
flex-wrap: wrap;
gap: 15px;
}
.section-title {
font-size: 20px;
font-weight: 700;
color: var(--text-primary);
letter-spacing: -0.5px;
}
.section-subtitle {
font-size: 13px;
color: var(--text-secondary);
font-weight: 500;
margin-top: 4px;
}
.filter-section {
display: flex;
gap: 20px;
margin-bottom: 20px;
flex-wrap: wrap;
}
.filter-group {
display: flex;
align-items: center;
gap: 10px;
}
.filter-label {
font-size: 14px;
color: var(--text-secondary);
}
.filter-input, .filter-select {
padding: 8px 12px;
border: 1px solid var(--border-color);
border-radius: 6px;
font-size: 14px;
min-width: 150px;
}
.search-bar {
display: flex;
align-items: center;
gap: 10px;
margin-bottom: 20px;
background: var(--panel-bg);
border-radius: 8px;
padding: 8px 12px;
}
.search-input {
flex: 1;
padding: 8px;
border: none;
background: transparent;
font-size: 14px;
}
.search-input:focus {
outline: none;
}
.search-icon {
color: var(--text-muted);
font-size: 16px;
}
.record-list {
margin-top: 20px;
display: flex;
flex-direction: column;
gap: 12px;
}
.record-item {
background: #f8fafc;
border: 1px solid #e2e8f0;
border-radius: 12px;
padding: 16px 20px;
display: flex;
justify-content: space-between;
align-items: center;
transition: all 0.2s;
flex-wrap: nowrap;
}
[data-theme="dark"] .record-item {
background: rgba(255,255,255,0.03);
border-color: rgba(255,255,255,0.1);
}
.record-item:hover {
border-color: var(--primary-accent);
box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.record-item:hover {
border-color: var(--primary-accent);
box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

/* === LEAVE MANAGEMENT SECTION STYLES === */
.leave-management-container {
background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
border-radius: 16px;
padding: 32px;
box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

.section-header {
margin-bottom: 32px;
padding-bottom: 20px;
border-bottom: 2px solid rgba(25, 211, 197, 0.2);
}

/* ... rest of the CSS I provided earlier ... */
.record-info {
display: flex;
align-items: center;
gap: 15px;
flex: 1;
}
.record-icon {
width: 40px;
height: 40px;
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
font-size: 18px;
flex-shrink: 0;
}
.record-icon.present { background: #dcfce7; color: #16a34a; }
.record-icon.late { background: #fef3c7; color: #d97706; }
.record-icon.absent { background: #fee2e2; color: #dc2626; }
.record-details {
display: flex;
flex-direction: column;
}
.record-name {
font-size: 15px;
font-weight: 600;
color: var(--text-primary);
}
.record-id {
font-size: 12px;
color: var(--text-muted);
}
.record-badge {
padding: 6px 12px;
border-radius: 20px;
font-size: 13px;
font-weight: 600;
min-width: 40px;
text-align: center;
}
.record-badge.present { background: #dcfce7; color: #166534; }
.record-badge.late { background: #fef3c7; color: #92400e; }
.record-badge.absent { background: #fee2e2; color: #991b1b; }
.record-status {
padding: 4px 8px;
border-radius: 4px;
font-size: 12px;
font-weight: 600;
}
.record-status.present { background: rgba(40,167,69,0.08); color: #28a745; }
.record-status.absent { background: rgba(193,40,40,0.06); color: #c12828; }
.record-status.late { background: rgba(255,179,25,0.12); color: #FFD580; }
.record-shift {
padding: 4px 8px;
border-radius: 4px;
font-size: 12px;
background: var(--panel-bg);
color: var(--text-muted);
}
.record-time {
display: flex;
flex-direction: column;
align-items: flex-end;
gap: 4px;
font-size: 12px;
color: var(--text-muted);
white-space: nowrap;
}
.record-actions {
display: flex;
gap: 8px;
}
.edit-btn {
padding: 6px 12px;
border: 1px solid var(--border-color);
border-radius: 6px;
font-size: 12px;
cursor: pointer;
}
.edit-btn:hover {
background: rgba(25,211,197,0.04);
}
.employee-table {
width: 100%;
border-collapse: collapse;
margin-top: 20px;
}
.employee-table th, .employee-table td {
padding: 12px;
text-align: left;
border-bottom: 1px solid var(--border-color);
}
.employee-table th {
background: var(--panel-bg);
font-weight: 600;
color: var(--text-primary);
}
.employee-table tr:hover {
background: rgba(255,255,255,0.02);
}
.employee-avatar {
width: 32px;
height: 32px;
border-radius: 50%;
background: linear-gradient(135deg, rgba(25,211,197,0.9) 0%, rgba(0,224,208,0.9) 100%);
color: #00110f;
display: flex;
align-items: center;
justify-content: center;
font-size: 14px;
}
.role-badge {
padding: 2px 6px;
border-radius: 4px;
font-size: 10px;
font-weight: 600;
}
.role-badge.admin { background: rgba(255,179,25,0.12); color: #FFD580; }
.role-badge.employee { background: var(--success-1); color: #168a3b; }
.action-icons {
display: flex;
gap: 8px;
align-items: center;
justify-content: center;
min-width: 60px;
}
.action-icon {
padding: 4px;
border-radius: 4px;
cursor: pointer;
transition: background-color 0.2s;
display: flex;
align-items: center;
justify-content: center;
width: 24px;
height: 24px;
font-size: 14px;
}
.action-icon.edit {
background: var(--panel-bg);
color: var(--text-muted);
}
.action-icon.edit:hover {
background: rgba(25,211,197,0.04);
}
.action-icon.delete {
background: var(--panel-bg);
color: #c12828;
}
.action-icon.delete:hover {
background: rgba(193,40,40,0.06);
}
.date-selector, .date-label, .date-input {
display: flex;
align-items: center;
gap: 10px;
margin-bottom: 20px;
font-size: 14px;
color: var(--text-primary);
}
.date-input {
padding: 8px 12px;
border: 1px solid var(--border-color);
border-radius: 6px;
font-size: 14px;
}
.modal {
display: none;
position: fixed;
z-index: 1001;
left: 0;
top: 0;
width: 100%;
height: 100%;
background-color: rgba(0, 0, 0, 0.5);
overflow: auto;
}
.modal-content {
background: var(--panel-bg);
margin: 10% auto;
padding: 20px;
border-radius: 12px;
box-shadow: 0 4px 20px rgba(0,0,0,0.2);
max-width: 600px;
max-height: 70vh;
overflow-y: auto;
border: 1px solid var(--border-color);
}
.modal-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 20px;
border-bottom: 1px solid var(--border-color);
padding-bottom: 15px;
position: relative;
}
.modal-header h3 {
font-size: 18px;
color: var(--text-primary);
margin: 0;
}
.modal-header p {
font-size: 12px;
color: var(--text-muted);
margin: 5px 0 0;
}
.close-modal {
color: var(--text-muted);
font-size: 28px;
font-weight: bold;
cursor: pointer;
margin-left: 20px;
}
.close-modal:hover, .close-modal:focus {
color: var(--text-primary);
}
.employee-list-item {
display: flex;
align-items: center;
gap: 15px;
padding: 15px;
border: 1px solid var(--border-color);
border-radius: 8px;
margin-bottom: 10px;
background: var(--panel-bg);
}
.employee-name {
font-size: 14px;
font-weight: 600;
color: var(--text-primary);
}
.employee-details {
font-size: 12px;
color: var(--text-muted);
}
.employee-role-badge {
padding: 4px 8px;
border-radius: 4px;
font-size: 10px;
font-weight: 600;
background: rgba(25,211,197,0.06);
color: var(--primary-accent);
}
.action-button {
padding: 10px 18px;
border: none;
border-radius: 10px;
cursor: pointer;
font-size: 12px;
font-weight: 600;
transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
display: inline-flex;
align-items: center;
justify-content: center;
gap: 8px;
letter-spacing: 0.3px;
}
.action-button.primary {
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
color: var(--text-primary);
box-shadow: 0 4px 15px rgba(102, 126, 244, 0.3);
}
.action-button.primary:hover {
transform: translateY(-2px);
box-shadow: 0 6px 20px rgba(102, 126, 244, 0.4);
}
.action-button.secondary {
background: linear-gradient(135deg, #f8f9ff 0%, #f0f1f5 100%);
color: #667eea;
border: 1.5px solid #e8ebf5;
}
.action-button.secondary:hover {
background: linear-gradient(135deg, #e8ebf5 0%, #d9dce8 100%);
border-color: #667eea;
}
/* AI Panel Container */
.ai-panel {
    position: fixed;
    right: 24px;
    bottom: 100px;
    width: 400px;
    max-width: calc(100vw - 48px);
    max-height: 650px;
    background: linear-gradient(145deg, rgba(30, 41, 59, 0.98), rgba(15, 23, 42, 0.98));
    backdrop-filter: blur(20px);
    border-radius: 24px;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4), 
                0 0 0 1px rgba(255, 255, 255, 0.1);
    z-index: 1000;
    display: none;
    flex-direction: column;
    overflow: hidden;
    animation: slideUpFade 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.ai-panel.active {
    display: flex;
}

@keyframes slideUpFade {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}








/* === ENHANCED AI CHATBOT STYLES === */

/* Floating Action Button */
.help-button {
    position: fixed;
    right: 24px;
    bottom: 24px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    cursor: pointer;
    z-index: 1000;
    box-shadow: 0 8px 30px rgba(102, 126, 234, 0.4);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    outline: none;
    user-select: none;
}

.help-button:hover {
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 12px 40px rgba(102, 126, 234, 0.5);
}

.help-button:active {
    transform: translateY(-1px) scale(0.98);
}

.help-button.dragging {
    cursor: grabbing;
    transition: none;
}



/* Panel Header */
.panel-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
}

.panel-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    animation: shimmer 6s linear infinite;
}

@keyframes shimmer {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.panel-title-section {
    position: relative;
    z-index: 1;
}

.panel-title {
    font-size: 20px;
    font-weight: 700;
    color: white;
    margin: 0 0 4px 0;
    letter-spacing: -0.5px;
}

.panel-subtitle {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.85);
    margin: 0;
}

.panel-controls {
    display: flex;
    gap: 8px;
    position: relative;
    z-index: 1;
}

.panel-control-btn, .panel-close {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.15);
    border: none;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    font-size: 16px;
    backdrop-filter: blur(10px);
}

.panel-control-btn:hover, .panel-close:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: translateY(-2px);
}

.panel-close:hover {
    background: rgba(239, 68, 68, 0.6);
}

/* Panel Body */
.panel-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: rgba(15, 23, 42, 0.6);
}

/* Quick Actions Section */
.quick-actions-section {
    padding: 20px 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.02);
}

.quick-actions-header {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.quick-actions-header i {
    color: #fbbf24;
    font-size: 14px;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.action-button {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.action-button::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
    opacity: 0;
    transition: opacity 0.3s ease;
}

.action-button:hover::before {
    opacity: 1;
}

.action-button:hover {
    transform: translateY(-3px);
    border-color: rgba(102, 126, 234, 0.4);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
    background: rgba(255, 255, 255, 0.08);
}

.action-button:active {
    transform: translateY(-1px);
}

.action-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    position: relative;
    z-index: 1;
}

.action-icon.request {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.2));
}

.action-icon.time {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(251, 191, 36, 0.2));
}

.action-icon.requests {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.2));
}

.action-icon.attendance {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(220, 38, 38, 0.2));
}

.action-text {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    position: relative;
    z-index: 1;
}

/* Chat Messages Container */
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    scroll-behavior: smooth;
}

.chat-messages::-webkit-scrollbar {
    width: 6px;
}

.chat-messages::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.02);
}

.chat-messages::-webkit-scrollbar-thumb {
    background: rgba(102, 126, 234, 0.4);
    border-radius: 10px;
}

.chat-messages::-webkit-scrollbar-thumb:hover {
    background: rgba(102, 126, 234, 0.6);
}

/* Message Bubbles */
.message {
    max-width: 85%;
    padding: 14px 18px;
    border-radius: 18px;
    font-size: 14px;
    line-height: 1.6;
    animation: messageSlide 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    word-wrap: break-word;
    position: relative;
}

@keyframes messageSlide {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.message-assistant {
    align-self: flex-start;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.95);
    border-bottom-left-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.message-user {
    align-self: flex-end;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-bottom-right-radius: 6px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.message-time {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.5);
    margin-top: 6px;
    text-align: right;
}

.message-assistant .message-time {
    color: rgba(255, 255, 255, 0.4);
}

/* Typing Indicator */
.typing-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 18px 20px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    border-bottom-left-radius: 6px;
    width: fit-content;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.typing-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.5);
    animation: typingBounce 1.4s infinite ease-in-out;
}

.typing-dot:nth-child(1) { animation-delay: -0.32s; }
.typing-dot:nth-child(2) { animation-delay: -0.16s; }

@keyframes typingBounce {
    0%, 80%, 100% {
        transform: scale(0.6);
        opacity: 0.5;
    }
    40% {
        transform: scale(1);
        opacity: 1;
    }
}

/* Chat Input Area */
.chat-input {
    padding: 20px 24px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.02);
    display: flex;
    gap: 12px;
    align-items: center;
}

.chat-input-field {
    flex: 1;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    padding: 14px 18px;
    color: white;
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
}

.chat-input-field::placeholder {
    color: rgba(255, 255, 255, 0.4);
}

.chat-input-field:focus {
    border-color: rgba(102, 126, 234, 0.5);
    background: rgba(255, 255, 255, 0.08);
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.chat-send-btn {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 18px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.chat-send-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.chat-send-btn:active {
    transform: translateY(0);
}

.chat-send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* AI Data Card (for structured responses) */
.ai-data-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 14px;
    margin-top: 12px;
    width: 100%;
    box-sizing: border-box;
}

.ai-list-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 13px;
}

.ai-list-row:last-child {
    border-bottom: none;
}

.ai-name {
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    display: flex;
    align-items: center;
    gap: 8px;
}

.ai-name i {
    font-size: 12px;
    color: #667eea;
}

.ai-dept {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.05);
    padding: 4px 10px;
    border-radius: 6px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .help-button {
        right: 16px;
        bottom: 16px;
        width: 56px;
        height: 56px;
        font-size: 24px;
    }
    
    .ai-panel {
        right: 12px;
        left: 12px;
        bottom: 90px;
        width: calc(100vw - 24px);
        max-height: calc(100vh - 120px);
        border-radius: 20px;
    }
    
    .quick-actions-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .action-button {
        padding: 14px 12px;
    }
    
    .action-icon {
        width: 36px;
        height: 36px;
        font-size: 18px;
    }
    
    .action-text {
        font-size: 12px;
    }
    
    .message {
        max-width: 90%;
        padding: 12px 16px;
        font-size: 13px;
    }
    
    .panel-header {
        padding: 20px;
    }
    
    .panel-title {
        font-size: 18px;
    }
    
    .panel-body {
        padding: 0;
    }
    
    .chat-messages {
        padding: 16px 20px;
    }
    
    .chat-input {
        padding: 16px 20px;
    }
}

@media (max-width: 480px) {
    .quick-actions-grid {
        grid-template-columns: 1fr;
    }
    
    .action-button {
        flex-direction: row;
        justify-content: flex-start;
        gap: 14px;
        padding: 14px 16px;
    }
    
    .action-icon {
        width: 32px;
        height: 32px;
        flex-shrink: 0;
    }
    
    .action-text {
        text-align: left;
    }
}

/* Dark mode adjustments */
[data-theme="dark"] .ai-panel {
    background: linear-gradient(145deg, rgba(15, 23, 42, 0.98), rgba(10, 15, 30, 0.98));
}

/* Loading state */
.chat-send-btn.loading {
    pointer-events: none;
}

.chat-send-btn.loading i {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

#reportsSection .filter-section {
margin-bottom: 25px;
}
#reportPreview {
min-height: 100px;
display: flex;
align-items: center;
justify-content: center;
color: var(--text-muted);
font-style: italic;
}
#leaveadministrationSection .record-item {
position: relative;
}
#leaveadministrationSection label {
display: flex;
align-items: center;
gap: 6px;
font-size: 12px;
color: #555;
margin-top: 6px;
}
#leaveadministrationSection input[type="checkbox"] {
width: 16px;
height: 16px;
cursor: pointer;
}
.status-badge.vl {
background: rgba(25,211,197,0.06);
color: var(--primary-accent);
}
.status-badge.sl {
background: rgba(255,179,25,0.12);
color: #FFD580;
}
#audittrailSection .record-item {
padding: 12px 15px;
}
#audittrailSection .record-name {
font-size: 14px;
font-weight: 500;
color: var(--text-primary);
}
#audittrailSection .record-id {
font-size: 12px;
color: var(--text-muted);
margin-top: 4px;
}
.loading-spinner {
display: inline-block;
width: 16px;
height: 16px;
border: 2px solid rgba(255,255,255,0.06);
border-top: 2px solid var(--primary-accent);
border-radius: 50%;
animation: spin 1s linear infinite;
margin-right: 6px;
vertical-align: middle;
}
@keyframes spin {
0% { transform: rotate(0deg); }
100% { transform: rotate(360deg); }
}
.fingerprint-module {
background: linear-gradient(135deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.01) 100%);
border-left: 4px solid var(--primary-1);
padding: 25px;
border-radius: 12px;
margin-top: 20px;
box-shadow: 0 4px 12px var(--shadow-prim);
}
.fingerprint-header {
display: flex;
align-items: center;
gap: 15px;
margin-bottom: 20px;
}
.fingerprint-icon {
width: 40px;
height: 40px;
border-radius: 50%;
background: rgba(255,255,255,0.02);
color: var(--primary-accent);
display: flex;
align-items: center;
justify-content: center;
font-size: 24px;
margin-right: 10px;
box-shadow: 0 6px 14px rgba(0,0,0,0.6);
}
.fingerprint-title h3 {
color: var(--primary-accent);
margin-bottom: 5px;
font-size: 1.4em;
}
.fingerprint-title p {
color: var(--text-secondary);
font-size: 0.95em;
}
.fingerprint-instructions {
background: var(--panel-bg);
padding: 20px;
border-radius: 10px;
margin-top: 15px;
border: 1px solid var(--border-color);
}
.fingerprint-instructions h4 {
color: var(--text-primary);
margin-bottom: 15px;
display: flex;
align-items: center;
gap: 8px;
}
.fingerprint-steps {
list-style: none;
padding-left: 0;
}
.fingerprint-steps li {
padding: 10px 0 10px 35px;
position: relative;
color: var(--text-muted);
border-bottom: 1px solid var(--border-color);
font-size: 0.95em;
}
.fingerprint-steps li:last-child {
border-bottom: none;
}
.fingerprint-steps li:before {
content: "✓";
position: absolute;
left: 0;
top: 10px;
width: 24px;
height: 24px;
background: #28a745;
color: var(--text-primary);
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
font-size: 0.9em;
font-weight: bold;
}
.scanner-status {
display: flex;
align-items: center;
gap: 10px;
margin-top: 15px;
padding: 12px;
background: rgba(255,255,255,0.02);
border-radius: 8px;
border-left: 4px solid var(--primary-accent);
}
.scanner-status i {
font-size: 1.5em;
color: var(--primary-accent);
}
.scanner-status span {
font-weight: 600;
color: var(--primary-accent);
}
.historical-record-card {
    background: var(--panel-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    animation: slideIn 0.3s ease;
}

.historical-record-card:hover {
    border-color: var(--primary-accent);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}

.historical-record-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--primary-accent);
}

.historical-record-card.present::before {
    background: #28a745;
}

.historical-record-card.late::before {
    background: #ffc107;
}

.historical-record-card.absent::before {
    background: #dc3545;
}

.record-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.record-date {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.record-status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.record-status-badge.present {
    background: rgba(40,167,69,0.15);
    color: #28a745;
}

.record-status-badge.late {
    background: rgba(255,193,7,0.15);
    color: #ffc107;
}

.record-status-badge.absent {
    background: rgba(220,53,69,0.15);
    color: #dc3545;
}

.record-body {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.record-info-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
}

.record-info-item i {
    color: var(--primary-accent);
    font-size: 16px;
    width: 20px;
}

.record-info-label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.record-info-value {
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 600;
}

.record-footer {
    margin-top: 15px;
    padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: var(--text-muted);
}
.fingerprint-success {
position: fixed;
top: 20px;
right: 20px;
background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
color: var(--text-primary);
padding: 20px 25px;
border-radius: 12px;
box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
z-index: 9999;
display: none;
animation: slideInRight 0.3s ease-out, slideOutRight 0.3s ease-in 4.7s;
min-width: 300px;
}
@keyframes slideInRight {
from { transform: translateX(400px); opacity: 0; }
to { transform: translateX(0); opacity: 1; }
}
@keyframes slideOutRight {
from { transform: translateX(0); opacity: 1; }
to { transform: translateX(400px); opacity: 0; }
}
@keyframes slideInLeft {
from { transform: translateX(-400px); opacity: 0; }
to { transform: translateX(0); opacity: 1; }
}
.fingerprint-success-icon {
font-size: 48px;
margin-bottom: 10px;
}
.fingerprint-success-name {
font-size: 20px;
font-weight: bold;
margin-bottom: 5px;
}
.fingerprint-success-message {
font-size: 14px;
opacity: 0.9;
margin-bottom: 5px;
}
.fingerprint-success-time {
font-size: 12px;
opacity: 0.8;
}
@media (max-width: 768px) {
.login-container { max-width: 100%; padding: 20px; }
.dashboard, .admin-dashboard { padding: 10px; }
.header, .admin-header { flex-direction: column; text-align: center; }
.info-card { grid-template-columns: 1fr; }
.attendance-buttons { flex-direction: column; }
.attendance-btn { width: 100%; min-width: auto; }
.day-off-card { flex-direction: column; text-align: left; gap: 15px; }
.new-request-btn { align-self: flex-start; }
.ai-panel { width: calc(100% - 40px); right: 20px; left: 20px; top: 80px; max-height: 80vh; }
.help-button { right: 20px; bottom: 20px; }
.admin-overview-cards { grid-template-columns: 1fr; }
.admin-nav { flex-wrap: wrap; }
#moduleSelector { min-width: 150px; font-size: 13px; }
.record-item { flex-direction: column; align-items: flex-start; }
.record-time { align-items: flex-start; }
.message { max-width: 90%; }
.notification-dropdown { width: 280px; }
}
/* === TOGGLEABLE FOOTER === */
.system-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--panel-bg);
    border-top: 1px solid var(--border-color);
    padding: 12px 20px;
    z-index: 1000;
    transition: all 0.3s ease;
    overflow: hidden;
}

/* Collapsed State */
.system-footer.collapsed {
    padding: 8px 20px;
}

.system-footer.collapsed .footer-content {
    opacity: 0;
    max-height: 0;
    pointer-events: none;
}
.system-footer .footer-content {
    opacity: 1;
    max-height: 100px;
    transition: all 0.3s ease;
}
/* Toggle Button (Arrow) */
.footer-toggle-btn {
    position: absolute;
    top: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 20px;
    height: 20px;
    background: var(--primary-accent);
    border-radius: 4px 4px 0 0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 1001;
}

.footer-toggle-btn:hover {
    background: var(--secondary-accent);
    transform: translateX(-50%) translateY(-2px);
}

.footer-toggle-btn i {
    color: #00110f;
    font-size: 10px;
    transition: transform 0.3s ease;
}

/* Rotate Arrow When Collapsed */
.system-footer.collapsed .footer-toggle-btn i {
    transform: rotate(180deg);
}

/* Footer Content */
.footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 1400px;
    margin: 0 auto;
    transition: all 0.3s ease;
    opacity: 1;
    max-height: 100px;
}

.footer-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.footer-info {
    color: var(--text-muted);
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.footer-right {
    display: flex;
    gap: 10px;
}

.footer-btn {
    padding: 6px 14px;
    border: 1px solid var(--primary-accent);
    border-radius: 6px;
    background: transparent;
    color: var(--primary-accent);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}

.footer-btn:hover {
    background: var(--primary-accent);
    color: #00110f;
}
body {
padding-bottom: 70px;
}
.system-footer {
margin-bottom: 0;
}
@media (max-width: 1024px) {
body { padding-bottom: 90px; }
.footer-content { flex-direction: column; text-align: center; gap: 10px; }
.footer-actions { width: 100%; justify-content: center; }
}
@media (max-width: 768px) {
body { padding-bottom: 110px; }
.system-footer { padding: 10px 15px; }
.footer-btn {
padding: 6px 12px;
background: var(--panel-bg);
border: 1px solid var(--border-color);
border-radius: 6px;
cursor: pointer;
font-size: 12px;
transition: all 0.2s;
flex: 1;
min-width: 80px;
}
.footer-btn:hover {
background: var(--primary-accent);
border-color: var(--primary-accent);
color: #00110f;
}
#scanPhoto {
width: 100px;
height: 100px;
border-radius: 50%;
object-fit: cover;
margin-bottom: 12px;
}
#scanName {
font-size: 18px;
font-weight: bold;
color: var(--text-primary);
margin-bottom: 6px;
}
.scan-subtitle {
font-size: 12px;
color: var(--text-muted);
}
}


.schedule-day-card.today {
border: 2px solid var(--primary-accent);
box-shadow: 0 0 20px rgba(25, 211, 197, 0.4);
transform: scale(1.02);
}
.schedule-day-card.working {
border-left-color: #28a745;
background: linear-gradient(135deg, rgba(40,167,69,0.05) 0%, var(--panel-bg) 100%);
}

/* Holiday Card Styles */
.schedule-day-card.holiday-regular {
    border-left-color: #19D3C5 !important;
    background: linear-gradient(135deg, rgba(25,211,197,0.08) 0%, var(--panel-bg) 100%);
    border-width: 2px;
}

.schedule-day-card.holiday-special {
    border-left-color: #fd7e14 !important;
    background: linear-gradient(135deg, rgba(253,126,20,0.08) 0%, var(--panel-bg) 100%);
    border-width: 2px;
}

.schedule-day-card.holiday-working {
    border-left-color: #17a2b8 !important;
    background: linear-gradient(135deg, rgba(23,162,184,0.08) 0%, var(--panel-bg) 100%);
    border-width: 2px;
}

.schedule-day-card.holiday-regular.today,
.schedule-day-card.holiday-special.today,
.schedule-day-card.holiday-working.today {
    box-shadow: 0 0 25px rgba(25,211,197,0.4);
    transform: scale(1.02);
}
.schedule-day-card.off {
border-left-color: #6c757d;
background: rgba(108, 117, 125, 0.08);
}
.schedule-day-card.leave {
border-left-color: #ffc107;
background: rgba(255, 193, 7, 0.08);
}



.schedule-day-status {
font-size: 11px;
padding: 4px 10px;
border-radius: 6px;
margin-top: 8px;
display: inline-block;
font-weight: 600;
}
@media (max-width: 1024px) {
.schedule-calendar-grid { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 768px) {
.schedule-calendar-grid { grid-template-columns: repeat(2, 1fr); }
.schedule-day-card { min-height: 120px; padding: 15px 10px; }
}
/* Password Change Modal Styles */
.password-section {
background: var(--panel-bg);
padding: 20px;
border-radius: 12px;
margin-top: 20px;
border: 1px solid var(--border-color);
}
.password-section h4 {
color: var(--text-primary);
margin-bottom: 15px;
font-size: 16px;
}
/* === MODERN EMPLOYEE MODAL STYLES === */
.modern-employee-modal {
    max-width: 900px;
    border: 1px solid var(--border-color);
    box-shadow: 0 20px 50px rgba(0,0,0,0.5);
}

.modern-employee-modal .modal-header {
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 20px;
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.modal-body-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 25px;
}

.section-title {
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--primary-accent);
    margin-bottom: 15px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid rgba(25, 211, 197, 0.2);
    padding-bottom: 8px;
}

.form-group {
    margin-bottom: 15px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
}

.form-input-modern {
    width: 100%;
    padding: 12px 15px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
    box-sizing: border-box; /* Fixes padding issues */
}

.form-input-modern:focus {
    background: rgba(255,255,255,0.05);
    border-color: var(--primary-accent);
    box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.1);
    outline: none;
}

.select-wrapper {
    position: relative;
}

.select-icon {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
    font-size: 12px;
}

/* Photo Upload Styling */
.photo-preview-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    overflow: hidden;
    border: 3px solid var(--primary-accent);
    box-shadow: 0 0 20px rgba(25, 211, 197, 0.3);
    background: rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition: all 0.3s ease;
}

.photo-preview-wrapper:hover {
    transform: scale(1.05);
    box-shadow: 0 0 30px rgba(25, 211, 197, 0.5);
}

.photo-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.photo-preview-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(25, 211, 197, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.photo-preview-wrapper:hover .photo-preview-overlay {
    opacity: 1;
}

.photo-preview-overlay i {
    color: #00110f;
    font-size: 32px;
}

.photo-upload-container {
    display: flex;
    align-items: center;
    gap: 25px;
    flex-wrap: wrap;
}

.photo-controls {
    display: flex;
    gap: 12px;
    flex-direction: column;
}

.btn-upload, .btn-remove {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    border: none;
    width: 100%;
    justify-content: center;
}

.btn-upload {
    background: rgba(25, 211, 197, 0.15);
    color: var(--primary-accent);
    border: 1px solid var(--primary-accent);
}

.btn-upload:hover {
    background: var(--primary-accent);
    color: #00110f;
    transform: translateY(-2px);
}

.btn-remove {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
    border: 1px solid #ef4444;
}

.btn-remove:hover {
    background: #ef4444;
    color: white;
    transform: translateY(-2px);
}

/* Footer Buttons */
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    padding-top: 20px;
    border-top: 1px solid var(--border-color);
}

.btn-primary-modern {
    background: var(--gradient-primary);
    color: #00110f;
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: transform 0.2s;
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(25, 211, 197, 0.3);
}

.btn-secondary-modern {
    background: transparent;
    color: var(--text-muted);
    padding: 12px 30px;
    border-radius: 8px;
    font-weight: 600;
    border: 1px solid var(--border-color);
    cursor: pointer;
    transition: all 0.2s;
}

.btn-secondary-modern:hover {
    border-color: var(--text-secondary);
    color: var(--text-primary);
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .modal-body-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    .photo-upload-container {
        flex-direction: column;
        text-align: center;
    }
    .photo-controls {
        justify-content: center;
        width: 100%;
    }
    .btn-upload, .btn-remove {
        flex: 1;
        justify-content: center;
    }
}
/* === COOL SCAN MODAL STYLES === */
.scan-modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(10px);
    z-index: 9999; display: none; justify-content: center; align-items: center;
    opacity: 0; transition: opacity 0.3s ease;
}
.scan-modal-overlay.active { display: flex; opacity: 1; }
.scan-modal-card {
    background: linear-gradient(145deg, rgba(6, 26, 31, 0.98), rgba(0, 0, 0, 0.98));
    border: 2px solid var(--primary-accent);
    width: 90%; max-width: 550px; border-radius: 24px; padding: 40px;
    text-align: center; box-shadow: 0 0 50px rgba(25, 211, 197, 0.3);
    transform: scale(0.9); transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative; overflow: hidden;
}
.scan-modal-overlay.active .scan-modal-card { transform: scale(1); }
.scan-modal-card::before {
    content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
    background: radial-gradient(circle, rgba(25, 211, 197, 0.1) 0%, transparent 70%);
    animation: rotateGlow 10s linear infinite; z-index: -1; pointer-events: none;
}
@keyframes rotateGlow { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.scan-modal-icon {
    width: 100px; height: 100px; margin: 0 auto 25px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; font-size: 45px;
    background: rgba(25, 211, 197, 0.1); color: var(--primary-accent);
    border: 2px solid var(--primary-accent);
    box-shadow: 0 0 40px rgba(25, 211, 197, 0.3);
    animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes popIn { 0% { transform: scale(0); opacity: 0; } 80% { transform: scale(1.1); opacity: 1; } 100% { transform: scale(1); opacity: 1; } }
.scan-modal-title { font-size: 28px; font-weight: 800; color: #fff; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 2px; }
.scan-modal-subtitle { font-size: 16px; color: var(--text-muted); margin-bottom: 30px; font-family: 'Courier New', monospace; }
.scan-modal-profile {
    background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color);
    border-radius: 16px; padding: 20px; display: flex; align-items: center; gap: 20px;
    margin-bottom: 25px; text-align: left;
}
.scan-modal-pic {
    width: 100px; height: 100px; border-radius: 50%; object-fit: cover;
    border: 3px solid var(--primary-accent); box-shadow: 0 0 20px rgba(25, 211, 197, 0.3);
}
.scan-modal-details h3 { color: #fff; font-size: 24px; margin-bottom: 5px; }
.scan-modal-details p { color: var(--text-muted); font-size: 14px; margin-bottom: 2px; }
.scan-modal-time {
    font-family: 'Courier New', monospace; font-size: 48px; font-weight: 700;
    color: var(--primary-accent); text-shadow: 0 0 20px rgba(25, 211, 197, 0.3);
    letter-spacing: 4px;
}
/* Contact Admin Button */
.contact-admin-btn {
    position: fixed; bottom: 30px; right: 30px;
    background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(10px);
    border: 1px solid var(--warning); color: var(--warning);
    padding: 12px 24px; border-radius: 50px; font-size: 14px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px; cursor: pointer;
    display: flex; align-items: center; gap: 10px; transition: all 0.3s ease;
    z-index: 100; box-shadow: 0 0 15px rgba(255, 170, 0, 0.2);
}
.contact-admin-btn:hover { background: var(--warning); color: #000; box-shadow: 0 0 30px rgba(255, 170, 0, 0.6); transform: translateY(-2px); }
/* === ENHANCED DEPARTMENTS MODULE STYLES === */

/* Department Grid Layout */
.departments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 25px;
    margin-top: 25px;
}

/* Department Card - Enhanced */
.department-card {
    background: linear-gradient(145deg, rgba(6, 26, 31, 0.95), rgba(10, 35, 40, 0.95));
    border: 1px solid rgba(25, 211, 197, 0.2);
    border-radius: 16px;
    padding: 0;
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}

.department-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: var(--gradient-primary);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.4s ease;
}

.department-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 40px rgba(25, 211, 197, 0.2);
    border-color: rgba(25, 211, 197, 0.5);
}

.department-card:hover::before {
    transform: scaleX(1);
}

/* Department Card Header */
.department-card-header {
    padding: 20px 25px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(255, 255, 255, 0.02);
}

.department-info {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.department-icon-wrapper {
    width: 55px;
    height: 55px;
    border-radius: 14px;
    background: var(--gradient-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #00110f;
    box-shadow: 0 5px 15px rgba(25, 211, 197, 0.3);
    flex-shrink: 0;
}

.department-name-wrapper {
    flex: 1;
    min-width: 0;
}

.department-name {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 4px;
  word-wrap: break-word;
    overflow: hidden;
    text-overflow: ellipsis;
}

.department-head {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 5px;
}

.department-head i {
    color: var(--primary-accent);
    font-size: 10px;
}

/* Department Actions */
.department-actions,
.dept-header .action-buttons {
    flex-shrink: 0;
    display: flex;
    gap: 8px;
    margin-left: auto;
}
.department-action-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.3s ease;
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-muted);
}
/* Truncate long department names */
.truncate-dept-name {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
    vertical-align: bottom;
}

/* Or allow multi-line with max lines */
.dept-name-multiline {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    max-width: 100%;
}
.department-action-btn.edit {
    color: var(--primary-accent);
}

.department-action-btn.edit:hover {
    background: rgba(25, 211, 197, 0.15);
    color: var(--primary-accent);
    transform: translateY(-2px);
}

.department-action-btn.delete {
    color: #ef4444;
}

.department-action-btn.delete:hover {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
    transform: translateY(-2px);
}

.department-action-btn.view {
    color: #6366f1;
}

.department-action-btn.view:hover {
    background: rgba(99, 102, 241, 0.15);
    color: #6366f1;
    transform: translateY(-2px);
}

/* Department Card Body */
.department-card-body {
    padding: 20px 25px;
}

/* Employee Avatars Stack */
.department-employees {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.employee-avatar-stack {
    display: flex;
    margin-right: 12px;
}

.employee-avatar-stack img,
.employee-avatar-stack .avatar-placeholder {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: 2px solid var(--panel-bg);
    margin-left: -10px;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.employee-avatar-stack img:hover,
.employee-avatar-stack .avatar-placeholder:hover {
    transform: scale(1.2);
    z-index: 10;
}

.employee-avatar-stack img:first-child,
.employee-avatar-stack .avatar-placeholder:first-child {
    margin-left: 0;
}

.avatar-placeholder {
    background: var(--gradient-primary);
    color: #00110f;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
}

.employee-count-text {
    font-size: 13px;
    color: var(--text-muted);
}

.employee-count-text strong {
    color: var(--primary-accent);
    font-weight: 700;
}

/* Department Stats Grid */
.department-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.department-stat-box {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 10px;
    padding: 12px;
    text-align: center;
    border: 1px solid rgba(255, 255, 255, 0.05);
    transition: all 0.3s ease;
}

.department-stat-box:hover {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(25, 211, 197, 0.3);
    transform: translateY(-2px);
}

.department-stat-value {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 3px;
}

.department-stat-label {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Attendance Rate Section */
.department-attendance-section {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
}

.department-attendance-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.department-attendance-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 600;
}

.department-attendance-percentage {
    font-size: 14px;
    font-weight: 700;
    color: var(--primary-accent);
}

.department-progress-bar-container {
    height: 8px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.department-progress-bar {
    height: 100%;
    border-radius: 4px;
    transition: width 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
    overflow: hidden;
}

.department-progress-bar::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(255, 255, 255, 0.3),
        transparent
    );
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Department Card Footer */
.department-card-footer {
    padding: 15px 25px;
    background: rgba(0, 0, 0, 0.2);
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.department-status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--text-muted);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #28a745;
    animation: pulse 2s infinite;
}

.status-dot.inactive {
    background: #6c757d;
}

.department-view-btn {
    padding: 8px 16px;
    background: rgba(25, 211, 197, 0.1);
    color: var(--primary-accent);
    border: 1px solid rgba(25, 211, 197, 0.3);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.department-view-btn:hover {
    background: var(--primary-accent);
    color: #00110f;
    transform: translateY(-2px);
}

/* Add Department Button */
.add-department-btn {
    background: var(--gradient-primary);
    color: #00110f;
    padding: 14px 28px;
    border-radius: 12px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    box-shadow: 0 5px 20px rgba(25, 211, 197, 0.3);
    font-size: 14px;
}

.add-department-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(25, 211, 197, 0.5);
}

.add-department-btn i {
    font-size: 16px;
}

/* Department Modal */
.department-modal {
    max-width: 600px;
}

.department-modal .modal-header {
    background: var(--gradient-primary);
    color: #00110f;
    border-radius: 16px 16px 0 0;
    padding: 25px;
}

.department-modal .modal-header h3 {
    color: #00110f;
    font-size: 20px;
}

.department-modal .modal-header p {
    color: rgba(0, 17, 15, 0.8);
}

.department-modal .modal-body {
    padding: 30px;
}

.department-form-row {
    margin-bottom: 22px;
}

.department-form-row label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: 6px;
}

.department-form-row label i {
    color: var(--primary-accent);
    font-size: 12px;
}

.department-form-row input,
.department-form-row select,
.department-form-row textarea {
    width: 100%;
    padding: 14px 16px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.department-form-row input:focus,
.department-form-row select:focus,
.department-form-row textarea:focus {
    border-color: var(--primary-accent);
    background: rgba(255, 255, 255, 0.05);
    box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.1);
    outline: none;
}

.department-form-row textarea {
    min-height: 100px;
    resize: vertical;
}

/* Department Detail Modal */
.department-detail-modal {
    max-width: 800px;
}

.department-detail-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 25px;
    background: rgba(25, 211, 197, 0.05);
    border-bottom: 1px solid var(--border-color);
}

.department-detail-icon {
    width: 70px;
    height: 70px;
    border-radius: 16px;
    background: var(--gradient-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: #00110f;
}

.department-detail-info h3 {
    font-size: 24px;
    color: var(--text-primary);
    margin-bottom: 5px;
}

.department-detail-info p {
    font-size: 13px;
    color: var(--text-muted);
}

.department-employees-list {
    padding: 25px;
    max-height: 400px;
    overflow-y: auto;
}

.department-employee-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.02);
    border-radius: 10px;
    margin-bottom: 10px;
    transition: all 0.3s ease;
    border: 1px solid transparent;
}

.department-employee-item:hover {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(25, 211, 197, 0.2);
    transform: translateX(5px);
}

.department-employee-avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: var(--gradient-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #00110f;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
    overflow: hidden;
}

.department-employee-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.department-employee-info {
    flex: 1;
    min-width: 0;
}

.department-employee-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.department-employee-position {
    font-size: 12px;
    color: var(--text-muted);
}

.department-employee-status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.department-employee-status.present {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
}

.department-employee-status.absent {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
}

.department-employee-status.late {
    background: rgba(255, 193, 7, 0.15);
    color: #ffc107;
}

/* Search & Filter Bar */
.departments-filter-bar {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
    flex-wrap: wrap;
    background: rgba(255, 255, 255, 0.02);
    padding: 20px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
}

.departments-search-input {
    flex: 1;
    min-width: 250px;
    padding: 12px 16px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 14px;
}

.departments-search-input:focus {
    border-color: var(--primary-accent);
    outline: none;
}

.departments-filter-select {
    padding: 12px 16px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 14px;
    min-width: 180px;
}

/* Stats Summary Cards */
.departments-stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.department-summary-card {
    background: var(--panel-bg);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
}

.department-summary-card:hover {
    transform: translateY(-5px);
    border-color: var(--primary-accent);
    box-shadow: 0 10px 30px rgba(25, 211, 197, 0.15);
}

.department-summary-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: var(--gradient-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #00110f;
    margin: 0 auto 15px;
}

.department-summary-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 5px;
}

.department-summary-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Empty State */
.departments-empty-state {
    grid-column: 1/-1;
    text-align: center;
    padding: 60px 20px;
    background: rgba(255, 255, 255, 0.02);
    border-radius: 16px;
    border: 2px dashed var(--border-color);
}

.departments-empty-state i {
    font-size: 64px;
    color: var(--text-muted);
    opacity: 0.5;
    margin-bottom: 20px;
}

.departments-empty-state h3 {
    font-size: 20px;
    color: var(--text-primary);
    margin-bottom: 10px;
}

.departments-empty-state p {
    font-size: 14px;
    color: var(--text-muted);
    margin-bottom: 20px;
}

/* Loading State */
.departments-loading {
    grid-column: 1/-1;
    text-align: center;
    padding: 60px 20px;
}

.departments-loading i {
    font-size: 48px;
    color: var(--primary-accent);
    margin-bottom: 15px;
}

.departments-loading p {
    font-size: 14px;
    color: var(--text-muted);
}

/* Responsive */
@media (max-width: 768px) {
    .departments-grid {
        grid-template-columns: 1fr;
    }
    
    .department-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .department-actions {
        width: 100%;
        justify-content: flex-end;
    }
    
    .department-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .departments-filter-bar {
        flex-direction: column;
    }
    
    .departments-search-input,
    .departments-filter-select {
        width: 100%;
    }
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
</head>
<body class="<?php echo isLoggedIn() ? 'dashboard-mode' : 'landing-page'; ?>">
<div id="toast-container"></div>
<?php if (!isLoggedIn()): ?>
<nav class="landing-nav">
<div class="landing-nav-brand">HELPORT</div>
<div class="landing-nav-menu">
<button class="btn-primary-saas" onclick="showLoginModal()" style="margin: 0;">Sign In</button>
</div>
</nav>
<section class="landing-hero">
<h1 class="landing-hero-heading">Helport AI <span>Attendance Management</span></h1>
<p class="landing-hero-subheading">Streamline your workforce with AI-powered fingerprint recognition and real-time analytics. Enterprise-grade attendance system built for modern teams.</p>
<div class="landing-hero-buttons">
<button class="btn-primary-saas" onclick="document.getElementById('loginModal').style.display='block'">Get Started</button>
<button class="btn-outline-saas" onclick="scrollToSection('features')">Learn More</button>
</div>
</section>
<section class="landing-features" id="features">
<h2 class="landing-features-title">Features</h2>
<div class="landing-features-grid">
<div class="feature-card">
<div class="feature-icon">👆</div>
<div class="feature-title">Biometric Scanning</div>
<div class="feature-description">Secure fingerprint recognition with 99.9% accuracy. Multi-finger enrollment for redundancy and reliability.</div>
</div>
<div class="feature-card">
<div class="feature-icon">📊</div>
<div class="feature-title">Real-Time Analytics</div>
<div class="feature-description">Live dashboards with attendance trends, productivity metrics, and comprehensive reporting in seconds.</div>
</div>
<div class="feature-card">
<div class="feature-icon">🔒</div>
<div class="feature-title">Enterprise Security</div>
<div class="feature-description">Bank-grade encryption, role-based access control, and audit trails for complete compliance and transparency.</div>
</div>
<div class="feature-card">
<div class="feature-icon">⚡</div>
<div class="feature-title">Mobile Access</div>
<div class="feature-description">Check in/out from anywhere with our responsive design. Works on all devices with seamless synchronization.</div>
</div>
</div>
</section>
<section class="landing-cta">
<h2 class="landing-cta-heading">Ready to Transform Your Attendance?</h2>
<p class="landing-cta-text">Join hundreds of organizations using HELPORT for accurate, efficient, and secure attendance management.</p>
<button class="btn-primary-saas" onclick="showLoginModal()">Start Free Trial</button>
</section>
<footer class="landing-footer">
<p>&copy; 2026 HELPORT. All rights reserved. | <a href="#">Privacy Policy</a> | <a href="#">Terms of Service</a> | <a href="#">Contact</a></p>
</footer>
<div id="loginModal" class="login-modal-container" style="display: none;">
<div class="login-box active">
<button class="close-modal-btn" onclick="document.getElementById('loginModal').style.display='none'">&times;</button>
<div class="login-container" id="loginPage">
<div class="header-logo" style="margin-bottom: 20px; text-align: center;">
<img src="./images/hellport-logo.png" alt="HELPORT Logo" style="height: 60px; width: auto;">
</div>
<div class="app-title" style="font-size: 24px; margin-bottom: 8px; text-align: center; color: var(--text-primary);">Welcome Back</div>
<div class="app-subtitle" style="font-size: 14px; margin-bottom: 25px; text-align: center; color: var(--text-secondary);">Sign in to your account</div>
<div id="loginError" style="display:none; color: #ff6b6b; margin-bottom: 15px; padding: 12px; background: rgba(255, 107, 107, 0.1); border-radius: 8px; font-size: 13px; border-left: 3px solid #ff6b6b;"></div>
<form id="loginForm" method="POST" class="login-form">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<div class="form-group-modern">
<input type="text" id="employeeId" name="employeeId" class="form-input-modern" placeholder=" " required autofocus />
<label for="employeeId" class="form-label-modern">Employee ID (EID)</label>
</div>
<div class="form-group-modern">
<input type="password" id="password" name="password" class="form-input-modern" placeholder=" " required />
<label for="password" class="form-label-modern">Password</label>
</div>
<div class="form-group-modern">
<select id="role" name="role" class="form-input-modern" required style="cursor: pointer;">
<option value="" disabled selected>Select your role</option>
<option value="Employee">Employee</option>
<option value="Admin">Admin</option>
</select>
<label for="role" class="form-label-modern" style="top: -10px; left: 12px; font-size: 12px; background: #1e293b; color: #19D3C5; padding: 0 4px; border-radius: 4px;">Role</label>
</div>
<button class="btn-primary-glow" name="login" type="submit">
<span>Sign In</span>
<i class="fas fa-arrow-right" style="margin-left: 8px;"></i>
</button>
</form>
<div class="demo-section" style="border-top: 1px solid var(--border-color); padding-top: 20px; margin-top: 25px;">
<div class="demo-title" style="color: var(--text-secondary); font-size: 12px; margin-bottom: 12px; text-align: center;">Quick Demo Access:</div>
<div class="demo-buttons" style="display: flex; gap: 10px; justify-content: center;">
<button class="demo-btn" type="button" onclick="loadDemo('Employee')" style="flex: 1; max-width: 120px;">Employee</button>
<button class="demo-btn" type="button" onclick="loadDemo('Admin')" style="flex: 1; max-width: 120px;">Admin</button>
</div>
</div>
</div>
</div>
</div>
<?php else: ?>
<?php if ($_SESSION['role'] === 'Employee'): ?>
<div class="dashboard" id="employeePortal" style="display:block;">
<div style="position: fixed; top: 100px; left: 20px; background: var(--panel-bg); padding: 15px; border-radius: 10px; box-shadow: var(--shadow-sm); z-index: 99; border-left: 4px solid var(--primary-accent); max-width: 250px; font-size: 12px; display: none; animation: slideInLeft 0.3s ease-out;" id="shortcutsCard">
<div style="font-weight: 600; margin-bottom: 8px; color: var(--text-primary);">⌨️ Quick Shortcuts</div>
<div style="color: var(--text-muted); line-height: 1.6;">
<div><strong>Ctrl+Shift+G</strong> - System Guide</div>
<div><strong>Ctrl+Shift+E</strong> - Export Data</div>
<div><strong>Click "Full Portal"</strong> - Enhanced View</div>
</div>
</div>
<div class="header">
<div class="header-left">
<div class="header-logo"><img src="./images/hellport-logo.png" alt="HELPORT Logo" style="height: 40px; width: auto;"></div>
<div class="header-title">Employee Portal</div>
<!-- REPLACE THIS BLOCK IN EMPLOYEE PORTAL HEADER -->
<div class="header-user">
<div class="user-avatar" style="overflow: hidden; padding: 0;">
<?php if (!empty($userInfo['photo_path']) && file_exists($userInfo['photo_path'])): ?>
<img src="<?= htmlspecialchars($userInfo['photo_path']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
<?php else: ?>
👤
<?php endif; ?>
</div>
<div class="user-info">
        <div class="user-name">Welcome, <span id="displayName"><?= htmlspecialchars($_SESSION['name']) ?></span></div>
        <div class="user-role"><?= htmlspecialchars($userInfo['position'] ?? 'Not Specified') ?></div>
    </div>
</div>
</div>
<div style="display: flex; align-items: center; gap: 12px;">
<div class="notification-bell" id="notificationBell">
<i class="fas fa-bell"></i>
<?php if ($unreadCount > 0): ?>
<span class="notification-badge"><?= $unreadCount ?></span>
<?php endif; ?>
<div class="notification-dropdown" id="notificationDropdown">
<div class="notification-header">Notifications</div>
<?php if (empty($notifications)): ?>
<div class="notification-empty">No new notifications</div>
<?php else: ?>
<?php foreach ($notifications as $notif): ?>
<div class="notification-item" onclick="handleNotificationClick('<?= htmlspecialchars($notif['link'] ?? '#') ?>', <?= $notif['id'] ?>)">
<div style="font-weight:600;"><?= htmlspecialchars($notif['title']) ?></div>
<div><?= htmlspecialchars($notif['message']) ?></div>
<div class="notification-time"><?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?></div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
<a href="?logout=true" class="logout-btn">Logout</a>
</div>
</div>
<!-- START OF REPLACEMENT BLOCK -->
<div class="admin-nav" style="justify-content: space-between; align-items: center; padding: 10px 20px; margin: 25px 0; border-radius: 12px;">
    <div style="display: flex; gap: 5px;">
        <div class="nav-tab active" onclick="switchEmployeeTab('overview')" data-tab="overview"><i class="fas fa-home"></i> Overview</div>
        
        <div class="nav-tab" onclick="switchEmployeeTab('schedule')" data-tab="schedule"><i class="fas fa-calendar-days"></i> My Schedule</div>
        
    </div>
    <div style="display: flex; gap: 8px; position: relative;">
        <!-- NEW SETTINGS BUTTON -->
        <button class="btn btn-primary" style="padding: 8px 14px; font-size: 12px;" onclick="toggleSettingsDropdown()">
            <i class="fas fa-cog"></i> Settings
        </button>
        <!-- SETTINGS DROPDOWN -->
        <div id="settingsDropdown" style="display: none; position: absolute; top: 100%; right: 40px; background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 8px; z-index: 1000; min-width: 200px; box-shadow: var(--shadow-sm); overflow: visible;">
            <div class="nav-tab" style="border-radius: 0; justify-content: flex-start;" onclick="openPasswordModal(); toggleSettingsDropdown()">
                <i class="fas fa-lock"></i> Change Password
            </div>
            <div class="nav-tab" style="border-radius: 0; justify-content: flex-start;" onclick="toggleDarkMode(); toggleSettingsDropdown()">
                <i class="fas fa-moon"></i> Toggle Dark/Light Mode
            </div>
        </div>

        <!-- ORIGINAL MENU BUTTON -->
        <button class="btn btn-primary" style="padding: 8px 14px; font-size: 12px;" onclick="toggleEmployeeDropdown()"><i class="fas fa-bars"></i> Menu</button>
        <div id="employeeDropdown" style="display: none; position: absolute; top: 100%; right: 0; background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 8px; z-index: 1000; min-width: 200px; box-shadow: var(--shadow-sm); overflow: visible;">
            <div class="nav-tab" style="border-radius: 0; justify-content: flex-start;" onclick="switchEmployeeTab('leaves'); toggleEmployeeDropdown()"><i class="fas fa-calendar-days"></i> Day Off Request</div>
            <div class="nav-tab" style="border-radius: 0; justify-content: flex-start;" onclick="switchEmployeeTab('leave'); toggleEmployeeDropdown()"><i class="fas fa-calendar-check"></i> Leave Request</div>
            <div class="nav-tab" style="border-radius: 0; justify-content: flex-start;" onclick="switchEmployeeTab('attendance'); toggleEmployeeDropdown()"><i class="fas fa-history"></i> Attendance History</div>
        </div>
    </div>
</div>
<div id="employeeOverviewSection" class="admin-section" style="display:block; padding:0; background:transparent;">
   












<div class="main-content" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        
        <!-- LEFT PANEL: EMPLOYEE INFORMATION -->
<div class="card" style="background: linear-gradient(135deg, rgba(25,211,197,0.05) 0%, rgba(0,224,208,0.02) 100%); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; position: relative; overflow: hidden;">
    <!-- Decorative Background Element -->
    <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(25,211,197,0.1) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>
    
    <div style="font-size: 12px; color: var(--primary-accent); margin-bottom: 25px; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="fas fa-user-circle"></i> Employee Information
    </div>
    
    <!-- Profile Header -->
    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 30px; position: relative; z-index: 1;">
        <div style="position: relative;">
            <div style="width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-accent), var(--secondary-accent)); display: flex; align-items: center; justify-content: center; font-size: 36px; color: #00110f; font-weight: 800; overflow: hidden; box-shadow: 0 8px 25px rgba(25,211,197,0.3); border: 3px solid rgba(255,255,255,0.1);">
                <?php if (!empty($userInfo['photo_path']) && file_exists($userInfo['photo_path'])): ?>
                    <img src="<?= htmlspecialchars($userInfo['photo_path']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                    <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div style="position: absolute; bottom: 5px; right: 5px; width: 20px; height: 20px; background: #28a745; border-radius: 50%; border: 3px solid var(--panel-bg); box-shadow: 0 0 10px rgba(40,167,69,0.5);"></div>
        </div>
        <div style="flex: 1;">
            <div style="font-size: 26px; font-weight: 800; color: var(--text-primary); margin-bottom: 5px; letter-spacing: -0.5px;"><?= htmlspecialchars($_SESSION['name']) ?></div>
            <div style="font-size: 14px; color: var(--primary-accent); font-weight: 600; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-briefcase" style="font-size: 12px;"></i> <?= htmlspecialchars($userInfo['position'] ?? 'Employee') ?>
            </div>
        </div>
    </div>
    
    <!-- Info Grid -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; position: relative; z-index: 1;">
        <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 12px; border-left: 3px solid var(--primary-accent); transition: all 0.3s ease;" onmouseover="this.style.background='rgba(25,211,197,0.08)'; this.style.transform='translateX(5px)'" onmouseout="this.style.background='rgba(255,255,255,0.03)'; this.style.transform='translateX(0)'">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <i class="fas fa-id-card" style="color: var(--primary-accent); font-size: 14px;"></i>
                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Employee ID</div>
            </div>
            <div style="font-size: 16px; font-weight: 700; color: var(--text-primary); font-family: 'Courier New', monospace;"><?= htmlspecialchars($_SESSION['employee_id']) ?></div>
        </div>
        
        <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 12px; border-left: 3px solid var(--secondary-accent); transition: all 0.3s ease;" onmouseover="this.style.background='rgba(0,224,208,0.08)'; this.style.transform='translateX(5px)'" onmouseout="this.style.background='rgba(255,255,255,0.03)'; this.style.transform='translateX(0)'">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <i class="fas fa-building" style="color: var(--secondary-accent); font-size: 14px;"></i>
                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Department</div>
            </div>
            <div style="font-size: 16px; font-weight: 700; color: var(--text-primary);"><?= htmlspecialchars($userInfo['department'] ?? 'N/A') ?></div>
        </div>
        
        <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 12px; border-left: 3px solid #ffc107; transition: all 0.3s ease;" onmouseover="this.style.background='rgba(255,193,7,0.08)'; this.style.transform='translateX(5px)'" onmouseout="this.style.background='rgba(255,255,255,0.03)'; this.style.transform='translateX(0)'">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <i class="fas fa-birthday-cake" style="color: #ffc107; font-size: 14px;"></i>
                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Birthday</div>
            </div>
            <div style="font-size: 16px; font-weight: 700; color: var(--text-primary);"><?= $userInfo && $userInfo['birthday'] ? date('M d, Y', strtotime($userInfo['birthday'])) : 'N/A' ?></div>
        </div>
        
        <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 12px; border-left: 3px solid #28a745; transition: all 0.3s ease;" onmouseover="this.style.background='rgba(40,167,69,0.08)'; this.style.transform='translateX(5px)'" onmouseout="this.style.background='rgba(255,255,255,0.03)'; this.style.transform='translateX(0)'">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <i class="fas fa-calendar-check" style="color: #28a745; font-size: 14px;"></i>
                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Joined Date</div>
            </div>
            <div style="font-size: 16px; font-weight: 700; color: var(--text-primary);"><?= $userInfo && $userInfo['created_at'] ? date('M d, Y', strtotime($userInfo['created_at'])) : 'N/A' ?></div>
        </div>
    </div>
</div>

<!-- RIGHT PANEL: ATTENDANCE & SHIFT STATUS -->
<div class="card attendance-status-card" style="background: linear-gradient(135deg, rgba(25,211,197,0.08) 0%, rgba(0,224,208,0.05) 100%); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; position: relative; overflow: hidden;">
    <!-- Animated Background Glow -->
    <div style="position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(25,211,197,0.03) 0%, transparent 70%); animation: pulse-glow 4s ease-in-out infinite; pointer-events: none;"></div>
    
    <div style="font-size: 12px; color: var(--primary-accent); margin-bottom: 25px; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; display: flex; align-items: center; gap: 8px; position: relative; z-index: 1;">
        <i class="fas fa-chart-line"></i> Attendance Status
    </div>
    
    <!-- Current Shift Badge -->
    <div style="background: rgba(255,255,255,0.05); border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 1px solid rgba(25,211,197,0.2); backdrop-filter: blur(10px); position: relative; z-index: 1; transition: all 0.3s ease;" onmouseover="this.style.background='rgba(25,211,197,0.1)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 25px rgba(25,211,197,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Today's Assigned Shift</div>
            <div style="width: 8px; height: 8px; border-radius: 50%; background: <?= $todayShiftColor ?>; box-shadow: 0 0 10px <?= $todayShiftColor ?>; animation: pulse-dot 2s infinite;"></div>
        </div>
        <div style="font-size: 22px; font-weight: 800; color: var(--text-primary); margin-bottom: 5px; letter-spacing: -0.5px;"><?= $todayShiftName ?></div>
        <div style="font-size: 14px; color: var(--text-secondary); font-family: 'Courier New', monospace; font-weight: 600;"><?= $todayShiftTime ?></div>
    </div>
    
    <!-- Status Indicator -->
    <div style="margin-bottom: 25px; position: relative; z-index: 1;">
        <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Current Status</div>
        <div id="currentStatusBadge" style="display: inline-flex; align-items: center; gap: 10px; padding: 12px 20px; border-radius: 10px; font-weight: 700; font-size: 14px; background: <?= $attendanceInfo && $attendanceInfo['check_out'] ? 'rgba(40,167,69,0.15)' : ($attendanceInfo && $attendanceInfo['check_in'] ? 'rgba(255,193,7,0.15)' : 'rgba(108,117,125,0.15)') ?>; color: <?= $attendanceInfo && $attendanceInfo['check_out'] ? '#28a745' : ($attendanceInfo && $attendanceInfo['check_in'] ? '#ffc107' : '#6c757d') ?>; border: 2px solid <?= $attendanceInfo && $attendanceInfo['check_out'] ? 'rgba(40,167,69,0.3)' : ($attendanceInfo && $attendanceInfo['check_in'] ? 'rgba(255,193,7,0.3)' : 'rgba(108,117,125,0.3)') ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: all 0.3s ease;">
            <i class="fas <?= $attendanceInfo && $attendanceInfo['check_out'] ? 'fa-check-circle' : ($attendanceInfo && $attendanceInfo['check_in'] ? 'fa-clock' : 'fa-circle') ?>" style="font-size: 16px;"></i>
            <span>
            <?php
            if ($attendanceInfo && $attendanceInfo['check_out']) {
                echo '✅ Scan Out';
            } elseif ($attendanceInfo && $attendanceInfo['check_in']) {
                echo $attendanceInfo['is_late'] ? '⚠️ Check In (Late)' : '✅ Check In';
            } else {
                echo '⭕ Not Checked In';
            }
            ?>
            </span>
        </div>
    </div>
    
    <!-- Time & Date Grid -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; position: relative; z-index: 1;">
        <div style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 20px; text-align: center; border: 1px solid rgba(255,255,255,0.05); transition: all 0.3s ease;" onmouseover="this.style.background='rgba(25,211,197,0.08)'; this.style.borderColor='rgba(25,211,197,0.3)'; this.style.transform='translateY(-3px)'" onmouseout="this.style.background='rgba(0,0,0,0.2)'; this.style.borderColor='rgba(255,255,255,0.05)'; this.style.transform='translateY(0)'">
            <div style="font-size: 10px; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Current Time</div>
            <div class="time-value live-timer" id="currentTime" style="font-size: 28px; font-weight: 800; color: var(--primary-accent); font-family: 'Courier New', monospace; letter-spacing: 2px; text-shadow: 0 0 20px rgba(25,211,197,0.5);">--:--:--</div>
        </div>
        <div style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 20px; text-align: center; border: 1px solid rgba(255,255,255,0.05); transition: all 0.3s ease;" onmouseover="this.style.background='rgba(25,211,197,0.08)'; this.style.borderColor='rgba(25,211,197,0.3)'; this.style.transform='translateY(-3px)'" onmouseout="this.style.background='rgba(0,0,0,0.2)'; this.style.borderColor='rgba(255,255,255,0.05)'; this.style.transform='translateY(0)'">
            <div style="font-size: 10px; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Today's Date</div>
            <div style="font-size: 16px; font-weight: 700; color: var(--text-primary);"><?= date('l, M d') ?></div>
        </div>
    </div>
    
    <!-- LIVE DURATION TRACKER -->
    <div id="durationTrackerContainer" style="display: none; background: linear-gradient(135deg, rgba(25,211,197,0.1) 0%, rgba(0,224,208,0.05) 100%); border: 2px solid rgba(25,211,197,0.3); border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 25px; position: relative; z-index: 1; animation: slideIn 0.5s ease;">
        <div style="font-size: 11px; color: var(--primary-accent); text-transform: uppercase; letter-spacing: 2px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; gap: 8px;">
            <i class="fas fa-hourglass-half" style="animation: flip 2s infinite;"></i> Time Working
        </div>
        <div id="durationTimer" class="live-timer" style="font-size: 36px; font-weight: 800; color: #fff; font-family: 'Courier New', monospace; text-shadow: 0 0 30px rgba(25,211,197,0.6); letter-spacing: 3px;">00h 00m 00s</div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 10px;">Started at: <span id="checkInTimeDisplay" style="color: var(--primary-accent); font-weight: 600;">--:--:--</span></div>
    </div>
    
    <!-- Manual Check-in Buttons -->
    <?php if ($manualCheckinAllowed): ?>
    <div style="position: relative; z-index: 1;">
        <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 15px; text-align: center; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Manual Attendance Backup</div>
        <div style="display: flex; gap: 12px;">
            <button class="attendance-btn" id="manualCheckInBtn" onclick="submitManualAttendance('IN')" style="flex: 1; background: linear-gradient(135deg, rgba(40,167,69,0.15) 0%, rgba(40,167,69,0.05) 100%); color: #28a745; border: 2px solid rgba(40,167,69,0.4); padding: 14px; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 13px; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(40,167,69,0.2);" onmouseover="this.style.background='linear-gradient(135deg, rgba(40,167,69,0.25) 0%, rgba(40,167,69,0.1) 100%)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(40,167,69,0.3)'" onmouseout="this.style.background='linear-gradient(135deg, rgba(40,167,69,0.15) 0%, rgba(40,167,69,0.05) 100%)'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(40,167,69,0.2)'">
                <i class="fas fa-sign-in-alt"></i> Check In
            </button>
            <button class="attendance-btn" id="manualCheckOutBtn" onclick="submitManualAttendance('OUT')" style="flex: 1; background: linear-gradient(135deg, rgba(220,53,69,0.15) 0%, rgba(220,53,69,0.05) 100%); color: #dc3545; border: 2px solid rgba(220,53,69,0.4); padding: 14px; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 13px; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(220,53,69,0.2);" onmouseover="this.style.background='linear-gradient(135deg, rgba(220,53,69,0.25) 0%, rgba(220,53,69,0.1) 100%)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(220,53,69,0.3)'" onmouseout="this.style.background='linear-gradient(135deg, rgba(220,53,69,0.15) 0%, rgba(220,53,69,0.05) 100%)'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(220,53,69,0.2)'">
                <i class="fas fa-sign-out-alt"></i> Check Out
            </button>
        </div>
        <div id="manualMsg" style="margin-top: 12px; font-size: 12px; color: var(--text-muted); text-align: center; min-height: 18px;"></div>
    </div>
    <?php else: ?>
    <div style="background: rgba(255,193,7,0.05); border: 1px solid rgba(255,193,7,0.2); border-radius: 10px; padding: 15px; text-align: center; position: relative; z-index: 1;">
        <div style="color: #ffc107; font-size: 13px; display: flex; align-items: center; justify-content: center; gap: 8px;">
            <i class="fas fa-lock"></i> Manual check-in is currently disabled by admin
        </div>
    </div>
    <?php endif; ?>
</div>


</div>
</div>
<!-- Change Password Modal for Employee -->
<div id="passwordModal" class="modal" style="display: none;">
<div class="modal-content">
<div class="modal-header"><h3>Change Password</h3><span class="close-modal" onclick="closePasswordModal()">&times;</span></div>
<form id="passwordForm">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<div class="form-row">
<label class="form-label">Current Password <span style="color:#dc3545">*</span></label>
<input type="password" id="currentPassword" name="current_password" class="form-select" required />
</div>
<div class="form-row">
<label class="form-label">New Password <span style="color:#dc3545">*</span></label>
<input type="password" id="newPassword" name="new_password" class="form-select" required minlength="6" />
</div>
<div class="form-row">
<label class="form-label">Confirm New Password <span style="color:#dc3545">*</span></label>
<input type="password" id="confirmPassword" name="confirm_password" class="form-select" required minlength="6" />
</div>
<div style="display: flex; gap: 10px; margin-top: 20px;">
<button type="submit" class="btn btn-primary" style="flex: 1;">Change Password</button>
<button type="button" class="btn" style="flex: 1; background: var(--panel-bg); color: var(--text-primary); border: 1px solid var(--border-color);" onclick="closePasswordModal()">Cancel</button>
</div>
</form>
</div>
</div>
<div id="employeeAttendanceSection" class="admin-section" style="display:none; padding:25px; border-radius:12px; background:var(--panel-bg); box-shadow: 0 2px 15px rgba(0,0,0,0.08); border:1px solid var(--border-color);">
<div class="section-header">
<div>
<div class="section-title">Attendance History</div>
<div class="section-subtitle">Your complete time-in/time-out audit trail</div>
</div>
<div style="display:flex; gap:10px;">
<input type="date" id="attendanceHistoryStart" class="filter-input" style="width:auto; padding:6px 10px; font-size:13px;" value="<?= date('Y-m-d', strtotime('-15 days')) ?>">
<span style="align-self:center;">to</span>
<input type="date" id="attendanceHistoryEnd" class="filter-input" style="width:auto; padding:6px 10px; font-size:13px;" value="<?= date('Y-m-d') ?>">
<button class="action-button secondary" style="padding:6px 12px; font-size:13px;" onclick="loadAttendanceHistory()"><i class="fas fa-sync-alt"></i> Refresh</button>
</div>
</div>
<div class="record-list" id="attendanceHistoryList" style="margin-top:20px;">
<?php
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND DATE(check_in) = ? ORDER BY check_in DESC");
$stmt->execute([$_SESSION['user_id'], date('Y-m-d')]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($history)) {
echo '<div style="text-align:center; padding:40px; background:var(--panel-bg); border-radius:12px; margin-top:15px; border:1px dashed var(--border-color);"><i class="fas fa-history" style="font-size:64px; color:var(--text-muted); margin-bottom:15px;"></i><div style="font-size:18px; color:var(--text-primary); margin-bottom:8px;">No attendance records for today</div><div style="color:var(--text-secondary);">Scan your fingerprint to start tracking</div></div>';
} else {
foreach ($history as $record) {
$checkIn = new DateTime($record['check_in']);
$checkOut = $record['check_out'] ? new DateTime($record['check_out']) : null;
$workingHours = $checkOut ? $checkIn->diff($checkOut)->format('%hh %im') : 'In Progress';
$statusColor = $checkOut ? '#28a745' : '#ffc107';
$statusText = $checkOut ? 'Completed' : 'Active Shift';
$lateBadge = $record['is_late'] ? '<span class="request-badge" style="background:rgba(255,179,25,0.12);color:#FFD580;margin-left:8px">Late</span>' : '';
echo '<div class="record-item" style="border-left:3px solid ' . $statusColor . '; padding-left:15px; margin-bottom:12px; background:var(--panel-bg); border-radius:8px; box-shadow:var(--shadow-sm);">';
echo '<div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:15px; width:100%;">';
echo '<div style="flex:1; min-width:220px;">';
echo '<div style="font-weight:600; font-size:16px; margin-bottom:4px;">' . $checkIn->format('M d, Y') . $lateBadge . '</div>';
echo '<div style="display:flex; flex-wrap:wrap; gap:15px; margin-top:8px; font-size:14px; color:#495057;">';
echo '<div><i class="fas fa-sign-in-alt" style="color:#007bff; margin-right:5px;"></i> <strong>In:</strong> ' . formatTime12Hour($checkIn->format('H:i:s')) . '</div>';
echo '<div><i class="fas fa-sign-out-alt" style="color:#dc3545; margin-right:5px;"></i> <strong>Out:</strong> ' . ($checkOut ? formatTime12Hour($checkOut->format('H:i:s')) : '--') . '</div>';
echo '<div><i class="fas fa-clock" style="color:#6c757d; margin-right:5px;"></i> <strong>Duration:</strong> ' . $workingHours . '</div>';
echo '</div>';
echo '</div>';
echo '<div style="text-align:right; min-width:110px; align-self:center;">';
echo '<div style="background:' . $statusColor . '; color:var(--text-primary); padding:4px 12px; border-radius:20px; font-weight:500; display:inline-block; font-size:13px;">' . $statusText . '</div>';
echo '</div>';
echo '</div>';
echo '</div>';
}
}
?>
</div>
<!-- OVERTIME APPROVAL MODAL -->
<div id="overtimeApprovalModal" class="modal" style="display: none;">
    <div class="modal-content modern-employee-modal">
        <div class="modal-header">
            <div>
                <h3 id="overtimeModalTitle">Review Overtime Request</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">Approve or reject employee overtime request</p>
            </div>
            <span class="close-modal" onclick="closeOvertimeModal()">&times;</span>
        </div>
        <form id="overtimeApprovalForm">
            <input type="hidden" id="overtimeRequestId" name="request_id" />
            <div class="modal-body-grid">
                <div class="form-column">
                    <h4 class="section-title"><i class="fas fa-user"></i> Employee Information</h4>
                    <div class="form-group">
                        <label class="form-label">Employee Name</label>
                        <input type="text" id="otEmployeeName" class="form-input-modern" readonly />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employee ID</label>
                        <input type="text" id="otEmployeeId" class="form-input-modern" readonly />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" id="otDepartment" class="form-input-modern" readonly />
                    </div>
                </div>
                <div class="form-column">
                    <h4 class="section-title"><i class="fas fa-clock"></i> Overtime Details</h4>
                    <div class="form-group">
                        <label class="form-label">Overtime Hours</label>
                        <input type="text" id="otHours" class="form-input-modern" readonly />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="text" id="otDate" class="form-input-modern" readonly />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Check In - Check Out</label>
                        <input type="text" id="otTimeRange" class="form-input-modern" readonly />
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Employee Reason</label>
                <textarea id="otReason" class="form-input-modern" readonly style="min-height: 80px;"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Admin Response (Optional)</label>
                <textarea id="otAdminResponse" name="admin_response" class="form-input-modern" placeholder="Add any notes or comments..." style="min-height: 80px;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary-modern" onclick="closeOvertimeModal()">Cancel</button>
                <button type="button" class="btn btn-reject" onclick="processOvertimeDecision('reject')" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 12px 30px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer;">
                    <i class="fas fa-times"></i> Reject
                </button>
                <button type="button" class="btn btn-approve" onclick="processOvertimeDecision('approve')" style="background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: white; padding: 12px 30px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer;">
                    <i class="fas fa-check"></i> Approve
                </button>
            </div>
        </form>
    </div>
</div>
<div style="text-align:center; margin-top:30px; padding-top:15px; border-top:1px dashed #dee2e6; color:#6c757d; font-size:13px;"><i class="fas fa-lock" style="margin-right:6px;"></i> All records are securely logged and immutable • Updated in real-time</div>
</div>
<!-- Leave Request Form -->
<div id="leaveRequestForm" style="display:none; background:var(--panel-bg); padding:25px; border-radius:12px; margin:25px 0; box-shadow:var(--shadow-sm);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid var(--border-color);">
        <div>
            <div style="font-size:18px; font-weight:600; color:var(--text-primary);">Submit New Leave Request</div>
            <div style="color:var(--text-secondary); margin-top:4px;">VL/SL/EL - Official Leave Request</div>
        </div>
        <button class="action-button secondary" onclick="toggleLeaveForm()" style="padding:6px 12px;"><i class="fas fa-times"></i> Cancel</button>
    </div>
    <form onsubmit="submitLeaveRequest(event)">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:20px;">
            <div class="form-row">
                <label class="form-label">Leave Type <span style="color:#dc3545">*</span></label>
                <select name="leave_type" class="form-select" required>
                    <option value="VL">Vacation Leave (VL)</option>
                    <option value="SL">Sick Leave (SL)</option>
                    <option value="EL">Emergency Leave (EL)</option>
                </select>
            </div>
            <div class="form-row">
                <label class="form-label">Number of Days <span style="color:#dc3545">*</span></label>
                <input type="number" name="days" class="form-select" min="1" required />
            </div>
        </div>
        <div class="form-row" style="margin-top:15px;">
            <label class="form-label">Start Date <span style="color:#dc3545">*</span></label>
            <input type="date" name="start_date" class="form-select" required min="<?= date('Y-m-d') ?>" />
        </div>
        <div class="form-row" style="margin-top:15px;">
            <label class="form-label">Reason <span style="color:#dc3545">*</span></label>
            <textarea name="reason" class="form-textarea" placeholder="Please provide detailed reason for your leave request" required minlength="20" style="min-height:100px;"></textarea>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:25px; padding-top:15px; border-top:1px solid var(--border-color);">
            <button type="button" class="btn" style="background:var(--panel-bg); border:1px solid var(--border-color); padding:10px 25px; border-radius:6px; color:var(--text-primary)" onclick="toggleLeaveForm()">Cancel</button>
            <button type="submit" class="btn btn-primary" style="padding:10px 25px; background:var(--primary-accent); border-color:var(--primary-accent); border-radius:6px;"><i class="fas fa-paper-plane" style="margin-right:8px;"></i> Submit Leave Request</button>
        </div>
    </form>
</div>
<div id="employeeLeaveSection" class="admin-section" style="display:none; padding:25px; border-radius:12px; background:var(--panel-bg); box-shadow: var(--shadow-sm);">
<div class="section-header">
<div>
<div class="section-title">Leave Management</div>
<div class="section-subtitle">Submit requests or view status of existing ones</div>
</div>
<button class="action-button primary" id="newLeaveBtn" onclick="toggleLeaveForm()" style="padding:8px 16px;"><i class="fas fa-plus-circle"></i> New Leave Request</button>
</div>
<div id="newLeaveForm" style="display:none; background:var(--panel-bg); border-radius:12px; padding:25px; margin:25px 0; box-shadow:var(--shadow-sm);">
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid var(--border-color);">
<div>
<div style="font-size:18px; font-weight:600; color:var(--text-primary);">Submit New Leave Request</div>
<div style="color:var(--text-secondary); margin-top:4px;">Minimum 3 business days advance notice required</div>
</div>
<button class="action-button secondary" onclick="toggleLeaveForm()" style="padding:6px 12px;"><i class="fas fa-times"></i> Cancel</button>
</div>
<form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:20px;">
<div class="form-row">
<label class="form-label">Start Date <span style="color:#dc3545">*</span></label>
<input type="date" name="startDate" class="form-select" required min="<?= date('Y-m-d', strtotime('+3 days')) ?>" />
</div>
<div class="form-row">
<label class="form-label">End Date <span style="color:#dc3545">*</span></label>
<input type="date" name="endDate" class="form-select" required min="<?= date('Y-m-d', strtotime('+3 days')) ?>" />
</div>
</div>
<div class="form-row" style="margin-top:15px;">
<label class="form-label">Reason <span style="color:#dc3545">*</span></label>
<textarea name="reason" class="form-textarea" placeholder="Please provide detailed reason for your leave request (minimum 20 characters)" required minlength="20" style="min-height:100px;"></textarea>
</div>
<div style="display:flex; justify-content:flex-end; gap:12px; margin-top:25px; padding-top:15px; border-top:1px solid var(--border-color);">
<button type="button" class="btn" style="background:var(--panel-bg); border:1px solid var(--border-color); padding:10px 25px; border-radius:6px; color:var(--text-primary)" onclick="toggleLeaveForm()">Cancel</button>
<button type="submit" name="submit_day_off" class="btn btn-primary" style="padding:10px 25px; background:#28a745; border-color:#28a745; border-radius:6px;"><i class="fas fa-paper-plane" style="margin-right:8px;"></i> Submit Request</button>
</div>
</form>
</div>
<div class="section-header" style="margin:30px 0 20px; padding-bottom:10px; border-bottom:1px solid var(--border-color);"><div class="section-title">My Leave Requests</div></div>
<div class="record-list">
<?php
$stmt = $pdo->prepare("SELECT * FROM day_off_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$_SESSION['user_id']]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($requests)) {
echo '<div style="text-align:center; padding:40px; background:var(--panel-bg); border-radius:12px; border:1px dashed var(--border-color);"><i class="fas fa-calendar-check" style="font-size:64px; color:var(--text-muted); margin-bottom:15px;"></i><div style="font-size:18px; color:var(--text-primary); margin-bottom:8px;">No leave requests found</div><div style="color:var(--text-secondary);">Click "New Leave Request" to submit your first request</div></div>';
} else {
foreach ($requests as $request) {
$status = strtolower($request['status']);
$statusColor = $status === 'approved' ? '#28a745' : ($status === 'rejected' ? '#dc3545' : '#ffc107');
$statusText = ucfirst($request['status']);
$duration = (new DateTime($request['start_date']))->diff(new DateTime($request['end_date']))->days + 1;
echo '<div class="record-item" style="border-left:3px solid ' . $statusColor . '; padding-left:15px; margin-bottom:12px; background:var(--panel-bg); border-radius:8px; box-shadow:var(--shadow-sm);">';
echo '<div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:15px; width:100%;">';
echo '<div style="flex:1; min-width:220px;">';
echo '<div style="font-weight:600; font-size:16px; margin-bottom:4px; color:#212529;">' . date('M d, Y', strtotime($request['start_date'])) . ' - ' . date('M d, Y', strtotime($request['end_date'])) . '</div>';
echo '<div style="color:#495057; margin:8px 0; line-height:1.5;">' . htmlspecialchars($request['reason']) . '</div>';
echo '<div style="display:flex; flex-wrap:wrap; gap:15px; margin-top:10px; font-size:13px; color:#6c757d;">';
echo '<span><i class="fas fa-calendar-day" style="color:#007bff; margin-right:5px;"></i> ' . $duration . ' day(s)</span>';
echo '<span><i class="fas fa-clock" style="color:#6c757d; margin-right:5px;"></i> Submitted: ' . date('M d, Y h:i A', strtotime($request['created_at'])) . '</span>';
if ($request['updated_at']) {
echo '<span><i class="fas fa-history" style="color:#6c757d; margin-right:5px;"></i> Updated: ' . date('M d, Y h:i A', strtotime($request['updated_at'])) . '</span>';
}
echo '</div>';
echo '</div>';
echo '<div style="text-align:right; min-width:110px; align-self:center;">';
echo '<div style="background:' . $statusColor . '; color:var(--text-primary); padding:5px 15px; border-radius:20px; font-weight:600; display:inline-block; font-size:13px;">' . $statusText . '</div>';
if ($status === 'pending') {
echo '<div style="color:#FFD580; background:rgba(255,179,25,0.12); padding:3px 10px; border-radius:15px; margin-top:6px; font-size:12px;"><i class="fas fa-hourglass-half"></i> Pending Review</div>';
}
echo '</div>';
echo '</div>';
echo '</div>';
}
}
?>
</div>
<div style="text-align:center; margin-top:30px; padding-top:15px; border-top:1px dashed #dee2e6; color:#6c757d; font-size:13px;"><i class="fas fa-info-circle" style="margin-right:6px;"></i> Approved leaves appear in Attendance History with VL/SL status • HR reviews within 48 business hours</div>
</div>
<div id="employeeScheduleSection" class="admin-section" style="display:none;">
    <!-- UPDATED HEADER WITH NAVIGATION -->
    <div class="calendar-nav-container">
        <button class="calendar-nav-btn" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
        
        <div class="calendar-header-info">
            <div class="calendar-month-year" id="calendarMonthYear">February 2026</div>
            <div class="calendar-subtitle"><i class="fas fa-calendar-alt"></i> Employee Work Schedule</div>
        </div>

        <div style="display:flex; gap:10px;">
            <button class="calendar-nav-btn" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
            <button class="action-button secondary" onclick="loadEmployeeSchedule()" style="padding: 6px 12px; margin-left:10px; background:rgba(255,255,255,0.2); border:none; color:#00110f;">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>

    <!-- THIS IS THE GRID CONTAINER -->
    <div id="scheduleCalendarGrid" class="schedule-calendar-grid">
        <!-- Content injected by JS -->
    </div>

     <div style="background: var(--panel-bg); padding: 15px; border-radius: 0 0 8px 8px; margin: 0 0 20px 0; border-left: 4px solid var(--primary-accent); border-top:1px solid var(--border-color);">
        <!-- CALENDAR LEGEND -->
<div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid var(--border-color);">
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(40,167,69,0.15); border: 2px solid #28a745;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Regular Holiday (Work)</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(25,211,197,0.15); border: 2px solid #19D3C5;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Regular Holiday (Off)</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(253,126,20,0.15); border: 2px solid #fd7e14;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Special Non-Working (Work)</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(108,117,125,0.15); border: 2px solid #6c757d;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Special Non-Working (Off)</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(23,162,184,0.15); border: 2px solid #17a2b8;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Special Working (Work)</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(0,255,136,0.1); border: 2px solid #00ff88;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Day Shift</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(108,117,125,0.1); border: 2px solid #6c757d;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Day Off</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: rgba(255,193,7,0.2); border: 2px solid #ffc107;"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">On Leave</span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <div style="width: 16px; height: 16px; border-radius: 4px; background: transparent; border: 2px solid var(--primary-accent);"></div>
        <span style="font-size: 11px; color: var(--text-secondary);">Today</span>
    </div>
</div>
<div id="employeeScheduleInfo" style="margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 12px; border-left: 4px solid var(--primary-accent);">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
        <i class="fas fa-info-circle" style="color: var(--primary-accent);"></i>
        <div style="font-weight: 700; color: var(--text-primary);">Schedule Information</div>
    </div>
    <div style="color: var(--text-secondary); margin-top: 8px; font-size: 13px;">Your schedule shows your assigned working hours for each day. Any changes to your schedule will be announced by HR.</div>
</div>
<div id="employeeLeavesSection" class="admin-section" style="display:none;">
<div class="admin-section-header" style="margin-bottom: 20px;">
<div class="section-title"><i class="fas fa-calendar-days"></i> Day Off Requests</div>
<div style="margin-top: 15px; font-size: 13px; color: #6c757d;">Submit and manage your day off requests</div>
</div>
<div id="leaveBalanceContent" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
<div style="background: var(--panel-bg); padding: 20px; border-radius: 8px; box-shadow: var(--shadow-sm); border: 2px solid var(--border-color);">
<div style="font-weight: 600; color: var(--text-primary); margin-bottom: 15px;">📅 Submit Day Off Request</div>
<form onsubmit="submitDayOffRequest(event)" style="display: flex; flex-direction: column; gap: 12px;">
<input type="date" id="dayOffDate" required style="padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px; background: rgba(255,255,255,0.02); color: var(--text-primary);" />
<select id="dayOffType" required style="padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px; background: rgba(255,255,255,0.02); color: var(--text-primary);">
<option value="">-- Select Type --</option>
<option value="Full Day">Full Day</option>
<option value="Half Day AM">Half Day (AM)</option>
<option value="Half Day PM">Half Day (PM)</option>
</select>
<textarea id="dayOffReason" placeholder="Reason (optional)" style="padding: 8px; border: 1px solid var(--soft-border); border-radius: 6px; font-size: 13px; resize: vertical; min-height: 60px;"></textarea>
<button type="submit" class="btn btn-primary" style="padding: 10px; font-size: 12px;">Submit Request</button>
</form>
</div>
</div>
<div id="dayOffList" style="margin-top: 20px;">
<div style="font-weight: 600; color: var(--text-primary); margin-bottom: 15px; font-size: 14px;">📋 Your Day Off Requests</div>
<div style="background: var(--panel-bg); padding: 20px; border-radius: 8px; box-shadow: var(--shadow-sm); text-align: center; color: var(--text-muted); padding: 40px;">
<div class="spinner" style="width: 30px; height: 30px; margin: 0 auto;"></div>
<div style="margin-top: 10px;">Loading your day off requests...</div>
</div>
</div>
</div>

<div class="help-button" onclick="toggleAIAssistant()">💬</div>
<div class="ai-panel" id="aiPanel">
<div class="panel-header">
<div>
<div class="panel-title">HELPORT AI Assistant</div>
<div class="panel-subtitle">How can I help you today?</div>
</div>
<div class="panel-controls">
<button class="panel-control-btn" title="Minimize">−</button>
<button class="panel-close" onclick="toggleAIAssistant()">&times;</button>
</div>
</div>
<div class="panel-body">
<div class="quick-actions-section">
<div class="quick-actions-header"><i class="fas fa-bolt"></i> Quick Actions</div>
<div class="quick-actions-grid">
<button class="action-button chat" onclick="sendQuickAction('How do I request a day off?')"><div class="action-icon request">📅</div><div class="action-text">Request Day Off</div></button>
<button class="action-button chat" onclick="sendQuickAction('What are my current attendance hours?')"><div class="action-icon time">🕒</div><div class="action-text">Check Hours</div></button>
<button class="action-button chat" onclick="sendQuickAction('Show me my pending requests')"><div class="action-icon requests">📋</div><div class="action-text">My Requests</div></button>
<button class="action-button chat" onclick="sendQuickAction('How does attendance tracking work?')"><div class="action-icon attendance">📊</div><div class="action-text">Attendance Help</div></button>
</div>
</div>
<div class="chat-messages" id="chatMessages">
<div class="message message-assistant">Hello! I'm your HELPORT AI assistant. How can I help you with your attendance, leave requests, or any questions today?<div class="message-time">Just now</div></div>
</div>
<div class="chat-input">
<input type="text" class="chat-input-field" id="userMessage" placeholder="Type your message..." onkeypress="handleKeyPress(event)" />
<button class="chat-send-btn" onclick="sendMessage()">Send</button>
</div>
</div>
</div>
</div>
<?php else: ?>
<div class="admin-dashboard" id="adminDashboard" style="display:block;">

<div style="position: fixed; top: 100px; left: 20px; background: var(--panel-bg); padding: 15px; border-radius: 10px; box-shadow: var(--shadow-sm); z-index: 99; border-left: 4px solid var(--primary-accent); max-width: 250px; font-size: 12px; display: none; animation: slideInLeft 0.3s ease-out;" id="adminShortcutsCard">
    
<div style="font-weight: 600; margin-bottom: 8px; color: var(--text-primary);">⌨️ Admin Shortcuts</div>
<div style="color: var(--text-muted); line-height: 1.6;">
    
<div><strong>Ctrl+Shift+G</strong> - System Guide</div>
<div><strong>Ctrl+Shift+E</strong> - Export Reports</div>
<div><strong>Tab Navigation</strong> - Switch sections</div>
<div><strong>Fingerprint Mgmt</strong> - Manage enrollments</div>
</div>
</div>
<div class="admin-header">
<div class="admin-header-left">
<div class="admin-header-logo"><img src="./images/hellport-logo.png" alt="HELPORT Logo" style="height: 40px; width: auto;"></div>
<div style="display: flex; flex-direction: column; align-items: flex-start;">
<div class="admin-header-title">Administrator Dashboard</div>
<div class="admin-header-subtitle" style="margin-top: 2px; font-size: 12px;">System Control & Analytics</div>

</div></div>
<div class="admin-header-user" style="display: flex; gap: 15px; align-items: center;">
<div class="notification-bell" id="notificationBell">
<i class="fas fa-bell"></i>
<?php if ($unreadCount > 0): ?>
<span class="notification-badge"><?= $unreadCount ?></span>
<?php endif; ?>
<div class="notification-dropdown" id="notificationDropdown">
<div class="notification-header">Notifications</div>
<?php if (empty($notifications)): ?>
<div class="notification-empty">No new notifications</div>
<?php else: ?>
<?php foreach ($notifications as $notif): ?>
<div class="notification-item" onclick="handleNotificationClick('<?= htmlspecialchars($notif['link'] ?? '#') ?>', <?= $notif['id'] ?>)">
<div style="font-weight:600;"><?= htmlspecialchars($notif['title']) ?></div>
<div><?= htmlspecialchars($notif['message']) ?></div>
<div class="notification-time"><?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?></div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
<div style="padding: 8px 16px; background: var(--panel-bg); border-radius: 8px; font-size: 12px; color: var(--text-primary)"><strong><?= htmlspecialchars($_SESSION['name']) ?></strong><br><small style="color: var(--text-muted);">Admin User</small></div>
<a href="?logout=true" class="logout-btn">Logout</a>
</div>
</div>
<div id="allEmployeesModal" class="modal" style="display: none;">
<div class="modal-content">
<div class="modal-header"><h3>All Employees</h3><p>Complete list of all employees in the system</p><span class="close-modal" onclick="closeAllEmployeesModal()">&times;</span></div>
<div class="modal-body">
<?php
$stmt = $pdo->query("SELECT * FROM employees ORDER BY name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($employees as $emp) {
echo '<div class="employee-list-item">';
echo '<div class="employee-avatar">👤</div>';
echo '<div class="employee-info">';
echo '<div class="employee-name">' . htmlspecialchars($emp['name']) . '</div>';
echo '<div class="employee-details">EID: ' . htmlspecialchars($emp['employee_id']) . ' • ' . htmlspecialchars($emp['department']) . '</div>';
echo '</div>';
echo '<div class="employee-role-badge">' . htmlspecialchars($emp['position']) . '</div>';
echo '</div>';
}
?>
</div>
</div>
</div>
<div id="departmentsModal" class="modal" style="display: none;">
<div class="modal-content">
<div class="modal-header"><h3>Active Departments</h3><p>List of departments and their employee counts</p><span class="close-modal" onclick="closeDepartmentsModal()">&times;</span></div>
<div class="modal-body">
<?php
$stmt = $pdo->query("SELECT department, COUNT(*) as employee_count FROM employees GROUP BY department ORDER BY department");
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($departments)) {
echo '<div style="padding: 20px; text-align: center; color: var(--text-muted);">No departments found.</div>';
} else {
foreach ($departments as $dept) {
echo '<div class="employee-list-item">';
echo '<div class="employee-avatar">🏢</div>';
echo '<div class="employee-info">';
echo '<div class="employee-name">' . htmlspecialchars($dept['department']) . '</div>';
echo '<div class="employee-details">' . $dept['employee_count'] . ' employees</div>';
echo '</div>';
echo '</div>';
}
}
?>
</div>
</div>
</div>
<div id="attendanceRateModal" class="modal" style="display: none;">
<div class="modal-content">
<div class="modal-header"><h3>Today's Attendance Breakdown</h3><p>Detailed view of attendance status</p><span class="close-modal" onclick="closeAttendanceRateModal()">&times;</span></div>
<div class="modal-body">
<?php
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN employees e ON a.employee_id = e.id WHERE DATE(a.check_in) = ? AND a.check_out IS NOT NULL");
$stmt->execute([$today]);
$present = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN employees e ON a.employee_id = e.id WHERE DATE(a.check_in) = ? AND a.is_late = 1");
$stmt->execute([$today]);
$late = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN attendance a ON e.id = a.employee_id AND DATE(a.check_in) = ? WHERE a.id IS NULL");
$stmt->execute([$today]);
$absent = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM employees");
$total = $stmt->fetchColumn();
echo '<div class="employee-list-item" style="background:rgba(40,167,69,0.06); color: #28a745"><strong>Present:</strong> ' . $present . ' employees</div>';
echo '<div class="employee-list-item" style="background:rgba(255,179,25,0.12); color: #FFD580"><strong>Late:</strong> ' . $late . ' employees</div>';
echo '<div class="employee-list-item" style="background:rgba(193,40,40,0.06); color: #c12828"><strong>Absent:</strong> ' . $absent . ' employees</div>';
echo '<div class="employee-list-item"><strong>Total:</strong> ' . $total . ' employees</div>';
?>
</div>
</div>
</div>
<div id="employeeModal" class="modal" style="display: none;">
    <div class="modal-content modern-employee-modal">
        <div class="modal-header">
            <div>
                <h3 id="modalTitle">Edit Employee</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">Update personal details, account info, and biometrics</p>
            </div>
            <span class="close-modal" onclick="closeEmployeeModal()">&times;</span>
        </div>
        
        <form id="employeeForm" onsubmit="event.preventDefault(); return false;">
    <!-- Hidden Fields -->
    <input type="hidden" id="employeeIdInput" name="id" />
    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
    

            <div class="modal-body-grid">
                <!-- Column 1: Personal Information -->
                <div class="form-column">
                    <h4 class="section-title"><i class="fas fa-user"></i> Personal Information</h4>
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" id="empName" name="name" class="form-input-modern" required autofocus placeholder="e.g. John Doe" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <input type="text" id="empPosition" name="position" class="form-input-modern" required placeholder="e.g. Software Engineer" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" id="empDept" name="department" class="form-input-modern" required placeholder="e.g. IT" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Birthday</label>
                        <input type="date" id="empBirthday" name="birthday" class="form-input-modern" />
                    </div>
                </div>

                <!-- Column 2: Account & Biometrics -->
                <div class="form-column">
                    <h4 class="section-title"><i class="fas fa-id-card"></i> Account & Access</h4>
                    <div class="form-group">
                        <label class="form-label">Employee ID</label>
                        <input type="text" id="empEid" name="employee_id" class="form-input-modern" required placeholder="e.g. EMP-001" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <div class="select-wrapper">
                            <select id="empRole" name="role" class="form-input-modern" required>
                                <option value="Employee">Employee</option>
                                <option value="Admin">Admin</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span style="font-size:0.8em; opacity:0.6">(Leave blank to keep current)</span></label>
                        <input type="password" id="empPassword" name="password" class="form-input-modern" placeholder="••••••••" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fingerprint ID</label>
                        <input type="number" id="empFingerId" name="finger_id" class="form-input-modern" placeholder="1-127" min="1" max="127" />
                    </div>
                </div>
            </div>

           <!-- Photo Upload Section -->
<div class="photo-upload-section">
    <label class="form-label">Employee Photo</label>
    <div class="photo-upload-container">
        <div class="photo-preview-wrapper">
            <img id="empPhotoPreview" src="./images/default-avatar.png" class="photo-preview" />
            <div class="photo-preview-overlay">
                <i class="fas fa-camera"></i>
            </div>
        </div>
        <div class="photo-controls">
            <input type="file" id="empPhotoInput" name="photo" accept="image/*" style="display: none;" onchange="previewPhoto(this)">
            <button type="button" class="btn-upload" onclick="document.getElementById('empPhotoInput').click()">
                <i class="fas fa-cloud-upload-alt"></i> Upload Photo
            </button>
            <button type="button" class="btn-remove" onclick="removePhoto()">
                <i class="fas fa-trash-alt"></i> Remove
            </button>
        </div>
    </div>
</div>

            <!-- Footer Actions -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary-modern" onclick="closeEmployeeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary-modern">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<div id="attendanceListModal" class="modal" style="display: none;">
<div class="modal-content">
<div class="modal-header"><h3 id="attendanceListTitle">Attendance List</h3><span class="close-modal" onclick="closeAttendanceListModal()">&times;</span></div>
<div class="modal-body" id="attendanceListContent" style="max-height: 400px; overflow-y: auto;"></div>
</div>
</div>
<div class="admin-nav">
<div class="nav-tab active" onclick="switchTab('overview')"><i class="fas fa-chart-pie"></i> Overview</div>
<div class="nav-tab" onclick="switchTab('attendance')"><i class="fas fa-clock"></i> Attendance</div>
<div class="nav-tab" onclick="switchTab('reports')"><i class="fas fa-file-alt"></i> Reports</div>

<div class="filter-group">
    
<select id="moduleSelector" class="filter-select" onchange="switchTab(this.value)" value="">
    <option value="" selected disabled hidden>☰ Menu</option>
    <option value="fingerprint">👆 Fingerprint</option>
    <option value="manualcheckin">⌨️ Manual Check-in</option>
    <option value="overtime">⏳ Overtime Tracker</option>
    <option value="employees">👥 Employees</option>
    <option value="dayoff">📅 Day Off</option>
    <option value="leaveadministration">📋 Leave Management</option>
    <option value="holidays">🎄 Holiday Management</option>
    <option value="audittrail">🔒 Audit Trail</option>
    <option value="departments">🏢 Departments</option>
    <option value="analytics">📊 Analytics</option>
    <option value="archives">🗄️ Archives</option>
</select>
</div>
</div>
<div class="admin-main-content">
<div class="admin-section" id="overviewSection">


<div class="dashboard-stat-grid">
    <!-- Present Today -->
    <div class="stat-card present">
        <div class="stat-card-header">
            <div class="stat-card-title">Present Today</div>
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="stat-card-value" id="stat-present-count">0</div>
        <div class="stat-card-desc">Employees checked in & out</div>
    </div>
    
    <!-- Absent Today -->
    <div class="stat-card absent">
        <div class="stat-card-header">
            <div class="stat-card-title">Absent Today</div>
            <div class="stat-card-icon"><i class="fas fa-times-circle"></i></div>
        </div>
        <div class="stat-card-value" id="stat-absent-count">0</div>
        <div class="stat-card-desc">No attendance record</div>
    </div>
    
    <!-- Late Arrivals -->
    <div class="stat-card late">
        <div class="stat-card-header">
            <div class="stat-card-title">Late Arrivals</div>
            <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
        <div class="stat-card-value" id="stat-late-count">0</div>
        <div class="stat-card-desc">Checked in after 8:00 AM</div>
    </div>
    
    <!-- Live Headcount -->
    <div class="stat-card live">
        <div class="stat-card-header">
            <div class="stat-card-title">Live Headcount</div>
            <div class="stat-card-icon"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card-value" id="stat-live-total">0</div>
        <div class="stat-card-breakdown">
            <span><i class="fas fa-sun"></i> <span id="stat-live-morning">0</span> Morning</span>
            <span><i class="fas fa-cloud-sun"></i> <span id="stat-live-afternoon">0</span> Afternoon</span>
            <span><i class="fas fa-moon"></i> <span id="stat-live-night">0</span> Night</span>
            <span><i class="fas fa-random"></i> <span id="stat-live-flexible">0</span> Flexible</span>
        </div>
    </div>
</div>

<div class="section-header">
    <div>
        <div class="section-title">Attendance Summary</div>
        <div class="section-subtitle">View attendance status for any date</div>
    </div>
</div>

<div class="date-selector">
    <div class="date-label">Select Date</div>
    <input type="date" class="date-input" id="attendanceDateFilter" value="<?php echo date('Y-m-d'); ?>" 
           onchange="updateDateLabel(this.value); updateAttendanceSummary(this.value);" />
</div>

<div class="section-subtitle" style="margin: 10px 0;">
    Showing data for: <span id="selectedDateLabel"><?php echo date('l, F j, Y'); ?></span>
</div>

<div class="record-list">
    <div class="record-item" onclick="showAttendanceList('present')" style="cursor: pointer;">
        <div class="record-info">
            <div class="record-icon present"><i class="fas fa-user-check"></i></div>
            <div class="record-details">
                <div class="record-name">Present</div>
            </div>
        </div>
        <div class="record-badge present" id="summary-present-count">0</div>
    </div>
    
    <div class="record-item" onclick="showAttendanceList('late')" style="cursor: pointer;">
        <div class="record-info">
            <div class="record-icon late"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="record-details">
                <div class="record-name">Late</div>
            </div>
        </div>
        <div class="record-badge late" id="summary-late-count">0</div>
    </div>
    
    <div class="record-item" onclick="showAttendanceList('absent')" style="cursor: pointer;">
        <div class="record-info">
            <div class="record-icon absent"><i class="fas fa-user-slash"></i></div>
            <div class="record-details">
                <div class="record-name">Absent</div>
            </div>
        </div>
        <div class="record-badge absent" id="summary-absent-count">0</div>
    </div>
</div>

<div style="display: flex; justify-content: space-between; padding: 10px 0; border-top: 1px solid var(--border-color); margin-top: 15px;">
    <div>Total Records:</div>
    <div style="font-weight: bold;" id="summary-total-count">0</div>
</div>
<div class="section-header" style="margin-top: 30px;"><div class="section-title">Generate Attendance Report</div><div class="section-subtitle">Export attendance data to PDF</div></div>
<div class="section-subtitle" style="margin: 10px 0;">Generate comprehensive attendance reports for any date range with detailed statistics and employee information.</div>
<button class="action-button primary" onclick="generateAttendanceReport()" style="width: 100%; margin-top: 10px;"><i class="fas fa-file-pdf" style="margin-right: 8px;"></i> Create PDF Report</button>
</div>

<div class="admin-section" id="attendanceSection" style="display: none;">
    <div class="section-header">
        <div>
            <div class="section-title">📊 Attendance Records</div>
            <div class="section-subtitle">View and manage historical attendance data</div>
        </div>
        <button class="action-button primary" onclick="exportAttendanceData()" style="padding: 8px 16px;">
            <i class="fas fa-download"></i> Export Data
        </button>
    </div>

    <!-- Enhanced Date Filter -->
    <div class="modern-filter-bar" style="background: rgba(255,255,255,0.02); padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--border-color);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
            <!-- Replace the existing Start Date and End Date inputs with these -->
<div>
    <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">Start Date</label>
    <input type="date" id="attendanceStartDate" autocomplete="off" class="modern-input" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;" />
</div>
<div>
    <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">End Date</label>
    <input type="date" id="attendanceEndDate" autocomplete="off" class="modern-input" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;" />
</div>
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">Search</label>
                <input type="text" id="attendanceSearchInput" class="modern-input" placeholder="🔍 Search by Name or EID..." style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;" />
            </div>
            <div>
                <button class="action-button primary" onclick="loadAttendanceHistory()" style="width: 100%; padding: 10px;">
                    <i class="fas fa-sync-alt"></i> Load Records
                </button>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="attendance-stats-grid" style="margin-bottom: 25px;">
        <div class="attendance-stat-card" style="background: linear-gradient(135deg, rgba(40,167,69,0.1) 0%, rgba(40,167,69,0.05) 100%); border-left: 4px solid #28a745;">
            <div class="stat-icon-box present"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <h4>Total Present</h4>
                <div class="count" id="totalPresentCount">0</div>
            </div>
        </div>
        <div class="attendance-stat-card" style="background: linear-gradient(135deg, rgba(255,193,7,0.1) 0%, rgba(255,193,7,0.05) 100%); border-left: 4px solid #ffc107;">
            <div class="stat-icon-box late"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <h4>Late Arrivals</h4>
                <div class="count" id="totalLateCount">0</div>
            </div>
        </div>
        <div class="attendance-stat-card" style="background: linear-gradient(135deg, rgba(220,53,69,0.1) 0%, rgba(220,53,69,0.05) 100%); border-left: 4px solid #dc3545;">
            <div class="stat-icon-box absent"><i class="fas fa-times-circle"></i></div>
            <div class="stat-info">
                <h4>Absent</h4>
                <div class="count" id="totalAbsentCount">0</div>
            </div>
        </div>
    </div>

    <!-- Attendance List -->
    <div class="attendance-list-container" id="attendanceHistoryList">
        <div style="text-align: center; padding: 60px 20px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 2px dashed var(--border-color);">
            <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">📅</div>
            <div style="font-size: 16px; color: var(--text-primary); font-weight: 600; margin-bottom: 8px;">Select Date Range to View Records</div>
            <div style="font-size: 13px; color: var(--text-muted);">Choose start and end dates above, then click "Load Records"</div>
        </div>
    </div>
</div>

<div class="admin-section" id="reportsSection" style="display: none;">  <!-- ✅ PASTE BEFORE THIS OPENING DIV (line 2899) -->    
<div class="section-header"><div><div class="section-title">Reports</div><div class="section-subtitle">Generate and export attendance, leave, and payroll-ready reports</div></div></div>
<div class="filter-section">
<div class="filter-group"><div class="filter-label">Start Date</div><input type="date" class="filter-input" id="reportStartDate" /></div>
<div class="filter-group"><div class="filter-label">End Date</div><input type="date" class="filter-input" id="reportEndDate" /></div>
<div class="filter-group"><div class="filter-label">Report Type</div><select class="filter-select" id="reportType"><option value="attendance">Attendance</option><option value="leave">Leave Summary</option><option value="payroll">Payroll-Ready (with Night Diff)</option></select></div>
<div class="filter-group"><div class="filter-label">Scope</div><select class="filter-select" id="reportScope" onchange="toggleReportFilterInput()"><option value="all">Whole Database</option><option value="department">Per Department</option><option value="employee">Individual Employee</option></select></div>
<div class="filter-group" id="reportFilterValueGroup" style="display:none;"><div class="filter-label">Filter Value</div><input type="text" class="filter-input" id="reportFilterValue" placeholder="Dept Name or EID" /></div>
</div>
<div class="section-subtitle" style="margin: 10px 0;">Preview will appear below after selection.</div>
<div class="record-list" id="reportPreview"><div class="record-item" style="justify-content: center; font-style: italic; color: var(--text-muted);">Select dates and report type to generate preview.</div></div>
<button class="action-button primary" style="margin-top: 20px; width: 100%;" onclick="generateCustomReport()"><i class="fas fa-file-export"></i> Export Report</button>
</div>

<!-- ENHANCED LEAVE MANAGEMENT SECTION -->
<div class="admin-section" id="leaveadministrationSection" style="display: none;">
    <div class="section-header">
        <div>
        <div class="section-title"><i class="fas fa-calendar-check" style="margin-right: 8px; color: var(--primary-accent);"></i>Leave Management</div>
        <div class="section-subtitle">Approve/reject VL, SL, and manage night differential eligibility</div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <select id="leaveStatusFilter" class="filter-select" onchange="filterLeaveRequests()" style="padding: 8px 12px; font-size: 13px; min-width: 150px;">
            <option value="All Requests" selected>All Requests</option>
            <option value="Pending">Pending</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
        </select>
        <input type="text" class="search-input" id="leaveAdminSearch" placeholder="Search..." onkeyup="filterLeaveRequests()" style="padding: 8px 12px; font-size: 13px; width: 200px;" />
        <button class="action-button secondary" onclick="refreshLeaveRequests()" style="padding: 8px 12px; font-size: 13px;" title="Refresh">
            <i class="fas fa-sync-alt"></i>
        </button>
        <button class="action-button secondary" onclick="openLeaveHistoryModal()" style="border-color: var(--primary-accent); color: var(--primary-accent); padding: 8px 12px; font-size: 13px;" title="History">
            <i class="fas fa-history"></i> <span style="display:none">@media (min-width: 768px){inline} History</span>
        </button>
    </div>
</div>


    <!-- Stats Overview (Optional but cool) -->
    <div class="dashboard-stat-grid" style="margin-bottom: 30px; grid-template-columns: repeat(3, 1fr);">
        <div class="stat-card late">
            <div class="stat-card-header">
                <div class="stat-card-title">Pending</div>
                <div class="stat-card-icon"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-card-value" id="count-pending">0</div>
            <div class="stat-card-desc">Awaiting action</div>
        </div>
        <div class="stat-card present">
            <div class="stat-card-header">
                <div class="stat-card-title">Approved</div>
                <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="stat-card-value" id="count-approved">0</div>
            <div class="stat-card-desc">This month</div>
        </div>
        <div class="stat-card absent">
            <div class="stat-card-header">
                <div class="stat-card-title">Rejected</div>
                <div class="stat-card-icon"><i class="fas fa-times-circle"></i></div>
            </div>
            <div class="stat-card-value" id="count-rejected">0</div>
            <div class="stat-card-desc">This month</div>
        </div>
    </div>

    <!-- Leave Requests List -->
    <div class="record-list" id="leaveRequestsList">
        <?php
        // Fetch ALL requests, ordered by newest first
        $stmt = $pdo->query("SELECT * FROM day_off_requests ORDER BY created_at DESC");
        $allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Initialize counters
        $pendingCount = 0;
        $approvedCount = 0;
        $rejectedCount = 0;

        if (empty($allRequests)) {
            echo '<div class="record-item" style="justify-content: center; background: rgba(255,255,255,0.02);">
                    <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <div style="font-size: 18px; font-weight: 600; color: var(--text-primary);">No leave requests found</div>
                        <div style="font-size: 14px;">Requests will appear here as employees submit them.</div>
                    </div>
                  </div>';
        } else {
            foreach ($allRequests as $request) {
                // Get Employee Details
                $empStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                $empStmt->execute([$request['employee_id']]);
                $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
                
                // Calculate Duration
                $startDate = new DateTime($request['start_date']);
                $endDate = new DateTime($request['end_date']);
                $duration = $startDate->diff($endDate)->days + 1;
                
                // Determine Status Styles
                $status = $request['status'];
                $statusLower = strtolower($status);
                $badgeColor = $statusLower === 'approved' ? '#28a745' : ($statusLower === 'rejected' ? '#dc3545' : '#ffc107');
                $badgeBg = $statusLower === 'approved' ? 'rgba(40,167,69,0.1)' : ($statusLower === 'rejected' ? 'rgba(220,53,69,0.1)' : 'rgba(255,193,7,0.1)');
                
                // Update Counters
                if($statusLower === 'pending') $pendingCount++;
                elseif($statusLower === 'approved') $approvedCount++;
                elseif($statusLower === 'rejected') $rejectedCount++;

                // Avatar Initials
                $initials = strtoupper(substr($employee['name'], 0, 1));
                if (strpos($employee['name'], ' ') !== false) {
                    $initials .= strtoupper(substr($employee['name'], strpos($employee['name'], ' ') + 1, 1));
                }
                ?>
                
                <!-- Dynamic Leave Request Card -->
                <div class="record-item leave-request-card" 
                     data-status="<?= htmlspecialchars($statusLower) ?>" 
                     data-name="<?= htmlspecialchars(strtolower($employee['name'])) ?>" 
                     data-eid="<?= htmlspecialchars(strtolower($employee['employee_id'])) ?>">
                    
                    <div class="employee-info">
                        <!-- Avatar -->
                        <div class="employee-avatar">
                            <?php if (!empty($employee['photo_path']) && file_exists($employee['photo_path'])): ?>
                                <img src="<?= htmlspecialchars($employee['photo_path']) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;">
                            <?php else: ?>
                                <?= $initials ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Details -->
                        <div class="employee-details">
                            <div class="employee-name"><?= htmlspecialchars($employee['name']) ?></div>
                            <div class="employee-meta">
                                EID: <?= htmlspecialchars($employee['employee_id']) ?> • <?= htmlspecialchars($employee['department'] ?? 'N/A') ?>
                                <span style="margin: 0 8px; color: var(--border-color);">|</span>
                                <span style="color: var(--primary-accent);"><?= $duration ?> Days</span>
                            </div>
                            <div class="leave-reason">
                                <span class="leave-reason-label">Reason:</span>
                                <span><?= htmlspecialchars($request['reason']) ?></span>
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 6px;">
                                <i class="far fa-clock"></i> Requested: <?= date('M d, Y h:i A', strtotime($request['created_at'])) ?>
                                <?php if($request['updated_at']): ?>
                                    • Updated: <?= date('M d, Y h:i A', strtotime($request['updated_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons / Status Badge -->
                    <div class="action-buttons">
                        <?php if ($statusLower === 'pending'): ?>
                            <button class="btn-approve" onclick="approveLeave(<?= $request['id'] ?>)">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn-reject" onclick="rejectLeave(<?= $request['id'] ?>)">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php else: ?>
                            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 16px; background: <?= $badgeBg ?>; border-radius: 8px; border: 1px solid <?= $badgeColor ?>; color: <?= $badgeColor ?>; font-weight: 600; font-size: 13px;">
                                <i class="fas <?= $statusLower === 'approved' ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                <?= $status ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php
            }
        }
        ?>
    </div>
</div>

</div>
<div class="admin-section" id="manualcheckinSection" style="display: none;">
    <div class="section-header">
        <div>
            <div class="section-title">⌨️ Manual Check-in Settings</div>
            <div class="section-subtitle">Enable or disable manual attendance for employees</div>
        </div>
    </div>
    
    <div class="card" style="max-width: 600px; margin: 0 auto; text-align: center; padding: 40px;">
        <div style="font-size: 48px; color: var(--primary-accent); margin-bottom: 20px;">
            <i class="fas <?= $manualCheckinAllowed ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
        </div>
        <h3 style="color: var(--text-primary); margin-bottom: 10px;">Manual Attendance is currently <?= $manualCheckinAllowed ? 'ENABLED' : 'DISABLED' ?></h3>
        <p style="color: var(--text-muted); margin-bottom: 30px;">
            When enabled, employees will see a "Manual Check In/Out" button on their dashboard as a backup if the fingerprint scanner fails.
        </p>
        
        <button id="toggleManualBtn" class="btn btn-primary" style="width: 200px;" onclick="toggleManualCheckin()">
            <?= $manualCheckinAllowed ? 'Disable Feature' : 'Enable Feature' ?>
        </button>
    </div>
</div>
<div class="admin-section" id="audittrailSection" style="display: none;">
<div class="section-header"><div><div class="section-title">Audit Trail</div><div class="section-subtitle">Track all system activities and changes</div></div><button class="action-button primary" onclick="refreshAuditTrail()" style="padding: 8px 16px;"><i class="fas fa-history"></i> Live Working Audit Trail History</button></div>
<div class="filter-section"><div class="filter-group"><div class="filter-label">Filter by Date</div><input type="date" id="auditTrailDate" class="filter-input" onchange="refreshAuditTrail()" /></div></div>
<div class="search-bar"><i class="fas fa-search search-icon"></i><input type="text" class="search-input" id="auditSearch" placeholder="Search audit logs..." onkeyup="filterAuditTrail(this.value)" /></div>
<div class="record-list" id="auditTrailList">
<?php
$stmt = $pdo->query("SELECT at.*, e.name as user_name FROM audit_trail at LEFT JOIN employees e ON (at.user = e.employee_id OR at.user = e.id) ORDER BY at.timestamp DESC LIMIT 50");
$auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($auditLogs)) {
echo '<div class="record-item" style="text-align: center; padding: 20px; color: var(--text-muted);">No audit trail records found.</div>';
} else {
foreach ($auditLogs as $log) {
$userDisplay = htmlspecialchars($log['user']);
if ($log['user_name']) {
$userDisplay = htmlspecialchars($log['user_name']) . ' (' . htmlspecialchars($log['user']) . ')';
}
echo '<div class="record-item" data-action="' . htmlspecialchars(strtolower($log['action'])) . '" data-user="' . htmlspecialchars(strtolower($log['user'])) . '" data-display="' . htmlspecialchars(strtolower($userDisplay)) . '">';
echo '<div class="record-info">';
echo '<div class="record-avatar">📋</div>';
echo '<div class="record-details">';
echo '<div class="record-name">' . htmlspecialchars($log['action']) . '</div>';
echo '<div class="record-id">By: ' . $userDisplay . ' • ' . date('M d, Y h:i A', strtotime($log['timestamp'])) . '</div>';
echo '</div>';
echo '</div>';
echo '</div>';
}
}
?>
</div>
</div>

<!-- ✅ REMOVED THE GLOBAL INLINE FORM HERE -->

<div class="admin-section" id="fingerprintSection" style="display: none;">
<div class="section-header">
<div>
    <div class="section-title">👆 Fingerprint Management</div>
    <div class="section-subtitle">Manage employee fingerprint enrollments and view scan logs</div>
</div>
<div style="display: flex; gap: 10px;">
    <!-- ✅ KIOSK LINK BUTTON - ADD THIS -->
    <a href="scan-display.php?mode=kiosk" target="_blank" class="action-button secondary" style="padding: 8px 16px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
        <i class="fas fa-desktop"></i> Open Kiosk Display
    </a>
    <!-- ✅ END KIOSK BUTTON -->
    <button class="action-button primary" onclick="loadFingerprintData()"><i class="fas fa-sync-alt"></i> Refresh</button>
</div>
</div>

<!-- ✅ LIVE STATS GRID - ADD THIS BLOCK -->
<div class="dashboard-stat-grid" style="margin-bottom: 30px;">
<!-- Enrolled Card -->
<div class="stat-card present">
<div class="stat-card-header"><div class="stat-card-title">Enrolled Employees</div><div class="stat-card-icon"><i class="fas fa-fingerprint"></i></div></div>
<div class="stat-card-value" id="fpEnrolledCount">0</div>
<div class="stat-card-desc">With fingerprint ID</div>
</div>

<!-- Today's Scans Card (LIVE) -->
<div class="stat-card live">
<div class="stat-card-header"><div class="stat-card-title">Today's Scans</div><div class="stat-card-icon"><i class="fas fa-check-circle"></i></div></div>
<div class="stat-card-value" id="fpTodayScans">0</div>
<div class="stat-card-desc">Successful scans</div>
</div>

<!-- Pending Card -->
<div class="stat-card late">
<div class="stat-card-header"><div class="stat-card-title">Pending Enrollment</div><div class="stat-card-icon"><i class="fas fa-user-plus"></i></div></div>
<div class="stat-card-value" id="fpPendingCount">0</div>
<div class="stat-card-desc">Need fingerprint</div>
</div>
</div>

    <!-- Rest of the section (Table, Scan List, etc.) remains the same -->
    <div class="section-header" style="margin-top: 20px;"><div class="section-title">Enrolled Employees</div></div>
    <div class="search-bar"><i class="fas fa-search search-icon"></i><input type="text" class="search-input" id="fpSearchInput" placeholder="Search enrolled employees..." onkeyup="filterFingerprintTable(this.value)" /></div>
    <table class="modern-employee-table">
        <thead>
        <tr>
            <th class="col-id">Photo</th>
            <th class="col-name">Employee Name</th>
            <th class="col-dept">Employee ID</th>
            <th class="col-dept">Department</th>
            <th class="col-finger">Finger ID</th>
            <th class="col-actions">Actions</th>
        </tr>
        </thead>
        <tbody id="fingerprintTableBody">
        <tr>
            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
            <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i>
            <div>Loading employee data...</div>
            </td>
        </tr>
        </tbody>
    </table>
    
    <div class="section-header" style="margin-top: 30px;"><div class="section-title">Recent Fingerprint Scans</div></div>
    <div class="record-list" id="fingerprintScansList"><div style="padding: 20px; text-align: center; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i><div>Loading recent scans...</div></div></div>
    
    <div style="margin-top: 30px; padding: 20px; background: rgba(25,211,197,0.05); border-radius: 12px; border-left: 4px solid var(--primary-accent);">
        <h4 style="color: var(--primary-accent); margin-bottom: 15px;"><i class="fas fa-info-circle"></i> How to Enroll Fingerprints</h4>
        <ol style="color: var(--text-secondary); line-height: 2; margin-left: 20px;">
        <li>Go to <strong>Employees</strong> section and edit an employee</li>
        <li>Assign a <strong>Finger ID</strong> (1-127) to the employee</li>
        <li>Use the NodeMCU enrollment sketch to enroll their fingerprint</li>
        <li>Once enrolled, scans will automatically log attendance</li>
        <li>View scan logs and enrolled employees in this section</li>
        </ol>
    </div>
</div>
<div class="admin-section" id="employeesSection" style="display: none;">
    <!-- SCHEDULE MANAGEMENT MODAL -->
<div id="scheduleModal" class="modal" style="display: none;">
    <div class="modal-content modern-employee-modal">
        <div class="modal-header">
            <div>
                <h3 id="scheduleModalTitle"><i class="fas fa-calendar-check"></i> Manage Work Schedule</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">Assign shifts for each day of the week.</p>
            </div>
            <span class="close-modal" onclick="closeScheduleModal()">&times;</span>
        </div>
        <<form id="scheduleForm" onsubmit="saveSchedule(event)">
<input type="hidden" id="scheduleEmployeeId" name="employee_id" />
<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            
            <div class="modal-body-grid" style="grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Left Column -->
                <div>
                    <div class="form-group">
                        <label class="form-label">Monday</label>
<select name="monday" class="form-input-modern" style="width:100%">
    <option value="Off">Off</option>
    <option value="Early Shift">Early Shift (07:00 AM - 04:00 PM)</option>
<option value="Morning Shift">Morning Shift (08:00 AM - 05:00 PM)</option>
<option value="Mid Shift">Mid Shift (09:00 AM - 06:00 PM)</option>
<option value="Afternoon Shift">Afternoon Shift (02:00 PM - 11:00 PM)</option>
<option value="Night Shift">Night Shift (10:00 PM - 07:00 AM)</option>
    <option value="Flexible">Flexible Hours</option>
</select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tuesday</label>
                        <select name="tuesday" class="form-input-modern" style="width:100%">
                            <option value="Off">Off</option>
   <option value="Early Shift">Early Shift (07:00 AM - 04:00 PM)</option>
<option value="Morning Shift">Morning Shift (08:00 AM - 05:00 PM)</option>
<option value="Mid Shift">Mid Shift (09:00 AM - 06:00 PM)</option>
<option value="Afternoon Shift">Afternoon Shift (02:00 PM - 11:00 PM)</option>
<option value="Night Shift">Night Shift (10:00 PM - 07:00 AM)</option>
    <option value="Flexible">Flexible Hours</option>
</select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Wednesday</label>
                        <select name="wednesday" class="form-input-modern" style="width:100%">
                           <option value="Off">Off</option>
    <option value="Early Shift">Early Shift (07:00 AM - 04:00 PM)</option>
<option value="Morning Shift">Morning Shift (08:00 AM - 05:00 PM)</option>
<option value="Mid Shift">Mid Shift (09:00 AM - 06:00 PM)</option>
<option value="Afternoon Shift">Afternoon Shift (02:00 PM - 11:00 PM)</option>
<option value="Night Shift">Night Shift (10:00 PM - 07:00 AM)</option>
    <option value="Flexible">Flexible Hours</option>
</select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Thursday</label>
                        <select name="thursday" class="form-input-modern" style="width:100%">
                            <option value="Off">Off</option>
    <option value="Early Shift">Early Shift (07:00 AM - 04:00 PM)</option>
<option value="Morning Shift">Morning Shift (08:00 AM - 05:00 PM)</option>
<option value="Mid Shift">Mid Shift (09:00 AM - 06:00 PM)</option>
<option value="Afternoon Shift">Afternoon Shift (02:00 PM - 11:00 PM)</option>
<option value="Night Shift">Night Shift (10:00 PM - 07:00 AM)</option>
    <option value="Flexible">Flexible Hours</option>
</select>
                    </div>
                </div>
                <!-- Right Column -->
                <div>
                    <div class="form-group">
                        <label class="form-label">Friday</label>
                        <select name="friday" class="form-input-modern" style="width:100%">
                           <option value="Off">Off</option>
    <option value="Early Shift">Early Shift (07:00 AM - 04:00 PM)</option>
<option value="Morning Shift">Morning Shift (08:00 AM - 05:00 PM)</option>
<option value="Mid Shift">Mid Shift (09:00 AM - 06:00 PM)</option>
<option value="Afternoon Shift">Afternoon Shift (02:00 PM - 11:00 PM)</option>
<option value="Night Shift">Night Shift (10:00 PM - 07:00 AM)</option>
    <option value="Flexible">Flexible Hours</option>
</select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Saturday</label>
                        <select name="saturday" class="form-input-modern" style="width:100%">
                           <option value="Off">Off</option>
    <option value="Early Shift">Early Shift (07:00 AM - 04:00 PM)</option>
<option value="Morning Shift">Morning Shift (08:00 AM - 05:00 PM)</option>
<option value="Mid Shift">Mid Shift (09:00 AM - 06:00 PM)</option>
<option value="Afternoon Shift">Afternoon Shift (02:00 PM - 11:00 PM)</option>
<option value="Night Shift">Night Shift (10:00 PM - 07:00 AM)</option>
    <option value="Flexible">Flexible Hours</option>
</select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sunday</label>
                        <select name="sunday" class="form-input-modern" style="width:100%">
                            <option value="Off">Off</option>
    <option value="Early Shift">Early Shift (07:00 AM - 04:00 PM)</option>
<option value="Morning Shift">Morning Shift (08:00 AM - 05:00 PM)</option>
<option value="Mid Shift">Mid Shift (09:00 AM - 06:00 PM)</option>
<option value="Afternoon Shift">Afternoon Shift (02:00 PM - 11:00 PM)</option>
<option value="Night Shift">Night Shift (10:00 PM - 07:00 AM)</option>
    <option value="Night Shift">Night Shift (22:00 - 07:00)</option>
    <option value="Flexible">Flexible Hours</option>
</select>
                    </div>
                    <div style="margin-top: 20px; padding: 15px; background: rgba(25, 211, 197, 0.05); border-radius: 8px; border: 1px solid var(--primary-accent);">
                        <div style="font-size: 13px; color: var(--text-primary); font-weight: 600;"><i class="fas fa-info-circle"></i> Shift Definitions</div>
                       <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
Early: 07:00 AM - 04:00 PM<br>
Morning: 08:00 AM - 05:00 PM<br>
Mid: 09:00 AM - 06:00 PM<br>
Afternoon: 02:00 PM - 11:00 PM<br>
Night: 10:00 PM - 07:00 AM
</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary-modern" onclick="closeScheduleModal()">Cancel</button>
                <button type="submit" class="btn btn-primary-modern">
                    <i class="fas fa-save"></i> Save Schedule
                </button>
            </div>
        </form>
    </div>
</div>
<div class="employees-header"><div class="employees-title"><h2>Employees</h2><p>Manage employee records and information</p></div><button class="add-employee-btn" onclick="openAddEmployeeModal()"><i class="fas fa-plus"></i> Add Employee</button></div>
<div class="employee-search-container"><i class="fas fa-search search-icon"></i><input type="text" class="employee-search-input" id="employeeSearchInput" placeholder="Search by name or EID..." onkeyup="filterEmployeesTable(this.value)" /></div>
<table class="modern-employee-table">
<thead><tr><th class="col-id">Employee ID</th><th class="col-name">Name</th><th class="col-position">Position</th><th class="col-dept">Department</th><th class="col-birthday">Birthday</th><th class="col-finger">Finger ID</th><th class="col-role">Role</th><th class="col-actions">Actions</th></tr></thead>
<tbody id="employeesTableBody">
<?php
$stmt = $pdo->query("SELECT * FROM employees ORDER BY name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($employees as $emp) {
$roleClass = strtolower($emp['role']);
$birthdayDisplay = $emp['birthday'] ? date('M d, Y', strtotime($emp['birthday'])) : '-';
$fingerDisplay = $emp['finger_id'] ? $emp['finger_id'] : '-';
echo '<tr data-id="' . $emp['id'] . '" data-name="' . htmlspecialchars(strtolower($emp['name'])) . '" data-eid="' . htmlspecialchars(strtolower($emp['employee_id'])) . '">';
echo '<td class="col-id">' . htmlspecialchars($emp['employee_id']) . '</td>';
// Check if photo exists
$avatarHtml = '<i class="fas fa-user"></i>'; // Default icon
if (!empty($emp['photo_path']) && file_exists($emp['photo_path'])) {
    $avatarHtml = '<img src="' . htmlspecialchars($emp['photo_path']) . '" alt="Photo" style="width: 100%; height: 100%; object-fit: cover;">';
}

echo '<td class="col-name">
        <div class="emp-avatar-wrapper">
            <div class="emp-avatar">' . $avatarHtml . '</div>
            <span class="emp-name-text" title="' . htmlspecialchars($emp['name']) . '">' . htmlspecialchars($emp['name']) . '</span>
        </div>
      </td>';
      echo '<td class="col-position" title="' . htmlspecialchars($emp['position']) . '">' . htmlspecialchars($emp['position']) . '</td>';
echo '<td class="col-dept" title="' . htmlspecialchars($emp['department'] ?? 'N/A') . '">' . htmlspecialchars($emp['department'] ?? 'N/A') . '</td>';
echo '<td class="col-birthday">' . $birthdayDisplay . '</td>';
echo '<td class="col-finger">' . $fingerDisplay . '</td>';
echo '<td class="col-role"><span class="role-badge ' . $roleClass . '">' . htmlspecialchars($emp['role']) . '</span></td>';
echo '<td class="col-actions"><div class="action-btn-group">';
// Schedule Button (New)
echo '<button class="action-icon-btn" style="color: #17a2b8;" title="Manage Schedule" onclick="openScheduleModal(' . $emp['id'] . ')"><i class="fas fa-calendar-alt"></i></button>';
// Edit Button
$jsName = addslashes($emp['name']);
$jsPos = addslashes($emp['position']);
$jsDept = addslashes($emp['department']);
$jsBirthday = $emp['birthday'] ?? '';
$jsFingerId = $emp['finger_id'] ?? '';
$jsPhoto = addslashes($emp['photo_path'] ?? '');
echo '<button class="action-icon-btn edit" title="Edit" onclick="openEditEmployeeModal(' . $emp['id'] . ', \'' . $jsName . '\', \'' . htmlspecialchars($emp['employee_id']) . '\', \'' . $jsPos . '\', \'' . $jsDept . '\', \'' . htmlspecialchars($emp['role']) . '\', \'' . $jsBirthday . '\', \'' . $jsFingerId . '\', \'' . $jsPhoto . '\')"><i class="fas fa-pen-to-square"></i></button>';
// Delete Button
echo '<button class="action-icon-btn delete" title="Delete" onclick="deleteEmployee(' . $emp['id'] . ')"><i class="fas fa-trash"></i></button>';
echo '</div></td>';
echo '</tr>';
}
?>
</tbody>
</table>
</div>
<!-- ARCHIVED EMPLOYEES SECTION -->
<div class="admin-section" id="archivesSection" style="display: none;">
    <div class="employees-header">
        <div class="employees-title">
            <h2>🗄️ Employee Archives</h2>
            <p>Previously deleted employees are stored here for record-keeping.</p>
        </div>
    </div>
    
    <table class="modern-employee-table">
        <thead>
            <tr>
                <th class="col-id">Archived ID</th>
                <th class="col-name">Name</th>
                <th class="col-position">Position</th>
                <th class="col-dept">Department</th>
                <th class="col-finger">Original EID</th>
                <th class="col-birthday">Archived Date</th>
                <th class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody id="archivesTableBody">
            <?php
            $stmt = $pdo->query("SELECT * FROM employee_archives ORDER BY archived_at DESC");
            $archives = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($archives)) {
                echo '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-muted);">No archived employees found.</td></tr>';
            } else {
                foreach ($archives as $arch) {
                    echo '<tr>';
                    echo '<td class="col-id">#' . $arch['original_id'] . '</td>';
                    echo '<td class="col-name">' . htmlspecialchars($arch['name']) . '</td>';
                    echo '<td class="col-position">' . htmlspecialchars($arch['position']) . '</td>';
                    echo '<td class="col-dept">' . htmlspecialchars($arch['department']) . '</td>';
                    echo '<td class="col-finger">' . htmlspecialchars($arch['employee_id']) . '</td>';
                    echo '<td class="col-birthday">' . date('M d, Y', strtotime($arch['archived_at'])) . '</td>';
                    echo '<td class="col-actions"><div class="action-btn-group">
                        <button class="action-icon-btn" style="color: #ffc107;" title="Restore (Coming Soon)"><i class="fas fa-undo"></i></button>
                    </div></td>';
                    echo '</tr>';
                }
            }
            ?>
        </tbody>
    </table>
</div>
<!-- ENHANCED DAY OFF REQUESTS SECTION -->
<div class="admin-section" id="dayoffSection" style="display: none;">
   <div class="section-header">
    <div>
        <div class="section-title"><i class="fas fa-calendar-alt" style="margin-right: 8px; color: var(--primary-accent);"></i>Day Off Requests</div>
        <div class="section-subtitle">Review and manage all day off requests</div>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <button class="action-button secondary" onclick="openDayOffHistoryModal()" style="border-color: var(--primary-accent); color: var(--primary-accent); padding: 8px 12px; font-size: 13px;" title="History">
            <i class="fas fa-history"></i> <span style="display:none">@media (min-width: 768px){inline} History</span>
        </button>
    </div>
</div>

<!-- DAY OFF HISTORY MODAL -->
<div id="dayOffHistoryModal" class="modal" style="display: none; z-index: 2000;">
    <div class="modal-content" style="max-width: 900px; max-height: 80vh; overflow-y: auto;">
        <div class="modal-header">
            <div>
                <h3><i class="fas fa-history"></i> Day Off Request History</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">Showing day off requests from the last 30 days</p>
            </div>
            <span class="close-modal" onclick="closeDayOffHistoryModal()">&times;</span>
        </div>
        <div style="padding: 20px;">
            <!-- Action Bar -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px;">
                <div style="font-size: 13px; color: var(--text-secondary);">
                    <i class="fas fa-info-circle"></i> Records older than 30 days can be purged to maintain performance.
                </div>
                <button class="btn-reject" onclick="purgeOldDayOffRecords()" style="padding: 8px 16px; font-size: 12px; border:none;">
                    <i class="fas fa-trash-alt"></i> Clear Records > 30 Days
                </button>
            </div>
            <!-- History List Container -->
            <div id="dayOffHistoryList" class="record-list">
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                    <div style="margin-top: 10px;">Loading history...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- OVERTIME TRACKER CONTENT END -->
    <!-- Modern Filter Bar -->
    <div class="modern-filter-bar" style="background: rgba(255,255,255,0.02); padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--border-color);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">Status</label>
                <select class="modern-select" id="dayOffStatusFilter" onchange="filterDayOffRequests()" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;">
                    <option value="All">All Statuses</option>
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Rejected">Rejected</option>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">From Date</label>
                <input type="date" class="modern-input" id="dayOffStartDate" onchange="filterDayOffRequests()" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;" />
            </div>
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">To Date</label>
                <input type="date" class="modern-input" id="dayOffEndDate" onchange="filterDayOffRequests()" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;" />
            </div>
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">Search Employee</label>
                <input type="text" class="modern-input" id="dayOffEmployeeSearch" placeholder="Employee name or EID" onkeyup="filterDayOffRequests()" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;" />
            </div>
        </div>
    </div>
    
    <!-- Stats Summary -->
    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(255,193,7,0.1) 0%, rgba(255,193,7,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid #ffc107;">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Pending</div>
            <div style="font-size: 24px; font-weight: 700; color: #ffc107;" id="pendingCount">0</div>
        </div>
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(40,167,69,0.1) 0%, rgba(40,167,69,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid #28a745;">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Approved</div>
            <div style="font-size: 24px; font-weight: 700; color: #28a745;" id="approvedCount">0</div>
        </div>
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(220,53,69,0.1) 0%, rgba(220,53,69,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid #dc3545;">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Rejected</div>
            <div style="font-size: 24px; font-weight: 700; color: #dc3545;" id="rejectedCount">0</div>
        </div>
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(25,211,197,0.1) 0%, rgba(25,211,197,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid var(--primary-accent);">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Total Requests</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--primary-accent);" id="totalCount">0</div>
        </div>
    </div>
    
    <!-- Day Off Requests List -->
    <div class="record-list" id="dayOffRecordsList">
        <?php
        $stmt = $pdo->query("SELECT * FROM day_off_requests ORDER BY created_at DESC");
        $allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($allRequests)) {
            echo '<div class="empty-state" style="text-align: center; padding: 60px 20px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 2px dashed var(--border-color);">
                <div style="font-size: 48px; margin-bottom: 15px;">📋</div>
                <div style="font-size: 16px; color: var(--text-primary); font-weight: 600; margin-bottom: 8px;">No Day Off Requests</div>
                <div style="font-size: 13px; color: var(--text-muted);">All day off requests will appear here</div>
            </div>';
        } else {
            foreach ($allRequests as $request) {
                $empStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                $empStmt->execute([$request['employee_id']]);
                $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
                $startDateDB = date('Y-m-d', strtotime($request['start_date']));
                $endDateDB = date('Y-m-d', strtotime($request['end_date']));
                $duration = (new DateTime($request['start_date']))->diff(new DateTime($request['end_date']))->days + 1;
                $statusColors = [
                    'Pending' => 'rgba(255,193,7,0.15); color: #ffc107; border-color: #ffc107',
                    'Approved' => 'rgba(40,167,69,0.15); color: #28a745; border-color: #28a745',
                    'Rejected' => 'rgba(220,53,69,0.15); color: #dc3545; border-color: #dc3545'
                ];
                $statusStyle = $statusColors[$request['status']] ?? $statusColors['Pending'];
                echo '<div class="modern-request-card" data-status="' . htmlspecialchars($request['status']) . '"
                      data-start="' . $startDateDB . '" data-end="' . $endDateDB . '"
                      data-employee="' . htmlspecialchars(strtolower($employee['name'] . ' ' . $employee['employee_id'])) . '"
                      style="background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 15px; transition: all 0.3s ease; position: relative; overflow: hidden;">
                    <div style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: ' . ($request['status'] == 'Pending' ? '#ffc107' : ($request['status'] == 'Approved' ? '#28a745' : '#dc3545')) . ';"></div>
                    <div style="display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: start;">
                        <div style="display: flex; gap: 15px; align-items: start;">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-accent), var(--secondary-accent)); display: flex; align-items: center; justify-content: center; color: #00110f; font-weight: 700; font-size: 16px; flex-shrink: 0;">
                                ' . strtoupper(substr($employee['name'], 0, 1)) . '
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                                    <div style="font-size: 16px; font-weight: 700; color: var(--text-primary);">' . htmlspecialchars($employee['name']) . '</div>
                                    <span class="status-badge-modern" style="padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; background: ' . $statusStyle . '; border: 1px solid;">' . htmlspecialchars($request['status']) . '</span>
                                </div>
                                <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">
                                    <span style="color: var(--text-secondary);">EID:</span> ' . htmlspecialchars($employee['employee_id']) . ' • ' . htmlspecialchars($employee['department'] ?? 'N/A') . '
                                </div>
                                <div style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 6px; margin-top: 8px;">
                                    <strong style="color: var(--text-muted);">Reason:</strong> ' . htmlspecialchars($request['reason']) . '
                                </div>
                            </div>
                        </div>
                        <div style="text-align: right; flex-shrink: 0;">
                            <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 5px;">
                                ' . date('M d, Y', strtotime($request['start_date'])) . ' - ' . date('M d, Y', strtotime($request['end_date'])) . '
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 10px;">
                                <i class="fas fa-calendar-day" style="margin-right: 5px;"></i>' . $duration . ' day' . ($duration > 1 ? 's' : '') . '
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted);">
                                Submitted: ' . date('M d, Y h:i A', strtotime($request['created_at'])) . '
                            </div>
                            ' . ($request['status'] == 'Pending' ? '
                            <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end;">
                                <button class="action-btn-approve" onclick="approveLeave(' . $request['id'] . ')" style="padding: 8px 16px; background: rgba(40,167,69,0.15); color: #28a745; border: 1px solid #28a745; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                                    <i class="fas fa-check" style="margin-right: 5px;"></i>Approve
                                </button>
                                <button class="action-btn-reject" onclick="rejectLeave(' . $request['id'] . ')" style="padding: 8px 16px; background: rgba(220,53,69,0.15); color: #dc3545; border: 1px solid #dc3545; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                                    <i class="fas fa-times" style="margin-right: 5px;"></i>Reject
                                </button>
                            </div>
                            ' : '') . '
                        </div>
                    </div>
                </div>';
            }
        }
        ?>
    </div>
</div>

<script>
function updateDepartmentChart(data) {
    const ctx = document.getElementById('departmentChart');
    if (!ctx) {
        console.error('Department Chart canvas not found!');
        return;
    }

    // Destroy existing chart to prevent overlay issues
    if (window.departmentChartInstance) {
        try {
            window.departmentChartInstance.destroy();
        } catch(e) {
            console.log('Chart destroy error:', e);
        }
    }

    try {
        window.departmentChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels || [],
                datasets: [{
                    data: data.values || [],
                    backgroundColor: ['#667eea', '#f5576c', '#4ade80', '#fbbf24', '#a78bfa', '#34d399', '#ec4899'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: 'var(--text-muted)',
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
    } 
    
    catch (e) {
        console.error('Department chart initialization error:', e);
    }
}
    function getCSRFToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || 
           document.querySelector('input[name="csrf_token"]')?.value || '';
}
// === HELPER: ANIMATE VALUE COUNTER ===
function animateValue(id, end, suffix = '') {
    const obj = document.getElementById(id);
    if (!obj) return;
    
    let start = 0;
    const duration = 1000; // Animation lasts 1 second
    let startTimestamp = null;
    
    const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        // Calculate current value based on progress
        const value = Math.floor(progress * (end - start) + start);
        obj.innerHTML = value + suffix;
        
        if (progress < 1) {
            window.requestAnimationFrame(step);
        } else {
            // Ensure final value is exact
            obj.innerHTML = end + suffix;
        }
    };
    window.requestAnimationFrame(step);
}
    /* === ENHANCED DEPARTMENTS MODULE LOGIC === */
let allDepartmentsData = [];

function loadDepartmentsData() {
    const grid = document.getElementById('departmentsGrid');
    if(!grid) {
        console.error('Departments grid element not found!');
        return;
    }
    
    // Show loading state
    grid.innerHTML = `
        <div class="departments-loading">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading department data...</p>
        </div>
    `;
    
    fetch('?action=get_all_departments')
    .then(r => {
        if (!r.ok) {
            throw new Error(`HTTP error! status: ${r.status}`);
        }
        return r.json();
    })
    .then(data => {
        console.log('Departments data received:', data);
        
        if(data.success && data.departments) {
            allDepartmentsData = data.departments;
            displayDepartments(data.departments);
            updateDepartmentStats(data.stats);
        } else {
            console.error('API Error:', data);
            grid.innerHTML = `
                <div class="departments-empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Error Loading Data</h3>
                    <p>${data.message || 'Failed to load departments'}</p>
                    <button class="action-button primary" onclick="loadDepartmentsData()" style="margin-top: 15px;">
                        <i class="fas fa-sync-alt"></i> Retry
                    </button>
                </div>
            `;
        }
    })
    .catch(e => {
        console.error('Fetch Error:', e);
        grid.innerHTML = `
            <div class="departments-empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Connection Error</h3>
                <p>${e.message || 'Failed to connect to server'}</p>
                <button class="action-button primary" onclick="loadDepartmentsData()" style="margin-top: 15px;">
                    <i class="fas fa-sync-alt"></i> Retry
                    </button>
            </div>
        `;
    });
}

function displayDepartments(departments) {
    const grid = document.getElementById('departmentsGrid');
    if(!grid) return;
    
    if(departments.length === 0) {
        grid.innerHTML = `
            <div class="departments-empty-state">
                <i class="fas fa-building"></i>
                <h3>No Departments Yet</h3>
                <p>Click "Add Department" to create your first department</p>
                <button class="add-department-btn" onclick="openAddDepartmentModal()" style="margin: 20px auto;">
                    <i class="fas fa-plus-circle"></i> Add Department
                </button>
            </div>
        `;
        return;
    }
    
    let html = '';
    departments.forEach(dept => {
        const attendanceRate = Math.round(dept.attendance_rate || 0);
        const presentToday = dept.present_today || 0;
        const totalEmployees = dept.total_employees || 0;
        
        // Determine color based on attendance rate
        let progressColor = '#28a745'; // Green
        if(attendanceRate < 50) progressColor = '#dc3545'; // Red
        else if(attendanceRate < 80) progressColor = '#ffc107'; // Yellow
        
        // Get employee avatars
        let avatarsHtml = '<div class="employee-avatar-stack">';
        (dept.employees || []).slice(0, 5).forEach(emp => {
            if(emp.photo_path && emp.photo_path !== '') {
                avatarsHtml += `<img src="${emp.photo_path}" alt="${emp.name}" title="${emp.name}">`;
            } else {
                const initials = emp.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
                avatarsHtml += `<div class="avatar-placeholder">${initials}</div>`;
            }
        });
        avatarsHtml += '</div>';
        
        const remainingCount = totalEmployees - 5;
        if(remainingCount > 0) {
            avatarsHtml += `<div class="avatar-placeholder" style="margin-left: -10px; background: rgba(255,255,255,0.1); color: var(--text-muted);">+${remainingCount}</div>`;
        }
        
        html += `
        <div class="department-card" data-department="${dept.name}" data-status="${dept.status}" data-employees="${totalEmployees}" data-attendance="${attendanceRate}">
            <div class="department-card-header">
                <div class="department-info">
                    <div class="department-icon-wrapper">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="department-name-wrapper">
<div class="department-name" title="${dept.name}">${dept.name}</div>
${dept.head ? `<div class="department-head"><i class="fas fa-user-tie"></i> Head: ${dept.head}</div>` : ''}
</div>
                </div>
                <div class="department-actions">
                    <button class="department-action-btn view" title="View Employees" onclick="viewDepartmentDetails(${dept.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="department-action-btn edit" title="Edit Department" onclick="openEditDepartmentModal(${dept.id}, '${dept.name.replace(/'/g, "\\'")}', '${(dept.head || '').replace(/'/g, "\\'")}', '${(dept.description || '').replace(/'/g, "\\'")}', '${dept.status}')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="department-action-btn delete" title="Delete Department" onclick="deleteDepartment(${dept.id}, '${dept.name.replace(/'/g, "\\'")}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            
            <div class="department-card-body">
                <div class="department-employees">
                    ${avatarsHtml}
                    <div class="employee-count-text"><strong>${totalEmployees}</strong> ${totalEmployees === 1 ? 'Employee' : 'Employees'}</div>
                </div>
                
                <div class="department-stats-grid">
                    <div class="department-stat-box">
                        <div class="department-stat-value" style="color: #28a745;">${presentToday}</div>
                        <div class="department-stat-label">Present</div>
                    </div>
                    <div class="department-stat-box">
                        <div class="department-stat-value" style="color: #dc3545;">${totalEmployees - presentToday}</div>
                        <div class="department-stat-label">Absent</div>
                    </div>
                    <div class="department-stat-box">
                        <div class="department-stat-value" style="color: var(--primary-accent);">${attendanceRate}%</div>
                        <div class="department-stat-label">Attendance</div>
                    </div>
                </div>
                
                <div class="department-attendance-section">
                    <div class="department-attendance-header">
                        <span class="department-attendance-label">Attendance Rate (30 days)</span>
                        <span class="department-attendance-percentage" style="color: ${progressColor};">${attendanceRate}%</span>
                    </div>
                    <div class="department-progress-bar-container">
                        <div class="department-progress-bar" style="width: ${attendanceRate}%; background: ${progressColor};"></div>
                    </div>
                </div>
            </div>
            
            <div class="department-card-footer">
                <div class="department-status">
                    <div class="status-dot ${dept.status === 'active' ? '' : 'inactive'}"></div>
                    <span>${dept.status === 'active' ? 'Active' : 'Inactive'}</span>
                </div>
                <button class="department-view-btn" onclick="viewDepartmentDetails(${dept.id})">
                    <i class="fas fa-arrow-right"></i> View Details
                </button>
            </div>
        </div>
        `;
    });
    
    grid.innerHTML = html;
    
    // Animate progress bars after render
    setTimeout(() => {
        document.querySelectorAll('.department-progress-bar').forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => {
                bar.style.width = width;
            }, 100);
        });
    }, 100);
}

function updateDepartmentStats(stats) {
    if(stats) {
        animateValue('deptTotalCount', stats.total_departments || 0);
        animateValue('deptTotalEmployees', stats.total_employees || 0);
        animateValue('deptAvgAttendance', stats.avg_attendance || 0, '%');
        animateValue('deptPresentToday', stats.total_present || 0);
    }
}

function filterDepartments() {
    const search = document.getElementById('departmentSearch').value.toLowerCase();
    const statusFilter = document.getElementById('departmentStatusFilter').value;
    const sortFilter = document.getElementById('departmentSortFilter').value;
    
    let filtered = [...allDepartmentsData];
    
    // Apply search filter
    if(search) {
        filtered = filtered.filter(dept => 
            dept.name.toLowerCase().includes(search) ||
            (dept.head && dept.head.toLowerCase().includes(search))
        );
    }
    
    // Apply status filter
    if(statusFilter !== 'all') {
        filtered = filtered.filter(dept => dept.status === statusFilter);
    }
    
    // Apply sorting
    if(sortFilter === 'name') {
        filtered.sort((a, b) => a.name.localeCompare(b.name));
    } else if(sortFilter === 'employees') {
        filtered.sort((a, b) => b.total_employees - a.total_employees);
    } else if(sortFilter === 'attendance') {
        filtered.sort((a, b) => b.attendance_rate - a.attendance_rate);
    }
    
    displayDepartments(filtered);
}

function openAddDepartmentModal() {
    document.getElementById('departmentModalTitle').innerHTML = '<i class="fas fa-building"></i> Add Department';
    document.getElementById('departmentForm').reset();
    document.getElementById('departmentId').value = '';
    document.getElementById('departmentModal').style.display = 'block';
}

function openEditDepartmentModal(id, name, head, description, status) {
    document.getElementById('departmentModalTitle').innerHTML = '<i class="fas fa-building"></i> Edit Department';
    document.getElementById('departmentId').value = id;
    document.getElementById('departmentName').value = name;
    document.getElementById('departmentHead').value = head || '';
    document.getElementById('departmentDescription').value = description || '';
    document.getElementById('departmentStatus').value = status || 'active';
    document.getElementById('departmentModal').style.display = 'block';
}

function closeDepartmentModal() {
    document.getElementById('departmentModal').style.display = 'none';
    document.getElementById('departmentForm').reset();
}

function saveDepartment(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('action', 'save_department');
    
    const btn = event.target.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if(data.success) {
            showToast(data.message, 'success');
            closeDepartmentModal();
            loadDepartmentsData();
        } else {
            showToast('Error: ' + (data.message || 'Failed to save department'), 'error');
        }
    })
    .catch(e => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showToast('Network error: ' + e.message, 'error');
    });
}

function deleteDepartment(id, name) {
    showConfirm(`Are you sure you want to delete the department "${name}"? This cannot be undone.`, async () => {
        const formData = new FormData();
        formData.append('action', 'delete_department');
        formData.append('id', id);
        // Get fresh CSRF token from meta tag or hidden input
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        formData.append('csrf_token', csrfToken);
        
        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // Check content type
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server returned non-JSON response. Page may have reloaded.');
            }
            
            const data = await response.json();
            
            if(data.success) {
                showToast('Department deleted successfully', 'success');
                // Remove the card from DOM immediately for smooth UX
                const card = document.querySelector(`.department-card[data-department-id="${id}"]`);
                if(card) {
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.9)';
                    setTimeout(() => {
                        card.remove();
                        loadDepartmentsData(); // Refresh to update stats
                    }, 300);
                } else {
                    loadDepartmentsData();
                }
            } else {
                showToast('Error: ' + (data.message || 'Failed to delete department'), 'error');
            }
        } catch (error) {
            console.error('Delete error:', error);
            showToast('Network error: ' + error.message, 'error');
            // Reload page if there's a serious error
            setTimeout(() => location.reload(), 2000);
        }
    });
}
// === VIEW DEPARTMENT DETAILS (WITH EXPORT) ===
function viewDepartmentDetails(deptId) {
    const modal = document.getElementById('departmentDetailModal');
    const content = document.getElementById('departmentDetailContent');
    
    if(!modal || !content) {
        console.error("Department Detail Modal not found!");
        return;
    }

    // Show Loading State
    content.innerHTML = `
    <div style="text-align:center; padding:40px;">
        <i class="fas fa-spinner fa-spin" style="font-size:32px; color:var(--primary-accent); margin-bottom:15px;"></i>
        <div style="color:var(--text-muted);">Loading department details...</div>
    </div>`;
    
    modal.style.display = 'block';

    // Fetch Employees for this Department
    fetch(`?action=get_department_employees&department_id=${deptId}`)
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            let html = `
            <!-- Header Info -->
            <div class="department-detail-header">
                <div class="department-detail-icon"><i class="fas fa-building"></i></div>
                <div class="department-detail-info">
                    <h3>${data.department.name}</h3>
                    <p>${data.department.description || 'No description provided'} <br> 
                    <span style="opacity:0.7">Head: ${data.department.head || 'N/A'} • Status: ${data.department.status}</span></p>
                </div>
                <!-- NEW EXPORT BUTTON -->
                <button onclick="exportDepartmentEmployees(${deptId})" class="action-button secondary" style="margin-left:auto;">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
            </div>
            
            <!-- Employee List -->
            <div class="department-employees-list">
                <h4 style="margin-bottom:15px; color:var(--text-primary); border-bottom:1px solid var(--border-color); padding-bottom:10px;">
                    Employees (${data.count})
                </h4>
            `;

            if(data.employees.length === 0) {
                html += '<div style="text-align:center; color:var(--text-muted); padding:20px;">No employees found in this department.</div>';
            } else {
                data.employees.forEach(emp => {
                    // Determine Status Color
                    let statusColor = '#6c757d'; // Default gray
                    let statusText = 'Absent';
                    let statusBg = 'rgba(108, 117, 125, 0.1)';
                    
                    if(emp.today_status === 'present') {
                        statusColor = '#28a745'; statusText = 'Present'; statusBg = 'rgba(40, 167, 69, 0.1)';
                    } else if (emp.today_status === 'late') {
                        statusColor = '#ffc107'; statusText = 'Late'; statusBg = 'rgba(255, 193, 7, 0.1)';
                    }

                    // Avatar Logic
                    const avatarContent = emp.photo_path 
                        ? `<img src="${emp.photo_path}" style="width:100%; height:100%; object-fit:cover;">` 
                        : emp.name.charAt(0).toUpperCase();

                    html += `
                    <div class="department-employee-item">
                        <div class="department-employee-avatar">
                            ${avatarContent}
                        </div>
                        <div class="department-employee-info">
                            <div class="department-employee-name">${emp.name}</div>
                            <div class="department-employee-position">${emp.position || 'N/A'}</div>
                        </div>
                        <div class="department-employee-status" style="color:${statusColor}; border:1px solid ${statusColor}; background:${statusBg};">
                            ${statusText}
                        </div>
                    </div>`;
                });
            }
            html += '</div>';
            content.innerHTML = html;
        } else {
            content.innerHTML = `<div style="text-align:center; color:#dc3545; padding:20px;">Error: ${data.message}</div>`;
        }
    })
    .catch(e => {
        content.innerHTML = '<div style="text-align:center; color:#dc3545; padding:20px;">Connection error.</div>';
        console.error(e);
    });
}
// === EXPORT DEPARTMENT EMPLOYEES ===
function exportDepartmentEmployees(deptId) {
    try {
        // Open the PHP export endpoint in a new tab/window to trigger download
        const exportUrl = `?action=export_department_employees&department_id=${deptId}`;
        window.open(exportUrl, '_blank');
        showToast('Downloading department report...', 'success');
    } catch (error) {
        console.error('Export error:', error);
        showToast('Failed to export data', 'error');
    }
}
// === CLOSE DEPARTMENT DETAIL MODAL ===
function closeDepartmentDetailModal() {
    document.getElementById('departmentDetailModal').style.display = 'none';
}

// === GLOBAL VARIABLES ===
var currentCalendarDate = new Date(); // <--- ADD THIS LINE HERE
    // === DRAGGABLE AI BUBBLE LOGIC ===
const dragBtn = document.querySelector('.help-button');
let isDragging = false;
let startX, startY, initialLeft, initialTop;
let hasMoved = false; // To distinguish click from drag

if (dragBtn) {
    // Mouse Events
    dragBtn.addEventListener('mousedown', dragStart);
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', dragEnd);

    // Touch Events (for mobile)
    dragBtn.addEventListener('touchstart', dragStart, { passive: false });
    document.addEventListener('touchmove', drag, { passive: false });
    document.addEventListener('touchend', dragEnd);

    function dragStart(e) {
        if (e.type === 'touchstart') {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        } else {
            startX = e.clientX;
            startY = e.clientY;
        }

        // Get current position
        const rect = dragBtn.getBoundingClientRect();
        initialLeft = rect.left;
        initialTop = rect.top;

        isDragging = true;
        hasMoved = false;
        
        // Remove transition during drag for smooth movement
        dragBtn.style.transition = 'none';
    }

    function drag(e) {
        if (!isDragging) return;
        e.preventDefault(); // Prevent scrolling on mobile

        let currentX, currentY;
        if (e.type === 'touchmove') {
            currentX = e.touches[0].clientX;
            currentY = e.touches[0].clientY;
        } else {
            currentX = e.clientX;
            currentY = e.clientY;
        }

        const dx = currentX - startX;
        const dy = currentY - startY;

        // If moved more than 5 pixels, consider it a drag, not a click
        if (Math.abs(dx) > 5 || Math.abs(dy) > 5) {
            hasMoved = true;
        }

        dragBtn.style.left = `${initialLeft + dx}px`;
        dragBtn.style.top = `${initialTop + dy}px`;
        dragBtn.style.right = 'auto'; // Clear right constraint
        dragBtn.style.bottom = 'auto'; // Clear bottom constraint
    }

    function dragEnd() {
        isDragging = false;
        // Restore transition for hover effects
        dragBtn.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.1s';
    }

    // Prevent opening chat if it was a drag action
    dragBtn.addEventListener('click', function(e) {
        if (hasMoved) {
            e.preventDefault();
            e.stopPropagation();
            hasMoved = false; // Reset for next time
        }
    });
}
    // === SCHEDULE MODAL FUNCTIONS ===
function openScheduleModal(employeeId) {
    document.getElementById('scheduleEmployeeId').value = employeeId;
    document.getElementById('scheduleModal').style.display = 'block';
    
    // Reset form first
    document.getElementById('scheduleForm').reset();
    
    // Fetch existing schedule
    fetch(`?action=get_employee_schedule_admin&employee_id=${employeeId}`)
    .then(r => r.json())
    .then(data => {
        if(data.success && data.schedule) {
            // Populate fields
            const s = data.schedule;
            if(s.monday) document.querySelector('select[name="monday"]').value = s.monday;
            if(s.tuesday) document.querySelector('select[name="tuesday"]').value = s.tuesday;
            if(s.wednesday) document.querySelector('select[name="wednesday"]').value = s.wednesday;
            if(s.thursday) document.querySelector('select[name="thursday"]').value = s.thursday;
            if(s.friday) document.querySelector('select[name="friday"]').value = s.friday;
            if(s.saturday) document.querySelector('select[name="saturday"]').value = s.saturday;
            if(s.sunday) document.querySelector('select[name="sunday"]').value = s.sunday;
        }
    });
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').style.display = 'none';
}

function saveSchedule(event) {
event.preventDefault();
const formData = new FormData(document.getElementById('scheduleForm'));
formData.append('action', 'update_employee_schedule');
const btn = event.target.querySelector('button[type="submit"]');
const originalText = btn.innerHTML;
btn.disabled = true;
btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

fetch('', { 
    method: 'POST', 
    body: formData,
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
})
.then(r => {
    if (!r.ok) {
        throw new Error('HTTP error! status: ' + r.status);
    }
    return r.json();
})
.then(data => {
btn.disabled = false;
btn.innerHTML = originalText;
if(data.success) {
showToast(data.message, 'success');
closeScheduleModal();
// Refresh employee table
if(typeof loadFingerprintData === 'function') loadFingerprintData();
} else {
showToast('Error: ' + (data.message || 'Failed to save schedule'), 'error');
}
})
.catch(e => {
btn.disabled = false;
btn.innerHTML = originalText;
console.error('Schedule save error:', e);
showToast('Network error: ' + e.message, 'error');
});
}

// Close modal when clicking outside
window.onclick = function(event) {
    // ... existing modal close logic ...
    const scheduleModal = document.getElementById('scheduleModal');
    if (event.target == scheduleModal) {
        closeScheduleModal();
    }
}
// ✅ Check if Departments tab is active on load
const deptSection = document.getElementById('departmentsSection');
if(deptSection && deptSection.style.display !== 'none') {
    loadDepartmentsData();
}

// Set default date range (TODAY for Live View)
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date();
    // Format to YYYY-MM-DD
    const todayStr = today.toISOString().split('T')[0];
    
    // 1. Force Start and End Date to TODAY by default
    // Note: These IDs match the Admin Dashboard inputs
    const startDateInput = document.getElementById('attendanceStartDate');
    const endDateInput = document.getElementById('attendanceEndDate');
    
    if(startDateInput) startDateInput.value = todayStr;
    if(endDateInput) endDateInput.value = todayStr;
    
    // 2. Auto-load data immediately on page load
    // Only run if we are on the admin dashboard to avoid errors on employee portal
    if(document.getElementById('adminDashboard')) {
        loadAttendanceHistory();
    }
});

    // === INITIALIZE LEAVE FILTER ON LOAD ===
document.addEventListener('DOMContentLoaded', function() {
    // Run the filter immediately so the view matches the default dropdown selection
    if(document.getElementById('leaveStatusFilter')) {
        filterLeaveRequests();
    }
});
 
// Update stats counters
function updateDayOffStats() {
    const cards = document.querySelectorAll('#dayOffRecordsList .modern-request-card');
    let pending = 0, approved = 0, rejected = 0;
    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        if (status === 'Pending') pending++;
        else if (status === 'Approved') approved++;
        else if (status === 'Rejected') rejected++;
    });
    
    document.getElementById('pendingCount').textContent = pending;
    document.getElementById('approvedCount').textContent = approved;
    document.getElementById('rejectedCount').textContent = rejected;
    document.getElementById('totalCount').textContent = cards.length;
}

// Filter functionality
function filterDayOffRequests() {
    const statusFilter = document.getElementById('dayOffStatusFilter').value;
    const startDateFilter = document.getElementById('dayOffStartDate').value;
    const endDateFilter = document.getElementById('dayOffEndDate').value;
    const employeeSearch = document.getElementById('dayOffEmployeeSearch').value.toLowerCase().trim();
    const records = document.querySelectorAll('#dayOffRecordsList .modern-request-card');
    let visibleCount = 0;
    
    records.forEach(record => {
        const status = record.getAttribute('data-status');
        const startDate = record.getAttribute('data-start');
        const endDate = record.getAttribute('data-end');
        const employee = record.getAttribute('data-employee');
        let show = true;
        
        if (statusFilter !== 'All' && status !== statusFilter) show = false;
        if (startDateFilter && startDate < startDateFilter) show = false;
        if (endDateFilter && endDate > endDateFilter) show = false;
        if (employeeSearch && employee && !employee.includes(employeeSearch)) show = false;
        
        record.style.display = show ? 'flex' : 'none';
        record.style.animation = show ? 'slideIn 0.3s ease' : 'none';
        if (show) visibleCount++;
    });
    
    updateDayOffStats();
    
    // Show empty state if no results
    const listContainer = document.getElementById('dayOffRecordsList');
    if (visibleCount === 0 && cards.length > 0) {
        if (!document.querySelector('.filter-empty-state')) {
            const emptyState = document.createElement('div');
            emptyState.className = 'filter-empty-state';
            emptyState.innerHTML = `
                <div style="text-align: center; padding: 40px; grid-column: 1/-1;">
                    <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">🔍</div>
                    <div style="color: var(--text-muted);">No requests match your filters</div>
                </div>
            `;
            listContainer.appendChild(emptyState);
        }
    } else {
        const existingEmpty = listContainer.querySelector('.filter-empty-state');
        if (existingEmpty) existingEmpty.remove();
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateDayOffStats();
});

// Add hover effects
document.addEventListener('mouseover', function(e) {
    if (e.target.closest('.modern-request-card')) {
        const card = e.target.closest('.modern-request-card');
        card.style.transform = 'translateY(-2px)';
        card.style.boxShadow = '0 8px 25px rgba(0,0,0,0.3)';
    }
    if (e.target.closest('.action-btn-approve')) {
        e.target.style.background = 'rgba(40,167,69,0.25)';
        e.target.style.transform = 'translateY(-1px)';
    }
    if (e.target.closest('.action-btn-reject')) {
        e.target.style.background = 'rgba(220,53,69,0.25)';
        e.target.style.transform = 'translateY(-1px)';
    }
});

document.addEventListener('mouseout', function(e) {
    if (e.target.closest('.modern-request-card')) {
        const card = e.target.closest('.modern-request-card');
        card.style.transform = 'translateY(0)';
        card.style.boxShadow = 'none';
    }
    if (e.target.closest('.action-btn-approve')) {
        e.target.style.background = 'rgba(40,167,69,0.15)';
        e.target.style.transform = 'translateY(0)';
    }
    if (e.target.closest('.action-btn-reject')) {
        e.target.style.background = 'rgba(220,53,69,0.15)';
        e.target.style.transform = 'translateY(0)';
    }
});
</script>

<style>
    
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modern-request-card {
    animation: slideIn 0.3s ease;
}

.modern-request-card:hover {
    border-color: var(--primary-accent);
}

.status-badge-modern {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.action-btn-approve:hover,
.action-btn-reject:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .modern-filter-bar div {
        grid-template-columns: 1fr !important;
    }
    
    .modern-request-card > div {
        grid-template-columns: 1fr !important;
    }
    
    .modern-request-card > div > div:last-child {
        text-align: left !important;
        margin-top: 15px;
    }
    
    .modern-request-card > div > div:last-child > div:last-child {
        justify-content: flex-start !important;
    }
}
</style>
   <!-- DITO MO LAGAY MGA MODULESSSS MO!!!!!!!!!!!!!!!!!!!!!!!! -->

   <!-- === HOLIDAY MANAGEMENT MODULE === -->
<div class="admin-section" id="holidaysSection" style="display: none;">
    <div class="section-header">
        <div>
            <div class="section-title"><i class="fas fa-calendar-alt" style="margin-right: 8px; color: var(--primary-accent);"></i>Holiday Management 2026</div>
            <div class="section-subtitle">Manage holiday schedules and assign working employees</div>
        </div>
        <button class="action-button secondary" onclick="loadHolidays()" style="padding: 8px 16px;">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
    </div>
    
    <!-- Stats Overview -->
    <div class="dashboard-stat-grid" style="margin-bottom: 30px; grid-template-columns: repeat(3, 1fr);">
        <div class="stat-card present">
            <div class="stat-card-header"><div class="stat-card-title">Upcoming Holidays</div><div class="stat-card-icon"><i class="fas fa-star"></i></div></div>
<?php
// === HOLIDAY DASHBOARD COUNTS ===
$currentYear = date('Y');

// 1. Upcoming Holidays (from today onwards)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE YEAR(date) = ? AND date >= CURDATE()");
$stmt->execute([$currentYear]);
$upcomingCount = $stmt->fetchColumn() ?: 0;

// 2. Regular Holidays (total for the year)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE YEAR(date) = ? AND LOWER(type) = 'regular'");
$stmt->execute([$currentYear]);
$regularCount = $stmt->fetchColumn() ?: 0;

// 3. Special Non-Working (total for the year)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE YEAR(date) = ? AND LOWER(type) = 'special_non_working'");
$stmt->execute([$currentYear]);
$specialCount = $stmt->fetchColumn() ?: 0;
?>
<div class="stat-card-value" id="holTotalCount"><?= $upcomingCount ?></div>
            <div class="stat-card-desc">In 2026</div>
        </div>
        <div class="stat-card live">
            <div class="stat-card-header"><div class="stat-card-title">Regular Holidays</div><div class="stat-card-icon"><i class="fas fa-flag"></i></div></div>
<div class="stat-card-value" id="holRegularCount"><?= $regularCount ?></div>
            <div class="stat-card-desc">2x Pay if Worked</div>
        </div>
        <div class="stat-card late">
            <div class="stat-card-header"><div class="stat-card-title">Special Non-Working</div><div class="stat-card-icon"><i class="fas fa-bed"></i></div></div>
<div class="stat-card-value" id="holSpecialCount"><?= $specialCount ?></div>            <div class="stat-card-desc">+2.4hrs if Worked</div>
        </div>
    </div>

    <!-- Holiday List -->
    <div class="modern-filter-bar" style="background: rgba(255,255,255,0.02); padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--border-color);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="color: var(--text-primary); margin: 0;"><i class="fas fa-list"></i> Holiday Schedule</h4>
        </div>
        <div id="holidayListContainer" style="display: flex; flex-direction: column; gap: 15px;">
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                <div style="margin-top: 10px;">Loading holidays...</div>
            </div>
        </div>
    </div>
</div>

<!-- Holiday Assignment Modal -->
<div id="holidayAssignmentModal" class="modal" style="display: none;">
    <div class="modal-content modern-employee-modal">
        <div class="modal-header">
            <div>
                <h3 id="holidayModalTitle"><i class="fas fa-users-cog"></i> Assign Holiday Work</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">Select employees who will work on this holiday</p>
            </div>
            <span class="close-modal" onclick="closeHolidayModal()">&times;</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="holidayIdInput" />
            <div style="margin-bottom: 20px; padding: 15px; background: rgba(25, 211, 197, 0.1); border-radius: 8px; border-left: 4px solid var(--primary-accent);">
                <div style="font-weight: 700; color: var(--text-primary);" id="modalHolidayName">Holiday Name</div>
                <div style="font-size: 13px; color: var(--text-secondary);" id="modalHolidayDate">Date</div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;" id="modalHolidayRule">Pay Rule</div>
            </div>
            <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px;">
                <div id="employeeAssignmentList">
                    <div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary-modern" onclick="closeHolidayModal()">Cancel</button>
            <button type="button" class="btn btn-primary-modern" onclick="saveHolidayAssignment()">
                <i class="fas fa-save"></i> Save Assignments
            </button>
        </div>
    </div>
</div>
   <!-- === OVERTIME TRACKER MODULE === -->
<div class="admin-section" id="overtimeSection" style="display: none;">
    <div class="section-header">
        <div>
            <div class="section-title"><i class="fas fa-hourglass-half" style="margin-right: 8px; color: var(--primary-accent);"></i>Overtime Tracker</div>
            <div class="section-subtitle">Review and approve extra hours worked beyond standard shift</div>
        </div>
        <button class="action-button secondary" onclick="loadOvertimeData()" style="padding: 8px 16px;">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
    </div>

    <!-- Stats Overview -->
    <div class="dashboard-stat-grid" style="margin-bottom: 30px; grid-template-columns: repeat(4, 1fr);">
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(255,193,7,0.1) 0%, rgba(255,193,7,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid #ffc107;">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Pending Approval</div>
            <div style="font-size: 24px; font-weight: 700; color: #ffc107;" id="otPendingCount">0</div>
        </div>
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(40,167,69,0.1) 0%, rgba(40,167,69,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid #28a745;">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Approved</div>
            <div style="font-size: 24px; font-weight: 700; color: #28a745;" id="otApprovedCount">0</div>
        </div>
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(220,53,69,0.1) 0%, rgba(220,53,69,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid #dc3545;">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Rejected</div>
            <div style="font-size: 24px; font-weight: 700; color: #dc3545;" id="otRejectedCount">0</div>
        </div>
        <div class="stat-card-mini" style="background: linear-gradient(135deg, rgba(25,211,197,0.1) 0%, rgba(25,211,197,0.05) 100%); padding: 15px; border-radius: 10px; border-left: 4px solid var(--primary-accent);">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Total OT Hours</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--primary-accent);" id="otTotalHours">0h</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="modern-filter-bar" style="background: rgba(255,255,255,0.02); padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--border-color);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">Status</label>
<select class="modern-select" id="overtimeStatusFilter" onchange="loadOvertimeData()" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;">                    <option value="all">All Requests</option>
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Rejected">Rejected</option>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); font-weight: 600;">Search Employee</label>
                <input type="text" class="modern-input" id="overtimeEmployeeSearch" placeholder="Name or EID" onkeyup="filterOvertimeRequests()" style="width: 100%; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 13px;" />
            </div>
        </div>
    </div>

    <!-- Requests List -->
    <div class="record-list" id="overtimeRequestsList">
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i>
            <div>Loading overtime data...</div>
        </div>
    </div>
</div>
 <!-- ✅ ENHANCED DEPARTMENTS MODULE SECTION -->
<div class="admin-section" id="departmentsSection" style="display: none;">
    <div class="section-header">
        <div>
            <div class="section-title">
                <i class="fas fa-building" style="margin-right: 10px; color: var(--primary-accent);"></i>
                Department Management
            </div>
            <div class="section-subtitle">Organize employees, track attendance, and manage department structures</div>
        </div>
        <button class="add-department-btn" onclick="openAddDepartmentModal()">
            <i class="fas fa-plus-circle"></i> Add Department
        </button>
    </div>
    
    <!-- Stats Summary -->
    <div class="departments-stats-summary">
        <div class="department-summary-card">
            <div class="department-summary-icon">
                <i class="fas fa-building"></i>
            </div>
            <div class="department-summary-value" id="deptTotalCount">0</div>
            <div class="department-summary-label">Total Departments</div>
        </div>
        <div class="department-summary-card">
            <div class="department-summary-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="department-summary-value" id="deptTotalEmployees">0</div>
            <div class="department-summary-label">Total Employees</div>
        </div>
        <div class="department-summary-card">
            <div class="department-summary-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="department-summary-value" id="deptAvgAttendance">0%</div>
            <div class="department-summary-label">Avg Attendance</div>
        </div>
        <div class="department-summary-card">
            <div class="department-summary-icon">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="department-summary-value" id="deptPresentToday">0</div>
            <div class="department-summary-label">Present Today</div>
        </div>
    </div>
    
    <!-- Search & Filter Bar -->
    <div class="departments-filter-bar">
        <input type="text" class="departments-search-input" id="departmentSearch" 
               placeholder="🔍 Search departments..." onkeyup="filterDepartments()">
        <select class="departments-filter-select" id="departmentStatusFilter" onchange="filterDepartments()">
            <option value="all">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <select class="departments-filter-select" id="departmentSortFilter" onchange="filterDepartments()">
            <option value="name">Sort by Name</option>
            <option value="employees">Sort by Employees</option>
            <option value="attendance">Sort by Attendance</option>
        </select>
        <button class="action-button primary" onclick="loadDepartmentsData()" style="padding: 12px 20px;">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
    </div>
    
    <!-- Departments Grid -->
    <div id="departmentsGrid" class="departments-grid">
        <div class="departments-loading">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading department data...</p>
        </div>
    </div>
</div>

<!-- Add/Edit Department Modal -->
<div id="departmentModal" class="modal" style="display: none;">
    <div class="modal-content department-modal">
        <div class="modal-header">
            <div>
                <h3 id="departmentModalTitle"><i class="fas fa-building"></i> Add Department</h3>
                <p>Create a new department or edit existing one</p>
            </div>
            <span class="close-modal" onclick="closeDepartmentModal()">&times;</span>
        </div>
        <form id="departmentForm" onsubmit="saveDepartment(event)">
            <div class="modal-body">
                <input type="hidden" id="departmentId" name="id" />
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="department-form-row">
                    <label><i class="fas fa-tag"></i> Department Name <span style="color:#dc3545">*</span></label>
                    <input type="text" id="departmentName" name="name" required 
                           placeholder="e.g. Information Technology" />
                </div>
                
                <div class="department-form-row">
                    <label><i class="fas fa-user-tie"></i> Department Head</label>
                    <input type="text" id="departmentHead" name="head" 
                           placeholder="e.g. John Doe" />
                </div>
                
                <div class="department-form-row">
                    <label><i class="fas fa-align-left"></i> Description</label>
                    <textarea id="departmentDescription" name="description" 
                              placeholder="Brief description of the department's role and responsibilities"></textarea>
                </div>
                
                <div class="department-form-row">
                    <label><i class="fas fa-toggle-on"></i> Status</label>
                    <select id="departmentStatus" name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary-modern" onclick="closeDepartmentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary-modern">
                    <i class="fas fa-save"></i> Save Department
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Department Detail Modal (View Employees) -->
<div id="departmentDetailModal" class="modal" style="display: none;">
    <div class="modal-content department-detail-modal">
        <div class="modal-header">
            <div>
                <h3><i class="fas fa-users"></i> Department Details</h3>
                <p>View all employees in this department</p>
            </div>
            <span class="close-modal" onclick="closeDepartmentDetailModal()">&times;</span>
        </div>
        <div id="departmentDetailContent">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>
<!-- ENHANCED ANALYTICS SECTION -->
<div class="admin-section" id="analyticsSection" style="display: none;">
    <div class="section-header">
        <div>
            
     
           
            <div class="section-title"><i class="fas fa-chart-line" style="margin-right: 8px;"></i>Advanced Analytics</div>
            <div class="section-subtitle">Real-time attendance insights and performance metrics</div>
        </div>
        <div style="display: flex; gap: 10px;">
            <select id="analyticsTimeRange" class="filter-select" onchange="updateAnalytics()">
                <option value="today">Today</option>
                <option value="week">This Week</option>
                <option value="month" selected>This Month</option>
                <option value="year">This Year</option>
            </select>
            <button class="refresh-btn" onclick="refreshAnalytics()" title="Refresh Data">
    <i class="fas fa-sync-alt"></i>
</button>
        </div>
    </div>

    <!-- Search Bar -->
<div class="search-bar" style="margin-bottom: 25px;">
    <i class="fas fa-search search-icon"></i>
    <input type="text" class="search-input" id="analyticsSearchInput" placeholder="Search employees in table..." onkeyup="filterAnalyticsData(this.value)" />
</div>

    <!-- Live Stats Grid -->
    <div class="dashboard-stat-grid" style="margin-bottom: 30px;">
        <div class="stat-card live" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="stat-card-header">
                <div class="stat-card-title">Active Now</div>
                <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card-value" id="stat-active-now">0</div>
            <div class="stat-card-desc">
                <span style="display: flex; align-items: center; gap: 4px;">
                    <span class="live-indicator" style="width: 8px; height: 8px; background: #4ade80; border-radius: 50%; animation: pulse 2s infinite;"></span>
                    Live
                </span>
            </div>
        </div>
        
        <div class="stat-card present">
            <div class="stat-card-header">
                <div class="stat-card-title">Attendance Rate</div>
                <div class="stat-card-icon"><i class="fas fa-percentage"></i></div>
            </div>
            <div class="stat-card-value" id="stat-attendance-rate">0%</div>
            <div class="stat-card-desc" id="stat-attendance-change">+0% from last period</div>
        </div>
        
        <div class="stat-card live" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <div class="stat-card-header">
                <div class="stat-card-title">Avg. Hours/Day</div>
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            </div>
            <div class="stat-card-value" id="stat-avg-hours">0h</div>
            <div class="stat-card-desc" id="stat-hours-trend">Trending up</div>
        </div>
        
        <div class="stat-card late">
            <div class="stat-card-header">
                <div class="stat-card-title">Late Arrivals</div>
                <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
            <div class="stat-card-value" id="stat-late-count">0</div>
            <div class="stat-card-desc" id="stat-late-percentage">0% of total</div>
        </div>
    </div>

    <!-- Charts Row -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-bottom: 30px;">

   <!-- Attendance Trend Chart -->
<div class="card" style="background: var(--panel-bg); border-radius: 16px; padding: 25px; border: 1px solid var(--border-color);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h3 style="color: var(--text-primary); font-size: 16px; margin-bottom: 5px;">Attendance Trends</h3>
            <p style="color: var(--text-muted); font-size: 13px;">Daily attendance pattern</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-secondary);">
                <span style="width: 12px; height: 12px; background: #667eea; border-radius: 3px;"></span> Present
            </span>
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-secondary);">
                <span style="width: 12px; height: 12px; background: #f5576c; border-radius: 3px;"></span> Absent
            </span>
        </div>
    </div>
    <!-- ✅ ENSURE THIS ID MATCHES -->
    <canvas id="attendanceTrendChart" style="max-height: 300px;"></canvas>
</div>


<!-- COOL SCAN MODAL -->
<div class="scan-modal-overlay" id="scanModal">
    <div class="scan-modal-card">
        <div class="scan-modal-icon" id="modalIcon"><i class="fas fa-check-circle"></i></div>
        <h2 class="scan-modal-title" id="modalTitle">ACCESS GRANTED</h2>
        <p class="scan-modal-subtitle" id="modalAction">Check-In Successful</p>
        <div class="scan-modal-profile">
            <img src="" alt="Employee" class="scan-modal-pic" id="modalPhoto">
            <div class="scan-modal-details">
                <h3 id="modalName">Employee Name</h3>
                <p id="modalDept">Department</p>
                <p id="modalId" style="font-size: 12px; opacity: 0.6;">ID: 0000</p>
            </div>
        </div>
        <div class="scan-modal-time" id="modalTime">00:00:00</div>
    </div>
</div>



<!-- CONTACT ADMIN BUTTON -->
<button class="contact-admin-btn" onclick="openAdminModal()">
    <i class="fas fa-headset"></i> <span>Contact Admin</span>
</button>

<!-- ADMIN CONTACT MODAL (Simple) -->
<div id="adminContactModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:10000; justify-content:center; align-items:center;">
    <div style="background:var(--panel-bg); padding:30px; border-radius:12px; border:1px solid var(--warning); max-width:400px; text-align:center;">
        <h3 style="color:var(--warning); margin-bottom:15px;">Contact Support</h3>
        <p style="color:var(--text-muted); margin-bottom:20px;">Having trouble scanning? Contact the security desk.</p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <a href="tel:+1234567890" style="padding:10px 20px; background:var(--primary-accent); color:#000; text-decoration:none; border-radius:6px; font-weight:bold;">Call Desk</a>
            <button onclick="document.getElementById('adminContactModal').style.display='none'" style="padding:10px 20px; background:transparent; border:1px solid var(--text-muted); color:var(--text-muted); border-radius:6px; cursor:pointer;">Close</button>
        </div>
    </div>
</div>
        <!-- Department Distribution -->
        <div class="card" style="background: var(--panel-bg); border-radius: 16px; padding: 25px; border: 1px solid var(--border-color);">
            <div style="margin-bottom: 20px;">
                <h3 style="color: var(--text-primary); font-size: 16px; margin-bottom: 5px;">By Department</h3>
                <p style="color: var(--text-muted); font-size: 13px;">Attendance distribution</p>
            </div>
            <canvas id="departmentChart" style="max-height: 250px;"></canvas>
        </div>
    </div>

    <!-- Performance Metrics -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 30px;">
        <!-- Top Performers -->
        <div class="card" style="background: var(--panel-bg); border-radius: 16px; padding: 25px; border: 1px solid var(--border-color);">
            <div style="margin-bottom: 20px;">
                <h3 style="color: var(--text-primary); font-size: 16px; margin-bottom: 5px;">
                    <i class="fas fa-trophy" style="color: #fbbf24; margin-right: 8px;"></i>Top Performers
                </h3>
                <p style="color: var(--text-muted); font-size: 13px;">Best attendance records</p>
            </div>
            <div id="topPerformersList" style="display: flex; flex-direction: column; gap: 12px;">
                <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                    <div style="margin-top: 10px;">Loading...</div>
                </div>
            </div>
        </div>

        <!-- Attendance Patterns -->
        <div class="card" style="background: var(--panel-bg); border-radius: 16px; padding: 25px; border: 1px solid var(--border-color);">
            <div style="margin-bottom: 20px;">
                <h3 style="color: var(--text-primary); font-size: 16px; margin-bottom: 5px;">
                    <i class="fas fa-chart-pie" style="color: #19D3C5; margin-right: 8px;"></i>Attendance Patterns
                </h3>
                <p style="color: var(--text-muted); font-size: 13px;">Time-based insights</p>
            </div>
            <div id="attendancePatterns" style="display: flex; flex-direction: column; gap: 15px;">
                <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                    <div style="margin-top: 10px;">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Weekly Breakdown -->
    <div class="card" style="background: var(--panel-bg); border-radius: 16px; padding: 25px; border: 1px solid var(--border-color); margin-bottom: 30px;">
        <div style="margin-bottom: 20px;">
            <h3 style="color: var(--text-primary); font-size: 16px; margin-bottom: 5px;">Weekly Breakdown</h3>
            <p style="color: var(--text-muted); font-size: 13px;">Day-wise attendance statistics</p>
        </div>
        <div id="weeklyBreakdown" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 15px;">
            <!-- Will be populated by JavaScript -->
        </div>
    </div>

    <!-- Detailed Metrics Table -->
    <div class="card" style="background: var(--panel-bg); border-radius: 16px; padding: 25px; border: 1px solid var(--border-color);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h3 style="color: var(--text-primary); font-size: 16px; margin-bottom: 5px;">Detailed Metrics</h3>
                <p style="color: var(--text-muted); font-size: 13px;">Comprehensive attendance data</p>
            </div>
            <button class="action-button secondary" onclick="exportAnalyticsData()" style="padding: 8px 16px;">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
        <div style="overflow-x: auto;">
            <table class="modern-employee-table" id="analyticsDataTable">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Days Present</th>
                        <th>Avg. Hours</th>
                        <th>Late Arrivals</th>
                        <th>Attendance Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="analyticsTableBody">
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                            <div style="margin-top: 10px;">Loading data...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Chart.js library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</div>



<div class="help-button" onclick="toggleAIAssistant()">💬


</div>
<div class="ai-panel" id="aiPanel">
<div class="panel-header">
<div><div class="panel-title">HELPORT AI Assistant</div><div class="panel-subtitle">How can I help you today?</div></div>
<div class="panel-controls"><button class="panel-control-btn" title="Minimize">−</button><button class="panel-close" onclick="toggleAIAssistant()">&times;</button></div>
</div>
<div class="panel-body">
<div class="quick-actions-section">
<div class="quick-actions-header"><i class="fas fa-bolt"></i> Quick Actions</div>
<div class="quick-actions-grid">
<button class="action-button chat" onclick="sendQuickAction('Show me attendance status')"><div class="action-icon request">📊</div><div class="action-text">Attendance Status</div></button>
<button class="action-button chat" onclick="sendQuickAction('How do I manage employees?')"><div class="action-icon time">👥</div><div class="action-text">Employee Mgmt</div></button>
<button class="action-button chat" onclick="sendQuickAction('Show me pending leave requests')"><div class="action-icon requests">📋</div><div class="action-text">Leave Requests</div></button>
<button class="action-button chat" onclick="sendQuickAction('How does fingerprint enrollment work?')"><div class="action-icon attendance">👆</div><div class="action-text">Fingerprint Help</div></button>
</div>
</div>
<div class="chat-messages" id="chatMessages"><div class="message message-assistant">Hello! I'm your HELPORT AI assistant. How can I help you with system administration, attendance management, or employee matters?<div class="message-time">Just now</div></div></div>
<div class="chat-input"><input type="text" class="chat-input-field" id="userMessage" placeholder="Type your message..." onkeypress="handleKeyPress(event)" /><button class="chat-send-btn" onclick="sendMessage()">Send</button></div>
</div>
</div>
</div>
<?php endif; ?>
<?php endif; ?>
<!-- LEAVE HISTORY MODAL -->
<div id="leaveHistoryModal" class="modal" style="display: none; z-index: 2000;">
    <div class="modal-content" style="max-width: 900px; max-height: 80vh; overflow-y: auto;">
        <div class="modal-header">
            <div>
                <h3><i class="fas fa-history"></i> Leave History</h3>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">Showing requests from the last 30 days</p>
            </div>
            <span class="close-modal" onclick="closeLeaveHistoryModal()">&times;</span>
        </div>
        <div style="padding: 20px;">
            <!-- Action Bar -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px;">
                <div style="font-size: 13px; color: var(--text-secondary);">
                    <i class="fas fa-info-circle"></i> Records older than 30 days can be purged to maintain performance.
                </div>
                <button class="btn-reject" onclick="purgeOldLeaveRecords()" style="padding: 8px 16px; font-size: 12px; border:none;">
                    <i class="fas fa-trash-alt"></i> Clear Records > 30 Days
                </button>
            </div>
            
            <!-- History List Container -->
            <div id="leaveHistoryList" class="record-list">
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                    <div style="margin-top: 10px;">Loading history...</div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- TOGGLEABLE SYSTEM FOOTER -->
<div class="system-footer" id="systemFooter">
    <!-- Toggle Arrow Button -->
    <div class="footer-toggle-btn" id="footerToggleBtn" onclick="toggleFooter()">
        <i class="fas fa-chevron-up" id="footerToggleIcon"></i>
    </div>
    
    <!-- Footer Content -->
    <div class="footer-content">
        <div class="footer-left">
            <div class="footer-info">
                <i class="fas fa-info-circle"></i>
                <span>HELPORT Attendance System v2.0 • Fingerprint + AI Features Enabled</span>
            </div>
        </div>
        <div class="footer-right">
            <button class="footer-btn" onclick="openHelpModal()">
                <i class="fas fa-book"></i> Help & Guide
            </button>
            <button class="footer-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i> Dark Mode
            </button>
        </div>
    </div>
</div>
<div id="systemGuideModal" class="modal" style="display: none;">
<div class="modal-content" style="max-width: 700px; max-height: 80vh; overflow-y: auto;">
<div class="modal-header"><h3>HELPORT System Guide</h3><span class="close-modal" onclick="document.getElementById('systemGuideModal').style.display='none'">&times;</span></div>
<div style="padding: 20px; line-height: 1.8;">
<ul style="margin-left: 20px;">
<h4 style="margin: 20px 0 10px; color: #007bff;">🔒 Admin Dashboard Features:</h4>
<ul style="margin-left: 20px;">
<li><strong>Attendance Overview:</strong> Real-time statistics and trends</li>
<li><strong>Employee Management:</strong> Add, edit, delete employees with birthday & fingerprint ID</li>
<li><strong>Leave Administration:</strong> Approve/reject employee requests</li>
<li><strong>Reports:</strong> Generate attendance, leave, and payroll reports</li>
<li><strong>Audit Trail:</strong> Track all system activities</li>
<li><strong>Analytics:</strong> View attendance trends and metrics</li>
<li><strong>Fingerprint Management:</strong> Manage device enrollments and scans from admin dashboard</li>
</ul>
<h4 style="margin: 20px 0 10px; color: #007bff;">👆 Fingerprint Scanner Usage:</h4>
<ul style="margin-left: 20px;">
<li>Place enrolled finger firmly on the scanner</li>
<li>Wait for green LED confirmation (1-2 seconds)</li>
<li>Attendance recorded automatically</li>
<li>Watch for floating notification confirmation</li>
</ul>
<h4 style="margin: 20px 0 10px; color: #007bff;">💡 Tips & Tricks:</h4>
<ul style="margin-left: 20px;">
<li>Use <strong>"Full Portal"</strong> for complete featured experience</li>
<li><strong>Dark mode</strong> available for eye comfort</li>
<li>All data exports are <strong>secure and encrypted</strong></li>
<li>Real-time notifications for leave decisions</li>
<li>Automatic calculation of overtime and night differentials</li>
<li>Employees can change their own password from the portal</li>
</ul>
</ul>
<div style="background: #e6f7ff; border-left: 4px solid #0084ff; padding: 12px; margin-top: 20px; border-radius: 4px;">
<strong>📞 Need Help?</strong>
<p>For support or issues, contact your HR administrator or system manager.</p>
</div>
</div>
</div>
</div>
<script>
    

    // === HOLIDAY MANAGEMENT FUNCTIONS ===

// Load Holidays Data
function loadHolidays() {
    const container = document.getElementById('holidayListContainer');
    if(!container) {
        console.error('Holiday list container not found!');
        return;
    }
    
    container.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><div style="margin-top: 10px;">Loading holidays...</div></div>';
    
    fetch('?action=get_holidays')
    .then(r => r.json())
    .then(data => {
        if(data.success && data.holidays) {
            displayHolidays(data.holidays);
            updateHolidayStats(data.holidays);
        } else {
            container.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545;">Error loading holidays</div>';
        }
    })
    .catch(e => {
        console.error(e);
        container.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545;">Connection error</div>';
    });
}

// Display Holidays List
function displayHolidays(holidays) {
    const container = document.getElementById('holidayListContainer');
    if(!container || holidays.length === 0) {
        container.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);">No upcoming holidays found</div>';
        return;
    }
    
    let html = '';
    holidays.forEach(hol => {
        // DEBUG: Log the type to console (press F12 to see)
        console.log('Holiday:', hol.name, 'Type:', hol.type);
        
        let badgeColor = '#ffc107';
        let ruleText = 'Normal Pay';
        
        // Check type (case-insensitive, trim whitespace)
        const holidayType = (hol.type || '').toLowerCase().trim();
        
        if(holidayType === 'regular') { 
            badgeColor = '#28a745'; 
            ruleText = '2x Pay if Worked / 8hrs if Not'; 
        }
        else if(holidayType === 'special_non_working') { 
            badgeColor = '#fd7e14'; 
            ruleText = '+2.4hrs if Worked / 0 if Not'; 
        }
        else if(holidayType === 'special_working') { 
            badgeColor = '#17a2b8'; 
            ruleText = 'Normal Pay if Worked / 0 if Not'; 
        }
        
        const dateObj = new Date(hol.date);
        const dateStr = dateObj.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        
        html += `
        <div style="background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s ease;">
            <div style="display: flex; align-items: center; gap: 15px; flex: 1;">
                <div style="width: 50px; height: 50px; border-radius: 12px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; color: var(--primary-accent); font-size: 20px;">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div>
                    <div style="font-weight: 700; color: var(--text-primary); font-size: 16px;">${hol.name}</div>
                    <div style="font-size: 13px; color: var(--text-muted);">${dateStr}</div>
                    <div style="font-size: 12px; color: ${badgeColor}; margin-top: 4px; font-weight: 600;">${ruleText}</div>
                </div>
            </div>
            <div style="text-align: right;">
                <span style="display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; background: ${badgeColor}20; color: ${badgeColor}; border: 1px solid ${badgeColor}; margin-bottom: 10px;">
                    ${holidayType.replace('_', ' ').toUpperCase()}
                </span>
                <div>
                    <button class="action-button secondary" onclick="openHolidayAssignment(${hol.id}, '${hol.name.replace(/'/g, "\\'")}', '${hol.date}', '${hol.type}')" style="padding: 6px 12px; font-size: 12px;">
                        <i class="fas fa-users-cog"></i> Assign Workers
                    </button>
                </div>
            </div>
        </div>
        `;
    });
    container.innerHTML = html;
}

// Update Holiday Stats
function updateHolidayStats(holidays) {
    document.getElementById('holTotalCount').textContent = holidays.length;
    document.getElementById('holRegularCount').textContent = holidays.filter(h => h.type === 'regular').length;
    document.getElementById('holSpecialCount').textContent = holidays.filter(h => h.type === 'special_non_working').length;
}

// Open Holiday Assignment Modal
function openHolidayAssignment(id, name, date, type) {
    document.getElementById('holidayIdInput').value = id;
    document.getElementById('modalHolidayName').textContent = name;
    document.getElementById('modalHolidayDate').textContent = new Date(date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    
    let ruleText = '';
    if(type === 'regular') ruleText = 'Pay Rule: 2x Regular Hours if Worked. 8 Hours Credit if Not Worked.';
    else if(type === 'special_non_working') ruleText = 'Pay Rule: Regular + 2.4 Hours if Worked. 0 Hours if Not Worked.';
    else if(type === 'special_working') ruleText = 'Pay Rule: Regular Hours if Worked. 0 Hours if Not Worked.';
    document.getElementById('modalHolidayRule').textContent = ruleText;
    
    document.getElementById('holidayAssignmentModal').style.display = 'block';
    loadEmployeeAssignmentList(id);
}

// Close Holiday Modal
function closeHolidayModal() {
    document.getElementById('holidayAssignmentModal').style.display = 'none';
}

// Load Employee Assignment List
function loadEmployeeAssignmentList(holidayId) {
    const container = document.getElementById('employeeAssignmentList');
    container.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i></div>';
    
    // Fetch all employees
    fetch('?action=get_all_employees_fp')
    .then(r => r.json())
    .then(empData => {
        if(!empData.success) throw new Error('Failed to load employees');
        // Fetch current assignments
        return fetch(`?action=get_holiday_assignments&holiday_id=${holidayId}`)
        .then(r => r.json())
        .then(assignData => {
            if(!assignData.success) throw new Error('Failed to load assignments');
            const assignedIds = assignData.assignments || [];
            
            let html = '';
            empData.employees.forEach(emp => {
                const isChecked = assignedIds.includes(emp.id) ? 'checked' : '';
                html += `
                <label style="display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid var(--border-color); cursor: pointer;">
                    <input type="checkbox" class="holiday-emp-checkbox" value="${emp.id}" ${isChecked} style="width: 18px; height: 18px;">
                    <div style="flex: 1;">
                        <div style="font-weight: 600; color: var(--text-primary);">${emp.name}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">${emp.employee_id} • ${emp.department}</div>
                    </div>
                </label>
                `;
            });
            container.innerHTML = html;
        });
    })
    .catch(e => {
        console.error(e);
        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading employees</div>';
    });
}

// Save Holiday Assignment
function saveHolidayAssignment() {
    const holidayId = document.getElementById('holidayIdInput').value;
    const checkboxes = document.querySelectorAll('.holiday-emp-checkbox:checked');
    const employeeIds = Array.from(checkboxes).map(cb => cb.value);
    
    const formData = new FormData();
    formData.append('action', 'assign_holiday_work');
    formData.append('holiday_id', holidayId);
    formData.append('employee_ids', JSON.stringify(employeeIds));
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    formData.append('csrf_token', csrfToken);
    
    const btn = document.querySelector('#holidayAssignmentModal .btn-primary-modern');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        if(data.success) {
            showToast('Assignments updated successfully', 'success');
            closeHolidayModal();
            loadHolidays();
        } else {
            showToast('Error: ' + (data.message || 'Failed to save'), 'error');
        }
    })
    .catch(e => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showToast('Network error: ' + e.message, 'error');
    });
}

// === UPDATE SWITCH TAB FUNCTION TO INCLUDE HOLIDAYS ===
const originalSwitchTab = window.switchTab || function() {};
window.switchTab = function(tabName) {
    originalSwitchTab(tabName);
    
    // Hide all sections
    document.querySelectorAll('.admin-section').forEach(s => s.style.display = 'none');
    
    // Show target section
    const target = document.getElementById(tabName + 'Section');
    if (target) {
        target.style.display = 'block';
        // Load data for specific tabs with proper timing
        setTimeout(() => {
            if(tabName === 'analytics') {
                updateAnalytics();
            }
            if(tabName === 'departments') {
                loadDepartmentsData();
            }
            if(tabName === 'audittrail') {
                startAuditTrailRefresh();
            }
            if(tabName === 'attendance') {
                startAttendanceHistoryRefresh();
            }
            if(tabName === 'holidays') {  // ✅ ADD THIS
                loadHolidays();
            }
        }, 300);
    }
    
    // Update nav tabs
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    
    // Update URL
    const newUrl = window.location.protocol + "//" + window.location.host +
    window.location.pathname + '?view=' + tabName;
    window.history.pushState({path:newUrl},'',newUrl);
};

// Close modal when clicking outside
window.onclick = function(event) {
    const holidayModal = document.getElementById('holidayAssignmentModal');
    if(event.target == holidayModal) {
        closeHolidayModal();
    }
    }
    // === LEAVE MANAGEMENT FUNCTIONS ===
function refreshLeaveRequests() {
showToast('Refreshing leave requests...', 'info');
// Just reload the leave requests list, not the whole page
if(document.getElementById('leaveRequestsList')) {
// Fetch and update the leave requests list
fetch('?action=get_day_off_requests_admin')
.then(r => r.json())
.then(data => {
if(data.success && data.requests) {
// Update the leave requests list UI
// (You can add code here to refresh the list without reload)
}
})
.catch(e => console.log('Refresh error:', e));
}
// ❌ REMOVED: location.reload(); - No full page reload
}

function updateLeaveStats() {
    fetch('?action=get_leave_stats')
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            document.getElementById('leavePendingCount').textContent = data.pending || 0;
            document.getElementById('leaveApprovedCount').textContent = data.approved || 0;
            document.getElementById('leaveRejectedCount').textContent = data.rejected || 0;
        }
    })
    .catch(e => console.log('Stats error:', e));
}

// Initialize leave stats on page load
document.addEventListener('DOMContentLoaded', function() {
    updateLeaveStats();
});
function showToast(message, type = 'info') {
const container = document.getElementById('toast-container');
const toast = document.createElement('div');
toast.className = `toast ${type}`;
toast.innerHTML = `<span>${message}</span><button class="toast-close" onclick="this.parentElement.remove()">&times;</button>`;
container.appendChild(toast);
setTimeout(() => { if(toast.parentElement) toast.remove(); }, 5000);
}
function showConfirm(message, callback) {
const modal = document.createElement('div');
modal.className = 'modal';
modal.style.display = 'block';
modal.style.zIndex = '10000';
modal.innerHTML = `
<div class="modal-content" style="max-width: 400px; text-align: center; background: var(--panel-bg);">
<div class="modal-header">
<h3>Confirm Action</h3>
<span class="close-modal" onclick="this.closest('.modal').remove()">&times;</span>
</div>
<p style="margin: 20px 0; color: var(--text-primary);">${message}</p>
<div style="display: flex; gap: 10px; justify-content: center; padding-bottom: 20px;">
<button class="btn" style="background: var(--panel-bg); color: var(--text-primary); border: 1px solid var(--border-color);" onclick="this.closest('.modal').remove()">Cancel</button>
<button class="btn btn-primary" id="confirmActionBtn">Confirm</button>
</div>
</div>
`;
document.body.appendChild(modal);
const confirmBtn = modal.querySelector('#confirmActionBtn');
confirmBtn.onclick = () => {
modal.remove();
if(callback) callback();
};
window.tempConfirmCallback = callback;
}
window.alert = showToast;
window.confirm = (msg) => { return confirm(msg); };
function switchEmployeeTab(tabName) {


    
    // Remove active class from all tabs
    document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
    
    // Add active class to clicked tab
    const mainTab = document.querySelector(`.nav-tab[data-tab="${tabName}"]`);
    if(mainTab) mainTab.classList.add('active');
    
    // Hide all employee sections
    ['Overview', 'Schedule', 'Leaves', 'Leave', 'Attendance'].forEach(section => {
        const el = document.getElementById(`employee${section}Section`);
        if(el) el.style.display = 'none';
    });

// Show target section
    const target = document.getElementById(`employee${tabName.charAt(0).toUpperCase() + tabName.slice(1)}Section`);
    if(target) {
        target.style.display = 'block';
        if (tabName === 'schedule') {
            setTimeout(() => loadEmployeeSchedule(), 100);
        }
if (tabName === 'attendance') {
    loadAttendanceHistory();
    startAttendanceHistoryRefresh(); // Start auto-refresh
} else {
    stopAttendanceHistoryRefresh(); // Stop when leaving tab
}        // ✅ ADD THIS LINE
        if (tabName === 'leaves') loadDayOffRequests(); 
    }
    
    // Hide leave form if not on leave tab
    if (tabName !== 'leave') {
        document.getElementById('newLeaveForm').style.display = 'none';
        document.getElementById('newLeaveBtn').style.display = 'flex';
    }

    // Update URL
    const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?emp_view=' + tabName;
    window.history.pushState({path:newUrl},'',newUrl);
}

function toggleEmployeeDropdown() {
const dropdown = document.getElementById('employeeDropdown');
const isVisible = dropdown.style.display === 'block';
document.querySelectorAll('.notification-dropdown').forEach(d => d.style.display = 'none');
if (isVisible) {
dropdown.style.display = 'none';
document.body.style.overflow = '';
} else {
dropdown.style.display = 'block';
document.body.style.overflow = 'hidden';
}
}
function submitDayOffRequest(event) {
    event.preventDefault();
    const date = document.getElementById('dayOffDate').value; 
    const type = document.getElementById('dayOffType').value;
    const reason = document.getElementById('dayOffReason').value;
    if (!date || !type) {
        showToast('Please fill in all required fields', 'error');
        return;
    }
    // Calculate End Date based on Type
    let endDate = date; 
    let durationText = "1 Day";
    if (type === 'Half Day (AM)' || type === 'Half Day (PM)') {
        durationText = "Half Day";
    } else if (type === 'Full Day') {
        durationText = "1 Day";
    }
    const formData = new FormData();
    formData.append('action', 'submit_day_off'); // <--- FIXED THIS LINE
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
    formData.append('date', date);
    formData.append('end_date', endDate); 
    formData.append('type', type);
    formData.append('reason', reason);
    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Day off request submitted successfully!', 'success');
            // 1. Clear the form
            document.getElementById('dayOffDate').value = '';
            document.getElementById('dayOffType').value = '';
            document.getElementById('dayOffReason').value = '';
            // 2. MANUALLY ADD TO THE LIST IMMEDIATELY
            const listContainer = document.getElementById('dayOffList');
            const loadingMsg = listContainer.querySelector('div[style*="text-align: center"]');
            if(loadingMsg) loadingMsg.remove();
            const startDateObj = new Date(date);
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            const formattedStart = startDateObj.toLocaleDateString('en-US', options);
            const formattedEnd = formattedStart; 
            const newCard = document.createElement('div');
            newCard.style.cssText = "background: var(--panel-bg); padding: 15px; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid #ffc107; box-shadow: var(--shadow-sm); animation: slideIn 0.3s ease;";
            newCard.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <div style="font-weight: 600; color: var(--text-primary);">${formattedStart} ${formattedStart !== formattedEnd ? 'to ' + formattedEnd : ''}</div>
                <span style="background: rgba(255,193,7,0.12); color: #FFD580; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600;">PENDING</span>
            </div>
            <div style="font-size: 12px; color: var(--text-muted);"><strong>Type:</strong> ${type} • <strong>Duration:</strong> ${durationText}</div>
            ${reason ? `<div style="font-size: 12px; color: var(--text-muted); margin-top: 8px;"><strong>Reason:</strong> ${reason}</div>` : ''}
            `;
            if(listContainer.firstChild) {
                listContainer.insertBefore(newCard, listContainer.firstChild);
            } else {
                listContainer.appendChild(newCard);
            }
            // 3. Refresh from server to ensure consistency
            loadDayOffRequests();
        } else {
            showToast('Error: ' + (data.message || 'Failed to submit request'), 'error');
        }
    })
    .catch(e => showToast('Error submitting request: ' + e, 'error'));
}


// === LEAVE REQUEST FUNCTIONS ===
function loadLeaveRequests() {
    const container = document.querySelector('#employeeLeaveSection .record-list');
    if(!container) return;
    
    container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted);">Loading leave requests...</div>';
    
    fetch('?action=get_leave_requests')
    .then(r => r.json())
    .then(data => {
        if (data.success && data.requests.length > 0) {
            let html = '';
            data.requests.forEach(req => {
                const statusColor = req.status === 'Approved' ? '#28a745' : (req.status === 'Rejected' ? '#dc3545' : '#ffc107');
                html += `
                <div class="record-item" style="border-left:4px solid ${statusColor}">
                    <div style="flex:1">
                        <div style="font-weight:600">${req.leave_type} - ${req.start_date}</div>
                        <div style="font-size:12px;color:var(--text-muted)">Reason: ${req.reason}</div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:5px">
                            Submitted: ${new Date(req.created_at).toLocaleString()}
                        </div>
                    </div>
                    <span class="status-badge" style="background:${statusColor}20;color:${statusColor}">
                        ${req.status}
                    </span>
                </div>`;
            });
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted);">No leave requests yet</div>';
        }
    });
}

function submitLeaveRequest(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('action', 'submit_leave_request');
    
    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            showToast('Leave request submitted!', 'success');
            toggleLeaveForm();
            loadLeaveRequests();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    });
}

function toggleLeaveForm() {
    const form = document.getElementById('leaveRequestForm');
    const btn = document.getElementById('newLeaveBtn');
    if(form.style.display === 'none') {
        form.style.display = 'block';
        btn.style.display = 'none';
    } else {
        form.style.display = 'none';
        btn.style.display = 'flex';
    }
}

function loadDayOffRequests() {
    const container = document.getElementById('dayOffList');
    if(!container) return;

    container.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-muted);">Loading your day off requests...</div>';

    fetch('?action=get_day_off_requests')
    .then(r => r.json())
    .then(data => {
        if (data.success && data.requests && data.requests.length > 0) {
            let html = '<div style="font-weight: 600; color: var(--text-primary); margin-bottom: 15px; font-size: 14px;">📋 Your Day Off Requests</div>';
            
            data.requests.forEach(req => {
                // Status colors
                let statusColor = '#ffc107';
                let statusBg = 'rgba(255,193,7,0.12)';
                let statusText = 'PENDING';

                if(req.status === 'Approved') {
                    statusColor = '#28a745';
                    statusBg = 'rgba(40,167,69,0.08)';
                    statusText = 'APPROVED';
                } else if(req.status === 'Rejected') {
                    statusColor = '#dc3545';
                    statusBg = 'rgba(220,53,69,0.06)';
                    statusText = 'REJECTED';
                }

                // Format dates
                const startDate = new Date(req.start_date).toLocaleDateString();
                const endDate = new Date(req.end_date).toLocaleDateString();
                const dateRange = startDate === endDate ? startDate : `${startDate} to ${endDate}`;

                html += `<div style="background: var(--panel-bg); padding: 15px; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid ${statusColor}; box-shadow: var(--shadow-sm);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div style="font-weight: 600; color: var(--text-primary);">${dateRange}</div>
                        <span style="background: ${statusBg}; color: ${statusColor}; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600;">${statusText}</span>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 5px;">Type: ${req.type || 'Full Day'}</div>
                    ${req.reason ? `<div style="font-size: 12px; color: var(--text-muted); margin-top: 8px;"><strong>Reason:</strong> ${req.reason}</div>` : ''}
                </div>`;
            });
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div style="background: var(--panel-bg); padding: 20px; border-radius: 8px; text-align: center; color: var(--text-muted);">No day off requests yet</div>';
        }
    })
    .catch(e => {
        console.log('Error loading requests:', e);
        container.innerHTML = '<div style="background: var(--panel-bg); padding: 20px; border-radius: 8px; text-align: center; color: var(--text-muted);">Error loading requests</div>';
    });
}
function loadAttendanceHistory() {
const start = document.getElementById('attendanceHistoryStart').value;
const end = document.getElementById('attendanceHistoryEnd').value;
window.location.href = `${window.location.pathname}?attendance_start=${start}&attendance_end=${end}#employeeAttendanceSection`;
}

async function loadEmployeeSchedule() {
    const grid = document.getElementById('scheduleCalendarGrid');
    const titleEl = document.getElementById('calendarMonthYear');
    
    if (typeof currentCalendarDate === 'undefined') {
        currentCalendarDate = new Date();
    }

    if(!grid) {
        console.error("Schedule Grid Element Not Found!");
        return;
    }

    grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i><div>Loading Schedule...</div></div>';

    try {
        // 1. Fetch Employee Schedule
        const scheduleRes = await fetch('?action=get_employee_schedule');
        const scheduleData = await scheduleRes.json();
        
        // 2. Fetch All Holidays for Current Year
        let holidays = [];
        try {
            const holidaysRes = await fetch('?action=get_holidays');
            const holidaysData = await holidaysRes.json();
            if(holidaysData.success && holidaysData.holidays) {
                holidays = holidaysData.holidays;
            }
        } catch (e) {
            console.warn('Could not load holidays:', e);
        }

        // 3. Fetch Employee's Holiday Assignments
        let assignedHolidayIds = [];
        try {
            const assignRes = await fetch('?action=get_my_holiday_assignments');
            const assignData = await assignRes.json();
            if(assignData.success && assignData.assignments) {
                assignedHolidayIds = assignData.assignments;
            }
        } catch (e) {
            console.warn('Could not load holiday assignments:', e);
        }

        const now = currentCalendarDate; 
        const currentMonth = now.getMonth();
        const currentYear = now.getFullYear();
        const today = new Date();
        
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        if(titleEl) {
            titleEl.textContent = `${monthNames[currentMonth]} ${currentYear}`;
        }

        const firstDay = new Date(currentYear, currentMonth, 1).getDay();
        const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

        const shiftConfig = {
            'Morning Shift': { time: '8:00 AM - 5:00 PM', color: '#00ff88', bg: 'rgba(0, 255, 136, 0.1)', icon: 'fa-sun' },
            'Day Shift':     { time: '8:00 AM - 5:00 PM', color: '#00ff88', bg: 'rgba(0, 255, 136, 0.1)', icon: 'fa-sun' },
            'Early Shift':   { time: '7:00 AM - 4:00 PM', color: '#00d4ff', bg: 'rgba(0, 212, 255, 0.1)', icon: 'fa-sun' },
            'Mid Shift':     { time: '9:00 AM - 6:00 PM', color: '#ff9f43', bg: 'rgba(255, 159, 67, 0.1)', icon: 'fa-cloud-sun' },
            'Afternoon Shift': { time: '2:00 PM - 11:00 PM', color: '#ffcc00', bg: 'rgba(255, 204, 0, 0.1)', icon: 'fa-cloud-sun' },
            'Night Shift':   { time: '10:00 PM - 7:00 AM', color: '#6c5ce7', bg: 'rgba(108, 92, 231, 0.1)', icon: 'fa-moon' },
            'Graveyard':     { time: '10:00 PM - 7:00 AM', color: '#6c5ce7', bg: 'rgba(108, 92, 231, 0.1)', icon: 'fa-moon' },
            'Flexible':      { time: 'Flexible Hours', color: '#00d2d3', bg: 'rgba(0, 210, 211, 0.1)', icon: 'fa-clock' },
            'Off':           { time: 'Day Off', color: '#6c757d', bg: 'rgba(108, 117, 125, 0.1)', icon: 'fa-bed' }
        };

        let schedule = {};
        if(scheduleData.success && scheduleData.schedule) {
            schedule = scheduleData.schedule;
        } else {
            schedule = { monday: 'Day Shift', tuesday: 'Day Shift', wednesday: 'Day Shift', thursday: 'Day Shift', friday: 'Day Shift', saturday: 'Off', sunday: 'Off' };
        }

        const leaves = scheduleData.leaves || [];
        const leaveDates = leaves.map(leave => {
            const start = new Date(leave.start_date);
            const end = new Date(leave.end_date);
            const dates = [];
            for(let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                dates.push(d.getDate());
            }
            return dates;
        }).flat();

        // 4. Map Holidays to Calendar Dates (CRITICAL FOR HOLIDAY DISPLAY)
        const holidayMap = {};
        holidays.forEach(hol => {
            const holDate = new Date(hol.date);
            // Only map holidays in the current viewing month
            if(holDate.getMonth() === currentMonth && holDate.getFullYear() === currentYear) {
                const dateKey = holDate.getDate(); // Day of month (1-31)
                holidayMap[dateKey] = {
                    id: hol.id,
                    name: hol.name,
                    type: hol.type,
                    isAssigned: assignedHolidayIds.includes(hol.id) // Check if employee is assigned to work
                };
            }
        });

        const dayNames = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
        const scheduleKeys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        let html = '';

        // Day Headers
        dayNames.forEach(day => {
            html += `<div style="background: rgba(255,255,255,0.05); padding: 15px 10px; text-align: center; font-weight: 700; font-size: 12px; color: var(--primary-accent); border-bottom: 2px solid var(--border-color); text-transform: uppercase; letter-spacing: 1px;">${day}</div>`;
        });

        // Empty cells before month starts
        for(let i = 0; i < firstDay; i++) {
            html += `<div style="background: rgba(0,0,0,0.1); min-height: 120px;"></div>`;
        }

        // Calendar Days
        for(let day = 1; day <= daysInMonth; day++) {
            const dayOfWeek = new Date(currentYear, currentMonth, day).getDay();
            const scheduleKey = scheduleKeys[dayOfWeek];
            const shiftType = schedule[scheduleKey] || 'Off';
            const shift = shiftConfig[shiftType] || shiftConfig['Off'];
            
            const isToday = (day === today.getDate() && currentMonth === today.getMonth() && currentYear === today.getFullYear());
            const isLeave = leaveDates.includes(day);
            const holiday = holidayMap[day]; // Check if this day is a holiday

            let cellBg = shift.bg;
            let borderColor = isToday ? 'var(--primary-accent)' : 'var(--border-color)';
            let boxShadow = isToday ? '0 0 25px rgba(25,211,197,0.4)' : 'none';
            let transform = isToday ? 'scale(1.05)' : 'scale(1)';
            let statusText = shiftType;
            let statusColor = shift.color;
            let statusIcon = shift.icon;
            let statusTime = shift.time;
            let badgeHtml = '';

            // 5. HOLIDAY LOGIC - This makes holidays show on calendar
            if(holiday) {
                if(holiday.type === 'regular') {
                    if(holiday.isAssigned) {
                        // Employee IS assigned to work on Regular Holiday
                        cellBg = 'rgba(40,167,69,0.15)';
                        statusText = 'Holiday Work (2x Pay)';
                        statusColor = '#28a745';
                        statusIcon = 'fa-briefcase';
                        statusTime = '8:00 AM - 5:00 PM';
                        badgeHtml = `<div style="font-size: 10px; color: #28a745; font-weight: 700; margin-top: 4px;">${holiday.name}</div>`;
                    } else {
                        // Employee is NOT assigned (Paid Day Off)
                        cellBg = 'rgba(25,211,197,0.15)';
                        statusText = 'Holiday (Paid 8hrs)';
                        statusColor = '#19D3C5';
                        statusIcon = 'fa-gift';
                        statusTime = 'Day Off';
                        badgeHtml = `<div style="font-size: 10px; color: #19D3C5; font-weight: 700; margin-top: 4px;">${holiday.name}</div>`;
                    }
                } else if(holiday.type === 'special_non_working') {
                    if(holiday.isAssigned) {
                        // Employee IS assigned to work on Special Non-Working
                        cellBg = 'rgba(253,126,20,0.15)';
                        statusText = 'Holiday Work (+2.4hrs)';
                        statusColor = '#fd7e14';
                        statusIcon = 'fa-briefcase';
                        statusTime = '8:00 AM - 5:00 PM';
                        badgeHtml = `<div style="font-size: 10px; color: #fd7e14; font-weight: 700; margin-top: 4px;">${holiday.name}</div>`;
                    } else {
                        // Employee is NOT assigned (Unpaid Day Off)
                        cellBg = 'rgba(108,117,125,0.15)';
                        statusText = 'Holiday (Unpaid)';
                        statusColor = '#6c757d';
                        statusIcon = 'fa-home';
                        statusTime = 'Day Off';
                        badgeHtml = `<div style="font-size: 10px; color: #6c757d; font-weight: 700; margin-top: 4px;">${holiday.name}</div>`;
                    }
                } else if(holiday.type === 'special_working') {
                    if(holiday.isAssigned) {
                        // Employee IS assigned to work on Special Working
                        cellBg = 'rgba(23,162,184,0.15)';
                        statusText = 'Holiday Work (Normal)';
                        statusColor = '#17a2b8';
                        statusIcon = 'fa-briefcase';
                        statusTime = '8:00 AM - 5:00 PM';
                        badgeHtml = `<div style="font-size: 10px; color: #17a2b8; font-weight: 700; margin-top: 4px;">${holiday.name}</div>`;
                    } else {
                        // Employee is NOT assigned (Unpaid Day Off)
                        cellBg = 'rgba(108,117,125,0.15)';
                        statusText = 'Holiday (Unpaid)';
                        statusColor = '#6c757d';
                        statusIcon = 'fa-home';
                        statusTime = 'Day Off';
                        badgeHtml = `<div style="font-size: 10px; color: #6c757d; font-weight: 700; margin-top: 4px;">${holiday.name}</div>`;
                    }
                }
                borderColor = statusColor;
            } else if(isLeave) {
                cellBg = 'rgba(255,193,7,0.2)';
                borderColor = '#ffc107';
                statusText = 'On Leave';
                statusColor = '#ffc107';
                statusIcon = 'fa-umbrella-beach';
                statusTime = 'Approved Leave';
            }

            html += `
            <div style="background: ${cellBg}; border: 2px solid ${borderColor}; border-radius: 12px; padding: 10px; min-height: 120px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: ${boxShadow}; transform: ${transform}; transition: all 0.3s ease; position: relative; overflow: hidden;"
            onmouseover="this.style.transform='scale(1.08)'; this.style.boxShadow='0 8px 25px rgba(25,211,197,0.3)'"
            onmouseout="this.style.transform='${isToday ? 'scale(1.05)' : 'scale(1)'}'; this.style.boxShadow='${boxShadow}'">
            ${isToday ? '<div style="position: absolute; top: 5px; right: 5px; width: 8px; height: 8px; background: var(--primary-accent); border-radius: 50%; animation: pulse 2s infinite;"></div>' : ''}
            ${isLeave && !holiday ? '<div style="position: absolute; top: 5px; left: 5px; font-size: 16px;">🏖️</div>' : ''}
            <div style="font-weight: 700; font-size: 18px; color: var(--text-primary); margin-bottom: 5px; ${isToday ? 'color: var(--primary-accent);' : ''}">${day}</div>
            ${badgeHtml}
            <div style="display: flex; align-items: center; gap: 5px; font-size: 11px; color: ${statusColor}; font-weight: 600; margin-bottom: 3px;">
            <i class="fas ${statusIcon}"></i>
            <span style="flex: 1;">${statusText}</span>
            </div>
            <div style="font-size: 10px; color: var(--text-secondary); font-family: 'Courier New', monospace; font-weight: 600; text-align: center; padding: 3px; background: rgba(0,0,0,0.2); border-radius: 4px;">
            ${statusTime}
            </div>
            ${isToday ? '<div style="position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--primary-accent), var(--secondary-accent));"></div>' : ''}
            </div>
            `;
        }

        grid.innerHTML = html;

    } catch(e) {
        console.error("Schedule Load Error:", e);
        grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 15px;"></i><div style="font-weight: 700;">Error loading schedule</div><button onclick="loadEmployeeSchedule()" style="margin-top: 15px; padding: 8px 16px; background: var(--primary-accent); border: none; border-radius: 6px; cursor: pointer; color: #000;">Retry</button></div>';
    }
}

function changeMonth(offset) {
    // This now correctly updates the GLOBAL variable
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() + offset);
    loadEmployeeSchedule();
}
// Password Modal Functions
function openPasswordModal() {
document.getElementById('passwordModal').style.display = 'block';
}
function closePasswordModal() {
document.getElementById('passwordModal').style.display = 'none';
document.getElementById('passwordForm').reset();
}
document.addEventListener('DOMContentLoaded', () => {
// Find this existing block and update the condition
document.addEventListener('click', function(event) {
    const settingsDropdown = document.getElementById('settingsDropdown');
    const employeeDropdown = document.getElementById('employeeDropdown');
    const settingsButton = event.target.closest('button[onclick*="toggleSettingsDropdown"]');
    const menuButton = event.target.closest('button[onclick*="toggleEmployeeDropdown"]');

     if(document.getElementById('employeeLeavesSection') && document.getElementById('employeeLeavesSection').style.display !== 'none') { 
        loadDayOffRequests(); 
    }
    // Close Settings Dropdown
    if (settingsDropdown && !settingsDropdown.contains(event.target) && !settingsButton) {
        settingsDropdown.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Close Employee Dropdown (Existing logic)
    if (employeeDropdown && !employeeDropdown.contains(event.target) && !menuButton) {
        employeeDropdown.style.display = 'none';
        document.body.style.overflow = '';
    }
});
const minDate = new Date();
minDate.setDate(minDate.getDate() + 3);
const minDateStr = minDate.toISOString().split('T')[0];
document.querySelectorAll('input[name="startDate"], input[name="endDate"]').forEach(el => { el.min = minDateStr; });
const params = new URLSearchParams(window.location.search);
if (params.has('attendance_date')) {
document.getElementById('attendanceHistoryDate').value = params.get('attendance_date');
switchEmployeeTab('attendance');
setTimeout(() => document.getElementById('employeeAttendanceSection').scrollIntoView({behavior:'smooth'}), 300);
}
if(document.getElementById('notificationBell')) { setInterval(pollNotifications, 10000); }
if(document.getElementById('employeeScheduleSection') && document.getElementById('employeeScheduleSection').style.display !== 'none') { loadEmployeeSchedule(); }
// Password Form Handler
const passwordForm = document.getElementById('passwordForm');
if(passwordForm) {
passwordForm.addEventListener('submit', function(e) {
e.preventDefault();
const formData = new FormData(this);
formData.append('action', 'change_password');
fetch('', { method: 'POST', body: formData })
.then(r => r.json())
.then(data => {
if(data.success) {
showToast('Password changed successfully!', 'success');
closePasswordModal();
} else {
showToast('Error: ' + (data.message || 'Failed to change password'), 'error');
}
})
.catch(e => showToast('Error: ' + e, 'error'));
});
}
});
function formatTime12(date) {
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; // Convert 0 to 12
    return `${hours}:${minutes}:${seconds} ${ampm}`;
}

function formatTime12Short(date) {
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    return `${hours}:${minutes} ${ampm}`;
}
// === HELPER: GET PHILIPPINE TIME DATE OBJECT ===
function getPhilippineDate() {
    const now = new Date();
    const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
    const phOffset = 8 * 60 * 60 * 1000;
    return new Date(utc + phOffset);
}

// === UPDATED LIVE CLOCK FUNCTION ===
function updateTime() {
    const now = getPhilippineDate(); // Use PH Time
    const timeEl = document.getElementById('currentTime');
    const dateEl = document.getElementById('currentDate');
    if (timeEl) timeEl.textContent = formatTime12(now);
    if (dateEl) dateEl.textContent = formatFullDate(now);
}
function formatFullDate(date) {
    const options = { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    };
    return date.toLocaleDateString('en-US', options);
}
document.addEventListener('DOMContentLoaded', function() {
    // ✅ RESTORE PREVIOUS VIEW ON LOAD
    const urlParams = new URLSearchParams(window.location.search);
    if (document.getElementById('adminDashboard')) {
        if (urlParams.has('view')) {
            switchTab(urlParams.get('view'));
        } else {
            switchTab('overview'); // Default
        }
    } else if (document.getElementById('employeePortal')) {
        if (urlParams.has('emp_view')) {
            switchEmployeeTab(urlParams.get('emp_view'));
        } else {
            switchEmployeeTab('overview'); // Default
        }
    }
    // ... rest of your existing code (updateTime, etc.) ...
    updateTime();
    setInterval(updateTime, 1000);
    
    // ✅ NEW: Check for existing check-ins from PHP data
    <?php if ($attendanceInfo && $attendanceInfo['check_in'] && !$attendanceInfo['check_out']): ?>
        startLiveTimer('<?= $attendanceInfo['check_in'] ?>');
    <?php endif; ?>
    
    if (document.getElementById('employeePortal') || document.getElementById('adminDashboard')) {
        startScanPolling();
    }
    if (document.getElementById('adminDashboard')) { setInterval(refreshRealtimeStats, 15000); refreshRealtimeStats(); }
});

// === LIVE DURATION TRACKER ===
let liveTimerInterval = null;

function startLiveTimer(checkInTimeStr) {
    // Clear any existing timer
    if (liveTimerInterval) clearInterval(liveTimerInterval);
    
    const checkInTime = new Date(checkInTimeStr);
    const display = document.getElementById('durationTimer');
    const container = document.getElementById('durationTrackerContainer');
    
    if (!display || !container) return;
    
    // Show the container
    container.style.display = 'block';
    
    const updateTimer = () => {
        const now = new Date();
        const diffMs = now - checkInTime;
        
        // Calculate hours, minutes, seconds
        const diffSec = Math.floor(diffMs / 1000);
        const hours = Math.floor(diffSec / 3600);
        const minutes = Math.floor((diffSec % 3600) / 60);
        const seconds = diffSec % 60;
        
        // Format with leading zeros
        const h = hours.toString().padStart(2, '0');
        const m = minutes.toString().padStart(2, '0');
        const s = seconds.toString().padStart(2, '0');
        
        display.textContent = `${h}h ${m}m ${s}s`;
    };
    
    // Run immediately then every second
    updateTimer();
    liveTimerInterval = setInterval(updateTimer, 1000);
}

function stopLiveTimer() {
    if (liveTimerInterval) {
        clearInterval(liveTimerInterval);
        liveTimerInterval = null;
    }
    const container = document.getElementById('durationTrackerContainer');
    if (container) container.style.display = 'none';
}

function pollNotifications() {
fetch('?action=get_notifications')
.then(r => r.json())
.then(data => {
if(data.success) {
const bell = document.getElementById('notificationBell');
const dropdown = document.getElementById('notificationDropdown');
if(bell) {
let badge = bell.querySelector('.notification-badge');
if(data.count > 0) {
if(!badge) { badge = document.createElement('span'); badge.className = 'notification-badge'; bell.appendChild(badge); }
badge.textContent = data.count;
} else if (badge) { badge.remove(); }
if(dropdown && dropdown.style.display === 'block') {}
}
}
})
.catch(console.error);
}
function handleNotificationClick(link, notifId) {
fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=mark_notifications_read&notification_id=${notifId}` }).then(() => {
if(link && link !== '#') { window.location.href = link; } else { document.getElementById('notificationDropdown').style.display = 'none'; location.reload(); }
});
}
document.addEventListener('DOMContentLoaded', function () {
const bell = document.getElementById('notificationBell');
const dropdown = document.getElementById('notificationDropdown');
if (bell && dropdown) {
bell.addEventListener('click', function (e) {
e.stopPropagation();
const isVisible = dropdown.style.display === 'block';
document.querySelectorAll('.notification-dropdown').forEach(d => d.style.display = 'none');
dropdown.style.display = isVisible ? 'none' : 'block';
if (!isVisible) {
fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=mark_notifications_read' })
.then(response => response.json())
.then(data => { if (data.success) { const badge = bell.querySelector('.notification-badge'); if (badge) badge.remove(); } })
.catch(console.error);
}
});
document.addEventListener('click', function () { dropdown.style.display = 'none'; });
}
});
function switchTab(tabName) {  // ← Make sure tabName is a parameter
    document.querySelectorAll('.admin-section').forEach(s => s.style.display = 'none');
    const target = document.getElementById(tabName + 'Section');
    if (target) {
        target.style.display = 'block';
        if(tabName === 'overtime') {
            setTimeout(() => {
                loadOvertimeData();
            }, 100);
        }    }
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    if (tabName === 'overview') document.querySelector('.nav-tab:nth-child(1)').classList.add('active');
    else if (tabName === 'attendance') document.querySelector('.nav-tab:nth-child(2)').classList.add('active');
    else if (tabName === 'reports') document.querySelector('.nav-tab:nth-child(3)').classList.add('active');
    const sel = document.getElementById('moduleSelector');
    if (sel) sel.value = tabName;
    if (tabName === 'audittrail') { startAuditTrailRefresh(); } else { if (window.auditRefreshInterval) clearInterval(window.auditRefreshInterval); }
// Auto-refresh overtime data every 30 seconds if tab is visible
setInterval(() => {
    const overtimeSection = document.getElementById('overtimeSection');
    if(overtimeSection && overtimeSection.style.display !== 'none') {
        loadOvertimeData();
    }
}, 30000);


// === DEPARTMENTS AUTO-REFRESH ===
let departmentsRefreshInterval = null;
let isDepartmentsVisible = false;

function checkDepartmentsVisibility() {
    const deptSection = document.getElementById('departmentsSection');
    if (deptSection) {
        const isVisible = deptSection.style.display !== 'none' && deptSection.offsetParent !== null;
        if (isVisible && !isDepartmentsVisible) {
            isDepartmentsVisible = true;
            startDepartmentsRefresh();
        } else if (!isVisible && isDepartmentsVisible) {
            isDepartmentsVisible = false;
            stopDepartmentsRefresh();
        }
    }
}

function startDepartmentsRefresh() {
    if (departmentsRefreshInterval) clearInterval(departmentsRefreshInterval);
    // Refresh immediately
    loadDepartmentsData();
    // Then refresh every 30 seconds
    departmentsRefreshInterval = setInterval(() => {
        if (isDepartmentsVisible) {
            loadDepartmentsData();
        }
    }, 30000); // 30 seconds
}

function stopDepartmentsRefresh() {
    if (departmentsRefreshInterval) {
        clearInterval(departmentsRefreshInterval);
        departmentsRefreshInterval = null;
    }
}

// === ANALYTICS AUTO-REFRESH ===
let analyticsRefreshInterval = null;
let isAnalyticsVisible = false;

function checkAnalyticsVisibility() {
    const analyticsSection = document.getElementById('analyticsSection');
    if (analyticsSection) {
        const isVisible = analyticsSection.style.display !== 'none' && analyticsSection.offsetParent !== null;
        if (isVisible && !isAnalyticsVisible) {
            isAnalyticsVisible = true;
            startAnalyticsRefresh();
        } else if (!isVisible && isAnalyticsVisible) {
            isAnalyticsVisible = false;
            stopAnalyticsRefresh();
        }
    }
}

function startAnalyticsRefresh() {
    if (analyticsRefreshInterval) clearInterval(analyticsRefreshInterval);
    // Refresh immediately
    updateAnalytics();
    // Then refresh every 30 seconds
    analyticsRefreshInterval = setInterval(() => {
        if (isAnalyticsVisible) {
            updateAnalytics();
        }
    }, 30000); // 30 seconds
}

function stopAnalyticsRefresh() {
    if (analyticsRefreshInterval) {
        clearInterval(analyticsRefreshInterval);
        analyticsRefreshInterval = null;
    }
}

// === MONITOR BOTH SECTIONS ON PAGE LOAD ===
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('adminDashboard')) {
        // Check visibility every 2 seconds
        setInterval(() => {
            checkDepartmentsVisibility();
            checkAnalyticsVisibility();
        }, 2000);
    }
});

// === CLEANUP ON PAGE UNLOAD ===
window.addEventListener('beforeunload', function() {
    stopDepartmentsRefresh();
    stopAnalyticsRefresh();
});

function switchEmployeeTab(tabName) {
    // Handle departments
    if (tabName === 'departments') {
        setTimeout(() => {
            loadDepartmentsData();
            startDepartmentsRefresh();
        }, 300);
    } else {
        stopDepartmentsRefresh();
    }
    
    // Handle analytics
    if (tabName === 'analytics') {
        setTimeout(() => {
            updateAnalytics();
            startAnalyticsRefresh();
        }, 300);
    } else {
        stopAnalyticsRefresh();
    }
};

    // ✅ UPDATE URL TO MATCH TAB
    const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?view=' + tabName;
    window.history.pushState({path:newUrl},'',newUrl);
}
function requestDayOff() { const form = document.getElementById('dayOffRequestForm'); form.style.display = form.style.display === 'none' ? 'block' : 'none'; }
function cancelDayOffRequest() { document.getElementById('dayOffRequestForm').style.display = 'none'; }
function toggleAIAssistant() {
const panel = document.getElementById('aiPanel');
panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
}
function sendMessage() {
const input = document.getElementById('userMessage');
const message = input.value.trim();
if (message) {
addMessage(message, 'user');
input.value = '';
getAIResponse(message);
}
}
// === UPDATED LIVE AI FUNCTIONS ===

function sendMessage() {
    const input = document.getElementById('userMessage');
    const message = input.value.trim();
    if (message) {
        addMessage(message, 'user');
        input.value = '';
        showTypingIndicator(); // Show "AI is typing..."
        getAIResponse(message);
    }
}

function showTypingIndicator() {
    const messagesContainer = document.getElementById('chatMessages');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message message-assistant typing-indicator';
    typingDiv.id = 'ai-typing';
    typingDiv.innerHTML = '<span class="dot">.</span><span class="dot">.</span><span class="dot">.</span>';
    messagesContainer.appendChild(typingDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function removeTypingIndicator() {
    const typingDiv = document.getElementById('ai-typing');
    if (typingDiv) typingDiv.remove();
}

function getAIResponse(userMessage) {
    // Call our new PHP Backend
    fetch(`?action=ai_query&message=${encodeURIComponent(userMessage)}`)
    .then(r => r.json())
    .then(data => {
        removeTypingIndicator();
        if (data.success) {
            // Add the text response
            addMessage(data.text, 'assistant');
            
            // ... inside getAIResponse ...
if (data.data) {
    // Check if it's our new custom HTML list
    if (data.data.html) {
        const lastMsg = document.querySelector('#chatMessages .message-assistant:last-child');
        if(lastMsg) {
            // Append the styled card to the message
            lastMsg.innerHTML += data.data.html;
        }
    } 
    // Fallback for old array format (if you kept it)
    else if (Array.isArray(data.data) && data.data.length > 0) {
        let html = '<div class="ai-data-card">';
        data.data.forEach(item => {
             html += `<div class="ai-list-row"><div class="ai-name">${item.name}</div><div class="ai-dept">${item.department || ''}</div></div>`;
        });
        html += '</div>';
        
        const lastMsg = document.querySelector('#chatMessages .message-assistant:last-child');
        if(lastMsg) lastMsg.innerHTML += html;
    }
}
// ...else {
            addMessage("Sorry, I encountered an error processing that request.", 'assistant');
        }
        // Auto scroll to bottom
        const container = document.getElementById('chatMessages');
        container.scrollTop = container.scrollHeight;
    })
    .catch(e => {
        removeTypingIndicator();
        addMessage("Network error. Please check your connection.", 'assistant');
        console.error(e);
    });
}

// Helper to add message bubbles
function addMessage(text, sender) {
    const messagesContainer = document.getElementById('chatMessages');
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${sender}`;
    // Allow HTML in assistant messages for formatting
    if(sender === 'assistant') {
        messageDiv.innerHTML = text + '<div class="message-time">' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '</div>';
    } else {
        messageDiv.textContent = text;
        const timeDiv = document.createElement('div');
        timeDiv.className = 'message-time';
        timeDiv.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        messageDiv.appendChild(timeDiv);
    }
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}
// === EMPLOYEE MANAGEMENT FUNCTIONS FOR FINGERPRINT MODULE ===
function clearEmployeeForm() {
document.getElementById('fpEmployeeForm').reset();
document.getElementById('fpEmpId').value = '';
document.getElementById('fpEmpPhotoPreview')?.remove();
}
function previewFPPPhoto(input) {
if (input.files && input.files[0]) {
const reader = new FileReader();
reader.onload = function(e) {
const existing = document.getElementById('fpEmpPhotoPreview');
if (existing) existing.remove();
const img = document.createElement('img');
img.id = 'fpEmpPhotoPreview';
img.src = e.target.result;
img.style.cssText = 'width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary-accent); margin-top: 10px;';
input.parentNode.appendChild(img);
}
reader.readAsDataURL(input.files[0]);
}
}
function editFPEmployee(id, name, eid, dept, pos, finger, photo) {
document.getElementById('fpEmpId').value = id;
document.getElementById('fpEmpName').value = name;
document.getElementById('fpEmpEid').value = eid;
document.getElementById('fpEmpDept').value = dept;
document.getElementById('fpEmpPos').value = pos || '';
document.getElementById('fpEmpFinger').value = finger || '';
if (photo) {
const existing = document.getElementById('fpEmpPhotoPreview');
if (existing) existing.remove();
const img = document.createElement('img');
img.id = 'fpEmpPhotoPreview';
img.src = photo;
img.style.cssText = 'width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary-accent); margin-top: 10px;';
document.getElementById('fpEmpPhoto').parentNode.appendChild(img);
}
document.querySelector('#fingerprintSection .card').scrollIntoView({ behavior: 'smooth' });
}
function deleteFPEmployee(id, name) {
if (confirm(`Delete employee ${name}? This cannot be undone.`)) {
const formData = new FormData();
formData.append('action', 'delete_employee');
formData.append('id', id);
fetch('', { method: 'POST', body: formData })
.then(r => r.json())
.then(data => {
if (data.success) {
showToast('Employee deleted successfully!', 'success');
loadFingerprintData();
} else {
showToast('Failed to delete employee', 'error');
}
});
}

tbody.innerHTML = employees.map(emp => `
<tr data-name="${emp.name.toLowerCase()}" data-eid="${emp.employee_id.toLowerCase()}">
<td>
${emp.photo_path && emp.photo_path !== '' ?
`<img src="${emp.photo_path}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary-accent);">` :
`<div style="width: 50px; height: 50px; border-radius: 50%; background: var(--primary-accent); display: flex; align-items: center; justify-content: center; color: #00110f;"><i class="fas fa-user"></i></div>`
}
</td>
<td><strong>${emp.name}</strong></td>
<td>${emp.employee_id}</td>
<td>${emp.department || '-'}</td>
<td>
${emp.finger_id ?
`<span style="background: var(--primary-accent); color: #00110f; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 12px;">${emp.finger_id}</span>` :
`<span style="color: var(--text-muted);">Not set</span>`
}
</td>
<td>
<div class="action-btn-group">
<button class="action-icon-btn edit" title="Edit" onclick="editFPEmployee(${emp.id}, '${emp.name.replace(/'/g, "\\'")}', '${emp.employee_id.replace(/'/g, "\\'")}', '${emp.department?.replace(/'/g, "\\'") || ''}', '${emp.position?.replace(/'/g, "\\'") || ''}', '${emp.finger_id || ''}', '${emp.photo_path || ''}')">
<i class="fas fa-edit"></i>
</button>
<button class="action-icon-btn delete" title="Delete" onclick="deleteFPEmployee(${emp.id}, '${emp.name.replace(/'/g, "\\'")}')">
<i class="fas fa-trash"></i>
</button>
</div>
</td>
</tr>
`).join('');
}
function loadFingerprintData() {
// Load ALL employees (not just enrolled)
fetch('?action=get_all_employees_fp')
.then(r => r.json())
.then(data => {
if (data.success) {
document.getElementById('fpEnrolledCount').textContent = data.enrolled || 0;
document.getElementById('fpPendingCount').textContent = data.pending || 0;
displayFPEmployeeTable(data.employees);
}
})
.catch(e => console.log('Employee load error:', e));
// Load today's scans
fetch('?action=get_fingerprint_scans')
.then(r => r.json())
.then(data => {
if (data.success) {
const today = new Date().toDateString();
const todayScans = data.scans.filter(s => new Date(s.check_in).toDateString() === today).length;
document.getElementById('fpTodayScans').textContent = todayScans;
displayFingerprintScans(data.scans);
}
})
.catch(e => console.log('Scans load error:', e));
}
function displayFingerprintTable(employees) {
const tbody = document.getElementById('fingerprintTableBody');
if (employees.length === 0) {
tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">No enrolled fingerprints yet</td></tr>';
return;
}
tbody.innerHTML = employees.map(emp => `
<tr data-name="${emp.name.toLowerCase()}" data-eid="${emp.employee_id.toLowerCase()}">
<td class="col-id"><span style="background: var(--primary-accent); color: #00110f; padding: 4px 12px; border-radius: 20px; font-weight: 600;">${emp.finger_id}</span></td>
<td class="col-name">
<div class="emp-avatar-wrapper">
<div class="emp-avatar"><i class="fas fa-user"></i></div>
<span class="emp-name-text">${emp.name}</span>
</div>
</td>
<td class="col-dept">${emp.employee_id}</td>
<td class="col-dept">${emp.department}</td>
<td class="col-actions">
<div class="action-btn-group">
<button class="action-icon-btn delete" title="Remove Enrollment" onclick="removeFingerprintEnrollment(${emp.id}, '${emp.name.replace(/'/g, "\\'")}')">
<i class="fas fa-trash"></i>
</button>
</div>
</td>
</tr>
`).join('');
}
function displayFingerprintScans(scans) {
const container = document.getElementById('fingerprintScansList');
if (scans.length === 0) {
container.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--text-muted);">No fingerprint scans recorded yet</div>';
return;
}
container.innerHTML = scans.map(scan => `
<div class="record-item">
<div class="record-info">
<div class="record-icon present"><i class="fas fa-fingerprint"></i></div>
<div class="record-details">
<div class="record-name">${scan.name}</div>
<div class="record-id">Finger ID: ${scan.finger_id || 'N/A'} • ${scan.employee_id}</div>
</div>
</div>
<div class="record-time">
<div>${new Date(scan.check_in).toLocaleDateString()}</div>
<div>${new Date(scan.check_in).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true })}</div>
</div>
</div>
`).join('');
}
function filterFingerprintTable(searchTerm) {
const search = searchTerm.toLowerCase();
const rows = document.querySelectorAll('#fingerprintTableBody tr[data-name]');
rows.forEach(row => {
const name = row.getAttribute('data-name');
const eid = row.getAttribute('data-eid');
row.style.display = (name.includes(search) || eid.includes(search)) ? '' : 'none';
});
}
function removeFingerprintEnrollment(employeeId, employeeName) {
showConfirm(`Remove fingerprint enrollment for ${employeeName}? They will need to re-enroll.`, () => {
fetch('', {
method: 'POST',
headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
body: `action=remove_fingerprint&employee_id=${employeeId}`
})
.then(r => r.json())
.then(data => {
if (data.success) {
showToast('Fingerprint enrollment removed', 'success');
loadFingerprintData();
} else {
showToast('Failed to remove enrollment', 'error');
}
});
});
}



if (typeof originalSwitchTab2 === 'function') {
originalSwitchTab2(tabName);
}
if (tabName === 'fingerprint' && document.getElementById('fingerprintSection')?.style.display !== 'none') {
loadFingerprintData();
}

function openAddEmployeeModal() {
document.getElementById('modalTitle').textContent = 'Add New Employee';
document.getElementById('employeeIdInput').value = '';
document.getElementById('employeeForm').reset();
document.getElementById('empPassword').value = 'password123';
document.getElementById('employeeModal').style.display = 'block';
}
function openEditEmployeeModal(id, name, eid, position, dept, role, birthday, fingerId, photoPath) {
    
        document.getElementById('modalTitle').textContent = 'Edit Employee';
    document.getElementById('employeeIdInput').value = id;
    document.getElementById('empName').value = name;
    document.getElementById('empEid').value = eid;
    document.getElementById('empPosition').value = position;
    document.getElementById('empDept').value = dept;
    document.getElementById('empRole').value = role;
    document.getElementById('empPassword').value = '';
    document.getElementById('empBirthday').value = birthday || '';
    document.getElementById('empFingerId').value = fingerId || '';
    

    
    // ADD THIS LINE TO LOAD PHOTO
const preview = document.getElementById('empPhotoPreview');
if(photoPath && photoPath !== '') {
preview.src = photoPath;
document.getElementById('empPhotoPath').value = photoPath;
} else {
preview.src = 'https://ui-avatars.com/api/?name=New+Employee&background=19D3C5&color=00110f&size=128';document.getElementById('empPhotoPath').value = '';
}

    document.getElementById('employeeModal').style.display = 'block';
}

function closeEmployeeModal() {
document.getElementById('employeeModal').style.display = 'none';
}
function toggleReportFilterInput() {
    const scope = document.getElementById('reportScope').value;
    const group = document.getElementById('reportFilterValueGroup');
    const input = document.getElementById('reportFilterValue');
    
    if(scope === 'all') {
        group.style.display = 'none';
        input.required = false;
    } else {
        group.style.display = 'flex';
        input.required = true;
        // Change placeholder based on selection
        if(scope === 'department') {
            input.placeholder = "Enter Department Name (e.g. IT)";
        } else if (scope === 'employee') {
            input.placeholder = "Enter Employee ID (e.g. 2025001)";
        }
    }
}
// === EMPLOYEE FORM SUBMISSION ===
document.addEventListener('DOMContentLoaded', function() {
    const employeeForm = document.getElementById('employeeForm');
    if (employeeForm) {
        employeeForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const formData = new FormData(this);
            const isEdit = !!document.getElementById('employeeIdInput').value;
            formData.append('action', isEdit ? 'update_employee' : 'add_employee');
            
            // Get CSRF token
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                
                if (data.success) {
                    showToast(data.message || (isEdit ? 'Employee updated!' : 'Employee added!'), 'success');
                    closeEmployeeModal();
                    
                    // Reload the employees table
                    if (isEdit) {
                        // Update existing row
                        updateEmployeeRowInTable(data.employee);
                    } else {
                        // Add new row
                        addEmployeeRowToTable(data.employee);
                    }
                } else {
                    showToast('Error: ' + (data.message || 'Failed to save'), 'error');
                }
            })
            .catch(err => {
                console.error('Save error:', err);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                showToast('Network error: ' + err.message, 'error');
            });
            
            return false;
        });
    }
});

// === HELPER: Update existing employee row in table ===
function updateEmployeeRowInTable(emp) {
    const row = document.querySelector(`#employeesTableBody tr[data-id="${emp.id}"]`);
    if (row) {
        // Animate the update
        row.style.transition = 'all 0.3s ease';
        row.style.background = 'rgba(25, 211, 197, 0.1)';
        
        setTimeout(() => {
            // Update each cell
            const cells = row.querySelectorAll('td');
            if (cells[0]) cells[0].textContent = emp.employee_id; // Employee ID
            
            // Name with photo
            if (cells[1]) {
                const photoHtml = emp.photo_path && emp.photo_path !== '' 
                    ? `<img src="${emp.photo_path}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`
                    : '<i class="fas fa-user"></i>';
                cells[1].innerHTML = `
                    <div class="emp-avatar-wrapper">
                        <div class="emp-avatar">${photoHtml}</div>
                        <span class="emp-name-text" title="${emp.name}">${emp.name}</span>
                    </div>
                `;
            }
            
            if (cells[2]) cells[2].textContent = emp.position; // Position
            if (cells[3]) cells[3].textContent = emp.department || 'N/A'; // Department
            if (cells[4]) cells[4].textContent = emp.birthday ? new Date(emp.birthday).toLocaleDateString() : '-'; // Birthday
            if (cells[5]) cells[5].textContent = emp.finger_id || '-'; // Finger ID
            
            // Role badge
            if (cells[6]) {
                const roleClass = emp.role.toLowerCase();
                cells[6].innerHTML = `<span class="role-badge ${roleClass}">${emp.role}</span>`;
            }
            
            // Update edit button onclick with new data
            const editBtn = row.querySelector('.action-icon-btn.edit');
            if (editBtn) {
                const jsName = emp.name.replace(/'/g, "\\'");
                const jsPos = (emp.position || '').replace(/'/g, "\\'");
                const jsDept = (emp.department || '').replace(/'/g, "\\'");
                const jsPhoto = (emp.photo_path || '').replace(/'/g, "\\'");
                editBtn.setAttribute('onclick', 
                    `openEditEmployeeModal(${emp.id}, '${jsName}', '${emp.employee_id}', '${jsPos}', '${jsDept}', '${emp.role}', '${emp.birthday || ''}', '${emp.finger_id || ''}', '${jsPhoto}')`
                );
            }
            
            setTimeout(() => {
                row.style.background = '';
            }, 1000);
        }, 300);
    }
}

// === HELPER: Add new employee row to table ===
function addEmployeeRowToTable(emp) {
    const tbody = document.getElementById('employeesTableBody');
    if (!tbody) {
        location.reload();
        return;
    }
    
    const newRow = document.createElement('tr');
    newRow.setAttribute('data-id', emp.id);
    newRow.setAttribute('data-name', emp.name.toLowerCase());
    newRow.setAttribute('data-eid', emp.employee_id.toLowerCase());
    
    const photoHtml = emp.photo_path && emp.photo_path !== ''
        ? `<img src="${emp.photo_path}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`
        : '<i class="fas fa-user"></i>';
    
    const birthdayDisplay = emp.birthday ? new Date(emp.birthday).toLocaleDateString() : '-';
    const fingerDisplay = emp.finger_id || '-';
    const roleClass = emp.role.toLowerCase();
    
    newRow.innerHTML = `
        <td class="col-id">${emp.employee_id}</td>
        <td class="col-name">
            <div class="emp-avatar-wrapper">
                <div class="emp-avatar">${photoHtml}</div>
                <span class="emp-name-text" title="${emp.name}">${emp.name}</span>
            </div>
        </td>
        <td class="col-position" title="${emp.position}">${emp.position}</td>
        <td class="col-dept" title="${emp.department || 'N/A'}">${emp.department || 'N/A'}</td>
        <td class="col-birthday">${birthdayDisplay}</td>
        <td class="col-finger">${fingerDisplay}</td>
        <td class="col-role"><span class="role-badge ${roleClass}">${emp.role}</span></td>
        <td class="col-actions">
            <div class="action-btn-group">
                <button class="action-icon-btn" style="color: #17a2b8;" title="Manage Schedule" onclick="openScheduleModal(${emp.id})">
                    <i class="fas fa-calendar-alt"></i>
                </button>
                <button class="action-icon-btn edit" title="Edit" onclick="openEditEmployeeModal(${emp.id}, '${emp.name.replace(/'/g, "\\'")}', '${emp.employee_id}', '${(emp.position||'').replace(/'/g, "\\'")}', '${(emp.department||'').replace(/'/g, "\\'")}', '${emp.role}', '${emp.birthday || ''}', '${emp.finger_id || ''}', '${(emp.photo_path||'').replace(/'/g, "\\'")}')">
                    <i class="fas fa-pen-to-square"></i>
                </button>
                <button class="action-icon-btn delete" title="Delete" onclick="deleteEmployee(${emp.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>
    `;
    
    // Add with animation
    newRow.style.opacity = '0';
    newRow.style.transform = 'translateX(-20px)';
    newRow.style.background = 'rgba(25, 211, 197, 0.1)';
    tbody.insertBefore(newRow, tbody.firstChild);
    
    setTimeout(() => {
        newRow.style.transition = 'all 0.3s ease';
        newRow.style.opacity = '1';
        newRow.style.transform = 'translateX(0)';
        setTimeout(() => {
            newRow.style.background = '';
        }, 1000);
    }, 50);
}
// === HELPER: Update existing employee row after edit ===
function updateEmployeeRow(emp) {
    const row = document.querySelector(`tr[data-id="${emp.id}"]`);
    if (row) {
        // Fade out for smooth transition
        row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        row.style.opacity = '0.5';
        
        setTimeout(() => {
            // Update each cell with new data
            const cells = {
                'col-id': emp.employee_id,
                'emp-name-text': emp.name,
                'col-position': emp.position,
                'col-dept': emp.department || 'N/A',
                'col-birthday': emp.birthday ? new Date(emp.birthday).toLocaleDateString() : '-',
                'col-finger': emp.finger_id || '-'
            };
            
            Object.keys(cells).forEach(selector => {
                const el = row.querySelector(`.${selector}`);
                if (el) el.textContent = cells[selector];
            });
            
            // Update role badge
            const roleBadge = row.querySelector('.role-badge');
            if (roleBadge) {
                roleBadge.textContent = emp.role;
                roleBadge.className = `role-badge ${emp.role.toLowerCase()}`;
            }
            
            // Update photo/avatar
            const avatar = row.querySelector('.emp-avatar');
            if (avatar) {
                if (emp.photo_path && emp.photo_path !== '') {
                    avatar.innerHTML = `<img src="${emp.photo_path}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`;
                } else {
                    avatar.innerHTML = '<i class="fas fa-user"></i>';
                }
            }
            
            // Update edit button onclick with new data
            const editBtn = row.querySelector('.action-icon-btn.edit');
            if (editBtn) {
                const jsName = emp.name.replace(/'/g, "\\'");
                const jsPos = (emp.position || '').replace(/'/g, "\\'");
                const jsDept = (emp.department || '').replace(/'/g, "\\'");
                const jsPhoto = (emp.photo_path || '').replace(/'/g, "\\'");
                editBtn.setAttribute('onclick', 
                    `openEditEmployeeModal(${emp.id}, '${jsName}', '${emp.employee_id}', '${jsPos}', '${jsDept}', '${emp.role}', '${emp.birthday || ''}', '${emp.finger_id || ''}', '${jsPhoto}')`
                );
            }
            
            // Fade back in
            row.style.opacity = '1';
            row.style.transform = 'translateX(0)';
        }, 300);
    }
}

// === HELPER: Add new employee row to table ===
function addEmployeeRow(emp) {
    const tbody = document.getElementById('employeesTableBody');
    if (!tbody) { location.reload(); return; }
    
    const newRow = document.createElement('tr');
    newRow.setAttribute('data-id', emp.id);
    newRow.setAttribute('data-name', emp.name.toLowerCase());
    newRow.setAttribute('data-eid', emp.employee_id.toLowerCase());
    
    const photoHtml = emp.photo_path && emp.photo_path !== '' 
        ? `<img src="${emp.photo_path}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">`
        : '<i class="fas fa-user"></i>';
    
    const birthdayDisplay = emp.birthday ? new Date(emp.birthday).toLocaleDateString() : '-';
    const fingerDisplay = emp.finger_id || '-';
    
    newRow.innerHTML = `
        <td class="col-id">${emp.employee_id}</td>
        <td class="col-name">
            <div class="emp-avatar-wrapper">
                <div class="emp-avatar">${photoHtml}</div>
                <span class="emp-name-text" title="${emp.name}">${emp.name}</span>
            </div>
        </td>
        <td class="col-position" title="${emp.position}">${emp.position}</td>
        <td class="col-dept" title="${emp.department || 'N/A'}">${emp.department || 'N/A'}</td>
        <td class="col-birthday">${birthdayDisplay}</td>
        <td class="col-finger">${fingerDisplay}</td>
        <td class="col-role"><span class="role-badge ${emp.role.toLowerCase()}">${emp.role}</span></td>
        <td class="col-actions">
            <div class="action-btn-group">
                <button class="action-icon-btn" style="color: #17a2b8;" title="Manage Schedule" onclick="openScheduleModal(${emp.id})">
                    <i class="fas fa-calendar-alt"></i>
                </button>
                <button class="action-icon-btn edit" title="Edit" onclick="openEditEmployeeModal(${emp.id}, '${emp.name.replace(/'/g, "\\'")}', '${emp.employee_id}', '${(emp.position||'').replace(/'/g, "\\'")}', '${(emp.department||'').replace(/'/g, "\\'")}', '${emp.role}', '${emp.birthday || ''}', '${emp.finger_id || ''}', '${(emp.photo_path||'').replace(/'/g, "\\'")}')">
                    <i class="fas fa-pen-to-square"></i>
                </button>
                <button class="action-icon-btn delete" title="Delete" onclick="deleteEmployee(${emp.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>
    `;
    
    // Add with animation
    newRow.style.opacity = '0';
    newRow.style.transform = 'translateX(-20px)';
    tbody.appendChild(newRow);
    
    setTimeout(() => {
        newRow.style.transition = 'all 0.3s ease';
        newRow.style.opacity = '1';
        newRow.style.transform = 'translateX(0)';
    }, 50);
}

// === DELETE EMPLOYEE - FIXED ===
function deleteEmployee(employeeId) {
    if (!confirm('Are you sure you want to delete this employee? This cannot be undone.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete_employee');
    formData.append('id', employeeId);
    
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || 
                     document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (csrfToken) {
        formData.append('csrf_token', csrfToken);
    }
    
    fetch('', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
    })
    .then(data => {
        if (data.success) {
            const row = document.querySelector(`#employeesTableBody tr[data-id="${employeeId}"]`);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    row.remove();
                    showToast('Employee moved to archives successfully!', 'success');
                }, 300);
            } else {
                location.reload();
            }
        } else {
            showToast('Failed: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(err => {
        console.error('Delete error:', err);
        showToast('Network error: ' + err.message, 'error');
    });
}


function approveLeave(requestId) {
    showConfirm('Are you sure you want to approve this leave request?', () => {
        fetch('', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=approve_leave&request_id=' + requestId
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Leave request approved successfully!', 'success');
                // UPDATE UI INSTANTLY WITHOUT RELOAD
                updateLeaveCardUI(requestId, 'Approved');
            } else {
                showToast('Failed to approve leave request.', 'error');
            }
        })
        .catch(err => {
            console.error('Approve error:', err);
            showToast('Error approving request', 'error');
        });
    });
}

function rejectLeave(requestId) {
    showConfirm('Are you sure you want to reject this leave request?', () => {
        fetch('', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=reject_leave&request_id=' + requestId
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Leave request rejected successfully!', 'success');
                // UPDATE UI INSTANTLY WITHOUT RELOAD
                updateLeaveCardUI(requestId, 'Rejected');
            } else {
                showToast('Failed to reject leave request.', 'error');
            }
        })
        .catch(err => {
            console.error('Reject error:', err);
            showToast('Error rejecting request', 'error');
        });
    });
}

// ADD THIS NEW FUNCTION TO HANDLE THE UI UPDATE
function updateLeaveCardUI(requestId, newStatus) {
    // 1. Find the button that was clicked
    const btn = document.querySelector(`button[onclick*="approveLeave(${requestId})"], button[onclick*="rejectLeave(${requestId})"]`);
    if (!btn) return;

    // 2. Find the parent card
    const card = btn.closest('.leave-request-card');
    if (!card) return;

    // 3. Find the action buttons container
    const actionContainer = card.querySelector('.action-buttons');
    
    // 4. Define styles for the new status
    const styles = {
        'Approved': {
            bg: 'rgba(40,167,69,0.1)',
            border: '#28a745',
            color: '#28a745',
            icon: 'fa-check-circle',
            text: 'Approved'
        },
        'Rejected': {
            bg: 'rgba(220,53,69,0.1)',
            border: '#dc3545',
            color: '#dc3545',
            icon: 'fa-times-circle',
            text: 'Rejected'
        }
    };
    
    const style = styles[newStatus];

    // 5. Replace buttons with the status badge
    actionContainer.innerHTML = `
        <div style="display: flex; align-items: center; gap: 8px; padding: 8px 16px; background: ${style.bg}; border-radius: 8px; border: 1px solid ${style.border}; color: ${style.color}; font-weight: 600; font-size: 13px; animation: slideIn 0.3s ease;">
            <i class="fas ${style.icon}"></i>
            ${style.text}
        </div>
    `;

    // 6. Update the data-status attribute
    card.setAttribute('data-status', newStatus.toLowerCase());

    // 7. Update the counters at the top (Pending, Approved, Rejected)
    updateLeaveCounters();
}

// ADD THIS HELPER TO RECALCULATE COUNTERS
function updateLeaveCounters() {
    const cards = document.querySelectorAll('.leave-request-card');
    let pending = 0, approved = 0, rejected = 0;
    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        if (status === 'pending') pending++;
        else if (status === 'approved') approved++;
        else if (status === 'rejected') rejected++;
    });

    // Update the number displays
    const pendingEl = document.getElementById('count-pending');
    const approvedEl = document.getElementById('count-approved');
    const rejectedEl = document.getElementById('count-rejected');

    if(pendingEl) pendingEl.textContent = pending;
    if(approvedEl) approvedEl.textContent = approved;
    if(rejectedEl) rejectedEl.textContent = rejected;
}

// Add this new function to update the card status
function updateLeaveCardStatus(requestId, newStatus) {
// Find the card with this request ID
const card = document.querySelector(`.leave-request-card[onclick*="${requestId}"], .record-item[data-status][onclick*="${requestId}"]`);
if (!card) {
// If we can't find the specific card, just refresh the leave section
const leaveSection = document.getElementById('leaveadministrationSection');
if (leaveSection && leaveSection.style.display !== 'none') {
// Re-fetch the leave requests
fetch('?action=get_day_off_requests')
.then(r => r.json())
.then(data => {
if (data.success) {
// You can reload just this section if needed
location.reload();
}
});
}
return;
}
/* === DEPARTMENTS MODULE LOGIC === */
function loadDepartmentsData() {
    const grid = document.getElementById('departmentsGrid');
    if(!grid) return;
    
    // Show loading state
    grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--text-muted);">
        <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
        <div style="margin-top: 10px;">Loading department data...</div>
    </div>`;

    fetch('?action=get_department_stats')
    .then(r => r.json())
    .then(data => {
        if(data.labels && data.labels.length > 0) {
            let html = '';
            data.labels.forEach((dept, index) => {
                const total = data.total ? data.total[index] : 0; // Fallback if API changes
                const present = data.values[index];
                const rate = total > 0 ? Math.round((present / total) * 100) : 0;
                
                // Determine color based on rate
                let color = '#28a745'; // Green
                if(rate < 50) color = '#dc3545'; // Red
                else if(rate < 80) color = '#ffc107'; // Yellow

                html += `
                <div class="dept-card">
                    <div class="dept-header">
                        <div class="dept-name">
                            <div class="dept-icon"><i class="fas fa-building"></i></div>
                            ${dept}
                        </div>
                    </div>
                    <div class="dept-stats">
                        <div class="dept-stat-item">
                            <div class="dept-stat-value">${present}</div>
                            <div class="dept-stat-label">Present</div>
                        </div>
                        <div class="dept-stat-item">
                            <div class="dept-stat-value">${total}</div>
                            <div class="dept-stat-label">Total</div>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted);">
                        <span>Attendance Rate</span>
                        <span class="dept-attendance-rate" style="color:${color}">${rate}%</span>
                    </div>
                    <div class="dept-progress">
                        <div class="dept-progress-bar" style="width: ${rate}%; background: ${color};"></div>
                    </div>
                </div>
                `;
            });
            grid.innerHTML = html;
        } else {
            grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--text-muted);">No department data available.</div>`;
        }
    })
    .catch(e => {
        console.error(e);
        grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #dc3545;">Error loading data.</div>`;
    });
}

// Ensure the existing switchTab function handles these new IDs
const originalSwitchTab = window.switchTab;
window.switchTab = function(tabName) {
    // Call original logic first
    if(typeof originalSwitchTab === 'function') {
        originalSwitchTab(tabName);
    }
    
    // Handle specific module initialization
    if(tabName === 'departments') {
        loadDepartmentsData();
    }
    if(tabName === 'analytics') {
        updateAnalytics();
    }
};
// Update the status badge
const actionButtons = card.querySelector('.action-buttons');
if (actionButtons) {
const statusColors = {
'Approved': { bg: 'rgba(40,167,69,0.1)', color: '#28a745', icon: 'fa-check-circle' },
'Rejected': { bg: 'rgba(220,53,69,0.1)', color: '#dc3545', icon: 'fa-times-circle' }
};
const colors = statusColors[newStatus];
actionButtons.innerHTML = `
<div style="display: flex; align-items: center; gap: 8px; padding: 8px 16px; background: ${colors.bg}; border-radius: 8px; border: 1px solid ${colors.color}; color: ${colors.color}; font-weight: 600; font-size: 13px;">
<i class="fas ${colors.icon}"></i>
${newStatus}
</div>
`;
}

// Update data-status attribute
card.setAttribute('data-status', newStatus.toLowerCase());

// Update stats counters
updateLeaveStats();
}

function deleteEmployee(employeeId) {
showConfirm('Are you sure you want to delete this employee? This cannot be undone.', () => {
fetch('', {
method: 'POST',
headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
body: 'action=delete_employee&id=' + employeeId
})
.then(r => r.json())
.then(data => {
if (data.success) {
// Remove the row from the table immediately
const row = document.querySelector(`tr[data-id="${employeeId}"]`);
if(row) {
row.style.opacity = '0';
row.style.transform = 'translateX(-20px)';
row.style.transition = 'all 0.3s ease';
setTimeout(() => row.remove(), 300);
}
showToast('Employee moved to archives successfully!', 'success');
// Update employee count if on employees page
const empCount = document.querySelector('#employeesTableBody tr');
if(empCount) {
// Just remove the row, no reload needed
}
} else {
showToast('Failed to delete employee: ' + (data.message || 'Unknown error'), 'error');
}
})
.catch(err => {
showToast('Network error: ' + err.message, 'error');
});
});
}
function showAttendanceList(type) {
const today = '<?= date('Y-m-d') ?>';
let url = '';
let title = '';
if (type === 'present') {
url = `?action=get_attendance_list&type=present&date=${today}`;
title = 'Employees Present Today';
} else if (type === 'late') {
url = `?action=get_attendance_list&type=late&date=${today}`;
title = 'Employees Late Today';
} else if (type === 'absent') {
url = `?action=get_attendance_list&type=absent&date=${today}`;
title = 'Employees Absent Today';
} else {
showToast('Invalid attendance type.', 'error');
return;
}
fetch(url)
.then(response => response.json())
.then(data => {
if (data.success) {
let html = '<div style="padding: 10px;">';
if (data.employees.length === 0) {
html += '<p style="text-align: center; color: var(--text-muted); padding: 20px;">No employees found.</p>';
} else {
data.employees.forEach(emp => {
html += `<div style="padding: 8px; border-bottom: 1px solid var(--border-color);">
<strong>${emp.name}</strong><br>
<small>EID: ${emp.employee_id} • ${emp.department}</small>
</div>`;
});
}
html += '</div>';
document.getElementById('attendanceListContent').innerHTML = html;
document.getElementById('attendanceListTitle').textContent = title;
document.getElementById('attendanceListModal').style.display = 'block';
} else {
showToast('Failed to fetch attendance list.', 'error');
}
})
.catch(error => {
console.error('Error:', error);
showToast('An error occurred while fetching attendance data.', 'error');
});
}
function closeAttendanceListModal() {
document.getElementById('attendanceListModal').style.display = 'none';
}
window.onclick = function(event) {
const modalIds = ['allEmployeesModal', 'departmentsModal', 'attendanceRateModal', 'attendanceListModal', 'employeeModal', 'passwordModal'];
for (let id of modalIds) {
const modal = document.getElementById(id);
if (modal && event.target === modal) {
modal.style.display = 'none';
}
}
};
function generateAttendanceReport() {
const { jsPDF } = window.jspdf;
const doc = new jsPDF();
doc.setFontSize(18);
doc.text('Attendance Report', 20, 20);
doc.setFontSize(12);
doc.text(`Generated on: ${new Date().toLocaleString()}`, 20, 30);
doc.setFontSize(14);
doc.text('Employee Attendance Summary', 20, 45);
const tableData = [];
const headers = ['Employee ID', 'Name', 'Department', 'Check In', 'Check Out', 'Working Hours'];
const rows = document.querySelectorAll('.record-item');
rows.forEach(row => {
const cells = row.querySelectorAll('.record-info .record-details > div:first-child, .record-time > div:first-child');
if (cells.length >= 2) {
const name = cells[0].textContent;
const time = cells[1].textContent;
tableData.push([name, '', '', time, '', '']);
}
});
doc.autoTable({
head: [headers],
body: tableData,
startY: 55,
theme: 'striped',
styles: { fontSize: 10 },
headStyles: { fillColor: [22, 160, 133] }
});
doc.save('attendance_report.pdf');
}





function generateCustomReport() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    
    // 1. Get Values
    const startDate = document.getElementById('reportStartDate').value;
    const endDate = document.getElementById('reportEndDate').value;
    const reportType = document.getElementById('reportType').value;
    const scope = document.getElementById('reportScope').value; // 'all', 'department', 'employee'
    const filterValue = document.getElementById('reportFilterValue').value;

    // 2. Validation
    if (!startDate || !endDate) {
        showToast('Please select both start and end dates.', 'error');
        return;
    }
    if ((scope === 'department' || scope === 'employee') && !filterValue) {
        showToast(`Please enter the ${scope === 'department' ? 'Department Name' : 'Employee ID'}.`, 'error');
        return;
    }

    // 3. UI Feedback
    const button = document.querySelector('#reportsSection .action-button.primary');
    const originalText = button.innerHTML;
    button.innerHTML = '<span class="loading-spinner"></span> Generating...';

    // 4. Build URL with Scope & Filter
    let url = `?action=get_export_data&type=${reportType}&start_date=${startDate}&end_date=${endDate}`;
    if(scope !== 'all') {
        url += `&scope=${scope}&filter_value=${encodeURIComponent(filterValue)}`;
    }

    fetch(url)
    .then(response => response.json())
    .then(data => {
        button.innerHTML = originalText;
        if (!data.success || !data.data || data.data.length === 0) {
            showToast('No data found for the selected filters.', 'warning');
            return;
        }

        // 5. Dynamic PDF Title based on Scope
        let reportTitle = "Attendance Report";
        let subTitle = `Period: ${new Date(startDate).toLocaleDateString()} to ${new Date(endDate).toLocaleDateString()}`;
        
        if (scope === 'department') {
            reportTitle = `Department Report: ${filterValue}`;
            subTitle += ` | Scope: Department`;
        } else if (scope === 'employee') {
            reportTitle = `Employee Report: ${filterValue}`;
            subTitle += ` | Scope: Individual`;
        }

        // --- PAYROLL REPORT ---
        if (reportType === 'payroll') {
            doc.setFontSize(18);
            doc.text(reportTitle, 20, 20);
            doc.setFontSize(12);
            doc.text(subTitle, 20, 30);
            doc.setFontSize(14);
            doc.text('Payroll Summary with Night Differential', 20, 45);
            
            const payrollData = data.data;
            const tableData = payrollData.map(record => [
                record.employee_id, record.name, record.department, 
                record.check_in, record.check_out, 
                record.regular_hours, record.overtime_hours, record.night_diff_hours
            ]);

            doc.autoTable({
                head: [['EID', 'Name', 'Dept', 'In', 'Out', 'Reg', 'OT', 'Night']],
                body: tableData,
                startY: 55,
                theme: 'striped',
                styles: { fontSize: 9 },
                headStyles: { fillColor: [22, 160, 133] }
            });
            doc.save(`payroll_${scope}_${filterValue || 'all'}_${startDate}.pdf`);
            showToast('Payroll report generated!', 'success');
        } 
        
        // --- ATTENDANCE REPORT ---
        else if (reportType === 'attendance') {
            doc.setFontSize(18);
            doc.text(reportTitle, 20, 20);
            doc.setFontSize(12);
            doc.text(subTitle, 20, 30);
            doc.setFontSize(14);
            doc.text('Daily Attendance Records', 20, 45);

            const attendanceData = data.data.map(record => [
                record.employee_id, record.name, record.department, 
                record.check_in, record.check_out, record.regular_hours + 'h'
            ]);

            doc.autoTable({
                head: [['EID', 'Name', 'Dept', 'In', 'Out', 'Hours']],
                body: attendanceData,
                startY: 55,
                theme: 'striped',
                styles: { fontSize: 10 },
                headStyles: { fillColor: [22, 160, 133] }
            });
            doc.save(`attendance_${scope}_${filterValue || 'all'}_${startDate}.pdf`);
            showToast('Attendance report generated!', 'success');
        } 
        
        // --- LEAVE REPORT ---
        else if (reportType === 'leave') {
            doc.setFontSize(18);
            doc.text(reportTitle, 20, 20);
            doc.setFontSize(12);
            doc.text(subTitle, 20, 30);
            doc.setFontSize(14);
            doc.text('Day Off Requests Summary', 20, 45);

            const leaveData = data.data.map(record => [
                record.employee_id, record.name, record.start_date, record.end_date, record.status
            ]);

            doc.autoTable({
                head: [['EID', 'Name', 'Start', 'End', 'Status']],
                body: leaveData,
                startY: 55,
                theme: 'striped',
                styles: { fontSize: 10 },
                headStyles: { fillColor: [22, 160, 133] }
            });
            doc.save(`leave_${scope}_${filterValue || 'all'}_${startDate}.pdf`);
            showToast('Leave report generated!', 'success');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        button.innerHTML = originalText;
        showToast('Failed to generate report.', 'error');
    });

// === FINGERPRINT LIVE POLLING ENGINE ===
let fingerprintPollingInterval = null;
let isFingerprintSectionVisible = false;
let lastScanTimestamp = 0;

// 1. Function to Load Data
function loadFingerprintData() {
// A. Load Employee Stats (Enrolled/Pending)
fetch('?action=get_all_employees_fp')
.then(r => r.json())
.then(data => {
if (data.success) {
document.getElementById('fpEnrolledCount').textContent = data.enrolled || 0;
document.getElementById('fpPendingCount').textContent = data.pending || 0;
displayFPEmployeeTable(data.employees);
}
})
.catch(e => console.log('Employee load error:', e));
// B. Load Scan Stats & Logs (Today's Scans)
fetch('?action=get_fingerprint_scans')
.then(r => r.json())
.then(data => {
if (data.success) {
document.getElementById('fpTodayScans').textContent = data.today_count || 0;
displayFingerprintScans(data.scans);
// C. Check for NEW Scan to trigger Modal (Live Popup)
if (data.scans.length > 0) {
const latestScanTime = new Date(data.scans[0].check_in).getTime();
if (latestScanTime > lastScanTimestamp) {
lastScanTimestamp = latestScanTime;
showCoolScanModal(data.scans[0]);
}
}
}
})
.catch(e => console.log('Scans load error:', e));
}

// 2. Start/Stop Polling based on Tab Visibility
function startFingerprintPolling() {
if (fingerprintPollingInterval) clearInterval(fingerprintPollingInterval);
fingerprintPollingInterval = setInterval(() => {
    if (isFingerprintSectionVisible) {
        loadFingerprintData();
    }
}, 3000);
}

function stopFingerprintPolling() {
if (fingerprintPollingInterval) {
clearInterval(fingerprintPollingInterval);
fingerprintPollingInterval = null;
}
}

// 3. Monitor Tab Visibility
function checkFingerprintSectionVisibility() {
const fingerprintSection = document.getElementById('fingerprintSection');
if (fingerprintSection) {
const isVisible = fingerprintSection.style.display !== 'none' && fingerprintSection.offsetParent !== null;
if (isVisible && !isFingerprintSectionVisible) {
isFingerprintSectionVisible = true;
startFingerprintPolling();
loadFingerprintData();
} else if (!isVisible && isFingerprintSectionVisible) {
isFingerprintSectionVisible = false;
stopFingerprintPolling();
}
}
}

// 4. Helper: Display Scan Logs
function displayFingerprintScans(scans) {
const container = document.getElementById('fingerprintScansList');
if (!container) return;
if (scans.length === 0) {
container.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--text-muted);">No fingerprint scans recorded yet</div>';
return;
}
container.innerHTML = scans.map(scan => `
<div class="record-item">
<div class="record-info">
<div class="record-icon present"><i class="fas fa-fingerprint"></i></div>
<div class="record-details">
<div class="record-name">${scan.name}</div>
<div class="record-id">Finger ID: ${scan.finger_id || 'N/A'} • ${scan.employee_id}</div>
</div>
</div>
<div class="record-time">
<div>${new Date(scan.check_in).toLocaleDateString()}</div>
<div>${new Date(scan.check_in).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true })}</div>
</div>
</div>
`).join('');
}

// 5. Helper: Display Employee Table
function displayFPEmployeeTable(employees) {
const tbody = document.getElementById('fingerprintTableBody');
if (!tbody) return;
if (employees.length === 0) {
tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No employees found.</td></tr>';
return;
}
tbody.innerHTML = employees.map(emp => `
<tr data-name="${emp.name.toLowerCase()}" data-eid="${emp.employee_id.toLowerCase()}">
<td>${emp.photo_path ? `<img src="${emp.photo_path}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">` : '<div style="width: 50px; height: 50px; border-radius: 50%; background: var(--primary-accent); display: flex; align-items: center; justify-content: center;"><i class="fas fa-user"></i></div>'}</td>
<td><strong>${emp.name}</strong></td>
<td>${emp.employee_id}</td>
<td>${emp.department || '-'}</td>
<td>${emp.finger_id ? `<span style="background: var(--primary-accent); color: #00110f; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 12px;">${emp.finger_id}</span>` : '<span style="color: var(--text-muted);">Not set</span>'}</td>
<td><div class="action-btn-group"><button class="action-icon-btn delete" onclick="removeFingerprintEnrollment(${emp.id}, '${emp.name.replace(/'/g, "\\'")}')"><i class="fas fa-trash"></i></button></div></td>
</tr>
`).join('');
}

// 6. THE COOL SCAN MODAL
function showCoolScanModal(data) {
    const modal = document.getElementById('coolScanModal');
    const nameEl = document.getElementById('modalName');
    const timeEl = document.getElementById('modalTime');
    const statusEl = document.getElementById('modalStatus');
    const iconEl = document.getElementById('modalIcon');
    const imgEl = document.getElementById('modalImage');

    // Set Name
    nameEl.textContent = data.name || 'Unknown Employee';

    // FIX: Format Time to 12-Hour AM/PM
    const scanTime = new Date(data.check_in || data.time);
timeEl.textContent = formatTime12(scanTime);

    // Set Status & Color
    if (data.status === 'Present' || data.status === 'Checked In') {
        statusEl.textContent = 'Checked In';
        statusEl.style.color = '#00ff88';
        iconEl.className = 'fas fa-check-circle';
        iconEl.style.color = '#00ff88';
    } else if (data.status === 'Late') {
        statusEl.textContent = 'Late Arrival';
        statusEl.style.color = '#ffcc00';
        iconEl.className = 'fas fa-exclamation-triangle';
        iconEl.style.color = '#ffcc00';
    } else {
        statusEl.textContent = 'Checked Out';
        statusEl.style.color = '#ff4757';
        iconEl.className = 'fas fa-sign-out-alt';
        iconEl.style.color = '#ff4757';
    }

    // Set Image (Fallback to generic if no image)
    imgEl.src = data.image || 'https://via.placeholder.com/150/007bff/ffffff?text=EMP';

    // Show Modal with Animation
    modal.style.display = 'flex';
    setTimeout(() => {
        modal.style.opacity = '1';
        modal.style.transform = 'scale(1)';
    }, 10);

    // Auto Hide after 4 seconds
    setTimeout(() => {
        hideCoolScanModal();
    }, 4000);

    
    // ... (existing modal content updates) ...
    document.getElementById('modalName').textContent = data.name;
    // ...

    // ✅ NEW CODE: UPDATE DASHBOARD STATUS BADGE FOR FINGERPRINT SCANS TOO
    const statusBadge = document.getElementById('currentStatusBadge');
    if (statusBadge) {
        if (data.action === 'checked_out') {
            statusBadge.style.background = 'rgba(40,167,69,0.1)';
            statusBadge.style.color = '#28a745';
            statusBadge.innerHTML = '✅ Scan Out';
        } else {
            statusBadge.style.background = 'rgba(255,193,7,0.1)';
            statusBadge.style.color = '#ffc107';
            statusBadge.innerHTML = '✅ Check In';
        }
    }

    // ... (rest of the modal logic) ...
    modal.classList.add('active');
    setTimeout(() => { modal.classList.remove('active'); }, 5000);
}

// Initialize Monitoring on Page Load
document.addEventListener('DOMContentLoaded', function() {
if(document.getElementById('adminDashboard')) {
setInterval(checkFingerprintSectionVisibility, 1000);
}
});
    // Update stats
    updateDayOffStats();
    
 // Show empty state if no results match search
    const listContainer = document.getElementById('overtimeRequestsList');
    if (visibleCount === 0 && cards.length > 0) {
        if (!document.querySelector('.filter-empty-state')) {
            const emptyState = document.createElement('div');
            emptyState.className = 'filter-empty-state';
            emptyState.innerHTML = `
            <div style="text-align: center; padding: 40px; grid-column: 1/-1;">
                <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">🔍</div>
                <div style="color: var(--text-muted);">No requests match your search</div>
            </div>`;
            listContainer.appendChild(emptyState);
        }
    } else {
        const existingEmpty = listContainer.querySelector('.filter-empty-state');
        if (existingEmpty) existingEmpty.remove();
    }
}

    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        if (status === 'Pending') pending++;
        else if (status === 'Approved') approved++;
        else if (status === 'Rejected') rejected++;
    });
    
    document.getElementById('pendingCount').textContent = pending;
    document.getElementById('approvedCount').textContent = approved;
    document.getElementById('rejectedCount').textContent = rejected;
    document.getElementById('totalCount').textContent = cards.length;


// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateDayOffStats();
});

function filterEmployeesTable(searchTerm) {
const search = searchTerm.toLowerCase();
const rows = document.querySelectorAll('#employeesTableBody tr');
rows.forEach(row => {
const name = row.getAttribute('data-name');
const eid = row.getAttribute('data-eid');
row.style.display = (name.includes(search) || eid.includes(search)) ? '' : 'none';
});
}
// ✅ FIXED: Removed duplicate function definitions
function filterLeaveAdmin(searchTerm) {
const search = searchTerm.toLowerCase();
const items = document.querySelectorAll('#leaveadministrationSection .record-item');
items.forEach(item => {
const name = item.getAttribute('data-name');
const eid = item.getAttribute('data-eid');
item.style.display = (name.includes(search) || eid.includes(search)) ? '' : 'none';
});
}
function filterAuditTrail(searchTerm) {
const search = searchTerm.toLowerCase();
const items = document.querySelectorAll('#audittrailSection .record-item');
items.forEach(item => {
const action = item.getAttribute('data-action');
const user = item.getAttribute('data-user');
const display = item.getAttribute('data-display');
item.style.display = (action.includes(search) || user.includes(search) || display.includes(search)) ? '' : 'none';
});
}
function filterAnalyticsSection(searchTerm) {
const search = searchTerm.toLowerCase();
const items = document.querySelectorAll('#analyticsSection .record-item');
items.forEach(item => {
const name = item.getAttribute('data-name');
const eid = item.getAttribute('data-eid');
item.style.display = (name.includes(search) || eid.includes(search)) ? '' : 'none';
});
}
function applyTheme(theme) {
if (theme === 'dark') {
document.documentElement.setAttribute('data-theme', 'dark');
document.cookie = 'dark_mode=dark; expires=Fri, 31 Dec 2030 23:59:59 GMT; path=/';
} else {
document.documentElement.removeAttribute('data-theme');
document.cookie = 'dark_mode=light; expires=Fri, 31 Dec 2030 23:59:59 GMT; path=/';
}
try { localStorage.setItem('helport_theme', theme); } catch(e) {}
document.querySelectorAll('button[onclick="toggleDarkMode()"] i').forEach(ic => {
ic.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
});
}
function toggleDarkMode() {
const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
applyTheme(isDark ? 'light' : 'dark');
}
document.addEventListener('DOMContentLoaded', function() {
let theme = null;
try { theme = localStorage.getItem('helport_theme'); } catch(e) { theme = null; }
if (!theme) {
const cookies = document.cookie.split(';').reduce((acc, c) => {
const [k, v] = c.split('=');
acc[(k||'').trim()] = (v||'').trim();
return acc;
}, {});
if (cookies['dark_mode']) theme = cookies['dark_mode'];
}
if (theme) applyTheme(theme === 'dark' ? 'dark' : 'light');
else {
const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
document.querySelectorAll('button[onclick="toggleDarkMode()"] i').forEach(ic => {
ic.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
});
}
});
// === ENHANCED FINGERPRINT SCAN POLLING ===
let lastScanTimestamp = 0;

function startScanPolling() {
    console.log("🚀 Scan Polling Started...");
    let lastScanTimestamp = 0;

    // 1. Get initial timestamp
    fetch('?action=get_scan_timestamp')
    .then(r => r.json())
    .then(data => {
        if(data.timestamp) lastScanTimestamp = data.timestamp;
    });

    // 2. Poll every 1 second
    setInterval(() => {
        fetch('?action=get_scan_timestamp')
        .then(r => r.json())
        .then(data => {
            // If DB timestamp is NEWER than what we have...
            if (data.timestamp > lastScanTimestamp) {
                lastScanTimestamp = data.timestamp;
                // Fetch the details (Name, Photo, etc.)
                fetch('?action=get_last_scan')
                .then(r => r.json())
                .then(scanData => {
                    if(scanData.success) {
                        showCoolScanModal(scanData); // Shows the popup
                    }
                });
            }
        })
        .catch(e => console.log('Polling error:', e));
    }, 1000);
}

// Ensure polling starts on load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('adminDashboard') || document.getElementById('employeePortal')) {
        startScanPolling();
    }
});


function openAdminModal() {
    const modal = document.getElementById('adminContactModal');
    if (modal) modal.style.display = 'flex';
}

    // Initialize and start the clock
    updateTime();  // Call immediately
    setInterval(updateTime, 1000);  // Update every second

// Close Admin Modal when clicking outside
window.onclick = function(event) {
    const adminModal = document.getElementById('adminContactModal');
    if (event.target == adminModal) {
        adminModal.style.display = "none";
    }
}
function refreshAuditTrail() {
    const dateFilter = document.getElementById('auditTrailDate') ? document.getElementById('auditTrailDate').value : '';
    let url = '?action=get_audit_trail';
    if(dateFilter) { url += `&date=${dateFilter}`; }
    fetch(url)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const container = document.getElementById('auditTrailList');
            if (!container) return;
            let html = '';
            data.logs.forEach(log => {
                const userDisplay = log.user_name ? `${log.user_name} (${log.user})` : log.user;
                html += `<div class="record-item" data-action="${log.action.toLowerCase()}" data-user="${log.user.toLowerCase()}" data-display="${userDisplay.toLowerCase()}">
                    <div class="record-info">
                        <div class="record-avatar">📋</div>
                        <div class="record-details">
                            <div class="record-name">${log.action}</div>
                            <div class="record-id">By: ${userDisplay} • ${new Date(log.timestamp).toLocaleString()}</div>
                        </div>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        }
    })
    .catch(console.error);
}

function startAuditTrailRefresh() {
    refreshAuditTrail();
    window.auditRefreshInterval = setInterval(refreshAuditTrail, 5000);
}

// === OPTIMIZED LIVE DATA POLLING ===
let statsRefreshInterval = null;

function refreshRealtimeStats() {
    fetch('?action=get_realtime_stats')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update Top Cards with animation
            animateValue('stat-present-count', data.present || 0);
            animateValue('stat-absent-count', data.absent || 0);
            animateValue('stat-late-count', data.late || 0);
            animateValue('stat-live-total', data.live_total || 0);
            
            // Update Live Breakdown
            document.getElementById('stat-live-morning').textContent = data.live_morning || 0;
            document.getElementById('stat-live-afternoon').textContent = data.live_afternoon || 0;
            document.getElementById('stat-live-night').textContent = data.live_night || 0;
            document.getElementById('stat-live-flexible').textContent = data.live_flexible || 0;
            
            // Update Summary List
            updateSummaryCounts(data.present, data.late, data.absent, data.total);
        }
    })
    .catch(console.error);
}

function animateValue(id, end) {
    const obj = document.getElementById(id);
    if (!obj) return;
    
    let start = parseInt(obj.textContent) || 0;
    const duration = 1000;
    let startTimestamp = null;
    
    const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        const value = Math.floor(progress * (end - start) + start);
        obj.innerHTML = value;
        if (progress < 1) {
            window.requestAnimationFrame(step);
        }
    };
    window.requestAnimationFrame(step);
}

function startAutoRefresh() {
    if (statsRefreshInterval) clearInterval(statsRefreshInterval);
    refreshRealtimeStats(); // Run immediately
    statsRefreshInterval = setInterval(refreshRealtimeStats, 5000); // Update every 5 seconds
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('adminDashboard')) {
        startAutoRefresh();
    }
});

function confirmAction(message) {
    return confirm(message);
}

window.addEventListener('beforeunload', function() {
    if (liveTimerInterval) {
        clearInterval(liveTimerInterval);
    }
});

function showSystemGuide() {
    document.getElementById('systemGuideModal').style.display = 'block';
}

function exportSystemData() {
    const role = '<?php echo isset($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'Employee'; ?>';
    const format = prompt(`Choose export format:\n1. JSON\n2. CSV\nEnter 1 or 2:`, '1');
    if (!format) return;
    if (format === '1') { exportToJSON(role); }
    else if (format === '2') { exportToCSV(role); }
    else { showToast('Invalid option', 'error'); }
}

function exportToJSON(role) {
    const data = {
        system: 'HELPORT Attendance System',
        version: '2.0',
        exportDate: new Date().toISOString(),
        user: {
            name: '<?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?>',
            id: '<?php echo isset($_SESSION['employee_id']) ? htmlspecialchars($_SESSION['employee_id']) : ''; ?>',
            role: role
        },
        features: {
            fingerprint: true,
            realTimeTracking: true,
            autoPayroll: true,
            nightDifferential: true,
            aiAssistant: true
        }
    };
    const json = JSON.stringify(data, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `helport-export-${new Date().toISOString().split('T')[0]}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    showToast('Data exported successfully!', 'success');
}

function exportToCSV(role) {
    const userName = '<?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?>';
    const employeeId = '<?php echo isset($_SESSION['employee_id']) ? htmlspecialchars($_SESSION['employee_id']) : ''; ?>';
    const exportDate = new Date().toLocaleString();
    showToast('Preparing export...', 'info');
    fetch('?action=get_export_data&type=attendance')
    .then(response => response.json())
    .then(data => {
        const attendanceRecords = data.success ? data.data : [];
        const csvRows = [
            ['HELPORT Attendance System Export'],
            ['Export Date', exportDate],
            ['User Name', userName],
            ['Employee ID', employeeId],
            ['Role', role],
            [''],
            ['Attendance Records'],
            ['Employee ID', 'Name', 'Department', 'Check In', 'Check Out', 'Regular Hours', 'Overtime Hours', 'Night Diff Hours', 'Status']
        ];
        if (attendanceRecords.length > 0) {
            attendanceRecords.forEach(record => {
                csvRows.push([
                    record.employee_id || '',
                    record.name || '',
                    record.department || '',
                    record.check_in || '',
                    record.check_out || '',
                    record.regular_hours || '0',
                    record.overtime_hours || '0',
                    record.night_diff_hours || '0',
                    record.status || 'Active'
                ]);
            });
        } else {
            csvRows.push(['', '', '', '', '', '', '', '', 'No attendance records found']);
        }
        csvRows.push(['']);
        csvRows.push(['Summary']);
        csvRows.push(['Total Records', attendanceRecords.length]);
        csvRows.push(['Exported By', userName]);
        const csv = csvRows.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `helport-export-${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('Data exported successfully!', 'success');
    })
    .catch(error => {
        console.error('Export error:', error);
        const fallbackCsv = [
            ['HELPORT Attendance System Export'],
            ['Export Date', exportDate],
            ['User Name', userName],
            ['Employee ID', employeeId],
            ['Role', role],
            [''],
            ['Note'],
            ['Live data unavailable - showing basic info only']
        ].map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([fallbackCsv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `helport-export-basic-${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('Export completed (basic data)', 'warning');
    });
}









document.addEventListener('keydown', function(event) {
    if (event.ctrlKey && event.shiftKey && event.key === 'G') {
        event.preventDefault();
        showSystemGuide();
    }
    if (event.ctrlKey && event.shiftKey && event.key === 'E') {
        event.preventDefault();
        exportSystemData();
    }
});


// Start the polling immediately when page loads
if (document.getElementById('adminDashboard') || document.getElementById('employeePortal')) { 
    startScanPolling(); 
}

//  LOGIN FORM JAVASCRIPT
document.addEventListener('DOMContentLoaded', function() {
const loginForm = document.getElementById('loginForm');
if(loginForm) {
loginForm.addEventListener('submit', function(e) {
e.preventDefault();
e.stopPropagation();
const formData = new FormData(this);
formData.append('is_ajax', '1');
const errorDiv = document.getElementById('loginError');
errorDiv.style.display = 'none';
errorDiv.textContent = 'Logging in...';
errorDiv.style.display = 'block';
errorDiv.style.background = 'rgba(25, 211, 197, 0.1)';
errorDiv.style.color = 'var(--primary-accent)';
errorDiv.style.borderLeft = '3px solid var(--primary-accent)';
fetch('', {
method: 'POST',
body: formData,
headers: {
'X-Requested-With': 'XMLHttpRequest'
}
})
.then(response => response.json())
.then(data => {
if(data.success) {
errorDiv.textContent = '✓ Login successful! Redirecting...';
errorDiv.style.background = 'rgba(40, 167, 69, 0.1)';
errorDiv.style.color = '#28a745';
errorDiv.style.borderLeft = '3px solid #28a745';
setTimeout(() => {
window.location.href = data.redirect || window.location.pathname;
}, 800);
} else {
errorDiv.textContent = '✗ ' + (data.error || 'Login failed');
errorDiv.style.display = 'block';
errorDiv.style.background = 'rgba(255, 107, 107, 0.1)';
errorDiv.style.color = '#ff6b6b';
errorDiv.style.borderLeft = '3px solid #ff6b6b';
}
})
.catch(err => {
errorDiv.textContent = '✗ Network error: ' + err.message;
errorDiv.style.display = 'block';
errorDiv.style.background = 'rgba(255, 107, 107, 0.1)';
errorDiv.style.color = '#ff6b6b';
errorDiv.style.borderLeft = '3px solid #ff6b6b';
});
return false;
});
}
});

function loadDemo(role) {
document.getElementById('employeeId').value = role === 'Admin' ? 'ADMIN001' : '202500260';
document.getElementById('password').value = 'password123';
document.getElementById('role').value = role;
// Add visual feedback
const errorDiv = document.getElementById('loginError');
errorDiv.style.display = 'block';
errorDiv.textContent = '✓ Demo credentials loaded for ' + role;
errorDiv.style.background = 'rgba(25, 211, 197, 0.1)';
errorDiv.style.color = 'var(--primary-accent)';
errorDiv.style.borderLeft = '3px solid var(--primary-accent)';
setTimeout(() => {
errorDiv.style.display = 'none';
}, 2000);
}

function showLoginModal() {
const modal = document.getElementById('loginModal');
modal.style.display = 'flex';
setTimeout(() => {
modal.querySelector('.login-box').classList.add('active');
}, 10);
}

// Add this function to handle the new dropdown
function toggleSettingsDropdown() {
    const dropdown = document.getElementById('settingsDropdown');
    const isVisible = dropdown.style.display === 'block';
    
    // Close other dropdowns if open
    document.querySelectorAll('.notification-dropdown').forEach(d => d.style.display = 'none');
    document.getElementById('employeeDropdown').style.display = 'none';

    if (isVisible) {
        dropdown.style.display = 'none';
        document.body.style.overflow = '';
    } else {
        dropdown.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}


// Add this helper to ensure dark mode toggles correctly from the menu
function toggleDarkModeFromSettings() {
    toggleDarkMode();
    // Optional: Show a toast notification
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    showToast(isDark ? 'Dark Mode Enabled' : 'Light Mode Enabled', 'info');
}

// === PHOTO PREVIEW FUNCTION ===
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('empPhotoPreview');
            preview.src = e.target.result;
            preview.style.transform = 'scale(0.95)';
            setTimeout(() => {
                preview.style.transform = 'scale(1)';
            }, 150);
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// === REMOVE PHOTO FUNCTION ===
function removePhoto() {
    const preview = document.getElementById('empPhotoPreview');
    const input = document.getElementById('empPhotoInput');
    
    if (preview) {
        // Fade out animation
        preview.style.opacity = '0';
        preview.style.transform = 'scale(0.8)';
        
        setTimeout(() => {
preview.src = 'https://ui-avatars.com/api/?name=New+Employee&background=19D3C5&color=00110f&size=128';            preview.style.opacity = '1';
            preview.style.transform = 'scale(1)';
        }, 200);
    }
    
    if (input) {
        input.value = ''; // Clear the file input
    }
}

// === UPDATE OPEN EDIT MODAL TO SHOW EXISTING PHOTO ===
function openEditEmployeeModal(id, name, eid, position, dept, role, birthday, fingerId, photoPath) {
    document.getElementById('modalTitle').textContent = 'Edit Employee';
    document.getElementById('employeeIdInput').value = id;
    document.getElementById('empName').value = name;
    document.getElementById('empEid').value = eid;
    document.getElementById('empPosition').value = position;
    document.getElementById('empDept').value = dept;
    document.getElementById('empRole').value = role;
    document.getElementById('empPassword').value = '';
    document.getElementById('empBirthday').value = birthday || '';
    document.getElementById('empFingerId').value = fingerId || '';
    
    // LOAD PHOTO - IMPROVED
    const preview = document.getElementById('empPhotoPreview');
    if (preview) {
        if (photoPath && photoPath !== '' && photoPath !== './images/default-avatar.png') {
            // Add timestamp to prevent caching
            preview.src = photoPath + '?t=' + new Date().getTime();
        } else {
preview.src = 'https://ui-avatars.com/api/?name=New+Employee&background=19D3C5&color=00110f&size=128';        }
    }
    
    document.getElementById('employeeModal').style.display = 'block';
}

// === SETTINGS MODAL FUNCTIONS ===
function openSettingsModal() {
    document.getElementById('settingsModal').style.display = 'block';
    const currentTheme = localStorage.getItem('helport_theme') || 'dark';
    if (currentTheme === 'light') {
        document.getElementById('lightThemeBtn')?.classList.add('active');
        document.getElementById('darkThemeBtn')?.classList.remove('active');
    } else {
        document.getElementById('darkThemeBtn')?.classList.add('active');
        document.getElementById('lightThemeBtn')?.classList.remove('active');
    }
}

function closeSettingsModal() {
    document.getElementById('settingsModal').style.display = 'none';
    document.getElementById('passwordForm')?.reset();
}



function displayFPEmployeeTable(employees) {
    const tbody = document.getElementById('fingerprintTableBody');
    if (!tbody) return;
    if (employees.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No employees found.</td></tr>';
        return;
    }
    tbody.innerHTML = employees.map(emp => `
        <tr data-name="${emp.name.toLowerCase()}" data-eid="${emp.employee_id.toLowerCase()}">
            <td>${emp.photo_path ? `<img src="${emp.photo_path}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">` : '<div style="width: 50px; height: 50px; border-radius: 50%; background: var(--primary-accent); display: flex; align-items: center; justify-content: center;"><i class="fas fa-user"></i></div>'}</td>
            <td><strong>${emp.name}</strong></td>
            <td>${emp.employee_id}</td>
            <td>${emp.department || '-'}</td>
            <td>${emp.finger_id ? `<span style="background: var(--primary-accent); color: #00110f; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 12px;">${emp.finger_id}</span>` : '<span style="color: var(--text-muted);">Not set</span>'}</td>
            <td><div class="action-btn-group"><button class="action-icon-btn delete" onclick="removeFingerprintEnrollment(${emp.id}, '${emp.name.replace(/'/g, "\\'")}')"><i class="fas fa-trash"></i></button></div></td>
        </tr>
    `).join('');
}

function displayFingerprintScans(scans) {
    const container = document.getElementById('fingerprintScansList');
    if (!container) return;
    if (scans.length === 0) {
        container.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--text-muted);">No fingerprint scans recorded yet</div>';
        return;
    }
    container.innerHTML = scans.map(scan => `
        <div class="record-item">
            <div class="record-info">
                <div class="record-icon present"><i class="fas fa-fingerprint"></i></div>
                <div class="record-details">
                    <div class="record-name">${scan.name}</div>
                    <div class="record-id">Finger ID: ${scan.finger_id || 'N/A'} • ${scan.employee_id}</div>
                </div>
            </div>
            <div class="record-time">
                <div>${new Date(scan.check_in).toLocaleDateString()}</div>
                <div>${new Date(scan.check_in).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true })}</div>
            </div>
        </div>
    `).join('');
}

function filterFingerprintTable(searchTerm) {
    const search = searchTerm.toLowerCase();
    const rows = document.querySelectorAll('#fingerprintTableBody tr[data-name]');
    rows.forEach(row => {
        const name = row.getAttribute('data-name');
        const eid = row.getAttribute('data-eid');
        row.style.display = (name.includes(search) || eid.includes(search)) ? '' : 'none';
    });
}

function removeFingerprintEnrollment(employeeId, employeeName) {
    if (confirm(`Remove fingerprint enrollment for ${employeeName}?`)) {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=remove_fingerprint&employee_id=${employeeId}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Fingerprint enrollment removed', 'success');
                loadFingerprintData();
            } else {
                showToast('Failed to remove enrollment', 'error');
            }
        });
    }
}
// === ADMIN: Toggle Manual Check-in ===
function toggleManualCheckin() {
    const btn = document.getElementById('toggleManualBtn');
    
    // 1. Identify current state by checking the button text or icon
    // We look at the main icon above the text to determine state
    const mainIcon = document.querySelector('#manualcheckinSection .fa-toggle-on, #manualcheckinSection .fa-toggle-off');
    const statusText = document.querySelector('#manualcheckinSection h3');
    
    // Determine if currently enabled based on icon class
    const isCurrentlyEnabled = mainIcon.classList.contains('fa-toggle-on');
    
    // 2. Optimistic UI Update (Change UI immediately before server responds)
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> Updating...';
    
    if (isCurrentlyEnabled) {
        // Switching to DISABLED
        mainIcon.className = 'fas fa-toggle-off';
        mainIcon.style.color = 'var(--text-muted)'; // Optional: Dim the icon
        statusText.innerHTML = 'Manual Attendance is currently <span style="color:#ef4444">DISABLED</span>';
        btn.dataset.nextAction = '0'; // Store intended state
    } else {
        // Switching to ENABLED
        mainIcon.className = 'fas fa-toggle-on';
        mainIcon.style.color = 'var(--primary-accent)';
        statusText.innerHTML = 'Manual Attendance is currently <span style="color:#28a745">ENABLED</span>';
        btn.dataset.nextAction = '1'; // Store intended state
    }

    // 3. Send Request to Server
    const formData = new FormData();
    formData.append('action', 'toggle_manual_checkin');
    formData.append('status', btn.dataset.nextAction);

    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        // Update button text to reflect the NEW state (which allows toggling back)
        if (btn.dataset.nextAction === '1') {
            btn.textContent = 'Disable Feature';
        } else {
            btn.textContent = 'Enable Feature';
        }
        
        if(data.success) {
            showToast('Setting updated successfully', 'success');
        } else {
            // If server fails, revert the UI
            showToast('Failed to update setting', 'error');
            // Revert Icon
            if (btn.dataset.nextAction === '1') {
                mainIcon.className = 'fas fa-toggle-off';
                statusText.innerHTML = 'Manual Attendance is currently <span style="color:#ef4444">DISABLED</span>';
                btn.textContent = 'Enable Feature';
            } else {
                mainIcon.className = 'fas fa-toggle-on';
                statusText.innerHTML = 'Manual Attendance is currently <span style="color:#28a745">ENABLED</span>';
                btn.textContent = 'Disable Feature';
            }
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = isCurrentlyEnabled ? 'Disable Feature' : 'Enable Feature';
        showToast('Network error: ' + err.message, 'error');
        // Revert Icon on error
        if (isCurrentlyEnabled) {
             mainIcon.className = 'fas fa-toggle-on';
             statusText.innerHTML = 'Manual Attendance is currently <span style="color:#28a745">ENABLED</span>';
        } else {
             mainIcon.className = 'fas fa-toggle-off';
             statusText.innerHTML = 'Manual Attendance is currently <span style="color:#ef4444">DISABLED</span>';
        }
    });
}
function exportAttendanceData() {
    // Get current filters from the UI
    const dateInput = document.getElementById('attendanceDateFilter');
    const searchInput = document.getElementById('attendanceSearchInput');
    
    const date = dateInput ? dateInput.value : new Date().toISOString().split('T')[0];
    const search = searchInput ? searchInput.value : '';

    showToast('Fetching live data...', 'info');

    // 1. Fetch FRESH data from the server based on current filters
    let apiUrl = `?action=get_export_data&type=attendance&start_date=${date}&end_date=${date}`;
    if(search) {
        apiUrl += `&search=${encodeURIComponent(search)}`;
    }

    fetch(apiUrl)
    .then(response => response.json())
    .then(data => {
        if (!data.success || !data.data || data.data.length === 0) {
            showToast('No data found for this selection.', 'warning');
            return;
        }

        // 2. Build the CSV Content
        // Header Row
        let csvContent = "Employee ID,Name,Department,Check In,Check Out,Regular Hours,Overtime,Night Diff,Status\n";

        // Data Rows
        data.data.forEach(row => {


        // We will use a safe string format
            const checkInStr = row.check_in ? new Date(row.check_in).toLocaleString('en-US', { 
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: 'numeric', minute: '2-digit', second: '2-digit',
    hour12: true 
}) : '';
const checkOutStr = row.check_out ? new Date(row.check_out).toLocaleString('en-US', { 
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: 'numeric', minute: '2-digit', second: '2-digit',
    hour12: true 
}) : '';

            // Escape commas in text fields to prevent CSV breaking
            const safeName = `"${row.name.replace(/"/g, '""')}"`;
            const safeDept = `"${row.department.replace(/"/g, '""')}"`;
            const safeStatus = `"${row.status}"`;

            csvContent += `${row.employee_id},${safeName},${safeDept},${checkInStr},${checkOutStr},${row.regular_hours},${row.overtime_hours},${row.night_diff_hours},${safeStatus}\n`;
        });

        // 3. Create and Download the File
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.setAttribute("href", url);
        
        // Generate filename with date
        const fileName = `Attendance_Report_${date}${search ? '_'+search.replace(/[^a-z0-9]/gi, '_') : ''}.csv`;
        link.setAttribute("download", fileName);
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showToast('Report downloaded successfully!', 'success');
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to generate report.', 'error');
    });
}







// === EMPLOYEE: Submit Manual Attendance ===
function submitManualAttendance(type) {
    const btn = type === 'IN' ? document.getElementById('manualCheckInBtn') : document.getElementById('manualCheckOutBtn');
    const msg = document.getElementById('manualMsg');
    if(!btn || btn.disabled) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span>';
    msg.textContent = 'Processing...';
    msg.style.color = 'var(--text-muted)';
    
    const formData = new FormData();
    formData.append('action', 'manual_attendance');
    formData.append('type', type);
    // ADD CSRF TOKEN
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || 
                      document.querySelector('meta[name="csrf-token"]')?.content;
    if(csrfToken) {
        formData.append('csrf_token', csrfToken);
    }
    
    fetch('', { 
        method: 'POST', 
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(r => {
        // Check if response is OK
        if (!r.ok) {
            throw new Error(`HTTP ${r.status}: ${r.statusText}`);
        }
        return r.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        if(data.success) {
            msg.textContent = `✓ ${data.message} at ${data.time}`;
            msg.style.color = '#28a745';
            
            // Update UI
            const statusBadge = document.getElementById('currentStatusBadge');
            if (statusBadge) {
                if (type === 'IN') {
                    statusBadge.style.background = 'rgba(255,193,7,0.1)';
                    statusBadge.style.color = '#ffc107';
                    statusBadge.innerHTML = '✅ Check In';
                    startLiveTimer(new Date().toISOString());
                } else {
                    statusBadge.style.background = 'rgba(40,167,69,0.1)';
                    statusBadge.style.color = '#28a745';
                    statusBadge.innerHTML = '✅ Scan Out';
                    stopLiveTimer();
                }
            }
            
            // Disable/enable buttons
            const checkInBtn = document.getElementById('manualCheckInBtn');
            const checkOutBtn = document.getElementById('manualCheckOutBtn');
            if (type === 'IN') {
                checkInBtn.disabled = true; 
                checkInBtn.style.opacity = '0.5';
                checkOutBtn.disabled = false; 
                checkOutBtn.style.opacity = '1';
            } else {
                checkOutBtn.disabled = true; 
                checkOutBtn.style.opacity = '0.5';
                checkInBtn.disabled = false; 
                checkInBtn.style.opacity = '1';
            }
            
            setTimeout(() => location.reload(), 2000);
        } else {
            msg.textContent = 'Error: ' + (data.message || 'Unknown error');
            msg.style.color = '#dc3545';
        }
    })
    .catch(err => {
        console.error('Manual attendance error:', err);
        btn.disabled = false;
        btn.innerHTML = originalText;
        // Show detailed error
        msg.textContent = 'Error: ' + err.message;
        msg.style.color = '#dc3545';
    });
}

// === LEAVE MODULE: Filter Function (ADMIN) ===
function filterLeaveRequests() {
    // 1. Get the selected value from the dropdown
    const statusFilter = document.getElementById('leaveStatusFilter').value;
    
    // 2. Get the search term
    const searchInput = document.getElementById('leaveAdminSearch');
    const search = searchInput ? searchInput.value.toLowerCase() : '';
    
    // 3. Select all leave cards
    const cards = document.querySelectorAll('#leaveRequestsList .leave-request-card');
    let visibleCount = 0;
    
    cards.forEach(card => {
        // Get card data
        const cardStatus = card.getAttribute('data-status'); // e.g., 'pending', 'approved'
        const cardName = card.getAttribute('data-name') || '';
        const cardEid = card.getAttribute('data-eid') || '';
        const cardText = card.textContent.toLowerCase();
        
        // 4. Determine if card should be visible
        let show = true;
        
        // Check Status Filter (only if not "All Requests")
        if (statusFilter !== 'All Requests') {
            // Convert filter to lowercase to match data-status
            if (cardStatus !== statusFilter.toLowerCase()) {
                show = false;
            }
        }
        
        // Check Search Filter
        if (search && !cardText.includes(search)) {
            show = false;
        }
        
        // 5. Apply visibility with animation
        if (show) {
            card.style.display = 'flex';
            card.style.animation = 'slideIn 0.3s ease';
            visibleCount++;
        } else {
            card.style.display = 'none';
            card.style.animation = 'none';
        }
    });
    
    // Update counters
    updateLeaveCounters();
    
    // Show/hide empty state
    const listContainer = document.getElementById('leaveRequestsList');
    if (visibleCount === 0 && cards.length > 0) {
        if (!document.querySelector('.filter-empty-state')) {
            const emptyState = document.createElement('div');
            emptyState.className = 'filter-empty-state';
            emptyState.innerHTML = `
                <div style="text-align: center; padding: 40px; grid-column: 1/-1;">
                    <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">🔍</div>
                    <div style="color: var(--text-muted);">No requests match your filters</div>
                </div>
            `;
            listContainer.appendChild(emptyState);
        }
    } else {
        const existingEmpty = listContainer.querySelector('.filter-empty-state');
        if (existingEmpty) existingEmpty.remove();
    }
}

// === LEAVE MODULE: Update Counters ===
function updateLeaveCounters() {
    const cards = document.querySelectorAll('#leaveRequestsList .leave-request-card');
    let pending = 0, approved = 0, rejected = 0;
    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        const display = card.style.display;
        
        // Only count visible cards
        if (display !== 'none') {
            if (status === 'pending') pending++;
            else if (status === 'approved') approved++;
            else if (status === 'rejected') rejected++;
        }
    });
    
    // Update the stat cards
    const pendingEl = document.getElementById('count-pending');
    const approvedEl = document.getElementById('count-approved');
    const rejectedEl = document.getElementById('count-rejected');
    
    if(pendingEl) pendingEl.textContent = pending;
    if(approvedEl) approvedEl.textContent = approved;
    if(rejectedEl) rejectedEl.textContent = rejected;
}

// === LEAVE MODULE: Refresh Function ===
function refreshLeaveRequests() {
    const btn = document.querySelector('button[onclick="refreshLeaveRequests()"] i');
    if(btn) btn.classList.add('fa-spin');
    
    showToast('Refreshing leave requests...', 'info');
    
    // Reset to All Requests
    document.getElementById('leaveStatusFilter').value = 'All Requests';
    document.getElementById('leaveAdminSearch').value = '';
    
    // Reload the page data
    fetch('?action=get_day_off_requests_admin&status=All Requests')
    .then(r => r.json())
    .then(data => {
        if(btn) btn.classList.remove('fa-spin');
        if(data.success) {
            showToast('Leave requests refreshed', 'success');
            // The page will auto-update via the existing PHP rendering
            setTimeout(() => location.reload(), 500);
        }
    })
    .catch(e => {
        if(btn) btn.classList.remove('fa-spin');
        showToast('Refresh error: ' + e.message, 'error');
    });
}

// === LEAVE MODULE: Initialize on Load ===
document.addEventListener('DOMContentLoaded', function() {
    // Set default to All Requests when module loads
    const statusFilter = document.getElementById('leaveStatusFilter');
    if(statusFilter) {
        statusFilter.value = 'All Requests';
    }
    
    // Apply filter immediately
    if(document.getElementById('leaveRequestsList')) {
        setTimeout(() => {
            filterLeaveRequests();
        }, 100);
    }
});

// === LEAVE MODULE: Update Status (AJAX) ===
function updateLeaveStatus(id, newStatus) {
    if(!confirm(`Are you sure you want to ${newStatus} this leave request?`)) return;
    
    const formData = new FormData();
    formData.append('action', 'update_leave_status');
    formData.append('id', id);
    formData.append('status', newStatus);
    
    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            showToast(`Leave request ${newStatus} successfully!`, 'success');
            // Update the card UI immediately
            const card = document.querySelector(`.leave-request-card[data-id="${id}"], .modern-request-card[data-id="${id}"]`);
            if(card) {
                card.style.opacity = '0';
                card.style.transform = 'translateX(-20px)';
                card.style.transition = 'all 0.3s ease';
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateX(0)';
                }, 300);
            }
            // Refresh the list
            setTimeout(() => filterLeaveRequests(), 500);
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    })
    .catch(err => {
        showToast('Network error: ' + err.message, 'error');
    });
}



// === LEAVE MODULE: Export ===
function exportLeaveData() {
    window.location.href = '?action=get_export_data&type=leave&start_date=2020-01-01&end_date=2030-12-31';
    showToast('Downloading leave report...', 'success');
}



// ✅ LOAD DEPARTMENT WHEN TAB IS SHOWN
window.switchTab = function(tabName) {
    // Hide all sections
    document.querySelectorAll('.admin-section').forEach(s => s.style.display = 'none');
    
    // Show target section
    const target = document.getElementById(tabName + 'Section');
    if (target) {
        target.style.display = 'block';
        
        // Load data for specific tabs with proper timing
        setTimeout(() => {
            if(tabName === 'analytics') {
                updateAnalytics();
            }
            if(tabName === 'departments') {
                loadDepartmentsData();
            }
            if(tabName === 'audittrail') {
                startAuditTrailRefresh();
            }
            if(tabName === 'attendance') {
                startAttendanceHistoryRefresh();
            }
        }, 300);
    }
    
    // Update nav tabs
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    
    // Update URL
    const newUrl = window.location.protocol + "//" + window.location.host +
    window.location.pathname + '?view=' + tabName;
    window.history.pushState({path:newUrl},'',newUrl);
};
// Auto-refresh departments every 30 seconds when visible
setInterval(() => {
    const deptSection = document.getElementById('departmentsSection');
    if(deptSection && deptSection.style.display !== 'none') {
        loadDepartmentsData();
    }
}, 30000);
        
        // ✅ LOAD ANALYTICS WHEN TAB IS SHOWN
        if(tabName === 'analytics') {
            setTimeout(() => {
                updateAnalytics();
            }, 300);
        }
    
    
    // Update nav tabs active state
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));

// === ATTENDANCE MODULE: Search Filter ===
function filterAttendanceRecords(searchTerm) {
    const search = searchTerm.toLowerCase();
    const records = document.querySelectorAll('.modern-attendance-card');
    records.forEach(record => {
        const name = record.querySelector('.emp-details h5')?.textContent.toLowerCase() || '';
        const eid = record.querySelector('.emp-details span')?.textContent.toLowerCase() || '';
        // Show if name OR employee ID matches
        record.style.display = (name.includes(search) || eid.includes(search)) ? 'flex' : 'none';
    });
}

function filterAttendanceByDate(date) {
    // Reload page with the new date parameter while keeping the view on 'attendance'
    window.location.href = `?view=attendance&attendance_date=${date}`;
}

function refreshAttendanceData() {
    // Simple reload to refresh data from server
    location.reload();
}




















// === ANALYTICS FUNCTIONS (FIXED) ===
let attendanceTrendChart = null;
let departmentChart = null;

function initAnalytics() {
    // Remove loading spinners immediately
    clearAnalyticsLoaders();
    updateAnalytics();
    // Auto-refresh every 30 seconds
    if (window.analyticsInterval) clearInterval(window.analyticsInterval);
    window.analyticsInterval = setInterval(() => {
        if(document.getElementById('analyticsSection').style.display !== 'none') {
            updateAnalytics();
        }
    }, 30000);
}

function clearAnalyticsLoaders() {
    // Clear specific loading states
    const loaders = document.querySelectorAll('#topPerformersList .fa-spinner, #attendancePatterns .fa-spinner');
    loaders.forEach(el => {
        const parent = el.closest('div[style*="text-align: center"]');
        if(parent) parent.innerHTML = '<div style="color:var(--text-muted); font-size:13px;">Waiting for data...</div>';
    });
}


function updateLiveStats(stats) {
    const activeNowEl = document.getElementById('stat-active-now');
    const attendanceRateEl = document.getElementById('stat-attendance-rate');
    const avgHoursEl = document.getElementById('stat-avg-hours');
    const lateCountEl = document.getElementById('stat-late-count');

    if(activeNowEl) activeNowEl.textContent = stats.active_now || 0;
    if(attendanceRateEl) attendanceRateEl.textContent = (stats.attendance_rate || 0) + '%';
    if(avgHoursEl) avgHoursEl.textContent = (stats.avg_hours || 0) + 'h';
    if(lateCountEl) lateCountEl.textContent = stats.late_count || 0;

    const changeEl = document.getElementById('stat-attendance-change');
    if(changeEl) {
        const change = stats.attendance_change || 0;
        changeEl.textContent = `${change >= 0 ? '+' : ''}${change}% from last period`;
        changeEl.style.color = change >= 0 ? '#4ade80' : '#f87171';
    }
    
    const latePctEl = document.getElementById('stat-late-percentage');
    if(latePctEl) latePctEl.textContent = `${stats.late_percentage || 0}% of total`;
}

















function updateAttendanceTrendChart(data) {
    const ctx = document.getElementById('attendanceTrendChart');
    if (!ctx) {
        console.error('Canvas attendanceTrendChart not found!');
        return;
    }

    // Destroy existing chart to prevent overlay glitches
    if (window.attendanceTrendChart) {
        try {
            window.attendanceTrendChart.destroy();
        } catch(e) {
            console.log('Chart destroy error:', e);
        }
    }

    const labels = data.labels || [];
    const presentData = data.present || [];
    const absentData = data.absent || [];

    // Ensure we have data (fallback if empty)
    if (labels.length === 0) {
        for(let i = 0; i < 7; i++) {
            labels.push('Day ' + (i + 1));
            presentData.push(0);
            absentData.push(0);
        }
    }

    try {
        window.attendanceTrendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Present',
                        data: presentData,
                        borderColor: '#19D3C5',
                        backgroundColor: 'rgba(25, 211, 197, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Absent',
                        data: absentData,
                        borderColor: '#f5576c',
                        backgroundColor: 'rgba(245, 87, 108, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        labels: { 
                            color: '#9AA4AF',
                            font: { size: 12 }
                        } 
                    }
                },
                scales: {
                    y: { 
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#9AA4AF' },
                        beginAtZero: true 
                    },
                    x: { 
                        grid: { display: false },
                        ticks: { color: '#9AA4AF' }
                    }
                }
            }
        });
    } catch (e) {
        console.error('Chart initialization error:', e);
        document.getElementById('attendanceTrendChart').parentNode.innerHTML = 
            '<div style="text-align:center; padding:40px; color:var(--text-muted);">Chart failed to load</div>';
    }
}


function updateTopPerformers(performers) {
    const container = document.getElementById('topPerformersList');
    if (!container) return;

    container.innerHTML = ''; // Clear spinner

    if (!performers || !performers.success || !performers.data || performers.data.length === 0) {
        container.innerHTML = '<div style="text-align:center; color:var(--text-muted); padding:20px;">No data available</div>';
        return;
    }

    const top5 = performers.data.slice(0, 5);
    const medals = ['🥇', '🥈', '🥉', '🏅', '🏅'];

    let html = '';
    top5.forEach((emp, index) => {
        const rate = emp.attendance_rate || 0;
        const days = emp.days_present || 0;
        
        html += `
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: rgba(255,255,255,0.02); border-radius: 8px; margin-bottom: 10px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 20px;">${medals[index] || '•'}</div>
                <div>
                    <div style="font-weight: 600; color: var(--text-primary);">${emp.name}</div>
                    <div style="font-size: 11px; color: var(--text-muted);">${emp.department || 'N/A'}</div>
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-weight: 700; color: var(--primary-accent);">${rate}%</div>
                <div style="font-size: 10px; color: var(--text-muted);">${days} days</div>
            </div>
        </div>`;
    });
    container.innerHTML = html;
}

function updateAttendancePatterns(stats) {
    const container = document.getElementById('attendancePatterns');
    if(!container) return;
    // Fallback data if stats are empty
    const patterns = [
        { label: 'Early Birds (Before 8 AM)', value: stats.early_birds || 0, color: '#4ade80' },
        { label: 'On Time (8-9 AM)', value: stats.on_time || 0, color: '#667eea' },
        { label: 'Late (After 9 AM)', value: stats.late_arrivals || 0, color: '#f5576c' },
        { label: 'Overtime Workers', value: stats.overtime_workers || 0, color: '#fbbf24' }
    ];
    container.innerHTML = patterns.map(pattern => `
    <div style="display: flex; align-items: center; gap: 12px;">
        <div style="width: 12px; height: 12px; background: ${pattern.color}; border-radius: 3px;"></div>
        <div style="flex: 1; font-size: 13px; color: var(--text-secondary);">${pattern.label}</div>
        <div style="font-weight: 600; color: var(--text-primary);">${pattern.value}</div>
    </div>
    `).join('');
}
function updateWeeklyBreakdown() {
    const container = document.getElementById('weeklyBreakdown');
    if (!container) return;

    container.innerHTML = '<div style="text-align:center; width:100%; padding:20px;"><i class="fas fa-spinner fa-spin"></i></div>';

    fetch('?action=get_weekly_breakdown')
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error('API failed');
        
        const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        const weeklyData = data.data || [0,0,0,0,0,0,0];
        const maxVal = Math.max(...weeklyData, 1);

        container.innerHTML = days.map((day, index) => {
            const heightPercent = (weeklyData[index] / maxVal) * 100;
            const color = weeklyData[index] >= 80 ? 'var(--primary-accent)' : (weeklyData[index] >= 50 ? '#fbbf24' : '#f5576c');
            
            return `
            <div style="text-align: center; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 8px;">
                <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px;">${day}</div>
                <div style="height: 80px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 8px;">
                    <div style="width: 40px; height: ${heightPercent}%; background: linear-gradient(to top, ${color}, rgba(25,211,197,0.3)); border-radius: 4px;"></div>
                </div>
                <div style="font-weight: 700; color: var(--text-primary); font-size: 14px;">${weeklyData[index]}%</div>
            </div>`;
        }).join('');
    })
    .catch(e => {
        console.error('Weekly breakdown error:', e);
        container.innerHTML = '<div style="text-align:center; color:#f5576c; padding:20px;">Failed to load</div>';
    });
}



function updateDetailedMetricsTable() {
    const tbody = document.getElementById('analyticsTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin"></i></td></tr>';

    fetch('?action=get_detailed_metrics')
    .then(r => r.json())
    .then(res => {
        if (res.success && res.data && res.data.length > 0) {
            let html = '';
            res.data.forEach(row => {
                const rate = Math.min(100, Math.round((row.days_present / 22) * 100));
                const statusColor = row.status === 'active' ? '#28a745' : '#dc3545';
                
                html += `
                <tr style="border-bottom:1px solid var(--border-color);">
                    <td style="padding:12px; font-weight:600; color:#fff;">${row.name || 'N/A'}</td>
                    <td style="padding:12px; color:var(--text-muted);">${row.department || 'N/A'}</td>
                    <td style="padding:12px;">${row.days_present || 0}</td>
                    <td style="padding:12px;">${row.avg_hours || 0}h</td>
                    <td style="padding:12px; color:${row.late_arrivals > 0 ? '#ffc107' : '#28a745'}">${row.late_arrivals || 0}</td>
                    <td style="padding:12px;"><span style="background:${statusColor}20; color:${statusColor}; padding:4px 8px; border-radius:4px; font-size:12px;">${rate}%</span></td>
                    <td style="padding:12px;"><span style="color:${statusColor};">● ${row.status}</span></td>
                </tr>`;
            });
            tbody.innerHTML = html;
        } else {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-muted);">No data</td></tr>';
        }
    })
    .catch(e => {
        console.error('Metrics error:', e);
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:#f5576c;">Failed to load</td></tr>';
    });
}

function exportAnalyticsData() {
    showToast('Preparing export...', 'info');

    fetch('?action=get_detailed_metrics')
    .then(r => r.json())
    .then(res => {
        if (!res.success || !res.data || res.data.length === 0) {
            showToast('No data to export', 'warning');
            return;
        }

        let csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "Employee Name,Employee ID,Department,Days Present,Avg Hours,Late Arrivals,Status\n";

        res.data.forEach(row => {
            csvContent += `"${row.name || ''}","${row.employee_id || ''}","${row.department || ''}",${row.days_present || 0},${row.avg_hours || 0},${row.late_arrivals || 0},"${row.status || ''}"\n`;
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `helport_analytics_${new Date().toISOString().split('T')[0]}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        showToast('Report downloaded!', 'success');
    })
    .catch(e => {
        console.error(e);
        showToast('Export failed', 'error');
    });
}

// Initialize analytics when section is shown
const originalSwitchTabAnalytics = window.switchTab || function() {};
window.switchTab = function(tabName) {
    originalSwitchTabAnalytics(tabName);
    if (tabName === 'analytics') {
        setTimeout(() => { updateAnalytics(); }, 300);
    }
};

// Close modal when clicking outside
window.onclick = function(event) {
    const overtimeModal = document.getElementById('overtimeApprovalModal');
    if(event.target == overtimeModal) {
        closeOvertimeModal();
    }
}

async function updateAnalytics() {
    const timeRange = document.getElementById('analyticsTimeRange')?.value || 'month';

    try {
        // 1. Fetch Stats (Active Now, Rates, etc.)
        const statsRes = await fetch(`?action=get_analytics_stats&range=${timeRange}`);
        const stats = await statsRes.json();
        if (stats.success) {
            updateLiveStats(stats);
            updateAttendancePatterns(stats);
        }

        // 2. Fetch Attendance Trends (Line Chart) - THIS IS THE KEY FIX
        try {
            const trendRes = await fetch(`?action=get_attendance_trend&range=week`);
            const trendData = await trendRes.json();
            if (trendData.success) {
                updateAttendanceTrendChart(trendData);
            } else {
                console.error('Trend API failed:', trendData);
            }
        } catch (e) {
            console.error('Trend Error:', e);
        }

        // 3. Fetch Department Stats (Doughnut Chart)
        try {
            const deptRes = await fetch(`?action=get_department_stats`);
            const deptData = await deptRes.json();
            if (deptData.success) updateDepartmentChart(deptData);
        } catch (e) { console.error('Dept Error:', e); }

        // 4. Fetch Weekly Breakdown (Bars)
        try {
            updateWeeklyBreakdown();
        } catch (e) { console.error('Weekly Error:', e); }

        // 5. Fetch Top Performers (List)
        try {
            const perfRes = await fetch(`?action=get_top_performers`);
            const performers = await perfRes.json();
            if (performers.success) updateTopPerformers(performers);
        } catch (e) { console.error('Performers Error:', e); }

        // 6. Fetch Detailed Metrics (Table)
        try {
            updateDetailedMetricsTable();
        } catch (e) { console.error('Metrics Error:', e); }

    } catch (error) {
        console.error('Critical Analytics Error:', error);
        showToast('Failed to load analytics data', 'error');
    }
}

function refreshAnalytics() {
    const btn = document.querySelector('.refresh-btn');
    btn.classList.add('spinning');
    
    // Your existing refresh logic
    updateAnalytics().then(() => {
        setTimeout(() => {
            btn.classList.remove('spinning');
        }, 500);
    });
    
    showToast('Analytics refreshed', 'success');
}

// === AUTO-REFRESH OVERTIME DATA EVERY 30 SECONDS ===
setInterval(() => {
    const overtimeSection = document.getElementById('overtimeSection');
    if(overtimeSection && overtimeSection.style.display !== 'none') {
        loadOvertimeData();
    }
}, 30000);
// Initialize fingerprint polling check on page load
document.addEventListener('DOMContentLoaded', function() {
if(document.getElementById('adminDashboard')) {
setInterval(checkFingerprintSectionVisibility, 1000);
}
});

// === LEAVE HISTORY FUNCTIONS ===
function openLeaveHistoryModal() {
    document.getElementById('leaveHistoryModal').style.display = 'block';
    loadLeaveHistoryData();
}

function closeLeaveHistoryModal() {
    document.getElementById('leaveHistoryModal').style.display = 'none';
}

function loadLeaveHistoryData() {
    const list = document.getElementById('leaveHistoryList');
    list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><div style="margin-top: 10px;">Loading history...</div></div>';
    
    fetch('?action=get_leave_history')
    .then(r => r.json())
    .then(data => {
        if (data.success && data.requests.length > 0) {
            let html = '';
            data.requests.forEach(req => {
    const statusLower = req.status.toLowerCase();
    const badgeColor = statusLower === 'approved' ? '#28a745' : (statusLower === 'rejected' ? '#dc3545' : '#ffc107');
    const badgeBg = statusLower === 'approved' ? 'rgba(40,167,69,0.1)' : (statusLower === 'rejected' ? 'rgba(220,53,69,0.1)' : 'rgba(255,193,7,0.1)');
    const initials = req.name ? req.name.charAt(0).toUpperCase() : 'E';
    html += `<div class="record-item" style="opacity: 0.8;">
        <div class="employee-info">
            <div class="employee-avatar">${initials}</div>
            <div class="employee-details">
                <div class="employee-name">${req.name}</div>
                <div class="employee-meta">EID: ${req.employee_id} • ${req.department || 'N/A'}</div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><i class="far fa-clock"></i> ${new Date(req.created_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}</div>
            </div>
        </div>
                    </div>
                    <div class="action-buttons">
                        <div style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: ${badgeBg}; border-radius: 6px; border: 1px solid ${badgeColor}; color: ${badgeColor}; font-weight: 600; font-size: 12px;">
                            ${req.status}
                        </div>
                        <div style="font-size: 12px; color: var(--text-secondary); text-align: right;">
                            <div>${new Date(req.start_date).toLocaleDateString()}</div>
                            <div style="font-size: 10px;">to ${new Date(req.end_date).toLocaleDateString()}</div>
                        </div>
                    </div>
                </div>`;
            });
            list.innerHTML = html;
        } else {
            list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i><div>No records found in the last 30 days.</div></div>';
        }
    })
    .catch(e => {
        list.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading history.</div>';
    });
}

function purgeOldLeaveRecords() {
// Show confirmation dialog
const confirmed = confirm("⚠️ WARNING: This will permanently delete all leave records older than 30 days.\nThis action CANNOT be undone.\nAre you sure you want to continue?");
if (!confirmed) {
return;
}
const formData = new FormData();
formData.append('action', 'purge_old_leave_records');
fetch('', {
method: 'POST',
body: formData
})
.then(r => r.json())
.then(data => {
if (data.success) {
showToast(data.message, 'success');
// Refresh the list without page reload
loadLeaveHistoryData();
refreshLeaveRequests();
} else {
showToast('Error: ' + data.message, 'error');
}
})
.catch(e => {
showToast('Network error: ' + e.message, 'error');
});
}

// Update window onclick to handle new modal
const originalWindowOnclick = window.onclick || function() {};
window.onclick = function(event) {
    originalWindowOnclick(event);
    const historyModal = document.getElementById('leaveHistoryModal');
    if (event.target == historyModal) {
        closeLeaveHistoryModal();
    }
}

// === DAY OFF HISTORY FUNCTIONS ===
function openDayOffHistoryModal() {
    document.getElementById('dayOffHistoryModal').style.display = 'block';
    loadDayOffHistoryData();
}

function closeDayOffHistoryModal() {
    document.getElementById('dayOffHistoryModal').style.display = 'none';
}

function loadDayOffHistoryData() {
    const list = document.getElementById('dayOffHistoryList');
    list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><div style="margin-top: 10px;">Loading day off history...</div></div>';
    
    fetch('?action=get_day_off_history')
    .then(r => r.json())
    .then(data => {
        if (data.success && data.requests.length > 0) {
            let html = '';
            data.requests.forEach(req => {
                const statusLower = req.status.toLowerCase();
                const badgeColor = statusLower === 'approved' ? '#28a745' : (statusLower === 'rejected' ? '#dc3545' : '#ffc107');
                const badgeBg = statusLower === 'approved' ? 'rgba(40,167,69,0.1)' : (statusLower === 'rejected' ? 'rgba(220,53,69,0.1)' : 'rgba(255,193,7,0.1)');
                const initials = req.name ? req.name.charAt(0).toUpperCase() : 'E';
                
                html += `<div class="record-item" style="opacity: 0.8;">
                    <div class="employee-info">
                        <div class="employee-avatar">${initials}</div>
                        <div class="employee-details">
                            <div class="employee-name">${req.name}</div>
                            <div class="employee-meta">EID: ${req.employee_id} • ${req.department || 'N/A'}</div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;"><i class="far fa-clock"></i> ${new Date(req.created_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}</div>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <div style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: ${badgeBg}; border-radius: 6px; border: 1px solid ${badgeColor}; color: ${badgeColor}; font-weight: 600; font-size: 12px;">
                            ${req.status}
                        </div>
                        <div style="font-size: 12px; color: var(--text-secondary); text-align: right;">
                            <div>${new Date(req.start_date).toLocaleDateString()}</div>
                            <div style="font-size: 10px;">to ${new Date(req.end_date).toLocaleDateString()}</div>
                        </div>
                    </div>
                </div>`;
            });
            list.innerHTML = html;
        } else {
            list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i><div>No day off records found in the last 30 days.</div></div>';
        }
    })
    .catch(e => {
        list.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading history.</div>';
    });
}

function purgeOldDayOffRecords() {
    const confirmed = confirm("⚠️ WARNING: This will permanently delete all day off records older than 30 days.\nThis action CANNOT be undone.\nAre you sure you want to continue?");
    if (!confirmed) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'purge_old_day_off_records');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            loadDayOffHistoryData();
            // Refresh the main day off list
            if(typeof filterDayOffRequests === 'function') {
                filterDayOffRequests();
            }
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    })
    .catch(e => {
        showToast('Network error: ' + e.message, 'error');
    });
}

// Update window onclick to handle day off history modal
const originalWindowOnclickDayOff = window.onclick || function() {};
window.onclick = function(event) {
    originalWindowOnclickDayOff(event);
    const dayOffHistoryModal = document.getElementById('dayOffHistoryModal');
    if (event.target == dayOffHistoryModal) {
        closeDayOffHistoryModal();
    }
}


// === TOGGLEABLE FOOTER FUNCTIONALITY ===
function toggleFooter() {
    const footer = document.getElementById('systemFooter');
    const icon = document.getElementById('footerToggleIcon');
    
    if (!footer) return;
    
    const isCollapsed = footer.classList.contains('collapsed');
    
    if (isCollapsed) {
        footer.classList.remove('collapsed');
        icon.className = 'fas fa-chevron-up';
        localStorage.setItem('footerState', 'expanded');
    } else {
        footer.classList.add('collapsed');
        icon.className = 'fas fa-chevron-down';
        localStorage.setItem('footerState', 'collapsed');
    }
}

// === RESTORE FOOTER STATE ON LOAD ===
document.addEventListener('DOMContentLoaded', function() {
    const footer = document.getElementById('systemFooter');
    const icon = document.getElementById('footerToggleIcon');
    
    if (footer) {
        // Get saved state from localStorage
        const savedState = localStorage.getItem('footerState') || 'expanded';
        
        if (savedState === 'collapsed') {
            footer.classList.add('collapsed');
            if (icon) icon.className = 'fas fa-chevron-down';
        }
    }
});
// === OVERTIME TRACKER FUNCTIONS ===
function loadOvertimeData() {
    const container = document.getElementById('overtimeRequestsList');
    if(!container) return;
    container.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i><div>Loading overtime data...</div></div>';
    
    const statusFilter = document.getElementById('overtimeStatusFilter')?.value || 'all';
    fetch(`?action=get_overtime_requests&status=${statusFilter}`)
    .then(r => r.json())
    .then(data => {
        if(data.success && data.requests) {
            displayOvertimeRequests(data.requests);
            updateOvertimeStats(data.requests);
          filterOvertimeRequests(); 
        } else {
            container.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);">No overtime requests found</div>';
        }
    })
    .catch(e => {
        console.error(e);
        container.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545;">Error loading overtime data</div>';
    });
}

function displayOvertimeRequests(requests) {
    const container = document.getElementById('overtimeRequestsList');
    if(!container || requests.length === 0) {
        container.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted);">No overtime requests found</div>';
        return;
    }
    let html = '';
    requests.forEach(req => {
        const statusColors = {
            'Pending': { bg: 'rgba(255,193,7,0.15)', color: '#ffc107', border: '#ffc107' },
            'Approved': { bg: 'rgba(40,167,69,0.15)', color: '#28a745', border: '#28a745' },
            'Rejected': { bg: 'rgba(220,53,69,0.15)', color: '#dc3545', border: '#dc3545' }
        };
        const status = statusColors[req.status] || statusColors['Pending'];
        const initials = req.name ? req.name.charAt(0).toUpperCase() : 'E';
        
        // Calculate Display Times
        const checkIn = req.check_in ? new Date(req.check_in).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : '--:--';
const checkOut = req.check_out ? new Date(req.check_out).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : '--:--';
        const dateStr = req.created_at ? new Date(req.created_at).toLocaleDateString() : 'N/A';

        html += `
        <div class="overtime-request-card"
             data-status="${req.status}"
             data-name="${req.name ? req.name.toLowerCase() : ''}"
             data-eid="${req.employee_id ? req.employee_id.toLowerCase() : ''}">
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: start;">
                <div style="display: flex; gap: 15px; align-items: start;">
                    <!-- Avatar -->
                    ${req.photo_path && req.photo_path !== '' 
                      ? `<img src="${req.photo_path}" style="width: 48px; height: 48px; border-radius: 12px; object-fit: cover;">` 
                      : `<div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-accent), var(--secondary-accent)); display: flex; align-items: center; justify-content: center; color: #00110f; font-weight: 700; font-size: 18px;">${initials}</div>`
                    }
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                            <div style="font-size: 16px; font-weight: 700; color: var(--text-primary);">${req.name || 'Unknown'}</div>
                            <span class="status-badge-modern" style="padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; background: ${status.bg}; border: 1px solid ${status.border}; color: ${status.color};">${req.status}</span>
                        </div>
                        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">
                            <span style="color: var(--text-secondary);">EID:</span> ${req.employee_id || 'N/A'} • ${req.department || 'N/A'}
                        </div>
                        <!-- Time Details Grid -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 10px;">
                            <div style="background: rgba(255,255,255,0.03); padding: 10px; border-radius: 6px;">
                                <div style="font-size: 11px; color: var(--text-muted);">Overtime Hours</div>
                                <div style="font-size: 16px; font-weight: 700; color: var(--primary-accent);">${req.overtime_hours}h</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.03); padding: 10px; border-radius: 6px;">
                                <div style="font-size: 11px; color: var(--text-muted);">Work Time</div>
                                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary);">${checkIn} - ${checkOut}</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.03); padding: 10px; border-radius: 6px;">
                                <div style="font-size: 11px; color: var(--text-muted);">Date</div>
                                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary);">${dateStr}</div>
                            </div>
                        </div>
                        ${req.reason ? `<div style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; background: rgba(255,255,255,0.03); padding: 10px; border-radius: 6px; margin-top: 8px;"><strong style="color: var(--text-muted);">Reason:</strong> ${req.reason}</div>` : ''}
                        ${req.admin_response ? `<div style="font-size: 12px; color: var(--text-muted); margin-top: 8px;"><i class="fas fa-comment"></i> Admin: ${req.admin_response}</div>` : ''}
                    </div>
                </div>
                <!-- Actions -->
                <div style="text-align: right; flex-shrink: 0;">
                    ${req.status === 'Pending' ? `
                    <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end;">
                        <button class="action-btn-approve" onclick="processOvertimeDecision(${req.id}, 'approve')">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="action-btn-reject" onclick="processOvertimeDecision(${req.id}, 'reject')">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    ` : `
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 12px;">
                        ${req.updated_at ? 'Updated: ' + new Date(req.updated_at).toLocaleString() : ''}
                    </div>
                    `}
                </div>
            </div>
        </div>
        `;
    });
    container.innerHTML = html;
}

function updateOvertimeStats(requests) {
    let totalHours = 0;
    let pending = 0, approved = 0, rejected = 0;
    requests.forEach(req => {
        totalHours += parseFloat(req.overtime_hours) || 0;
        if(req.status === 'Pending') pending++;
        else if(req.status === 'Approved') approved++;
        else if(req.status === 'Rejected') rejected++;
    });
    document.getElementById('otTotalHours').textContent = totalHours.toFixed(1) + 'h';
    document.getElementById('otPendingCount').textContent = pending;
    document.getElementById('otApprovedCount').textContent = approved;
    document.getElementById('otRejectedCount').textContent = rejected;
}

















function filterOvertimeRequests() {
    const statusFilter = document.getElementById('overtimeStatusFilter')?.value || 'all';
    const employeeSearch = document.getElementById('overtimeEmployeeSearch')?.value.toLowerCase().trim() || '';
    const cards = document.querySelectorAll('.overtime-request-card');
    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        const name = card.getAttribute('data-name');
        const eid = card.getAttribute('data-eid');
        let show = true;
        
        if(statusFilter !== 'all' && status !== statusFilter) show = false;
        if(employeeSearch && name && !name.includes(employeeSearch) && eid && !eid.includes(employeeSearch)) show = false;
        
        card.style.display = show ? 'flex' : 'none';
    });
}

function processOvertimeDecision(requestId, decision) {
    if(!confirm(`Are you sure you want to ${decision} this overtime request?`)) return;
    
    const formData = new FormData();
    formData.append('action', decision === 'approve' ? 'approve_overtime' : 'reject_overtime');
    formData.append('request_id', requestId);
    
    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            showToast(`Overtime request ${decision}d successfully!`, 'success');
            loadOvertimeData(); // Refresh list
        } else {
            showToast('Error: ' + (data.message || 'Failed to process request'), 'error');
        }
    })
    .catch(e => {
        showToast('Network error: ' + e, 'error');
    });
}

// === AUTO-REFRESH ATTENDANCE HISTORY ===
let attendanceHistoryInterval = null;

function startAttendanceHistoryRefresh() {
    if (attendanceHistoryInterval) clearInterval(attendanceHistoryInterval);
    
    // Refresh immediately
    loadAttendanceHistory();
    
    // Then refresh every 10 seconds when on attendance tab
    attendanceHistoryInterval = setInterval(() => {
        const attendanceSection = document.getElementById('employeeAttendanceSection');
        if (attendanceSection && attendanceSection.style.display !== 'none') {
            loadAttendanceHistory();
        }
    }, 10000); // 10 seconds
}

function stopAttendanceHistoryRefresh() {
    if (attendanceHistoryInterval) {
        clearInterval(attendanceHistoryInterval);
        attendanceHistoryInterval = null;
    }
}

// Auto-refresh overtime data every 30 seconds if tab is visible
setInterval(() => {
    const overtimeSection = document.getElementById('overtimeSection');
    if(overtimeSection && overtimeSection.style.display !== 'none') {
        loadOvertimeData();
    }
}, 30000);
function loadAttendanceHistory() {
    const startDate = document.getElementById('attendanceStartDate').value;
    const endDate = document.getElementById('attendanceEndDate').value;
    const searchTerm = document.getElementById('attendanceSearchInput').value.toLowerCase();
    
    if (!startDate || !endDate) {
        showToast('Please select both start and end dates', 'error');
        return;
    }

    const container = document.getElementById('attendanceHistoryList');
    // === LIVE TIMER FOR ACTIVE SESSIONS ===
function startLiveTimers() {
    // Clear existing interval
    if (window.liveTimerInterval) clearInterval(window.liveTimerInterval);
    
    // Update every second
    window.liveTimerInterval = setInterval(() => {
        document.querySelectorAll('.live-timer').forEach(timer => {
            const checkInTime = new Date(timer.getAttribute('data-checkin'));
            const now = new Date();
            const diffMs = now - checkInTime;
            const diffHrs = Math.floor(diffMs / 3600000);
            const diffMins = Math.floor((diffMs % 3600000) / 60000);
            const diffSecs = Math.floor((diffMs % 60000) / 1000);
            timer.textContent = `${diffHrs}h ${diffMins}m ${diffSecs}s`;
            timer.style.color = '#28a745';
            timer.style.fontWeight = '700';
        });
    }, 1000);
}
// Start live timers for currently checked-in employees
startLiveTimers();
// Add fade effect
container.classList.add('is-refreshing'); 
container.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading-spinner"... style="width: 40px; height: 40px; margin: 0 auto 15px;"></div><div style="color: var(--text-muted);">Loading attendance records...</div></div>';

    fetch(`?action=get_attendance_history&start_date=${startDate}&end_date=${endDate}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.records && data.records.length > 0) {
                let html = '';
                let presentCount = 0;
                let lateCount = 0;
                let absentCount = 0;

                data.records.forEach(record => {
                    const statusClass = record.is_late ? 'late' : 'present';
                    const statusText = record.is_late ? 'Late' : 'Present';
                    const statusColor = record.is_late ? '#ffc107' : '#28a745';
                    
                    if (record.is_late) lateCount++;
                    else presentCount++;

                  const checkIn = record.check_in ? new Date(record.check_in).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true }) : '--:--';
let checkOut = '--:--';
let duration = '-';
let isCurrentlyCheckedIn = false;

if (record.check_out) {
    checkOut = new Date(record.check_out).toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit', 
        second: '2-digit',
        hour12: true 
    });
    // Calculate duration
    const checkInTime = new Date(record.check_in);
    const checkOutTime = new Date(record.check_out);
    const diffMs = checkOutTime - checkInTime;
    const diffHrs = Math.floor(diffMs / 3600000);
    const diffMins = Math.floor((diffMs % 3600000) / 60000);
    duration = `${diffHrs}h ${diffMins}m`;
} else if (record.check_in) {
    // Currently checked in - show live timer
    isCurrentlyCheckedIn = true;
    checkOut = '<span style="color: #ffc107;">Still Working</span>';
    duration = `<span class="live-timer" data-checkin="${record.check_in}" data-id="${record.id}">Calculating...</span>`;
}                    const dateStr = new Date(record.check_in).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

                    html += `
                        <div class="historical-record-card ${statusClass}">
                            <div class="record-header">
                                <div class="record-date">
                                    <i class="far fa-calendar" style="color: ${statusColor};"></i>
                                    ${dateStr}
                                </div>
                                <span class="record-status-badge ${statusClass}">${statusText}</span>
                            </div>
                            <div class="record-body">
                                <div class="record-info-item">
                                    <i class="fas fa-user"></i>
                                    <div>
                                        <div class="record-info-label">Employee</div>
                                        <div class="record-info-value">${record.name}</div>
                                    </div>
                                </div>
                                <div class="record-info-item">
                                    <i class="fas fa-id-card"></i>
                                    <div>
                                        <div class="record-info-label">Employee ID</div>
                                        <div class="record-info-value">${record.employee_id}</div>
                                    </div>
                                </div>
                                <div class="record-info-item">
                                    <i class="fas fa-clock"></i>
                                    <div>
                                        <div class="record-info-label">Check In</div>
                                        <div class="record-info-value">${checkIn}</div>
                                    </div>
                                </div>
                                <div class="record-info-item">
                                    <i class="fas fa-door-open"></i>
                                    <div>
                                        <div class="record-info-label">Check Out</div>
                                        <div class="record-info-value">${checkOut}</div>
                                    </div>
                                </div>
                                ${record.department ? `
                                <div class="record-info-item">
                                    <i class="fas fa-building"></i>
                                    <div>
                                        <div class="record-info-label">Department</div>
                                        <div class="record-info-value">${record.department}</div>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                            <div class="record-footer">
                                <span>Recorded: ${new Date(record.check_in).toLocaleString()}</span>
                                ${record.scan_method ? `<span>Method: <i class="fas ${record.scan_method === 'fingerprint' ? 'fa-fingerprint' : 'fa-keyboard'}"></i> ${record.scan_method}</span>` : ''}
                            </div>
                        </div>
                    `;
                });

                // Update stats
                document.getElementById('totalPresentCount').textContent = presentCount;
                document.getElementById('totalLateCount').textContent = lateCount;
                document.getElementById('totalAbsentCount').textContent = absentCount;

                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div style="text-align: center; padding: 60px 20px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 2px dashed var(--border-color);">
                        <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">📭</div>
                        <div style="font-size: 16px; color: var(--text-primary); font-weight: 600; margin-bottom: 8px;">No Records Found</div>
                        <div style="font-size: 13px; color: var(--text-muted);">No attendance records found for the selected date range.</div>
                    </div>
                `;
            }
        })
        .catch(e => {
            container.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 10px;"></i><div>Error loading records. Please try again.</div></div>';
        });
}

// Cleanup when leaving page
window.addEventListener('beforeunload', function() {
    stopAttendanceHistoryRefresh();
    if (window.liveTimerInterval) {
        clearInterval(window.liveTimerInterval);
    }
});

// Allow clicking on the photo circle to trigger upload
document.addEventListener('DOMContentLoaded', function() {
    const photoWrapper = document.querySelector('.photo-preview-wrapper');
    const photoInput = document.getElementById('empPhotoInput');
    
    if (photoWrapper && photoInput) {
        photoWrapper.addEventListener('click', function() {
            photoInput.click();
        });
    }
});

 if(document.getElementById('adminDashboard')) {
        setInterval(checkFingerprintSectionVisibility, 1000);
    }
// === ATTENDANCE SUMMARY LIVE DATA - FIXED ===
function updateAttendanceSummary(date) {
    if (!date) {
        const dateInput = document.querySelector('#attendanceDateFilter');
        date = dateInput ? dateInput.value : new Date().toISOString().split('T')[0];
    }
    
    console.log("Fetching attendance summary for date:", date);
    
    fetch(`?action=get_attendance_summary&date=${date}`)
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        console.log("Received summary data:", data);
        if (data.success) {
            // Animate numbers
            animateValue('summary-present-count', data.present || 0);
            animateValue('summary-late-count', data.late || 0);
            animateValue('summary-absent-count', data.absent || 0);
            animateValue('summary-total-count', data.total || 0);
            
            // Update date label if debug_date is provided
            if(data.debug_date) {
                const dateObj = new Date(data.debug_date);
                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                const label = document.getElementById('selectedDateLabel');
                if(label) label.textContent = dateObj.toLocaleDateString('en-US', options);
            }
            console.log("Summary UI updated successfully.");
        } else {
            console.error("API returned success: false", data.message);
        }
    })
    .catch(e => {
        console.error("Summary fetch error:", e);
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.querySelector('#attendanceDateFilter');
    if (dateInput) {
        // Set to TODAY
        const today = new Date().toISOString().split('T')[0];
        dateInput.value = today;
        
        // Load data immediately
        updateAttendanceSummary(today);
        
        // Add listener
        dateInput.addEventListener('change', function() {
            updateAttendanceSummary(this.value);
        });
    } else {
        console.error("Date input #attendanceDateFilter not found!");
    }
});

function updateDateLabel(date) {
    const dateObj = new Date(date);
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('selectedDateLabel').textContent = dateObj.toLocaleDateString('en-US', options);
}

function updateDateLabel(date) {
    const dateObj = new Date(date);
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('selectedDateLabel').textContent = dateObj.toLocaleDateString('en-US', options);
}
// === LIVE CLOCK AND DURATION TRACKER ===
let checkInTimestamp = null;
let durationInterval = null;

function startLiveClock() {
    // Update clock immediately and every second
    updateClock();
    setInterval(updateClock, 1000);
}

function updateClock() {
    const now = new Date();
    const timeEl = document.getElementById('currentTime');
    if (timeEl) {
        timeEl.textContent = formatTime12(now);
    }
}

function startDurationTracker(checkInTime) {
    // Store check-in time
    checkInTimestamp = new Date(checkInTime);
    
    // Show the container
    const container = document.getElementById('durationTrackerContainer');
    const checkInDisplay = document.getElementById('checkInTimeDisplay');
    
    if (container) {
        container.style.display = 'block';
    }
    
    if (checkInDisplay && checkInTimestamp) {
        checkInDisplay.textContent = formatTime12(checkInTimestamp);
    }
    
    // Update duration immediately and every second
    updateDuration();
    if (durationInterval) clearInterval(durationInterval);
    durationInterval = setInterval(updateDuration, 1000);
}

function updateDuration() {
    if (!checkInTimestamp) return;
    
    const now = new Date();
    const diffMs = now - checkInTimestamp;
    
    // Calculate hours, minutes, seconds
    const diffSec = Math.floor(diffMs / 1000);
    const hours = Math.floor(diffSec / 3600);
    const minutes = Math.floor((diffSec % 3600) / 60);
    const seconds = diffSec % 60;
    
    // Format with leading zeros
    const h = hours.toString().padStart(2, '0');
    const m = minutes.toString().padStart(2, '0');
    const s = seconds.toString().padStart(2, '0');
    
    const display = document.getElementById('durationTimer');
    if (display) {
        display.textContent = `${h}h ${m}m ${s}s`;
    }
}

function stopDurationTracker() {
    if (durationInterval) {
        clearInterval(durationInterval);
        durationInterval = null;
    }
    const container = document.getElementById('durationTrackerContainer');
    if (container) {
        container.style.display = 'none';
    }
    checkInTimestamp = null;
}

function formatTime12(date) {
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    return `${hours}:${minutes}:${seconds} ${ampm}`;
}

// === START CLOCK ON PAGE LOAD ===
document.addEventListener('DOMContentLoaded', function() {
    startLiveClock();
    
    // Check if employee is already checked in (from PHP data)
    <?php if ($attendanceInfo && $attendanceInfo['check_in'] && !$attendanceInfo['check_out']): ?>
    startDurationTracker('<?= $attendanceInfo['check_in'] ?>');
    <?php endif; ?>
});

// === UPDATE MANUAL CHECK-IN FUNCTIONS ===
function submitManualAttendance(type) {
    const btn = type === 'IN' ? document.getElementById('manualCheckInBtn') : document.getElementById('manualCheckOutBtn');
    const msg = document.getElementById('manualMsg');
    
    if(!btn || btn.disabled) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> Processing...';
    msg.textContent = 'Processing request...';
    msg.style.color = 'var(--text-muted)';
    
    const formData = new FormData();
    formData.append('action', 'manual_attendance');
    formData.append('type', type);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
    
    fetch('', { 
        method: 'POST', 
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if(data.success) {
            msg.textContent = `✓ ${data.message} at ${data.time}`;
            msg.style.color = '#28a745';
            
            // Update status badge
            const statusBadge = document.getElementById('currentStatusBadge');
            if (statusBadge) {
                if (type === 'IN') {
                    statusBadge.style.background = 'rgba(255,193,7,0.1)';
                    statusBadge.style.color = '#ffc107';
                    statusBadge.innerHTML = '✅ Check In';
                } else {
                    statusBadge.style.background = 'rgba(40,167,69,0.1)';
                    statusBadge.style.color = '#28a745';
                    statusBadge.innerHTML = '✅ Scan Out';
                }
            }
            
            // Reload page after 2 seconds to update all data
            setTimeout(() => location.reload(), 2000);
        } else {
            msg.textContent = 'Error: ' + (data.message || 'Failed to process request');
            msg.style.color = '#dc3545';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        console.error('Manual attendance error:', err);
        msg.textContent = 'Network error: ' + err.message;
        msg.style.color = '#dc3545';
    });
}
// === LIVE CLOCK FUNCTION ===
function startLiveClock() {
    function updateClock() {
        const now = getPhilippineDate(); // Use PH Time
        const timeEl = document.getElementById('currentTime');
        const dateEl = document.getElementById('currentDate');
        if (timeEl) {
            timeEl.textContent = formatTime12(now);
        }
        if (dateEl) {
            dateEl.textContent = formatFullDate(now);
        }
    }
    updateClock();
    setInterval(updateClock, 1000);
}
    
    // Update immediately and then every second
    updateClock();
    setInterval(updateClock, 1000);


// Start clock on page load
document.addEventListener('DOMContentLoaded', function() {
    startLiveClock();
});
// === HELPER: Update Summary Counts ===
function updateSummaryCounts(present, late, absent, total) {
    const summaryPresent = document.getElementById('summary-present-count');
    const summaryLate = document.getElementById('summary-late-count');
    const summaryAbsent = document.getElementById('summary-absent-count');
    const summaryTotal = document.getElementById('summary-total-count');
    
    if(summaryPresent) summaryPresent.textContent = present || 0;
    if(summaryLate) summaryLate.textContent = late || 0;
    if(summaryAbsent) summaryAbsent.textContent = absent || 0;
    if(summaryTotal) summaryTotal.textContent = total || 0;
}

</script>
</body>
</html>
