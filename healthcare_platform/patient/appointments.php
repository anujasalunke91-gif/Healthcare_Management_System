<?php
/**
 * Patient Appointments & Doctor Directory (INR ₹, Confirmed-Only Receipts, Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient']);

$patientId = getCurrentUserId();
$appointments = [];
$doctors = [];
$error = '';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT a.*, d.name AS doctor_name, dp.specialization, dp.room_number, dp.consultation_fee
        FROM appointments a
        JOIN users d ON a.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute([$patientId]);
    $appointments = $stmt->fetchAll();

    $doctors = $pdo->query("
        SELECT u.id, u.name, u.email, dp.specialization, dp.room_number, dp.consultation_fee
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.id = dp.user_id
        WHERE u.role = 'doctor'
        ORDER BY u.name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("Patient Appointments Error: " . $e->getMessage());
    $error = t('msg_error');
}

$csrf_token = generateCSRFToken();
$page_title = t('pat_appts_title');
$page_subtitle = t('pat_appts_sub');
$active_nav = 'appointments';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="small text-muted">
        <?php echo e_t('total_appts_label'); ?> <strong><?php echo count($appointments); ?></strong>
    </div>
    <button type="button" class="btn-modern btn-modern-primary btn-modern-sm" data-bs-toggle="modal" data-bs-target="#appointmentModal">
        <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_book_appt'); ?>
    </button>
</div>

<!-- Patient Appointments Table -->
<div class="card-modern mb-4">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-calendar2-check text-primary me-2"></i><?php echo e_t('appt_history'); ?></h5>
        <select class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('patientApptsTable', this)">
            <option value="2:date:desc"><?php echo e_t('sort_newest'); ?></option>
            <option value="2:date:asc"><?php echo e_t('sort_oldest'); ?></option>
            <option value="1:text:asc"><?php echo e_t('col_doctor'); ?> (A-Z)</option>
        </select>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($appointments)): ?>
            <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_appts'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="patientApptsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th class="sortable-th" onclick="sortTable('patientApptsTable', 1, 'text')"><?php echo e_t('col_doctor'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('patientApptsTable', 2, 'date')"><?php echo e_t('col_schedule'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
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
                                <td data-sort="<?php echo htmlspecialchars($apt['doctor_name']); ?>">
                                    <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($apt['doctor_name']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($apt['specialization'] ?? ''); ?> &bull; <?php echo htmlspecialchars($apt['room_number'] ?? 'TBA'); ?></div>
                                </td>
                                <td data-sort="<?php echo htmlspecialchars($apt['appointment_date'] . ' ' . $apt['appointment_time']); ?>" class="small">
                                    <div class="fw-semibold"><?php echo formatDate($apt['appointment_date']); ?></div>
                                    <div class="extra-small text-muted"><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></div>
                                </td>
                                <td class="small text-muted" style="max-width:220px;"><?php echo htmlspecialchars($apt['symptom_notes'] ?: '-'); ?></td>
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

<!-- Available Doctors Directory -->
<div class="card-modern">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-stethoscope text-primary me-2"></i><?php echo e_t('available_doctors'); ?></h5>
        <select class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('patientDoctorsTable', this)">
            <option value="0:text:asc"><?php echo e_t('sort_name_asc'); ?></option>
            <option value="0:text:desc"><?php echo e_t('sort_name_desc'); ?></option>
            <option value="1:text:asc"><?php echo e_t('sort_specialty'); ?></option>
            <option value="3:number:asc"><?php echo e_t('sort_fee_asc'); ?></option>
            <option value="3:number:desc"><?php echo e_t('sort_fee_desc'); ?></option>
        </select>
    </div>
    <div class="card-body-modern p-0">
        <div class="table-responsive">
            <table class="table-modern" id="patientDoctorsTable">
                <thead>
                    <tr>
                        <th class="sortable-th" onclick="sortTable('patientDoctorsTable', 0, 'text')"><?php echo e_t('col_doctor'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        <th class="sortable-th" onclick="sortTable('patientDoctorsTable', 1, 'text')"><?php echo e_t('col_specialty'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        <th><?php echo e_t('col_room'); ?></th>
                        <th class="sortable-th" onclick="sortTable('patientDoctorsTable', 3, 'number')"><?php echo e_t('col_fee'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        <th class="text-end"><?php echo e_t('label_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doctors as $doc): ?>
                        <tr>
                            <td data-sort="<?php echo htmlspecialchars($doc['name']); ?>" class="fw-semibold text-slate-900"><?php echo htmlspecialchars($doc['name']); ?></td>
                            <td><span class="badge-modern badge-modern-primary"><?php echo htmlspecialchars($doc['specialization'] ?? 'General'); ?></span></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($doc['room_number'] ?? 'TBA'); ?></td>
                            <td data-sort="<?php echo floatval($doc['consultation_fee'] ?? 0); ?>" class="fw-bold text-success tabular-nums"><?php echo formatCurrency($doc['consultation_fee'] ?? 500); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn-modern btn-modern-outline btn-modern-sm" onclick="preselectDoctorAndBook(<?php echo (int)$doc['id']; ?>)">
                                    <i class="bi bi-calendar-plus"></i> <?php echo e_t('btn_select'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
                    <input type="hidden" name="patient_id" value="<?php echo (int)$patientId; ?>">

                    <div class="mb-3">
                        <label for="doctorSelect" class="form-label-modern"><?php echo e_t('label_select_doctor'); ?></label>
                        <select class="form-select-modern" id="doctorSelect" name="doctor_id" required>
                            <option value=""><?php echo e_t('opt_choose_doctor'); ?></option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?php echo (int)$doc['id']; ?>">
                                    <?php echo htmlspecialchars($doc['name']); ?> &bull; <?php echo htmlspecialchars($doc['specialization'] ?? ''); ?> — <?php echo formatCurrency($doc['consultation_fee'] ?? 500); ?>
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

function preselectDoctorAndBook(docId) {
    const sel = document.getElementById('doctorSelect');
    if (sel) sel.value = String(docId);
    new bootstrap.Modal(document.getElementById('appointmentModal')).show();
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
