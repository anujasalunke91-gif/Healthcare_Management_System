<?php
/**
 * Database Configuration and Helper Functions
 * Intelligent Healthcare Management Platform
 * Portable PDO connection with security, billing, and imaging helpers
 */

if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

// Load optional .env file and local PHP config override (neither should be committed)
$envFileConfig = [];
foreach ([dirname(__DIR__) . '/.env', dirname(__DIR__, 2) . '/.env'] as $envPath) {
    if (is_file($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '=') === false) {
                    continue;
                }
                [$envKey, $envVal] = explode('=', $trimmed, 2);
                $envKey = trim($envKey);
                $envVal = trim($envVal);
                if ((substr($envVal, 0, 1) === '"' && substr($envVal, -1) === '"') ||
                    (substr($envVal, 0, 1) === "'" && substr($envVal, -1) === "'")) {
                    $envVal = substr($envVal, 1, -1);
                }
                if ($envKey !== '' && !array_key_exists($envKey, $envFileConfig)) {
                    $envFileConfig[$envKey] = $envVal;
                }
            }
        }
        break;
    }
}

$localConfig = [];
$localConfigPath = __DIR__ . '/config.local.php';
if (file_exists($localConfigPath)) {
    $loaded = require $localConfigPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}

$getConfig = function ($key, $default = '') use ($localConfig, $envFileConfig) {
    foreach (['HP_' . $key, $key] as $envName) {
        $envVal = getenv($envName);
        if ($envVal !== false && $envVal !== '') {
            return $envVal;
        }
        if (isset($envFileConfig[$envName]) && $envFileConfig[$envName] !== '') {
            return $envFileConfig[$envName];
        }
    }
    if (isset($localConfig[$key]) && $localConfig[$key] !== null) {
        return $localConfig[$key];
    }
    return $default;
};

if (!defined('DB_HOST')) define('DB_HOST', $getConfig('DB_HOST', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', $getConfig('DB_NAME', 'healthcare_platform'));
if (!defined('DB_USER')) define('DB_USER', $getConfig('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', $getConfig('DB_PASS', ''));
if (!defined('DB_CHARSET')) define('DB_CHARSET', $getConfig('DB_CHARSET', 'utf8mb4'));

// Dynamic Base URL detection for XAMPP, Apache virtual hosts, and PHP built-in server
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(auth|doctor|patient|admin|config|includes|api)$#i', '', $scriptDir);
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}
$basePath = rtrim($basePath, '/');

if (!defined('BASE_URL')) {
    define('BASE_URL', $protocol . '://' . $host . $basePath);
}

if (!defined('AI_SERVICE_URL')) {
    define('AI_SERVICE_URL', $getConfig('AI_SERVICE_URL', 'http://127.0.0.1:8000'));
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'MedCore Health');
}

require_once __DIR__ . '/../includes/i18n.php';

/**
 * Get PDO Database Connection
 *
 * @return PDO
 * @throws PDOException
 */
function getDBConnection() {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database Connection Error: " . $e->getMessage());
        throw new PDOException("Database connection failed. Please contact system administrator.");
    }
}

/**
 * Ensure optional bills table exists (safe idempotent DDL).
 */
