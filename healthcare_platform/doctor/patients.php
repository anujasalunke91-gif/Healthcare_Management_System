<?php
/**
 * Dedicated Doctor My Patients Page
 * Destination for Doctor Dashboard "My Patients" card and Prescription Save redirect.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

$doctorId = getCurrentUserId();
$patients = [];
$error = '';
$rxSaved = isset($_GET['rx_saved']) && $_GET['rx_saved'] === '1';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

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
} catch (PDOException $e) {
    error_log("Doctor Patients Error: " . $e->getMessage());
    $error = t('msg_error');
}

$page_title = t('doc_patients_title');
$page_subtitle = t('doc_patients_sub');
$active_nav = 'patients';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($rxSaved): ?>
    <div class="alert alert-success border-0 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div class="small fw-semibold"><?php echo e_t('rx_saved_banner'); ?></div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="input-group-modern" style="max-width: 360px; flex: 1;">
        <i class="bi bi-search input-icon"></i>
        <input type="text" id="patientsSearchInput" class="form-control-modern" placeholder="<?php echo e_t('ph_filter_patients'); ?>" onkeyup="filterPatientsList()">
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>/doctor/workstation.php" class="btn-modern btn-modern-primary btn-modern-sm">
            <i class="bi bi-activity"></i> <?php echo e_t('btn_new_risk'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/doctor/appointments.php" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_manage_appts'); ?>
        </a>
    </div>
</div>

<div class="card-modern">
    <div class="card-header-modern">
        <div class="d-flex align-items-center gap-2">
            <h5 class="h6 fw-bold mb-0"><i class="bi bi-people text-primary me-2"></i><?php echo e_t('doc_patients_title'); ?></h5>
            <span class="badge-modern badge-modern-primary"><?php echo e_t('doc_assigned_badge', ['n' => count($patients)]); ?></span>
        </div>
        <select class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('myPatientsTable', this)">
            <option value="0:text:asc"><?php echo e_t('sort_name_asc'); ?></option>
            <option value="0:text:desc"><?php echo e_t('sort_name_desc'); ?></option>
            <option value="1:number:asc"><?php echo e_t('sort_age_asc'); ?></option>
            <option value="1:number:desc"><?php echo e_t('sort_age_desc'); ?></option>
            <option value="2:text:asc"><?php echo e_t('sort_blood'); ?></option>
            <option value="4:date:desc"><?php echo e_t('sort_newest'); ?></option>
        </select>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($patients)): ?>
            <div class="text-center py-5">
                <div class="dropzone-icon-wrap bg-slate-100 text-slate-400 mb-2"><i class="bi bi-people"></i></div>
                <h6 class="fw-bold text-slate-800"><?php echo e_t('empty_doc_patients_title'); ?></h6>
                <p class="text-muted small mb-3"><?php echo e_t('empty_doc_patients_desc'); ?></p>
                <a href="<?php echo BASE_URL; ?>/doctor/appointments.php" class="btn-modern btn-modern-primary btn-modern-sm">
                    <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_schedule_appt'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="myPatientsTable">
                    <thead>
                        <tr>
                            <th class="sortable-th" onclick="sortTable('myPatientsTable', 0, 'text')"><?php echo e_t('col_patient'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('myPatientsTable', 1, 'number')"><?php echo e_t('col_age_gender'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('myPatientsTable', 2, 'text')"><?php echo e_t('col_blood_group'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('col_visits_assessments'); ?></th>
                            <th class="sortable-th" onclick="sortTable('myPatientsTable', 4, 'date')"><?php echo e_t('col_last_activity'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="text-end"><?php echo e_t('label_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $pat): ?>
                            <?php $age = !empty($pat['dob']) ? calculateAge($pat['dob']) : '--'; ?>
                            <tr class="patient-list-row">
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

<?php
$extra_scripts = <<<HTML
<script>
function filterPatientsList() {
    const q = (document.getElementById('patientsSearchInput').value || '').toLowerCase();
    document.querySelectorAll('#myPatientsTable tbody tr.patient-list-row').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
