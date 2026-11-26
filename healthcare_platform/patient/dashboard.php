<?php
/**
 * Patient Care Portal Dashboard
 * Features: INR Currency (₹), Confirmed-Only Receipts, Total Appointment Amount Card, Multilingual EN/MR/HI
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient']);

$patient_id = getCurrentUserId();
$patient_name = $_SESSION['user_name'] ?? 'Patient';
$patient_profile = null;
$next_appointment = null;
$appointments = [];
$prescriptions = [];
$diagnostics = [];
$doctors = [];
$bills = [];
$totalExpenses = 0.0;
$unpaidExpenses = 0.0;
$totalAppointmentAmount = 0.0;
$activeAppointmentCount = 0;
$error = '';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    $stmt = $pdo->prepare("SELECT pp.*, u.name as patient_name, u.email FROM users u LEFT JOIN patient_profiles pp ON pp.user_id = u.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$patient_id]);
    $patient_profile = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT a.*, d.name as doctor_name, dp.specialization, dp.room_number
        FROM appointments a
        JOIN users d ON a.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE a.patient_id = ? AND a.status IN ('pending', 'confirmed') AND a.appointment_date >= CURDATE()
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $next_appointment = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT a.*, d.name as doctor_name, dp.specialization, dp.room_number, dp.consultation_fee
        FROM appointments a
        JOIN users d ON a.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 5
    ");
    $stmt->execute([$patient_id]);
    $appointments = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT pr.*, dr.predicted_condition, d.name as doctor_name
        FROM prescriptions_and_reminders pr
        JOIN diagnostic_records dr ON pr.diagnostic_id = dr.id
        JOIN users d ON dr.doctor_id = d.id
        WHERE pr.patient_id = ?
        ORDER BY pr.created_at DESC
        LIMIT 4
    ");
    $stmt->execute([$patient_id]);
    $prescriptions = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT dr.*, d.name as doctor_name, dp.specialization
        FROM diagnostic_records dr
        JOIN users d ON dr.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE dr.patient_id = ?
        ORDER BY dr.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$patient_id]);
    $diagnostics = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT b.*, d.name AS doctor_name
        FROM bills b
        JOIN users d ON b.doctor_id = d.id
        WHERE b.patient_id = ?
        ORDER BY b.bill_date DESC, b.id DESC
        LIMIT 5
    ");
    $stmt->execute([$patient_id]);
    $bills = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_status != 'cancelled' THEN amount ELSE 0 END), 0) AS total_billed, COALESCE(SUM(CASE WHEN payment_status IN ('unpaid','partial') THEN amount ELSE 0 END), 0) AS unpaid FROM bills WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $expRow = $stmt->fetch();
    $totalExpenses = (float)($expRow['total_billed'] ?? 0);
    $unpaidExpenses = (float)($expRow['unpaid'] ?? 0);

    // Total Appointment Amount (excludes cancelled appointments & cancelled bills, avoids duplicate counting)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS active_count,
               COALESCE(SUM(appt_charge), 0) AS total_appt_amount
        FROM (
            SELECT a.id,
                   COALESCE(
                       (SELECT b.amount FROM bills b WHERE b.appointment_id = a.id AND b.payment_status != 'cancelled' ORDER BY b.id DESC LIMIT 1),
                       dp.consultation_fee,
                       0
                   ) AS appt_charge
            FROM appointments a
            LEFT JOIN doctor_profiles dp ON a.doctor_id = dp.user_id
            WHERE a.patient_id = ? AND a.status != 'cancelled'
        ) AS t
    ");
    $stmt->execute([$patient_id]);
    $apptAmtRow = $stmt->fetch();
    $activeAppointmentCount = (int)($apptAmtRow['active_count'] ?? 0);
    $totalAppointmentAmount = (float)($apptAmtRow['total_appt_amount'] ?? 0);

    $doctors = $pdo->query("SELECT u.id, u.name, dp.specialization, dp.room_number, dp.consultation_fee FROM users u LEFT JOIN doctor_profiles dp ON u.id = dp.user_id WHERE u.role = 'doctor' ORDER BY u.name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Patient Dashboard Error: " . $e->getMessage());
    $error = t('msg_error');
}

$csrf_token = generateCSRFToken();
$page_title = t('nav_overview');
$page_subtitle = $patient_name . ' • ' . t('portal_patient');
$active_nav = 'dashboard';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Welcome Banner -->
<div class="card-modern p-4 mb-4 border-0 text-white" style="background: linear-gradient(135deg, #0369a1 0%, #0284c7 60%, #0d9488 100%);">
    <div class="row align-items-center g-3">
        <div class="col-md-8">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="badge bg-white text-primary fw-bold rounded-pill px-3 py-1 extra-small">
                    <?php echo e_t('label_patient_id'); ?> #<?php echo (int)$patient_id; ?>
                </span>
                <?php if (!empty($patient_profile['blood_group'])): ?>
                    <span class="badge bg-white bg-opacity-25 text-white rounded-pill px-3 py-1 extra-small">
                        <?php echo e_t('col_blood_group'); ?>: <?php echo htmlspecialchars($patient_profile['blood_group']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <h2 class="h4 fw-bold text-white mb-1"><?php echo e_t('pat_welcome', ['name' => $patient_name]); ?></h2>
            <p class="text-white-50 small mb-0"><?php echo e_t('pat_banner_sub'); ?></p>
        </div>
        <div class="col-md-4 text-md-end d-flex flex-wrap gap-2 justify-content-md-end">
            <button type="button" class="btn-modern btn-modern-sm bg-white text-primary border-0 fw-bold" data-bs-toggle="modal" data-bs-target="#appointmentModal">
                <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_book_consultation'); ?>
            </button>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-primary"><i class="bi bi-calendar-check"></i></div>
            <?php if ($next_appointment): ?>
                <div class="fw-bold text-slate-900 small mb-1"><?php echo formatDate($next_appointment['appointment_date']); ?> &bull; <?php echo date('h:i A', strtotime($next_appointment['appointment_time'])); ?></div>
                <div class="extra-small text-muted mb-1"><?php echo htmlspecialchars($next_appointment['doctor_name']); ?></div>
            <?php else: ?>
                <div class="fw-bold text-slate-900 mb-1"><?php echo e_t('kpi_none_scheduled'); ?></div>
            <?php endif; ?>
            <div class="kpi-label"><?php echo e_t('kpi_next_appt'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-accent"><i class="bi bi-file-earmark-medical"></i></div>
            <div class="kpi-value"><?php echo count($diagnostics); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_risk_assessments'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-success"><i class="bi bi-capsule"></i></div>
            <div class="kpi-value"><?php echo count($prescriptions); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_prescriptions'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="kpi-icon-wrap kpi-icon-warning"><i class="bi bi-wallet2"></i></div>
            <div class="kpi-value"><?php echo formatCurrency($totalExpenses); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_total_expenses'); ?><?php if ($unpaidExpenses > 0) echo ' • ' . formatCurrency($unpaidExpenses) . ' ' . e_t('kpi_due_suffix'); ?></div>
        </div>
    </div>
</div>

<!-- Appointments & Confirmed-Only Receipts -->
<div class="card-modern mb-4">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-calendar2-check text-primary me-2"></i><?php echo e_t('pat_recent_appts_title'); ?></h5>
        <a href="<?php echo BASE_URL; ?>/patient/appointments.php" class="small fw-semibold"><?php echo e_t('btn_view_all'); ?> &rarr;</a>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($appointments)): ?>
            <div class="text-center py-4 text-muted small"><?php echo e_t('hist_empty_appts'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo e_t('col_doctor'); ?></th>
                            <th><?php echo e_t('col_schedule'); ?></th>
                            <th><?php echo e_t('label_status'); ?></th>
                            <th class="text-end"><?php echo e_t('col_receipt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $apt): ?>
                            <?php $isConfirmed = in_array($apt['status'], ['confirmed', 'completed'], true); ?>
                            <tr>
                                <td class="fw-semibold small">#APT-<?php echo str_pad((string)$apt['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                <td>
                                    <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($apt['doctor_name']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($apt['specialization'] ?? ''); ?></div>
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
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('label_view'); ?>">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                                <i class="bi bi-download"></i> <?php echo e_t('btn_receipt'); ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge-modern badge-modern-neutral">
                                            <i class="bi bi-clock"></i>
                                            <?php echo $apt['status'] === 'cancelled' ? e_t('status_cancelled') : e_t('receipt_awaiting'); ?>
                                        </span>
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

<!-- Diagnostic Risk Assessments -->
<div class="card-modern mb-4">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-journal-medical text-primary me-2"></i><?php echo e_t('pat_diag_title'); ?></h5>
        <span class="badge-modern badge-modern-primary"><?php echo count($diagnostics); ?></span>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($diagnostics)): ?>
            <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_risk'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th><?php echo e_t('label_date'); ?></th>
                            <th><?php echo e_t('col_attending_doc'); ?></th>
                            <th><?php echo e_t('col_modality'); ?></th>
                            <th><?php echo e_t('col_finding'); ?></th>
                            <th><?php echo e_t('col_latest_risk'); ?></th>
                            <th class="text-end"><?php echo e_t('col_report'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diagnostics as $diag): ?>
                            <?php $scanUrl = getScanWebUrl($diag['id'], $diag['scan_file_path']); ?>
                            <tr>
                                <td class="small">
                                    <div class="fw-semibold text-slate-900"><?php echo formatDate($diag['created_at']); ?></div>
                                    <div class="extra-small text-muted"><?php echo date('h:i A', strtotime($diag['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($diag['doctor_name']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($diag['specialization'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($scanUrl): ?>
                                            <img src="<?php echo htmlspecialchars($scanUrl); ?>" alt="Scan" class="rounded border" style="width: 38px; height: 38px; object-fit: cover;">
                                        <?php endif; ?>
                                        <span class="badge-modern badge-modern-info"><?php echo htmlspecialchars($diag['scan_type']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-modern <?php echo $diag['predicted_condition'] === 'Normal' ? 'badge-modern-success' : 'badge-modern-danger'; ?>">
                                        <?php echo htmlspecialchars($diag['predicted_condition']); ?>
                                    </span>
                                </td>
                                <td class="fw-semibold tabular-nums"><?php echo number_format((float)$diag['clinical_risk_score'], 0); ?>%</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('label_view'); ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                            <i class="bi bi-download"></i> <?php echo e_t('col_report'); ?>
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

<!-- Expenses & Prescriptions Split -->
<div class="row g-4">
    <!-- Expenses & Total Appointment Amount Card (Requirement 7) -->
    <div class="col-lg-6">
        <div class="card-modern h-100 d-flex flex-column">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-wallet2 text-warning me-2"></i><?php echo e_t('pat_expenses_title'); ?></h5>
                <a href="<?php echo BASE_URL; ?>/patient/billing.php" class="small fw-semibold"><?php echo e_t('btn_view_all_expenses'); ?> &rarr;</a>
            </div>
            <div class="card-body-modern p-0 flex-grow-1">
                <?php if (empty($bills)): ?>
                    <div class="text-center py-4 text-muted small"><?php echo e_t('hist_empty_bills'); ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table-modern">
                            <thead>
                                <tr>
                                    <th><?php echo e_t('col_service_desc'); ?></th>
                                    <th><?php echo e_t('label_amount'); ?></th>
                                    <th><?php echo e_t('label_status'); ?></th>
                                    <th class="text-end"><?php echo e_t('label_download'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bills as $b): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold small text-slate-900"><?php echo htmlspecialchars($b['description']); ?></div>
                                            <div class="extra-small text-muted"><?php echo formatDate($b['bill_date']); ?> &bull; <?php echo htmlspecialchars($b['bill_number']); ?></div>
                                        </td>
                                        <td class="fw-bold tabular-nums"><?php echo formatCurrency($b['amount']); ?></td>
                                        <td>
                                            <span class="badge-modern <?php echo $b['payment_status'] === 'paid' ? 'badge-modern-success' : ($b['payment_status'] === 'cancelled' ? 'badge-modern-danger' : 'badge-modern-warning'); ?>">
                                                <?php echo htmlspecialchars(translateStatus($b['payment_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?id=<?php echo (int)$b['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('label_view'); ?>">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?id=<?php echo (int)$b['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm" title="<?php echo e_t('label_download'); ?>">
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

            <!-- Total Appointment Amount Summary Card at Bottom of Expenses & Bills -->
            <div class="p-3 bg-slate-50 border-top border-slate-200">
                <div class="p-3 bg-white border border-slate-200 rounded-3 d-flex align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="kpi-icon-wrap kpi-icon-primary mb-0" style="width:40px;height:40px;font-size:1.1rem;">
                            <i class="bi bi-calendar2-check"></i>
                        </div>
                        <div>
                            <div class="fw-bold small text-slate-900"><?php echo e_t('pat_total_appt_amount_title'); ?></div>
                            <div class="extra-small text-muted"><?php echo e_t('pat_total_appt_amount_sub', ['n' => $activeAppointmentCount]); ?></div>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="h5 fw-bold text-primary mb-0 tabular-nums" id="totalAppointmentAmountValue"><?php echo formatCurrency($totalAppointmentAmount); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Prescriptions -->
    <div class="col-lg-6">
        <div class="card-modern h-100">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-prescription2 text-success me-2"></i><?php echo e_t('pat_active_rx_title'); ?></h5>
                <span class="badge-modern badge-modern-success"><?php echo e_t('badge_active_n', ['n' => count($prescriptions)]); ?></span>
            </div>
            <div class="card-body-modern">
                <?php if (empty($prescriptions)): ?>
                    <div class="text-center py-4 text-muted small"><?php echo e_t('hist_empty_rx'); ?></div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($prescriptions as $prescription): ?>
                            <?php $meds = json_decode($prescription['medication_list'] ?? '[]', true) ?: []; ?>
                            <div class="p-3 bg-slate-50 border border-slate-200 rounded-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold small text-slate-900">Rx #<?php echo (int)$prescription['id']; ?> &bull; <?php echo htmlspecialchars($prescription['doctor_name']); ?></span>
                                    <span class="badge-modern badge-modern-warning">
                                        <?php echo e_t('label_followup_date'); ?>: <?php echo $prescription['next_followup_date'] ? formatDate($prescription['next_followup_date']) : '-'; ?>
                                    </span>
                                </div>
                                <ul class="list-unstyled small mb-2">
                                    <?php foreach ($meds as $m): ?>
                                        <li><i class="bi bi-capsule text-primary me-1"></i><strong><?php echo htmlspecialchars($m['drug_name']); ?></strong> — <?php echo htmlspecialchars($m['dosage']); ?>, <?php echo htmlspecialchars($m['frequency']); ?> (<?php echo htmlspecialchars($m['duration']); ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (!empty($prescription['clinical_remedies_advice'])): ?>
                                    <div class="extra-small text-muted border-top pt-2">
                                        <strong><?php echo e_t('label_clinical_advice'); ?>:</strong> <?php echo htmlspecialchars($prescription['clinical_remedies_advice']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Book Appointment Modal -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-content-modern">
            <div class="modal-header modal-header-modern">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-plus text-primary me-2"></i><?php echo e_t('modal_book_appt'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="appointmentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="patient_id" value="<?php echo (int)$patient_id; ?>">

                    <div class="mb-3">
                        <label for="doctorSelect" class="form-label-modern"><?php echo e_t('label_select_doctor'); ?></label>
                        <select class="form-select-modern" id="doctorSelect" name="doctor_id" required>
                            <option value=""><?php echo e_t('opt_choose_doctor'); ?></option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?php echo (int)$doc['id']; ?>">
                                    <?php echo htmlspecialchars($doc['name']); ?> &bull; <?php echo htmlspecialchars($doc['specialization'] ?? ''); ?> (<?php echo htmlspecialchars($doc['room_number'] ?? 'TBA'); ?>) — <?php echo formatCurrency($doc['consultation_fee'] ?? 500); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="appDate" class="form-label-modern"><?php echo e_t('label_date'); ?></label>
                            <input type="date" class="form-control-modern" id="appDate" name="appointment_date" required>
                        </div>
                        <div class="col-md-6">
                            <label for="appTime" class="form-label-modern"><?php echo e_t('label_time'); ?></label>
                            <input type="time" class="form-control-modern" id="appTime" name="appointment_time" value="10:00" required>
                        </div>
                    </div>
                    <div>
                        <label for="symptomNotes" class="form-label-modern"><?php echo e_t('label_reason_notes'); ?></label>
                        <textarea class="form-control-modern" id="symptomNotes" name="symptom_notes" rows="3" placeholder="<?php echo e_t('ph_appt_notes'); ?>"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-slate-50 border-top p-3">
                <button type="button" class="btn-modern btn-modern-outline" data-bs-dismiss="modal"><?php echo e_t('label_cancel'); ?></button>
                <button type="button" class="btn-modern btn-modern-primary" onclick="bookAppointment()"><?php echo e_t('btn_confirm_booking'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('appDate');
    if (dateInput) {
        const now = new Date();
        const today = now.toISOString().split('T')[0];
        const tomorrowDate = new Date(now.getTime() + 86400000).toISOString().split('T')[0];
        dateInput.min = today;
        dateInput.value = tomorrowDate;
    }
});
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
