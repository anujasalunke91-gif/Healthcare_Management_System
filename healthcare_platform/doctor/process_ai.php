<?php
/**
 * Risk Assessment Processing Handler (Integrated Multi-Cancer MobileNetV2 Keras Model)
 * Validates medical scans, invokes the real FastAPI/Keras ML service (no dummy/random fallbacks),
 * stores genuine model predictions & clinical vitals with the patient record, and generates billing.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, ['message' => 'Method not allowed'], 405);
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf_token)) {
    json_response(false, ['message' => t('err_csrf')], 403);
}

$allowedCancers = [
    'Auto-Detect',
    'Acute Lymphoblastic Leukemia (ALL)',
    'Brain Cancer',
    'Breast Cancer',
    'Cervical Cancer',
    'Colon Cancer',
    'Kidney Cancer',
    'Lung Cancer',
    'Lymphoma',
];

$savedScanFile = null;

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    $patient_id = intval($_POST['patient_id'] ?? 0);
    $doctor_id = getCurrentUserId();
    $systolic_bp = floatval($_POST['systolic_bp'] ?? 0);
    $diastolic_bp = floatval($_POST['diastolic_bp'] ?? 0);
    $glucose = floatval($_POST['glucose'] ?? 0);
    $smoking = floatval($_POST['smoking'] ?? 0);
    $family_history = sanitize($_POST['family_history'] ?? 'No');
    $tumor_marker_cea = floatval($_POST['tumor_marker_cea'] ?? 0);
    $tumor_marker_ca125 = floatval($_POST['tumor_marker_ca125'] ?? 0);
    $scan_type = trim($_POST['scan_type'] ?? '');
    $cancer_type = trim($_POST['cancer_type'] ?? 'Auto-Detect');

    if ($patient_id <= 0 || $doctor_id <= 0 || !in_array($scan_type, ['X-Ray', 'CT-Scan', 'MRI'], true)) {
        json_response(false, ['message' => 'Missing or invalid required fields'], 400);
    }
    if (!in_array($cancer_type, $allowedCancers, true)) {
        json_response(false, ['message' => 'Invalid cancer assessment focus selected'], 400);
    }

    // Verify patient exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->execute([$patient_id]);
    if (!$stmt->fetch()) {
        json_response(false, ['message' => 'Selected patient not found'], 404);
    }

    if (!isset($_FILES['scan_file']) || $_FILES['scan_file']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, ['message' => 'Please upload a valid medical scan image'], 400);
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    $max_size = 15728640;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($_FILES['scan_file']['tmp_name']);
    if (!in_array($mime_type, $allowed_types, true) || @getimagesize($_FILES['scan_file']['tmp_name']) === false) {
        json_response(false, ['message' => 'Invalid image file. Only valid JPEG and PNG medical images are allowed'], 400);
    }
    if ($_FILES['scan_file']['size'] > $max_size) {
        json_response(false, ['message' => 'File size exceeds 15MB limit'], 400);
    }

    $upload_dir = __DIR__ . '/../uploads/scans';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $extension = $mime_type === 'image/png' ? 'png' : 'jpg';
    $scan_filename = uniqid('scan_', true) . '.' . $extension;
    $scan_filepath = $upload_dir . DIRECTORY_SEPARATOR . $scan_filename;
    $relative_scan_path = 'uploads/scans/' . $scan_filename;

    if (!move_uploaded_file($_FILES['scan_file']['tmp_name'], $scan_filepath)) {
        json_response(false, ['message' => 'Failed to save uploaded scan file'], 500);
    }
    $savedScanFile = $scan_filepath;

    $clinical_risk_score = calculateClinicalRisk(
        $systolic_bp,
        $diastolic_bp,
        $glucose,
        $smoking,
        $family_history,
        $tumor_marker_cea,
        $tumor_marker_ca125
    );

    $ai_result = callPythonAIService($scan_filepath, $scan_type, $cancer_type, [
        'bp' => $systolic_bp,
        'glucose' => $glucose,
        'smoking' => $smoking,
        'tumor_marker_cea' => $tumor_marker_cea,
        'tumor_marker_ca125' => $tumor_marker_ca125
    ]);

    if (!$ai_result['success']) {
        if ($savedScanFile && is_file($savedScanFile)) {
            @unlink($savedScanFile);
        }
        $httpStatus = (int)($ai_result['http_code'] ?? 503);
        if ($httpStatus < 400 || $httpStatus >= 600) {
            $httpStatus = 503;
        }
        json_response(false, [
            'message' => $ai_result['message'] ?: t('ml_service_unavailable')
        ], $httpStatus);
    }

    $aiData = $ai_result['data'];
    $predicted_condition = (string)($aiData['condition'] ?? 'Unknown');
    $predicted_stage = substr((string)($aiData['classification_status'] ?? $aiData['stage'] ?? 'Clinical Review Required'), 0, 50);
    $confidence_score = round((float)($aiData['confidence'] ?? 0), 2);
    $recommended_actions = is_array($aiData['actions'] ?? null) ? $aiData['actions'] : [];

    $vitals_snapshot = json_encode([
        'systolic_bp' => $systolic_bp,
        'diastolic_bp' => $diastolic_bp,
        'glucose' => $glucose,
        'smoking' => $smoking,
        'family_history' => $family_history,
        'tumor_marker_cea' => $tumor_marker_cea,
        'tumor_marker_ca125' => $tumor_marker_ca125,
        'ml_assessment' => [
            'model_name' => $aiData['model_name'] ?? 'Multi-Cancer MobileNetV2 (Keras 24-Class)',
            'cancer_type' => $aiData['cancer_type'] ?? $cancer_type,
            'requested_cancer_type' => $aiData['requested_cancer_type'] ?? $cancer_type,
            'raw_class' => $aiData['raw_class'] ?? '',
            'is_benign' => (bool)($aiData['is_benign'] ?? false),
            'raw_softmax_confidence' => (float)($aiData['raw_softmax_confidence'] ?? $confidence_score),
            'overall_top_class' => $aiData['overall_top_class'] ?? '',
            'overall_top_label' => $aiData['overall_top_label'] ?? '',
            'overall_top_cancer_type' => $aiData['overall_top_cancer_type'] ?? '',
            'overall_top_confidence' => (float)($aiData['overall_top_confidence'] ?? $confidence_score),
            'top_predictions' => $aiData['top_predictions'] ?? [],
            'actions' => $recommended_actions,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("INSERT INTO diagnostic_records (patient_id, doctor_id, scan_type, scan_file_path, heatmap_file_path, predicted_condition, predicted_stage, confidence_score, clinical_risk_score, vitals_snapshot, status) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 'analyzed')");
    $stmt->execute([
        $patient_id,
        $doctor_id,
        $scan_type,
        $relative_scan_path,
        $predicted_condition,
        $predicted_stage,
        $confidence_score,
        $clinical_risk_score,
        $vitals_snapshot
    ]);

    $diagnostic_id = (int)$pdo->lastInsertId();

    createDiagnosticBill($pdo, $diagnostic_id, $patient_id, $doctor_id, $scan_type, date('Y-m-d'));

    logActivity($doctor_id, 'AI_DIAGNOSIS', "ML risk assessment #$diagnostic_id completed for patient #$patient_id: $predicted_condition ({$confidence_score}%)");

    json_response(true, [
        'diagnostic_id' => $diagnostic_id,
        'predicted_condition' => $predicted_condition,
        'predicted_stage' => $predicted_stage,
        'cancer_type' => $aiData['cancer_type'] ?? $cancer_type,
        'confidence_score' => $confidence_score,
        'clinical_risk_score' => $clinical_risk_score,
        'scan_path' => BASE_URL . '/includes/serve_scan.php?id=' . $diagnostic_id,
        'actions' => $recommended_actions,
        'recommended_actions' => $recommended_actions
    ], 200);

} catch (PDOException $e) {
    if ($savedScanFile && is_file($savedScanFile)) @unlink($savedScanFile);
    error_log("Process AI Error: " . $e->getMessage());
    json_response(false, ['message' => 'Database error occurred'], 500);
} catch (Exception $e) {
    if ($savedScanFile && is_file($savedScanFile)) @unlink($savedScanFile);
    error_log("Process AI Error: " . $e->getMessage());
    json_response(false, ['message' => 'An error occurred during processing'], 500);
}

function calculateClinicalRisk($systolic_bp, $diastolic_bp, $glucose, $smoking, $family_history, $cea, $ca125) {
    $risk = 0;
    if ($systolic_bp >= 140 || $diastolic_bp >= 90) $risk += 25;
    elseif ($systolic_bp >= 130 || $diastolic_bp >= 85) $risk += 15;
    elseif ($systolic_bp >= 120 || $diastolic_bp >= 80) $risk += 5;

    if ($glucose >= 126) $risk += 20;
    elseif ($glucose >= 100) $risk += 10;

    $risk += min(max($smoking, 0) * 2, 20);
    if ($family_history === 'Yes') $risk += 15;
    if ($cea > 5) $risk += 10;
    if ($ca125 > 35) $risk += 10;

    return min($risk, 100);
}

function callPythonAIService($scan_path, $scan_type, $cancer_type, $params) {
    $api_url = AI_SERVICE_URL . '/predict';
    if (!file_exists($scan_path)) {
        return ['success' => false, 'http_code' => 400, 'message' => 'Scan file not found'];
    }

    $mime = mime_content_type($scan_path) ?: 'image/jpeg';
    $cfile = new CURLFile($scan_path, $mime, basename($scan_path));
    $post_data = [
        'scan_file' => $cfile,
        'scan_type' => $scan_type,
        'cancer_type' => $cancer_type,
        'bp' => $params['bp'],
        'glucose' => $params['glucose'],
        'smoking' => $params['smoking'],
        'tumor_marker_cea' => $params['tumor_marker_cea'],
        'tumor_marker_ca125' => $params['tumor_marker_ca125']
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("ML Service curl error: " . $error);
        return ['success' => false, 'http_code' => 503, 'message' => t('ml_service_unavailable')];
    }

    $result = json_decode((string)$response, true);
    if ($http_code !== 200 || !$result || empty($result['success'])) {
        $detail = $result['detail'] ?? $result['message'] ?? t('ml_service_unavailable');
        return ['success' => false, 'http_code' => $http_code ?: 503, 'message' => $detail];
    }
    return $result;
}