function ensureBillingTableExists(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bills` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `bill_number` VARCHAR(40) NOT NULL,
            `patient_id` INT NOT NULL,
            `doctor_id` INT NOT NULL,
            `appointment_id` INT NULL,
            `diagnostic_id` INT NULL,
            `description` VARCHAR(255) NOT NULL,
            `items_json` JSON NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_status` ENUM('unpaid', 'paid', 'partial', 'cancelled') NOT NULL DEFAULT 'unpaid',
            `bill_date` DATE NOT NULL,
            `paid_at` DATETIME NULL,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_bill_number` (`bill_number`),
            INDEX `idx_bill_patient` (`patient_id`),
            INDEX `idx_bill_doctor` (`doctor_id`),
            INDEX `idx_bill_appointment` (`appointment_id`),
            INDEX `idx_bill_diagnostic` (`diagnostic_id`),
            INDEX `idx_bill_status` (`payment_status`),
            CONSTRAINT `fk_bills_patient` FOREIGN KEY (`patient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_bills_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Create a consultation bill for an appointment if one does not already exist.
 */
function createAppointmentBill(PDO $pdo, $appointmentId, $patientId, $doctorId, $billDate = null) {
    if (!$pdo->inTransaction()) {
        ensureBillingTableExists($pdo);
    }

    $stmt = $pdo->prepare("SELECT id FROM bills WHERE appointment_id = ? LIMIT 1");
    $stmt->execute([$appointmentId]);
    if ($stmt->fetch()) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT dp.consultation_fee, dp.specialization, u.name AS doctor_name FROM doctor_profiles dp JOIN users u ON dp.user_id = u.id WHERE dp.user_id = ? LIMIT 1");
    $stmt->execute([$doctorId]);
    $doc = $stmt->fetch();

    $fee = $doc ? floatval($doc['consultation_fee']) : 500.00;
    $spec = $doc['specialization'] ?? 'General Consultation';
    $docName = $doc['doctor_name'] ?? 'Attending Physician';
    $dateVal = $billDate ?: date('Y-m-d');
    $billNumber = 'BILL-' . date('Y') . '-' . str_pad((string)$appointmentId, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    $description = 'Clinical Consultation — ' . $spec . ' (Dr. ' . $docName . ')';
    $items = json_encode([
        [
            'item' => 'Specialist Consultation (' . $spec . ')',
            'category' => 'Consultation',
            'qty' => 1,
            'unit_price' => $fee,
            'total' => $fee
        ]
    ]);

    $stmt = $pdo->prepare("INSERT INTO bills (bill_number, patient_id, doctor_id, appointment_id, description, items_json, amount, payment_status, bill_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'unpaid', ?)");
    $stmt->execute([$billNumber, $patientId, $doctorId, $appointmentId, $description, $items, $fee, $dateVal]);
    return $pdo->lastInsertId();
}

/**
 * Create a diagnostic imaging bill for a risk assessment if one does not already exist.
 */
function createDiagnosticBill(PDO $pdo, $diagnosticId, $patientId, $doctorId, $scanType, $billDate = null) {
    if (!$pdo->inTransaction()) {
        ensureBillingTableExists($pdo);
    }

    $stmt = $pdo->prepare("SELECT id FROM bills WHERE diagnostic_id = ? LIMIT 1");
    $stmt->execute([$diagnosticId]);
    if ($stmt->fetch()) {
        return null;
    }

    $feeMap = [
        'X-Ray' => 500.00,
        'CT-Scan' => 2500.00,
        'MRI' => 4500.00,
    ];
    $imagingFee = $feeMap[$scanType] ?? 800.00;
    $assessmentFee = 300.00;
    $total = $imagingFee + $assessmentFee;
    $dateVal = $billDate ?: date('Y-m-d');
    $billNumber = 'BILL-' . date('Y') . '-DX' . str_pad((string)$diagnosticId, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    $description = $scanType . ' Diagnostic Imaging & Risk Assessment';
    $items = json_encode([
        [
            'item' => $scanType . ' Radiographic Imaging',
            'category' => 'Diagnostics',
            'qty' => 1,
            'unit_price' => $imagingFee,
            'total' => $imagingFee
        ],
        [
            'item' => 'Clinical Risk Assessment Analysis',
            'category' => 'Clinical Evaluation',
            'qty' => 1,
            'unit_price' => $assessmentFee,
            'total' => $assessmentFee
        ]
    ]);

    $stmt = $pdo->prepare("INSERT INTO bills (bill_number, patient_id, doctor_id, diagnostic_id, description, items_json, amount, payment_status, bill_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'unpaid', ?)");
    $stmt->execute([$billNumber, $patientId, $doctorId, $diagnosticId, $description, $items, $total, $dateVal]);
    return $pdo->lastInsertId();
}

/**
 * Check whether a doctor is authorized to view a patient's records.
 */
function isDoctorAuthorizedForPatient(PDO $pdo, $doctorId, $patientId) {
    $queries = [
        "SELECT 1 FROM appointments WHERE doctor_id = ? AND patient_id = ? LIMIT 1",
        "SELECT 1 FROM diagnostic_records WHERE doctor_id = ? AND patient_id = ? LIMIT 1",
        "SELECT 1 FROM prescriptions_and_reminders WHERE doctor_id = ? AND patient_id = ? LIMIT 1",
    ];
    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$doctorId, $patientId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    try {
        ensureBillingTableExists($pdo);
        $stmt = $pdo->prepare("SELECT 1 FROM bills WHERE doctor_id = ? AND patient_id = ? LIMIT 1");
        $stmt->execute([$doctorId, $patientId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Exception $e) {
        // Ignore if bills table check fails
    }
    return false;
}

/**
 * Resolve stored scan path (supports legacy absolute paths and portable relative paths).
 *
 * @param string|null $storedPath
 * @return string|null Absolute path on disk if file exists, otherwise null.
 */
function resolveScanFilePath($storedPath) {
    if (empty($storedPath)) {
        return null;
    }
    $normalized = str_replace('\\', '/', $storedPath);
    $filename = basename($normalized);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }
    $scansDir = realpath(__DIR__ . '/../uploads/scans');
    if (!$scansDir) {
        return null;
    }
    $candidate = $scansDir . DIRECTORY_SEPARATOR . $filename;
    if (is_file($candidate)) {
        return $candidate;
    }
    return null;
}

/**
 * Get authenticated web URL for a diagnostic scan image if the file exists.
 */
function getScanWebUrl($diagnosticId, $storedPath) {
    if (!resolveScanFilePath($storedPath)) {
        return null;
    }
    return BASE_URL . '/includes/serve_scan.php?id=' . intval($diagnosticId);
}

/**
 * Standardized JSON Response
 */
function json_response($status, $data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $status,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sanitize Input Data
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

/**
 * Format currency in Indian Rupees (INR ₹) with Indian numbering format
 */
function formatCurrency($amount) {
    $val = round((float)$amount, 2);
    $neg = $val < 0 ? '-' : '';
    $abs = abs($val);
    $parts = explode('.', number_format($abs, 2, '.', ''));
    $intPart = $parts[0];
    $decPart = $parts[1] ?? '00';
    if (strlen($intPart) > 3) {
        $last3 = substr($intPart, -3);
        $rest = substr($intPart, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $formattedInt = $rest . ',' . $last3;
    } else {
        $formattedInt = $intPart;
    }
    return $neg . '₹' . $formattedInt . '.' . $decPart;
}

/**
 * Compute a valid Date of Birth matching the specified integer age.
 */
function computeDobFromAge($age, $existingDob = null) {
    $age = max(0, min(125, intval($age)));
    $today = new DateTime('today');
    $month = (int)$today->format('m');
    $day = (int)$today->format('d');

    if (!empty($existingDob)) {
        try {
            $ex = new DateTime($existingDob);
            $month = (int)$ex->format('m');
            $day = (int)$ex->format('d');
        } catch (Exception $e) {
            // Keep today's month/day
        }
    }

    if ($month === 2 && $day === 29) {
        $day = 28;
    }

    $currentYear = (int)$today->format('Y');
    $birthYear = $currentYear - $age;
    $candidate = DateTime::createFromFormat('Y-n-j', $birthYear . '-' . $month . '-' . $day);
    if (!$candidate) {
        $candidate = new DateTime($birthYear . '-01-01');
    }
    // If birthday hasn't occurred yet this year, subtract 1 year so age matches exactly
    $checkThisYear = DateTime::createFromFormat('Y-n-j', $currentYear . '-' . $month . '-' . $day);
    if ($checkThisYear && $checkThisYear > $today) {
        $candidate->modify('-1 year');
    }
    return $candidate->format('Y-m-d');
}

/**
 * Generate CSRF Token
 */
function generateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF Token
 */
function validateCSRFToken($token, $max_age = 3600) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || !is_string($token)) {
        return false;
    }

    if (time() - $_SESSION['csrf_token_time'] > $max_age) {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return false;
    }

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }

    return true;
}

/**
 * Regenerate CSRF Token
 */
function regenerateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    unset($_SESSION['csrf_token']);
    unset($_SESSION['csrf_token_time']);

    return generateCSRFToken();
}

/**
 * Secure Session Configuration
 */
function configureSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', 3600);

    session_set_cookie_params([
        'lifetime' => 3600,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();

    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

/**
 * Check if User is Authenticated
 */
function isAuthenticated() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']) && isset($_SESSION['user_role']);
}

/**
 * Get Current User ID
 */
function getCurrentUserId() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
}

/**
 * Get Current User Role
 */
function getCurrentUserRole() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return $_SESSION['user_role'] ?? null;
}

/**
 * Return dashboard URL for the given role.
 */
function getDashboardUrlForRole($role) {
    switch ($role) {
        case 'admin':
            return BASE_URL . '/admin/dashboard.php';
        case 'doctor':
            return BASE_URL . '/doctor/dashboard.php';
        case 'patient':
            return BASE_URL . '/patient/dashboard.php';
        default:
            return BASE_URL . '/auth/login.php';
    }
}

/**
 * Require Authentication
 */
function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
}

