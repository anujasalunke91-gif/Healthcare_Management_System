<?php
/**
 * Secure Medical Scan Image Server
 * Serves diagnostic scan images only to authorized users (Patient own, Doctor associated, or Admin)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient', 'doctor', 'admin']);

$diagnosticId = intval($_GET['id'] ?? 0);
if ($diagnosticId <= 0) {
    http_response_code(400);
    exit('Invalid scan ID');
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, patient_id, doctor_id, scan_file_path FROM diagnostic_records WHERE id = ? LIMIT 1");
    $stmt->execute([$diagnosticId]);
    $record = $stmt->fetch();

    if (!$record) {
        http_response_code(404);
        exit('Scan record not found');
    }

    $role = getCurrentUserRole();
    $userId = getCurrentUserId();

    if ($role === 'patient' && intval($record['patient_id']) !== $userId) {
        http_response_code(403);
        exit('Access denied');
    }

    if ($role === 'doctor' && intval($record['doctor_id']) !== $userId && !isDoctorAuthorizedForPatient($pdo, $userId, intval($record['patient_id']))) {
        http_response_code(403);
        exit('Access denied');
    }

    $filePath = resolveScanFilePath($record['scan_file_path']);
    if (!$filePath || !is_readable($filePath)) {
        http_response_code(404);
        exit('Scan image file not found');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($filePath) ?: 'image/jpeg';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    readfile($filePath);
    exit;
} catch (Exception $e) {
    error_log('Serve Scan Error: ' . $e->getMessage());
    http_response_code(500);
    exit('Error loading scan image');
}
