<?php
/**
 * Prescription Save Handler
 * Validates inputs, prevents duplicate submissions, saves prescription,
 * and returns redirect URL back to the Doctor's My Patients list.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, ['message' => 'Method not allowed'], 405);
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    json_response(false, ['message' => t('err_csrf')], 403);
}

try {
    $pdo = getDBConnection();

    $diagnostic_id = intval($_POST['diagnostic_id'] ?? 0);
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $doctor_id = getCurrentUserId();
    $clinical_advice = sanitize($_POST['clinical_advice'] ?? '');
    $followup_date = sanitize($_POST['followup_date'] ?? '');

    $medications = [];
    if (isset($_POST['medications']) && is_array($_POST['medications'])) {
        foreach ($_POST['medications'] as $med) {
            $drug = sanitize($med['drug_name'] ?? '');
            $dosage = sanitize($med['dosage'] ?? '');
            $freq = sanitize($med['frequency'] ?? '');
            $dur = sanitize($med['duration'] ?? '');
            if ($drug !== '' && $dosage !== '' && $freq !== '' && $dur !== '') {
                $medications[] = [
                    'drug_name' => $drug,
                    'dosage' => $dosage,
                    'frequency' => $freq,
                    'duration' => $dur
                ];
            }
        }
    }

    if ($diagnostic_id <= 0 || $patient_id <= 0 || $doctor_id <= 0 || empty($medications)) {
        json_response(false, ['message' => t('msg_complete_medication')], 400);
    }

    $stmt = $pdo->prepare("SELECT id, patient_id FROM diagnostic_records WHERE id = ? AND doctor_id = ? LIMIT 1");
    $stmt->execute([$diagnostic_id, $doctor_id]);
    $diagRow = $stmt->fetch();
    if (!$diagRow || intval($diagRow['patient_id']) !== $patient_id) {
        json_response(false, ['message' => t('msg_run_assessment_first')], 400);
    }

    $medJson = json_encode($medications, JSON_UNESCAPED_UNICODE);

    // Guard against duplicate submissions for the same diagnostic record & payload
    $stmt = $pdo->prepare("SELECT id, medication_list FROM prescriptions_and_reminders WHERE diagnostic_id = ? AND patient_id = ? AND doctor_id = ? AND created_at >= (NOW() - INTERVAL 15 SECOND) ORDER BY id DESC LIMIT 5");
    $stmt->execute([$diagnostic_id, $patient_id, $doctor_id]);
    while ($existing = $stmt->fetch()) {
        $existingMeds = json_decode($existing['medication_list'] ?? '[]', true);
        if (is_array($existingMeds) && count($existingMeds) === count($medications)) {
            $normExisting = array_map(function ($m) { if (is_array($m)) ksort($m); return $m; }, $existingMeds);
            $normCurrent = array_map(function ($m) { if (is_array($m)) ksort($m); return $m; }, $medications);
            if ($normExisting === $normCurrent) {
                json_response(true, [
                    'prescription_id' => (int)$existing['id'],
                    'message' => t('msg_rx_saved'),
                    'redirect_url' => BASE_URL . '/doctor/patients.php?rx_saved=1'
                ], 200);
            }
        }
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO prescriptions_and_reminders (diagnostic_id, patient_id, doctor_id, medication_list, clinical_remedies_advice, next_followup_date, reminder_status) VALUES (?, ?, ?, ?, ?, ?, 'scheduled')");
    $stmt->execute([
        $diagnostic_id,
        $patient_id,
        $doctor_id,
        $medJson,
        $clinical_advice,
        $followup_date ?: null
    ]);
    $prescription_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("UPDATE diagnostic_records SET status = 'reviewed_by_doctor' WHERE id = ?");
    $stmt->execute([$diagnostic_id]);
    $pdo->commit();

    logActivity($doctor_id, 'PRESCRIPTION_SAVED', "Prescription #$prescription_id saved for diagnostic #$diagnostic_id, patient #$patient_id");

    json_response(true, [
        'prescription_id' => $prescription_id,
        'message' => t('msg_rx_saved'),
        'redirect_url' => BASE_URL . '/doctor/patients.php?rx_saved=1'
    ], 200);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Save Prescription Error: " . $e->getMessage());
    json_response(false, ['message' => t('msg_rx_error')], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Save Prescription Error: " . $e->getMessage());
    json_response(false, ['message' => t('msg_rx_error')], 500);
}
