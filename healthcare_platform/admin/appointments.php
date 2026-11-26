<?php
/**
 * Admin Appointment Management (INR, Confirmed Receipt Gating, & Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['admin']);

$action = $_GET['action'] ?? '';
$adminId = getCurrentUserId();

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    $patientId = intval($_POST['patient_id'] ?? 0);
    $doctorId = intval($_POST['doctor_id'] ?? 0);
    $date = sanitize($_POST['appointment_date'] ?? '');
    $time = sanitize($_POST['appointment_time'] ?? '');
    $status = trim($_POST['status'] ?? 'confirmed');
    $notes = sanitize($_POST['symptom_notes'] ?? '');

    if ($patientId <= 0 || $doctorId <= 0 || empty($date) || empty($time) || !in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
        json_response(false, ['message' => t('err_reg_required')], 400);
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient'");
        $stmt->execute([$patientId]);
        if (!$stmt->fetch()) json_response(false, ['message' => t('msg_error')], 400);

        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'doctor'");
        $stmt->execute([$doctorId]);
        if (!$stmt->fetch()) json_response(false, ['message' => t('msg_error')], 400);

        $stmt = $pdo->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status != 'cancelled' LIMIT 1");
        $stmt->execute([$doctorId, $date, $time]);
        if ($stmt->fetch()) {
            json_response(false, ['message' => 'This doctor already has an appointment at the selected date and time'], 400);
        }

        ensureBillingTableExists($pdo);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status, symptom_notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$patientId, $doctorId, $date, $time, $status, $notes]);
        $appointmentId = (int)$pdo->lastInsertId();

        if ($status !== 'cancelled') {
            createAppointmentBill($pdo, $appointmentId, $patientId, $doctorId, $date);
        }
        $pdo->commit();

        logActivity($adminId, 'APPOINTMENT_CREATED', "Admin created appointment #$appointmentId for patient #$patientId with doctor #$doctorId");
        json_response(true, ['appointment_id' => $appointmentId, 'message' => t('msg_appt_booked')], 200);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Admin Add Appointment Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    $appointmentId = intval($_POST['appointment_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    if ($appointmentId <= 0 || !in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
        json_response(false, ['message' => t('msg_error')], 400);
    }

    try {
        $pdo = getDBConnection();
        ensureBillingTableExists($pdo);
        $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        $stmt->execute([$status, $appointmentId]);

        if ($status === 'cancelled') {
            $pdo->prepare("UPDATE bills SET payment_status = 'cancelled' WHERE appointment_id = ? AND payment_status != 'paid'")->execute([$appointmentId]);
        }

        logActivity($adminId, 'APPOINTMENT_STATUS', "Updated appointment #$appointmentId status to $status");
        json_response(true, ['message' => t('msg_updated')], 200);
    } catch (Exception $e) {
        error_log("Update Appointment Status Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

$appointments = [];
$patients = [];
$doctors = [];
$error = '';

try {
    $pdo = getDBConnection();
    $appointments = $pdo->query("
        SELECT a.*, p.name AS patient_name, d.name AS doctor_name, dp.specialization, dp.consultation_fee
        FROM appointments a
        JOIN users p ON a.patient_id = p.id
        JOIN users d ON a.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ")->fetchAll();

    $patients = $pdo->query("SELECT id, name, email FROM users WHERE role = 'patient' ORDER BY name ASC")->fetchAll();
    $doctors = $pdo->query("SELECT u.id, u.name, dp.specialization, dp.consultation_fee FROM users u LEFT JOIN doctor_profiles dp ON u.id = dp.user_id WHERE u.role = 'doctor' ORDER BY u.name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Admin Appointments Error: " . $e->getMessage());
    $error = t('msg_error');
}

$csrf_token = generateCSRFToken();
$page_title = t('appts_title');
$page_subtitle = t('appts_sub');
$active_nav = 'appointments';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="input-group-modern" style="max-width: 360px; flex: 1;">
        <i class="bi bi-search input-icon"></i>
        <input type="text" id="apptSearch" class="form-control-modern" placeholder="<?php echo e_t('ph_search_users'); ?>" onkeyup="filterAppointments()">
    </div>
    <button type="button" class="btn-modern btn-modern-primary btn-modern-sm" data-bs-toggle="modal" data-bs-target="#addAppointmentModal">
        <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_add_appt'); ?>
    </button>
</div>

<div class="card-modern">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-calendar2-check text-primary me-2"></i><?php echo e_t('all_appts_count', ['n' => count($appointments)]); ?></h5>
        <select class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('adminApptsTable', this)">
            <option value="2:date:desc"><?php echo e_t('sort_newest'); ?></option>
            <option value="2:date:asc"><?php echo e_t('sort_oldest'); ?></option>
            <option value="0:text:asc"><?php echo e_t('col_patient'); ?> (A-Z)</option>
            <option value="1:text:asc"><?php echo e_t('col_doctor'); ?> (A-Z)</option>
        </select>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($appointments)): ?>
            <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_appts'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="adminApptsTable">
                    <thead>
                        <tr>
                            <th class="sortable-th" onclick="sortTable('adminApptsTable', 0, 'text')"><?php echo e_t('col_patient'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminApptsTable', 1, 'text')"><?php echo e_t('col_doctor'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminApptsTable', 2, 'date')"><?php echo e_t('col_schedule'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('col_notes'); ?></th>
                            <th><?php echo e_t('label_status'); ?></th>
                            <th class="text-end"><?php echo e_t('col_receipt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $apt): ?>
                            <?php $isConfirmed = in_array($apt['status'], ['confirmed', 'completed'], true); ?>
                            <tr class="appt-row">
                                <td data-sort="<?php echo htmlspecialchars($apt['patient_name']); ?>">
                                    <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$apt['patient_id']; ?>" class="fw-semibold">
                                        <?php echo htmlspecialchars($apt['patient_name']); ?>
                                    </a>
                                    <div class="extra-small text-muted">#APT-<?php echo str_pad((string)$apt['id'], 5, '0', STR_PAD_LEFT); ?></div>
                                </td>
                                <td data-sort="<?php echo htmlspecialchars($apt['doctor_name']); ?>">
                                    <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($apt['doctor_name']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($apt['specialization'] ?? ''); ?></div>
                                </td>
                                <td data-sort="<?php echo htmlspecialchars($apt['appointment_date'] . ' ' . $apt['appointment_time']); ?>">
                                    <div class="fw-semibold small"><?php echo formatDate($apt['appointment_date']); ?></div>
                                    <div class="extra-small text-muted"><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></div>
                                </td>
                                <td class="small text-muted" style="max-width:220px;">
                                    <?php echo htmlspecialchars($apt['symptom_notes'] ?: '-'); ?>
                                </td>
                                <td>
                                    <select class="role-select-inline" onchange="updateApptStatus(<?php echo (int)$apt['id']; ?>, this)">
                                        <?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo $apt['status'] === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars(translateStatus($st)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-end">
                                    <?php if ($isConfirmed): ?>
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>" class="btn-modern btn-modern-outline btn-modern-sm" target="_blank" title="<?php echo e_t('label_view'); ?>">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                                <i class="bi bi-download"></i> <?php echo e_t('btn_receipt'); ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge-modern badge-modern-neutral"><?php echo $apt['status'] === 'cancelled' ? e_t('status_cancelled') : e_t('receipt_awaiting'); ?></span>
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

<!-- Add Appointment Modal -->
<div class="modal fade" id="addAppointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-modern">
            <div class="modal-header modal-header-modern">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-plus text-primary me-2"></i><?php echo e_t('modal_add_appt'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="adminApptForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('col_patient'); ?></label>
                        <select name="patient_id" class="form-select-modern" required>
                            <option value=""><?php echo e_t('opt_choose_patient'); ?></option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['email']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('col_doctor'); ?></label>
                        <select name="doctor_id" class="form-select-modern" required>
                            <option value=""><?php echo e_t('opt_choose_doctor'); ?></option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?> — <?php echo htmlspecialchars($d['specialization'] ?? ''); ?> (<?php echo formatCurrency($d['consultation_fee'] ?? 500); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label-modern"><?php echo e_t('label_date'); ?></label>
                            <input type="date" name="appointment_date" class="form-control-modern" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label-modern"><?php echo e_t('label_time'); ?></label>
                            <input type="time" name="appointment_time" class="form-control-modern" value="10:00" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('label_status'); ?></label>
                        <select name="status" class="form-select-modern">
                            <option value="confirmed"><?php echo e_t('status_confirmed'); ?></option>
                            <option value="pending"><?php echo e_t('status_pending'); ?></option>
                            <option value="completed"><?php echo e_t('status_completed'); ?></option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label-modern"><?php echo e_t('label_reason_notes'); ?></label>
                        <textarea name="symptom_notes" class="form-control-modern" rows="2" placeholder="<?php echo e_t('ph_appt_notes'); ?>"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-slate-50 border-top p-3">
                <button type="button" class="btn-modern btn-modern-outline" data-bs-dismiss="modal"><?php echo e_t('label_cancel'); ?></button>
                <button type="button" class="btn-modern btn-modern-primary" onclick="submitAdminAppt()"><?php echo e_t('btn_save_appt'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
$csrfJson = json_encode($csrf_token);
$extra_scripts = <<<HTML
<script>
const APPT_CSRF = {$csrfJson};

function filterAppointments() {
    const q = (document.getElementById('apptSearch').value || '').toLowerCase();
    document.querySelectorAll('#adminApptsTable tbody tr.appt-row').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}

async function submitAdminAppt() {
    const form = document.getElementById('adminApptForm');
    if (!form.reportValidity()) return;
    const res = await fetch(window.BASE_URL + '/admin/appointments.php?action=add', { method: 'POST', body: new FormData(form) });
    const data = await res.json();
    if (data.success) {
        showToast(data.data?.message || tr('msg_appt_booked'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('addAppointmentModal')).hide();
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(data.data?.message || tr('msg_appt_failed'), 'error');
    }
}

async function updateApptStatus(id, sel) {
    const fd = new FormData();
    fd.append('csrf_token', APPT_CSRF);
    fd.append('appointment_id', id);
    fd.append('status', sel.value);
    const res = await fetch(window.BASE_URL + '/admin/appointments.php?action=update_status', { method: 'POST', body: fd });
    const data = await res.json();
    showToast(data.data?.message || (data.success ? tr('msg_updated') : tr('msg_error')), data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 600);
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
