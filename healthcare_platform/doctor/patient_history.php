<?php
/**
 * Doctor Patient Clinical History View
 * Clean, authorized clinical history page accessible from My Patients list.
 * Displays previous consultations/checkups, appointments, prescriptions & medications,
 * risk assessments, and uploaded medical scans & reports from the database.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

$doctorId = getCurrentUserId();
$patientId = intval($_GET['id'] ?? 0);

if ($patientId <= 0) {
    header('Location: ' . BASE_URL . '/doctor/patients.php');
    exit;
}

$patient = null;
$appointments = [];
$prescriptions = [];
$diagnostics = [];
$error = '';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    if (!isDoctorAuthorizedForPatient($pdo, $doctorId, $patientId)) {
        http_response_code(403);
        die('Access denied: You are not authorized to view this patient history.');
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.created_at,
               pp.dob, pp.gender, pp.blood_group, pp.chronic_conditions, pp.contact_number
        FROM users u
        LEFT JOIN patient_profiles pp ON u.id = pp.user_id
        WHERE u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(404);
        die('Patient record not found.');
    }

    // Previous checkups & appointments
    $stmt = $pdo->prepare("
        SELECT a.*, d.name AS doctor_name, dp.specialization, dp.room_number
        FROM appointments a
        JOIN users d ON a.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute([$patientId]);
    $appointments = $stmt->fetchAll();

    // Previous prescriptions & prescribed medications
    $stmt = $pdo->prepare("
        SELECT pr.*, d.name AS doctor_name, dp.specialization, dr.predicted_condition
        FROM prescriptions_and_reminders pr
        JOIN users d ON pr.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        LEFT JOIN diagnostic_records dr ON pr.diagnostic_id = dr.id
        WHERE pr.patient_id = ?
        ORDER BY pr.created_at DESC
    ");
    $stmt->execute([$patientId]);
    $prescriptions = $stmt->fetchAll();

    // Previous risk assessments, vitals & reports
    $stmt = $pdo->prepare("
        SELECT dr.*, d.name AS doctor_name, dp.specialization
        FROM diagnostic_records dr
        JOIN users d ON dr.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE dr.patient_id = ?
        ORDER BY dr.created_at DESC
    ");
    $stmt->execute([$patientId]);
    $diagnostics = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Doctor Patient History Error: ' . $e->getMessage());
    $error = t('msg_error');
}

$age = !empty($patient['dob']) ? calculateAge($patient['dob']) : '--';
$page_title = ($patient['name'] ?? '') . ' — ' . t('doc_history_title');
$page_subtitle = t('doc_history_sub');
$active_nav = 'patients';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Top Navigation & Summary Actions -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <a href="<?php echo BASE_URL; ?>/doctor/patients.php" class="btn-modern btn-modern-outline btn-modern-sm">
        <i class="bi bi-arrow-left"></i> <?php echo e_t('nav_my_patients'); ?>
    </a>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$patient['id']; ?>&view_summary=1" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-eye"></i> <?php echo e_t('btn_view_summary'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$patient['id']; ?>&download=1" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-download"></i> <?php echo e_t('btn_download_summary'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/doctor/workstation.php?patient_id=<?php echo (int)$patient['id']; ?>" class="btn-modern btn-modern-primary btn-modern-sm">
            <i class="bi bi-activity"></i> <?php echo e_t('btn_new_risk'); ?>
        </a>
    </div>
</div>

<!-- Compact Patient Header & Clinical Baseline -->
<div class="card-modern p-4 mb-4">
    <div class="row g-3 align-items-center">
        <div class="col-lg-6">
            <div class="d-flex align-items-center gap-3">
                <div class="user-avatar-circle" style="width: 52px; height: 52px; font-size: 1.15rem;">
                    <?php echo strtoupper(substr($patient['name'], 0, 2)); ?>
                </div>
                <div>
                    <h2 class="h5 fw-bold text-slate-900 mb-1"><?php echo htmlspecialchars($patient['name']); ?></h2>
                    <div class="small text-muted">
                        <?php echo e_t('label_patient_id'); ?> #<?php echo (int)$patient['id']; ?> &bull;
                        <?php echo e_t('label_age'); ?>: <strong><?php echo $age !== '--' ? $age . ' ' . e_t('label_yrs') : 'N/A'; ?></strong> &bull;
                        <?php echo htmlspecialchars(translateStatus($patient['gender'] ?? 'Other')); ?> &bull;
                        <?php echo e_t('col_blood_group'); ?>: <strong class="text-danger"><?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A'); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="row g-2">
                <div class="col-sm-5">
                    <div class="profile-detail-item">
                        <div class="profile-detail-label"><?php echo e_t('label_contact'); ?></div>
                        <div class="profile-detail-value small"><?php echo htmlspecialchars($patient['contact_number'] ?: t('not_recorded')); ?></div>
                    </div>
                </div>
                <div class="col-sm-7">
                    <div class="profile-detail-item">
                        <div class="profile-detail-label"><?php echo e_t('label_update_chronic'); ?></div>
                        <div class="profile-detail-value small"><?php echo htmlspecialchars($patient['chronic_conditions'] ?: t('none_reported')); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History Metric Overview -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="kpi-card h-100">
            <div class="kpi-value"><?php echo count($appointments); ?></div>
            <div class="kpi-label"><?php echo e_t('hist_tab_appts'); ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="kpi-card h-100">
            <div class="kpi-value"><?php echo count($prescriptions); ?></div>
            <div class="kpi-label"><?php echo e_t('hist_tab_rx'); ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="kpi-card h-100">
            <div class="kpi-value"><?php echo count($diagnostics); ?></div>
            <div class="kpi-label"><?php echo e_t('hist_tab_risk'); ?></div>
        </div>
    </div>
</div>

<!-- Organized History Tabs -->
<ul class="nav nav-pills gap-2 mb-4" id="docHistoryTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-semibold small" id="dh-appts-tab" data-bs-toggle="pill" data-bs-target="#dh-appts" type="button" role="tab">
            <i class="bi bi-calendar2-check me-1"></i><?php echo e_t('hist_tab_appts'); ?> (<?php echo count($appointments); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold small" id="dh-rx-tab" data-bs-toggle="pill" data-bs-target="#dh-rx" type="button" role="tab">
            <i class="bi bi-prescription2 me-1"></i><?php echo e_t('hist_tab_rx'); ?> (<?php echo count($prescriptions); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold small" id="dh-risk-tab" data-bs-toggle="pill" data-bs-target="#dh-risk" type="button" role="tab">
            <i class="bi bi-clipboard2-pulse me-1"></i><?php echo e_t('hist_tab_risk'); ?> (<?php echo count($diagnostics); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold small" id="dh-records-tab" data-bs-toggle="pill" data-bs-target="#dh-records" type="button" role="tab">
            <i class="bi bi-file-earmark-medical me-1"></i><?php echo e_t('hist_tab_records'); ?> (<?php echo count($diagnostics); ?>)
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- Checkups & Appointments -->
    <div class="tab-pane fade show active" id="dh-appts" role="tabpanel">
        <div class="card-modern">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-calendar2-check text-primary me-2"></i><?php echo e_t('hist_tab_appts'); ?></h5>
            </div>
            <div class="card-body-modern p-0">
                <?php if (empty($appointments)): ?>
                    <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_appts'); ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table-modern">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th><?php echo e_t('col_schedule'); ?></th>
                                    <th><?php echo e_t('col_doctor'); ?></th>
                                    <th><?php echo e_t('col_notes'); ?></th>
                                    <th><?php echo e_t('label_status'); ?></th>
                                    <th class="text-end"><?php echo e_t('col_receipt'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $apt): ?>
                                    <?php $isConfirmed = in_array($apt['status'], ['confirmed', 'completed'], true); ?>
                                    <tr>
                                        <td class="fw-semibold small">#APT-<?php echo str_pad((string)$apt['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td class="small">
                                            <div class="fw-semibold"><?php echo formatDate($apt['appointment_date']); ?></div>
                                            <div class="extra-small text-muted"><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></div>
                                        </td>
                                        <td class="small">
                                            <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($apt['doctor_name']); ?></div>
                                            <div class="extra-small text-muted"><?php echo htmlspecialchars($apt['specialization'] ?? ''); ?></div>
                                        </td>
                                        <td class="small text-muted" style="max-width:260px;"><?php echo htmlspecialchars($apt['symptom_notes'] ?: '-'); ?></td>
                                        <td>
                                            <span class="badge-modern <?php echo $isConfirmed ? 'badge-modern-success' : ($apt['status'] === 'cancelled' ? 'badge-modern-danger' : 'badge-modern-warning'); ?>">
                                                <?php echo htmlspecialchars(translateStatus($apt['status'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($isConfirmed): ?>
                                                <div class="d-inline-flex gap-1">
                                                    <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('label_view'); ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                                        <i class="bi bi-download"></i> <?php echo e_t('btn_receipt'); ?>
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <span class="extra-small text-muted"><?php echo e_t('receipt_unavailable_short'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Prescriptions & Prescribed Medications -->
    <div class="tab-pane fade" id="dh-rx" role="tabpanel">
        <div class="card-modern">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-prescription2 text-success me-2"></i><?php echo e_t('hist_tab_rx'); ?></h5>
            </div>
            <div class="card-body-modern">
                <?php if (empty($prescriptions)): ?>
                    <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_rx'); ?></div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($prescriptions as $rx): ?>
                            <?php $meds = json_decode($rx['medication_list'] ?? '[]', true) ?: []; ?>
                            <div class="col-lg-6">
                                <div class="p-3 bg-slate-50 border border-slate-200 rounded-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2 border-bottom pb-2">
                                        <div>
                                            <div class="fw-bold small text-slate-900">Rx #<?php echo (int)$rx['id']; ?> &bull; <?php echo htmlspecialchars($rx['doctor_name']); ?></div>
                                            <div class="extra-small text-muted"><?php echo htmlspecialchars($rx['specialization'] ?? ''); ?><?php if (!empty($rx['predicted_condition'])) echo ' &bull; ' . htmlspecialchars($rx['predicted_condition']); ?></div>
                                        </div>
                                        <span class="badge-modern badge-modern-neutral"><?php echo formatDate($rx['created_at']); ?></span>
                                    </div>
                                    <table class="table table-sm table-bordered bg-white small mb-2">
                                        <thead><tr><th><?php echo e_t('col_medication'); ?></th><th><?php echo e_t('col_dosage'); ?></th><th><?php echo e_t('col_frequency'); ?></th><th><?php echo e_t('col_duration'); ?></th></tr></thead>
                                        <tbody>
                                            <?php foreach ($meds as $m): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars($m['drug_name'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($m['dosage'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($m['frequency'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($m['duration'] ?? ''); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php if (!empty($rx['clinical_remedies_advice'])): ?>
                                        <div class="extra-small text-slate-700 mb-1"><strong><?php echo e_t('label_clinical_advice'); ?>:</strong> <?php echo htmlspecialchars($rx['clinical_remedies_advice']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($rx['next_followup_date'])): ?>
                                        <div class="extra-small text-primary"><strong><?php echo e_t('label_followup_date'); ?>:</strong> <?php echo formatDate($rx['next_followup_date']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Risk Assessments -->
    <div class="tab-pane fade" id="dh-risk" role="tabpanel">
        <div class="card-modern">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-clipboard2-pulse text-primary me-2"></i><?php echo e_t('hist_tab_risk'); ?></h5>
            </div>
            <div class="card-body-modern">
                <?php if (empty($diagnostics)): ?>
                    <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_risk'); ?></div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($diagnostics as $diag): ?>
                            <?php $v = json_decode($diag['vitals_snapshot'] ?? '{}', true) ?: []; ?>
                            <div class="p-3 bg-slate-50 border border-slate-200 rounded-3">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge-modern <?php echo $diag['predicted_condition'] === 'Normal' ? 'badge-modern-success' : 'badge-modern-danger'; ?>">
                                            <?php echo htmlspecialchars($diag['predicted_condition']); ?>
                                        </span>
                                        <span class="badge-modern badge-modern-info"><?php echo htmlspecialchars($diag['scan_type']); ?></span>
                                        <span class="small fw-semibold"><?php echo e_t('result_stage'); ?>: <?php echo htmlspecialchars($diag['predicted_stage'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="extra-small text-muted"><?php echo formatDate($diag['created_at'], 'M d, Y h:i A'); ?> &bull; <?php echo htmlspecialchars($diag['doctor_name']); ?></div>
                                </div>
                                <div class="row g-2 small mb-2">
                                    <div class="col-6 col-sm-3"><span class="text-muted"><?php echo e_t('result_risk_score'); ?>:</span> <strong><?php echo number_format((float)$diag['clinical_risk_score'], 1); ?>%</strong></div>
                                    <div class="col-6 col-sm-3"><span class="text-muted"><?php echo e_t('result_confidence'); ?>:</span> <strong><?php echo number_format((float)$diag['confidence_score'], 1); ?>%</strong></div>
                                    <div class="col-6 col-sm-3"><span class="text-muted">BP:</span> <strong><?php echo htmlspecialchars((string)($v['systolic_bp'] ?? '--')); ?>/<?php echo htmlspecialchars((string)($v['diastolic_bp'] ?? '--')); ?></strong></div>
                                    <div class="col-6 col-sm-3"><span class="text-muted">Glucose:</span> <strong><?php echo htmlspecialchars((string)($v['glucose'] ?? '--')); ?> mg/dL</strong></div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="<?php echo BASE_URL; ?>/doctor/assessment_result.php?id=<?php echo (int)$diag['id']; ?>" class="btn-modern btn-modern-outline btn-modern-sm">
                                        <i class="bi bi-clipboard2-pulse"></i> <?php echo e_t('result_page_title'); ?>
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
                                        <i class="bi bi-eye"></i> <?php echo e_t('btn_view_report'); ?>
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                        <i class="bi bi-download"></i> <?php echo e_t('btn_download_report'); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Medical Scans & Reports -->
    <div class="tab-pane fade" id="dh-records" role="tabpanel">
        <div class="card-modern">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-file-earmark-medical text-info me-2"></i><?php echo e_t('hist_tab_records'); ?></h5>
            </div>
            <div class="card-body-modern">
                <?php if (empty($diagnostics)): ?>
                    <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_records'); ?></div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($diagnostics as $diag): ?>
                            <?php $scanUrl = getScanWebUrl($diag['id'], $diag['scan_file_path']); ?>
                            <div class="col-md-6">
                                <div class="p-3 border border-slate-200 rounded-3 bg-slate-50 h-100 d-flex gap-3">
                                    <div class="flex-shrink-0" style="width: 100px;">
                                        <?php if ($scanUrl): ?>
                                            <img src="<?php echo htmlspecialchars($scanUrl); ?>" alt="Medical Scan" class="img-fluid rounded border bg-dark" style="width:100px;height:100px;object-fit:cover;">
                                        <?php else: ?>
                                            <div class="rounded border bg-white d-flex flex-column align-items-center justify-content-center text-muted extra-small text-center p-2" style="width:100px;height:100px;">
                                                <i class="bi bi-image fs-4 mb-1"></i>
                                                <span><?php echo e_t('scan_unavailable'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                            <span class="badge-modern badge-modern-info"><?php echo htmlspecialchars($diag['scan_type']); ?></span>
                                            <span class="extra-small text-muted"><?php echo formatDate($diag['created_at']); ?></span>
                                        </div>
                                        <div class="small fw-bold text-slate-900 mb-1"><?php echo htmlspecialchars($diag['predicted_condition']); ?></div>
                                        <div class="extra-small text-muted mb-2"><?php echo htmlspecialchars($diag['doctor_name']); ?></div>
                                        <div class="d-flex gap-1">
                                            <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
                                                <i class="bi bi-eye"></i> <?php echo e_t('label_view'); ?>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                                <i class="bi bi-download"></i> <?php echo e_t('label_download'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
