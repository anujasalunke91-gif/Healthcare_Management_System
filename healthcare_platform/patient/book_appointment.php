<?php
/**
 * Book Appointment Handler (Patient)
 * Creates appointment record and associated consultation bill atomically.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, ['message' => 'Method not allowed'], 405);
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    json_response(false, ['message' => t('err_csrf')], 403);
}

try {
    $pdo = getDBConnection();

    $patient_id = getCurrentUserId();
    $posted_patient_id = intval($_POST['patient_id'] ?? $patient_id);
    $doctor_id = intval($_POST['doctor_id'] ?? 0);
    $appointment_date = sanitize($_POST['appointment_date'] ?? '');
    $appointment_time = sanitize($_POST['appointment_time'] ?? '');
    $symptom_notes = sanitize($_POST['symptom_notes'] ?? '');

    if ($posted_patient_id !== $patient_id) {
        json_response(false, ['message' => 'Unauthorized patient identifier'], 403);
    }

    if ($doctor_id <= 0 || empty($appointment_date) || empty($appointment_time)) {
        json_response(false, ['message' => 'Please select a doctor, date, and time'], 400);
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'doctor' LIMIT 1");
    $stmt->execute([$doctor_id]);
    if (!$stmt->fetch()) {
        json_response(false, ['message' => 'Invalid doctor selected'], 400);
    }

    $appointment_datetime = strtotime($appointment_date . ' ' . $appointment_time);
    if ($appointment_datetime === false || $appointment_datetime < time() - 300) {
        json_response(false, ['message' => 'Appointment date and time cannot be in the past'], 400);
    }

    $stmt = $pdo->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status != 'cancelled' LIMIT 1");
    $stmt->execute([$doctor_id, $appointment_date, $appointment_time]);
    if ($stmt->fetch()) {
        json_response(false, ['message' => 'This time slot is already booked with the selected doctor'], 400);
    }

    ensureBillingTableExists($pdo);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status, symptom_notes) VALUES (?, ?, ?, ?, 'pending', ?)");
    $stmt->execute([$patient_id, $doctor_id, $appointment_date, $appointment_time, $symptom_notes]);
    $appointment_id = (int)$pdo->lastInsertId();

    createAppointmentBill($pdo, $appointment_id, $patient_id, $doctor_id, $appointment_date);
    $pdo->commit();

    logActivity($patient_id, 'APPOINTMENT_BOOKED', "Appointment #$appointment_id booked with doctor #$doctor_id");

    json_response(true, [
        'appointment_id' => $appointment_id,
        'message' => t('msg_appt_booked')
    ], 200);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Book Appointment Error: " . $e->getMessage());
    json_response(false, ['message' => 'Database error occurred'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Book Appointment Error: " . $e->getMessage());
    json_response(false, ['message' => 'An error occurred'], 500);
}
