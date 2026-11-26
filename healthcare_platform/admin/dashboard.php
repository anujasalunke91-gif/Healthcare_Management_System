<?php
/**
 * Hospital Admin Dashboard (INR & Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['admin']);

function checkAIServiceHealth() {
    $api_url = AI_SERVICE_URL . '/health';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!$error && $http_code === 200) {
        $result = json_decode($response, true);
        if ($result && isset($result['status'])) {
            return [
                'status' => 'online',
                'message' => t('ai_engine_online'),
                'details' => t('ai_engine_online_desc')
            ];
        }
    }

    return [
        'status' => 'fallback',
        'message' => t('ai_engine_fallback'),
        'details' => t('ai_engine_fallback_desc')
    ];
}

$total_consultations = 0;
$total_appointments = 0;
$appt_status_counts = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
$role_counts = ['admin' => 0, 'doctor' => 0, 'patient' => 0];
$disease_distribution = [];
$doctors = [];
$patients = [];
$recent_diagnostics = [];
$error = '';

try {
    $pdo = getDBConnection();

    $total_consultations = (int)$pdo->query("SELECT COUNT(*) FROM diagnostic_records")->fetchColumn();
    $total_appointments = (int)$pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();

    $appts_by_status_raw = $pdo->query("SELECT status, COUNT(*) as count FROM appointments GROUP BY status")->fetchAll();
    foreach ($appts_by_status_raw as $stRow) {
        if (isset($appt_status_counts[$stRow['status']])) {
            $appt_status_counts[$stRow['status']] = (int)$stRow['count'];
        }
    }

    $users_by_role_raw = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role")->fetchAll();
    foreach ($users_by_role_raw as $r) {
        $role_counts[$r['role']] = (int)$r['count'];
    }

    $disease_distribution = $pdo->query("SELECT predicted_condition, COUNT(*) as count FROM diagnostic_records WHERE predicted_condition IS NOT NULL AND predicted_condition != 'Normal' GROUP BY predicted_condition ORDER BY count DESC")->fetchAll();

    $doctors = $pdo->query("SELECT u.id, u.name, u.email, u.created_at, dp.specialization, dp.room_number, dp.consultation_fee FROM users u LEFT JOIN doctor_profiles dp ON u.id = dp.user_id WHERE u.role = 'doctor' ORDER BY u.name ASC")->fetchAll();

    $patients = $pdo->query("SELECT u.id, u.name, u.email, u.created_at, pp.dob, pp.gender, pp.blood_group, pp.contact_number FROM users u LEFT JOIN patient_profiles pp ON u.id = pp.user_id WHERE u.role = 'patient' ORDER BY u.name ASC")->fetchAll();

    $recent_diagnostics = $pdo->query("SELECT dr.*, p.name as patient_name, d.name as doctor_name FROM diagnostic_records dr JOIN users p ON dr.patient_id = p.id JOIN users d ON dr.doctor_id = d.id ORDER BY dr.created_at DESC LIMIT 8")->fetchAll();

    $ai_service_status = checkAIServiceHealth();
} catch (PDOException $e) {
    error_log("Admin Dashboard Error: " . $e->getMessage());
    $error = t('msg_error');
    $ai_service_status = ['status' => 'fallback', 'message' => t('ai_engine_fallback'), 'details' => ''];
}

$page_title = t('admin_dash_title');
$page_subtitle = t('admin_dash_sub');
$active_nav = 'dashboard';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex align-items-center gap-2 px-3 py-2 bg-white border border-slate-200 rounded-3">
        <span class="status-dot status-dot-active"></span>
        <span class="small fw-semibold text-slate-800"><?php echo htmlspecialchars($ai_service_status['message']); ?></span>
        <span class="text-muted extra-small d-none d-md-inline">&bull; <?php echo htmlspecialchars($ai_service_status['details']); ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo BASE_URL; ?>/admin/manage_users.php" class="btn-modern btn-modern-primary btn-modern-sm">
            <i class="bi bi-person-plus"></i> <?php echo e_t('btn_manage_users'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/appointments.php" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-calendar-plus"></i> <?php echo e_t('nav_appointments'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/bills.php" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-receipt"></i> <?php echo e_t('nav_billing_invoices'); ?>
        </a>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-success"><i class="bi bi-person-heart"></i></div>
            <div class="kpi-value"><?php echo number_format($role_counts['patient']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_reg_patients'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-primary"><i class="bi bi-hospital"></i></div>
            <div class="kpi-value"><?php echo number_format($role_counts['doctor']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_active_doctors'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-warning"><i class="bi bi-calendar2-check"></i></div>
            <div class="kpi-value"><?php echo number_format($total_appointments); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_total_appts'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-accent"><i class="bi bi-clipboard2-pulse"></i></div>
            <div class="kpi-value"><?php echo number_format($total_consultations); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_risk_assessments'); ?></div>
        </div>
    </div>
</div>

<!-- Appointment Status Breakdown Strip -->
<div class="card-modern p-3 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="small fw-bold text-slate-800">
            <i class="bi bi-calendar2-week text-primary me-2"></i><?php echo e_t('appt_status_summary'); ?>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="<?php echo BASE_URL; ?>/admin/appointments.php" class="badge-modern badge-modern-warning text-decoration-none">
                <?php echo htmlspecialchars(translateStatus('pending')); ?>: <?php echo (int)$appt_status_counts['pending']; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/appointments.php" class="badge-modern badge-modern-success text-decoration-none">
                <?php echo htmlspecialchars(translateStatus('confirmed')); ?>: <?php echo (int)$appt_status_counts['confirmed']; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/appointments.php" class="badge-modern badge-modern-primary text-decoration-none">
                <?php echo htmlspecialchars(translateStatus('completed')); ?>: <?php echo (int)$appt_status_counts['completed']; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>/admin/appointments.php" class="badge-modern badge-modern-danger text-decoration-none">
                <?php echo htmlspecialchars(translateStatus('cancelled')); ?>: <?php echo (int)$appt_status_counts['cancelled']; ?>
            </a>
        </div>
    </div>
</div>

<!-- Analytics Charts -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card-modern h-100">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-pie-chart text-primary me-2"></i><?php echo e_t('chart_findings'); ?></h5>
                <span class="badge-modern badge-modern-neutral"><?php echo e_t('chart_clinical_records'); ?></span>
            </div>
            <div class="card-body-modern">
                <?php if (empty($disease_distribution)): ?>
                    <div class="text-center py-5 text-muted small">
                        <i class="bi bi-pie-chart fs-2 d-block mb-2 opacity-50"></i>
                        <?php echo e_t('chart_no_pathology'); ?>
                    </div>
                <?php else: ?>
                    <div style="height: 250px;"><canvas id="diseaseChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-modern h-100">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-bar-chart text-primary me-2"></i><?php echo e_t('chart_user_comp'); ?></h5>
                <span class="badge-modern badge-modern-primary"><?php echo e_t('chart_by_role'); ?></span>
            </div>
            <div class="card-body-modern">
                <div style="height: 250px;"><canvas id="userRoleChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<!-- Registered Doctors Directory -->
<div class="card-modern mb-4">
    <div class="card-header-modern">
        <div class="d-flex align-items-center gap-2">
            <h5 class="h6 fw-bold mb-0"><i class="bi bi-stethoscope text-primary me-2"></i><?php echo e_t('dir_doctors'); ?></h5>
            <span class="badge-modern badge-modern-primary"><?php echo e_t('badge_doctors', ['n' => count($doctors)]); ?></span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="input-group-modern" style="width: 220px;">
                <i class="bi bi-search input-icon"></i>
                <input type="text" id="adminDoctorSearch" class="form-control-modern form-control-sm" placeholder="<?php echo e_t('ph_search_doctors'); ?>" onkeyup="filterAdminDirectory('adminDoctorsTable', 'adminDoctorSearch')">
            </div>
            <label for="adminDoctorSort" class="extra-small text-muted mb-0"><?php echo e_t('label_sort_by'); ?></label>
            <select id="adminDoctorSort" class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('adminDoctorsTable', this)">
                <option value="0:text:asc"><?php echo e_t('sort_name_asc'); ?></option>
                <option value="0:text:desc"><?php echo e_t('sort_name_desc'); ?></option>
                <option value="2:text:asc"><?php echo e_t('sort_specialty'); ?></option>
                <option value="4:number:asc"><?php echo e_t('sort_fee_asc'); ?></option>
                <option value="4:number:desc"><?php echo e_t('sort_fee_desc'); ?></option>
            </select>
        </div>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($doctors)): ?>
            <div class="text-center py-4 text-muted small"><?php echo e_t('not_recorded'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="adminDoctorsTable">
                    <thead>
                        <tr>
                            <th class="sortable-th" onclick="sortTable('adminDoctorsTable', 0, 'text')"><?php echo e_t('col_physician_name'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminDoctorsTable', 1, 'text')"><?php echo e_t('label_email'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminDoctorsTable', 2, 'text')"><?php echo e_t('col_specialty'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminDoctorsTable', 3, 'text')"><?php echo e_t('col_room'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminDoctorsTable', 4, 'number')"><?php echo e_t('col_fee'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($doctors as $doc): ?>
                            <tr>
                                <td data-sort="<?php echo htmlspecialchars($doc['name']); ?>" class="fw-semibold text-slate-900">
                                    <?php echo htmlspecialchars($doc['name']); ?>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars($doc['email']); ?></td>
                                <td><span class="badge-modern badge-modern-primary"><?php echo htmlspecialchars($doc['specialization'] ?? 'General'); ?></span></td>
                                <td class="small"><?php echo htmlspecialchars($doc['room_number'] ?? 'N/A'); ?></td>
                                <td data-sort="<?php echo floatval($doc['consultation_fee'] ?? 0); ?>" class="fw-bold text-success tabular-nums">
                                    <?php echo formatCurrency($doc['consultation_fee'] ?? 0); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Registered Patients Directory -->
<div class="card-modern mb-4">
    <div class="card-header-modern">
        <div class="d-flex align-items-center gap-2">
            <h5 class="h6 fw-bold mb-0"><i class="bi bi-person-heart text-success me-2"></i><?php echo e_t('dir_patients'); ?></h5>
            <span class="badge-modern badge-modern-success"><?php echo e_t('badge_patients', ['n' => count($patients)]); ?></span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="input-group-modern" style="width: 220px;">
                <i class="bi bi-search input-icon"></i>
                <input type="text" id="adminPatientSearch" class="form-control-modern form-control-sm" placeholder="<?php echo e_t('ph_search_patients'); ?>" onkeyup="filterAdminDirectory('adminPatientsTable', 'adminPatientSearch')">
            </div>
            <label for="adminPatientSort" class="extra-small text-muted mb-0"><?php echo e_t('label_sort_by'); ?></label>
            <select id="adminPatientSort" class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('adminPatientsTable', this)">
                <option value="0:text:asc"><?php echo e_t('sort_name_asc'); ?></option>
                <option value="0:text:desc"><?php echo e_t('sort_name_desc'); ?></option>
                <option value="1:number:asc"><?php echo e_t('sort_age_asc'); ?></option>
                <option value="1:number:desc"><?php echo e_t('sort_age_desc'); ?></option>
                <option value="2:text:asc"><?php echo e_t('sort_blood'); ?></option>
                <option value="4:date:desc"><?php echo e_t('sort_newest'); ?></option>
                <option value="4:date:asc"><?php echo e_t('sort_oldest'); ?></option>
            </select>
        </div>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($patients)): ?>
            <div class="text-center py-4 text-muted small"><?php echo e_t('not_recorded'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="adminPatientsTable">
                    <thead>
                        <tr>
                            <th class="sortable-th" onclick="sortTable('adminPatientsTable', 0, 'text')"><?php echo e_t('col_patient_name'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminPatientsTable', 1, 'number')"><?php echo e_t('col_age_gender'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminPatientsTable', 2, 'text')"><?php echo e_t('col_blood_group'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('label_contact'); ?></th>
                            <th class="sortable-th" onclick="sortTable('adminPatientsTable', 4, 'date')"><?php echo e_t('col_registered'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="text-end"><?php echo e_t('col_profile'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $pat): ?>
                            <?php $age = !empty($pat['dob']) ? calculateAge($pat['dob']) : '--'; ?>
                            <tr>
                                <td data-sort="<?php echo htmlspecialchars($pat['name']); ?>">
                                    <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($pat['name']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($pat['email']); ?></div>
                                </td>
                                <td data-sort="<?php echo is_numeric($age) ? $age : 0; ?>" class="small">
                                    <?php echo $age !== '--' ? $age . ' ' . e_t('label_yrs') : '--'; ?> &bull; <?php echo htmlspecialchars(translateStatus($pat['gender'] ?? 'Other')); ?>
                                </td>
                                <td><span class="badge-modern badge-modern-danger"><?php echo htmlspecialchars($pat['blood_group'] ?? 'N/A'); ?></span></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($pat['contact_number'] ?? 'N/A'); ?></td>
                                <td data-sort="<?php echo htmlspecialchars($pat['created_at']); ?>" class="small text-muted">
                                    <?php echo formatDate($pat['created_at']); ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo $pat['id']; ?>" class="btn-modern btn-modern-outline btn-modern-sm">
                                        <i class="bi bi-person-vcard"></i> <?php echo e_t('btn_view_profile'); ?>
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

<!-- Recent Diagnostic Assessments -->
<div class="card-modern">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-clock-history text-info me-2"></i><?php echo e_t('recent_assessments'); ?></h5>
        <span class="badge-modern badge-modern-info"><?php echo e_t('badge_latest', ['n' => count($recent_diagnostics)]); ?></span>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($recent_diagnostics)): ?>
            <div class="text-center py-4 text-muted small"><?php echo e_t('hist_empty_risk'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th><?php echo e_t('label_date'); ?></th>
                            <th><?php echo e_t('col_patient'); ?></th>
                            <th><?php echo e_t('col_attending_doc'); ?></th>
                            <th><?php echo e_t('col_modality'); ?></th>
                            <th><?php echo e_t('col_finding'); ?></th>
                            <th><?php echo e_t('col_confidence'); ?></th>
                            <th class="text-end"><?php echo e_t('col_report'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_diagnostics as $diag): ?>
                            <tr>
                                <td class="small text-muted"><?php echo formatDate($diag['created_at'], 'M d, Y H:i'); ?></td>
                                <td class="fw-semibold text-slate-900">
                                    <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo $diag['patient_id']; ?>">
                                        <?php echo htmlspecialchars($diag['patient_name']); ?>
                                    </a>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($diag['doctor_name']); ?></td>
                                <td><span class="badge-modern badge-modern-neutral"><?php echo htmlspecialchars($diag['scan_type']); ?></span></td>
                                <td>
                                    <span class="badge-modern <?php echo $diag['predicted_condition'] === 'Normal' ? 'badge-modern-success' : 'badge-modern-danger'; ?>">
                                        <?php echo htmlspecialchars($diag['predicted_condition']); ?>
                                    </span>
                                </td>
                                <td class="fw-semibold text-primary tabular-nums"><?php echo number_format((float)$diag['confidence_score'], 1); ?>%</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo $diag['id']; ?>" class="btn-modern btn-modern-outline btn-modern-sm" target="_blank" title="<?php echo e_t('btn_view_report'); ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo $diag['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm" title="<?php echo e_t('btn_download_report'); ?>">
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

<?php
$diseaseLabels = json_encode(array_column($disease_distribution, 'predicted_condition'));
$diseaseCounts = json_encode(array_map('intval', array_column($disease_distribution, 'count')));
$roleLabels = json_encode([t('filter_patients'), t('filter_doctors'), t('filter_admins')], JSON_UNESCAPED_UNICODE);
$roleData = json_encode([$role_counts['patient'], $role_counts['doctor'], $role_counts['admin']]);

$extra_scripts = <<<HTML
<script>
function filterAdminDirectory(tableId, inputId) {
    const q = (document.getElementById(inputId)?.value || '').toLowerCase();
    document.querySelectorAll('#' + tableId + ' tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
document.addEventListener('DOMContentLoaded', function() {
    const diseaseCtx = document.getElementById('diseaseChart');
    if (diseaseCtx) {
        new Chart(diseaseCtx, {
            type: 'doughnut',
            data: {
                labels: {$diseaseLabels},
                datasets: [{
                    data: {$diseaseCounts},
                    backgroundColor: ['#dc2626', '#d97706', '#0284c7', '#0d9488', '#4f46e5'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                cutout: '64%'
            }
        });
    }

    const userCtx = document.getElementById('userRoleChart');
    if (userCtx) {
        new Chart(userCtx, {
            type: 'bar',
            data: {
                labels: {$roleLabels},
                datasets: [{
                    data: {$roleData},
                    backgroundColor: ['#059669', '#0284c7', '#4f46e5'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
