<?php
/**
 * Official Appointment Receipt Generator & Downloader (Confirmed Appointments Only, INR ₹, Multilingual)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient', 'doctor', 'admin']);

$userId = getCurrentUserId();
$role = getCurrentUserRole();
$curLang = getCurrentLang();
$appointmentId = intval($_GET['id'] ?? 0);
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

if ($appointmentId <= 0) {
    http_response_code(400);
    die('Invalid appointment reference ID.');
}

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    $stmt = $pdo->prepare("
        SELECT a.*,
               p.name AS patient_name, p.email AS patient_email,
               pp.dob, pp.gender, pp.blood_group, pp.contact_number,
               d.name AS doctor_name, d.email AS doctor_email,
               dp.specialization, dp.room_number, dp.consultation_fee,
               b.bill_number, b.amount AS bill_amount, b.payment_status
        FROM appointments a
        JOIN users p ON a.patient_id = p.id
        LEFT JOIN patient_profiles pp ON p.id = pp.user_id
        JOIN users d ON a.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        LEFT JOIN bills b ON b.appointment_id = a.id AND b.payment_status != 'cancelled'
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $apt = $stmt->fetch();

    if (!$apt) {
        http_response_code(404);
        die('Appointment record not found.');
    }

    if ($role === 'patient' && intval($apt['patient_id']) !== $userId) {
        http_response_code(403);
        die('Access denied to this appointment receipt.');
    }
    if ($role === 'doctor' && intval($apt['doctor_id']) !== $userId) {
        http_response_code(403);
        die('Access denied to this appointment receipt.');
    }
} catch (PDOException $e) {
    error_log('Appointment Receipt Error: ' . $e->getMessage());
    die(t('msg_error'));
}

$backUrl = $role === 'admin' ? BASE_URL . '/admin/appointments.php'
    : ($role === 'doctor' ? BASE_URL . '/doctor/appointments.php' : BASE_URL . '/patient/appointments.php');

// Enforce confirmation gate (Requirement 3)
$isConfirmed = in_array($apt['status'], ['confirmed', 'completed'], true);
if (!$isConfirmed) {
    http_response_code(403);
    $statusMsg = $apt['status'] === 'cancelled' ? t('receipt_blocked_cancelled') : t('receipt_blocked_pending');
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo htmlspecialchars($curLang); ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e_t('receipt_blocked_title'); ?> - <?php echo e_t('app_name'); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="card-modern p-4 text-center" style="max-width: 500px;">
            <div class="kpi-icon-wrap kpi-icon-warning mx-auto mb-3"><i class="bi bi-clock-history"></i></div>
            <h2 class="h5 fw-bold text-slate-900 mb-2"><?php echo e_t('receipt_blocked_title'); ?></h2>
            <p class="text-muted small mb-3"><?php echo htmlspecialchars($statusMsg); ?></p>
            <div class="mb-4">
                <span class="badge-modern <?php echo $apt['status'] === 'cancelled' ? 'badge-modern-danger' : 'badge-modern-warning'; ?>">
                    <?php echo e_t('label_status'); ?>: <?php echo htmlspecialchars(translateStatus($apt['status'])); ?>
                </span>
            </div>
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-modern btn-modern-primary">
                <i class="bi bi-arrow-left"></i> <?php echo e_t('nav_back'); ?>
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$receiptRef = 'APT-' . str_pad((string)$apt['id'], 6, '0', STR_PAD_LEFT);
$fee = $apt['bill_amount'] !== null ? (float)$apt['bill_amount'] : (float)($apt['consultation_fee'] ?? 500.00);

if ($isDownload) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="Appointment_Receipt_' . $receiptRef . '.html"');
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($curLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e_t('receipt_title'); ?> #<?php echo $receiptRef; ?> - <?php echo e_t('app_name'); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
        body { background: #f4f7fb; font-family: 'Inter', 'Noto Sans Devanagari', 'Segoe UI', Arial, sans-serif; color: #1e293b; margin: 0; }
        .doc-toolbar { background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .receipt-sheet { max-width: 820px; margin: 28px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 40px; box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
        .sheet-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0284c7; padding-bottom: 20px; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; }
        .brand-title { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; }
        .brand-sub { font-size: 13px; color: #64748b; margin-top: 4px; }
        .ref-box { text-align: right; }
        .ref-badge { display: inline-block; background: #d1fae5; color: #047857; font-weight: 700; font-size: 12px; padding: 4px 12px; border-radius: 999px; margin-bottom: 6px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; font-size: 13px; line-height: 1.65; }
        .info-box h4 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #0284c7; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 14px; }
        th, td { border: 1px solid #e2e8f0; padding: 11px 14px; text-align: left; }
        th { background: #f8fafc; font-size: 12px; text-transform: uppercase; color: #475569; }
        .total-row td { font-weight: 700; background: #f8fafc; font-size: 15px; }
        .sheet-footer { border-top: 1px solid #e2e8f0; padding-top: 16px; font-size: 12px; color: #64748b; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .btn-action { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid #cbd5e1; cursor: pointer; background: #fff; color: #334155; }
        .btn-primary-action { background: #0284c7; border-color: #0284c7; color: #fff; }
        @media (max-width: 640px) { .grid-2 { grid-template-columns: 1fr; } .receipt-sheet { margin: 12px; padding: 20px; } }
        @media print { body { background: #fff; } .no-print { display: none !important; } .receipt-sheet { margin: 0; border: none; box-shadow: none; padding: 20px; } }
    </style>
</head>
<body>

<?php if (!$isDownload): ?>
<div class="doc-toolbar no-print">
    <div style="font-weight:700; color:#0f172a;"><?php echo e_t('receipt_title'); ?> #<?php echo $receiptRef; ?></div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn-action btn-primary-action" onclick="window.print()"><?php echo e_t('label_print_pdf'); ?></button>
        <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>&download=1" class="btn-action"><?php echo e_t('label_download'); ?></a>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-action"><?php echo e_t('nav_back'); ?></a>
    </div>
</div>
<?php endif; ?>

<div class="receipt-sheet">
    <div class="sheet-header">
        <div>
            <h1 class="brand-title"><?php echo e_t('app_name'); ?></h1>
            <div class="brand-sub"><?php echo e_t('receipt_outpatient'); ?></div>
            <div class="brand-sub"><?php echo e_t('receipt_official_sub'); ?></div>
        </div>
        <div class="ref-box">
            <div class="ref-badge"><?php echo e_t('receipt_badge'); ?></div>
            <div style="font-weight:700; font-size:14px;">#<?php echo $receiptRef; ?></div>
            <div style="font-size:12px; color:#64748b;"><?php echo e_t('receipt_issued'); ?>: <?php echo formatDate($apt['created_at'], 'F d, Y'); ?></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="info-box">
            <h4><?php echo e_t('receipt_patient_details'); ?></h4>
            <div style="font-weight:700; font-size:15px; color:#0f172a;"><?php echo htmlspecialchars($apt['patient_name']); ?></div>
            <div><?php echo e_t('label_patient_id'); ?>: #<?php echo (int)$apt['patient_id']; ?></div>
            <div><?php echo e_t('label_email'); ?>: <?php echo htmlspecialchars($apt['patient_email']); ?></div>
            <div><?php echo e_t('label_contact'); ?>: <?php echo htmlspecialchars($apt['contact_number'] ?? 'N/A'); ?></div>
            <div><?php echo e_t('col_blood_group'); ?>: <?php echo htmlspecialchars($apt['blood_group'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-box">
            <h4><?php echo e_t('receipt_attending_doc'); ?></h4>
            <div style="font-weight:700; font-size:15px; color:#0f172a;"><?php echo htmlspecialchars($apt['doctor_name']); ?></div>
            <div><?php echo e_t('col_specialty'); ?>: <?php echo htmlspecialchars($apt['specialization'] ?? ''); ?></div>
            <div><?php echo e_t('col_room'); ?>: <?php echo htmlspecialchars($apt['room_number'] ?? 'TBA'); ?></div>
            <div><?php echo e_t('label_email'); ?>: <?php echo htmlspecialchars($apt['doctor_email']); ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th><?php echo e_t('receipt_appt_date'); ?></th>
                <th><?php echo e_t('receipt_sched_time'); ?></th>
                <th><?php echo e_t('receipt_appt_status'); ?></th>
                <th><?php echo e_t('receipt_bill_ref'); ?></th>
                <th><?php echo e_t('receipt_pay_status'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo formatDate($apt['appointment_date'], 'F d, Y'); ?></td>
                <td><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></td>
                <td><strong><?php echo htmlspecialchars(translateStatus($apt['status'])); ?></strong></td>
                <td><?php echo htmlspecialchars($apt['bill_number'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars(translateStatus($apt['payment_status'] ?? 'unpaid')); ?></td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th><?php echo e_t('receipt_service_desc'); ?></th>
                <th><?php echo e_t('receipt_clinical_notes'); ?></th>
                <th style="text-align:right;"><?php echo e_t('receipt_fee'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo htmlspecialchars($apt['specialization'] ?? 'Consultation'); ?></td>
                <td><?php echo htmlspecialchars($apt['symptom_notes'] ?: '-'); ?></td>
                <td style="text-align:right;"><?php echo formatCurrency($fee); ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="2" style="text-align:right;"><?php echo e_t('receipt_total_charge'); ?></td>
                <td style="text-align:right;"><?php echo formatCurrency($fee); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="sheet-footer">
        <div><?php echo e_t('receipt_arrive_note'); ?></div>
        <div><?php echo strtoupper(substr(hash('sha256', $receiptRef . $apt['created_at']), 0, 16)); ?></div>
    </div>
</div>

</body>
</html>
