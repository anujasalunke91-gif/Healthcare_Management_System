<?php
/**
 * Doctor Dashboard (Clickable KPI Cards, INR, Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

$doctorId = getCurrentUserId();
$doctorProfile = null;
$patients = [];
$appointments = [];
$recentDiagnostics = [];
$billCount = 0;
$error = '';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    $stmt = $pdo->prepare("SELECT dp.*, u.name FROM doctor_profiles dp JOIN users u ON dp.user_id = u.id WHERE dp.user_id = ? LIMIT 1");
    $stmt->execute([$doctorId]);
    $doctorProfile = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, pp.dob, pp.gender, pp.blood_group, pp.chronic_conditions, pp.contact_number,
               (SELECT COUNT(*) FROM appointments a2 WHERE a2.patient_id = u.id AND a2.doctor_id = ?) AS total_appointments,
               (SELECT COUNT(*) FROM diagnostic_records dr2 WHERE dr2.patient_id = u.id AND dr2.doctor_id = ?) AS total_assessments,
               (SELECT MAX(COALESCE(a3.appointment_date, DATE(dr3.created_at)))
                FROM users u2
                LEFT JOIN appointments a3 ON a3.patient_id = u2.id AND a3.doctor_id = ?
                LEFT JOIN diagnostic_records dr3 ON dr3.patient_id = u2.id AND dr3.doctor_id = ?
                WHERE u2.id = u.id) AS last_interaction,
               (SELECT dr4.clinical_risk_score FROM diagnostic_records dr4 WHERE dr4.patient_id = u.id AND dr4.doctor_id = ? ORDER BY dr4.created_at DESC LIMIT 1) AS latest_risk_score
        FROM users u
        LEFT JOIN patient_profiles pp ON u.id = pp.user_id
        WHERE u.role = 'patient'
          AND (
              EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = u.id AND a.doctor_id = ?)
              OR EXISTS (SELECT 1 FROM diagnostic_records dr WHERE dr.patient_id = u.id AND dr.doctor_id = ?)
              OR EXISTS (SELECT 1 FROM prescriptions_and_reminders pr WHERE pr.patient_id = u.id AND pr.doctor_id = ?)
              OR EXISTS (SELECT 1 FROM bills b WHERE b.patient_id = u.id AND b.doctor_id = ?)
          )
        ORDER BY u.name ASC
    ");
    $stmt->execute([$doctorId, $doctorId, $doctorId, $doctorId, $doctorId, $doctorId, $doctorId, $doctorId, $doctorId]);
    $patients = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT a.*, p.name AS patient_name
        FROM appointments a
        JOIN users p ON a.patient_id = p.id
        WHERE a.doctor_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 6
    ");
    $stmt->execute([$doctorId]);
    $appointments = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT dr.*, p.name AS patient_name
        FROM diagnostic_records dr
        JOIN users p ON dr.patient_id = p.id
        WHERE dr.doctor_id = ?
        ORDER BY dr.created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$doctorId]);
    $recentDiagnostics = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bills WHERE doctor_id = ?");
    $stmt->execute([$doctorId]);
    $billCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Doctor Dashboard Error: " . $e->getMessage());
    $error = t('msg_error');
}

$page_title = t('doc_dash_title');
$page_subtitle = ($doctorProfile['specialization'] ?? t('role_doctor')) . (!empty($doctorProfile['room_number']) ? ' • ' . $doctorProfile['room_number'] : '');
$active_nav = 'dashboard';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <span class="badge-modern badge-modern-primary">
            <i class="bi bi-hospital"></i> <?php echo htmlspecialchars($doctorProfile['specialization'] ?? t('role_doctor')); ?>
        </span>
        <?php if (!empty($doctorProfile['consultation_fee'])): ?>
            <span class="badge-modern badge-modern-neutral ms-1">
                <?php echo e_t('col_fee'); ?>: <?php echo formatCurrency($doctorProfile['consultation_fee']); ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo BASE_URL; ?>/doctor/workstation.php" class="btn-modern btn-modern-primary btn-modern-sm">
            <i class="bi bi-activity"></i> <?php echo e_t('btn_new_risk'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/doctor/appointments.php" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_manage_appts'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/doctor/bills.php" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-receipt"></i> <?php echo e_t('btn_view_bills'); ?>
        </a>
    </div>
</div>

<!-- Clickable KPI Cards (Requirement 4) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <a href="<?php echo BASE_URL; ?>/doctor/patients.php" class="kpi-card kpi-card-interactive d-block h-100">
            <div class="kpi-icon-wrap kpi-icon-primary"><i class="bi bi-people"></i></div>
            <div class="kpi-value"><?php echo count($patients); ?></div>
            <div class="kpi-label"><?php echo e_t('doc_kpi_patients'); ?></div>
            <span class="kpi-cta"><?php echo e_t('doc_kpi_patients_cta'); ?> <i class="bi bi-arrow-right"></i></span>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?php echo BASE_URL; ?>/doctor/appointments.php" class="kpi-card kpi-card-interactive d-block h-100">
            <div class="kpi-icon-wrap kpi-icon-success"><i class="bi bi-calendar2-check"></i></div>
            <div class="kpi-value"><?php echo count($appointments); ?></div>
            <div class="kpi-label"><?php echo e_t('doc_kpi_appts'); ?></div>
            <span class="kpi-cta"><?php echo e_t('doc_kpi_appts_cta'); ?> <i class="bi bi-arrow-right"></i></span>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?php echo BASE_URL; ?>/doctor/workstation.php" class="kpi-card kpi-card-interactive d-block h-100">
            <div class="kpi-icon-wrap kpi-icon-accent"><i class="bi bi-clipboard2-pulse"></i></div>
            <div class="kpi-value"><?php echo count($recentDiagnostics); ?></div>
            <div class="kpi-label"><?php echo e_t('doc_kpi_assessments'); ?></div>
            <span class="kpi-cta"><?php echo e_t('doc_kpi_assessments_cta'); ?> <i class="bi bi-arrow-right"></i></span>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a href="<?php echo BASE_URL; ?>/doctor/bills.php" class="kpi-card kpi-card-interactive d-block h-100">
            <div class="kpi-icon-wrap kpi-icon-warning"><i class="bi bi-receipt"></i></div>
            <div class="kpi-value"><?php echo $billCount; ?></div>
            <div class="kpi-label"><?php echo e_t('doc_kpi_bills'); ?></div>
            <span class="kpi-cta"><?php echo e_t('doc_kpi_bills_cta'); ?> <i class="bi bi-arrow-right"></i></span>
        </a>
    </div>
</div>

<!-- My Patients Overview Table -->
<div class="card-modern mb-4">
    <div class="card-header-modern">
        <div class="d-flex align-items-center gap-2">
            <h5 class="h6 fw-bold mb-0"><i class="bi bi-person-lines-fill text-primary me-2"></i><?php echo e_t('doc_patients_title'); ?></h5>
            <span class="badge-modern badge-modern-primary"><?php echo e_t('doc_assigned_badge', ['n' => count($patients)]); ?></span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="input-group-modern" style="width: 220px;">
                <i class="bi bi-search input-icon"></i>
                <input type="text" id="docPatientSearch" class="form-control-modern form-control-sm" placeholder="<?php echo e_t('ph_filter_patients'); ?>" onkeyup="filterDoctorPatients()">
            </div>
            <select class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('doctorPatientsTable', this)">
                <option value="0:text:asc"><?php echo e_t('sort_name_asc'); ?></option>
                <option value="0:text:desc"><?php echo e_t('sort_name_desc'); ?></option>
                <option value="1:number:asc"><?php echo e_t('sort_age_asc'); ?></option>
                <option value="1:number:desc"><?php echo e_t('sort_age_desc'); ?></option>
                <option value="2:text:asc"><?php echo e_t('sort_blood'); ?></option>
                <option value="4:date:desc"><?php echo e_t('sort_newest'); ?></option>
            </select>
            <a href="<?php echo BASE_URL; ?>/doctor/patients.php" class="small fw-semibold ms-1"><?php echo e_t('btn_view_all'); ?> &rarr;</a>
        </div>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($patients)): ?>
            <div class="text-center py-5">
                <div class="dropzone-icon-wrap bg-slate-100 text-slate-400 mb-2">
                    <i class="bi bi-people"></i>
                </div>
                <h6 class="fw-bold text-slate-800"><?php echo e_t('empty_doc_patients_title'); ?></h6>
                <p class="text-muted small mb-3"><?php echo e_t('empty_doc_patients_desc'); ?></p>
                <a href="<?php echo BASE_URL; ?>/doctor/appointments.php" class="btn-modern btn-modern-primary btn-modern-sm">
                    <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_schedule_appt'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="doctorPatientsTable">
                    <thead>
                        <tr>
                            <th class="sortable-th" onclick="sortTable('doctorPatientsTable', 0, 'text')"><?php echo e_t('col_patient'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('doctorPatientsTable', 1, 'number')"><?php echo e_t('col_age_gender'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('doctorPatientsTable', 2, 'text')"><?php echo e_t('col_blood_group'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('col_visits_assessments'); ?></th>
                            <th class="sortable-th" onclick="sortTable('doctorPatientsTable', 4, 'date')"><?php echo e_t('col_last_activity'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="text-end"><?php echo e_t('label_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $pat): ?>
                            <?php $age = !empty($pat['dob']) ? calculateAge($pat['dob']) : '--'; ?>
                            <tr class="doc-patient-row">
                                <td data-sort="<?php echo htmlspecialchars($pat['name']); ?>">
                                    <a href="<?php echo BASE_URL; ?>/doctor/patient_history.php?id=<?php echo (int)$pat['id']; ?>" class="fw-semibold text-slate-900">
                                        <?php echo htmlspecialchars($pat['name']); ?>
                                    </a>
                                    <div class="extra-small text-muted"><?php echo e_t('label_patient_id'); ?> #<?php echo (int)$pat['id']; ?></div>
                                </td>
                                <td data-sort="<?php echo is_numeric($age) ? $age : 0; ?>" class="small">
                                    <?php echo $age !== '--' ? $age . ' ' . e_t('label_yrs') : '--'; ?> &bull; <?php echo htmlspecialchars(translateStatus($pat['gender'] ?? 'Other')); ?>
                                </td>
                                <td data-sort="<?php echo htmlspecialchars($pat['blood_group'] ?? ''); ?>">
                                    <span class="badge-modern badge-modern-danger"><?php echo htmlspecialchars($pat['blood_group'] ?? 'N/A'); ?></span>
                                </td>
                                <td class="small text-muted">
                                    <?php echo (int)$pat['total_appointments']; ?> <?php echo e_t('hist_tab_appts'); ?> &bull; <?php echo (int)$pat['total_assessments']; ?> <?php echo e_t('hist_tab_risk'); ?>
                                </td>
                                <td data-sort="<?php echo htmlspecialchars($pat['last_interaction'] ?? ''); ?>" class="small text-muted">
                                    <?php echo $pat['last_interaction'] ? formatDate($pat['last_interaction']) : '--'; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/doctor/patient_history.php?id=<?php echo (int)$pat['id']; ?>" class="btn-modern btn-modern-primary btn-modern-sm">
                                        <i class="bi bi-clock-history"></i> <?php echo e_t('btn_view_history'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Appointments & Recent Assessments Split -->
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card-modern h-100">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-calendar2-check text-primary me-2"></i><?php echo e_t('nav_appointments'); ?></h5>
                <a href="<?php echo BASE_URL; ?>/doctor/appointments.php" class="small fw-semibold"><?php echo e_t('btn_view_all'); ?> &rarr;</a>
            </div>
            <div class="card-body-modern p-0">
                <?php if (empty($appointments)): ?>
                    <div class="text-center py-4 text-muted small"><?php echo e_t('hist_empty_appts'); ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table-modern">
                            <thead>
                                <tr>
                                    <th><?php echo e_t('col_patient'); ?></th>
                                    <th><?php echo e_t('col_schedule'); ?></th>
                                    <th><?php echo e_t('label_status'); ?></th>
                                    <th class="text-end"><?php echo e_t('col_receipt'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $apt): ?>
                                    <?php $isConfirmed = in_array($apt['status'], ['confirmed', 'completed'], true); ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$apt['patient_id']; ?>" class="fw-semibold">
                                                <?php echo htmlspecialchars($apt['patient_name']); ?>
                                            </a>
                                        </td>
                                        <td class="small">
                                            <?php echo formatDate($apt['appointment_date']); ?> &bull; <?php echo date('h:i A', strtotime($apt['appointment_time'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge-modern <?php echo $isConfirmed ? 'badge-modern-success' : ($apt['status'] === 'cancelled' ? 'badge-modern-danger' : 'badge-modern-warning'); ?>">
                                                <?php echo htmlspecialchars(translateStatus($apt['status'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($isConfirmed): ?>
                                                <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>&download=1" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('btn_receipt'); ?>">
                                                    <i class="bi bi-download"></i>
                                                </a>
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

    <div class="col-lg-6">
        <div class="card-modern h-100">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-clipboard2-pulse text-success me-2"></i><?php echo e_t('recent_assessments'); ?></h5>
                <a href="<?php echo BASE_URL; ?>/doctor/workstation.php" class="small fw-semibold"><?php echo e_t('btn_new_risk'); ?> &rarr;</a>
            </div>
            <div class="card-body-modern p-0">
                <?php if (empty($recentDiagnostics)): ?>
                    <div class="text-center py-4 text-muted small"><?php echo e_t('hist_empty_risk'); ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table-modern">
                            <thead>
                                <tr>
                                    <th><?php echo e_t('col_patient'); ?></th>
                                    <th><?php echo e_t('col_modality'); ?></th>
                                    <th><?php echo e_t('col_finding'); ?></th>
                                    <th class="text-end"><?php echo e_t('col_report'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentDiagnostics as $diag): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$diag['patient_id']; ?>" class="fw-semibold">
                                                <?php echo htmlspecialchars($diag['patient_name']); ?>
                                            </a>
                                            <div class="extra-small text-muted"><?php echo formatDate($diag['created_at']); ?></div>
                                        </td>
                                        <td><span class="badge-modern badge-modern-neutral"><?php echo htmlspecialchars($diag['scan_type']); ?></span></td>
                                        <td>
                                            <span class="badge-modern <?php echo $diag['predicted_condition'] === 'Normal' ? 'badge-modern-success' : 'badge-modern-danger'; ?>">
                                                <?php echo htmlspecialchars($diag['predicted_condition']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <a href="<?php echo BASE_URL; ?>/doctor/assessment_result.php?id=<?php echo (int)$diag['id']; ?>" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('label_view'); ?>">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm" title="<?php echo e_t('label_download'); ?>">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
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
</div>

<?php
$extra_scripts = <<<HTML
<script>
function filterDoctorPatients() {
    const q = (document.getElementById('docPatientSearch').value || '').toLowerCase();
    document.querySelectorAll('#doctorPatientsTable tbody tr.doc-patient-row').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