/**
 * Require Specific Role
 */
function requireRole($allowedRoles) {
    requireAuth();

    $currentRole = getCurrentUserRole();
    $allowedRoles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];

    if (!in_array($currentRole, $allowedRoles, true)) {
        header('Location: ' . getDashboardUrlForRole($currentRole));
        exit;
    }
}

/**
 * Log User Activity
 */
function logActivity($userId, $action, $details = '') {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([
            $userId,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    } catch (Exception $e) {
        error_log("Activity Log Error: " . $e->getMessage());
    }
}

/**
 * Format Date for Display
 */
function formatDate($date, $format = 'M d, Y') {
    if (empty($date)) {
        return '--';
    }
    $timestamp = is_numeric($date) ? (int)$date : strtotime($date);
    if ($timestamp === false) {
        return '--';
    }
    return date($format, $timestamp);
}

/**
 * Calculate Age from Date of Birth
 */
function calculateAge($dob) {
    if (empty($dob)) {
        return '--';
    }
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        return $today->diff($birthDate)->y;
    } catch (Exception $e) {
        return '--';
    }
}

/**
 * Upload File Securely
 */
function uploadFile($file, $targetDir, $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'], $maxSize = 15728640) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Invalid file upload'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error code: ' . $file['error']];
    }

    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File size exceeds maximum limit'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $allowedTypes, true)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = uniqid('scan_', true) . '.' . $extension;
    $targetPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'message' => 'Failed to move uploaded file'];
    }

    return ['success' => true, 'filepath' => $targetPath, 'filename' => $filename];
}
