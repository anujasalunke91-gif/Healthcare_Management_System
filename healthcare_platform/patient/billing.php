<?php
/**
 * Patient Bills & Expenses Page (INR ₹, Total Appointment Amount Card, Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient']);

$patientId = getCurrentUserId();
$bills = [];
$totals = ['all' => 0, 'paid' => 0, 'unpaid' => 0];
$totalAppointmentAmount = 0.0;
$activeAppointmentCount = 0;
$error = '';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    $stmt = $pdo->prepare("
        SELECT b.*, d.name AS doctor_name, dp.specialization
        FROM bills b
        JOIN users d ON b.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE b.patient_id = ?
        ORDER BY b.bill_date DESC, b.id DESC
    ");
    $stmt->execute([$patientId]);
    $bills = $stmt->fetchAll();

    foreach ($bills as $b) {
        if ($b['payment_status'] !== 'cancelled') {
            $totals['all'] += (float)$b['amount'];
            if ($b['payment_status'] === 'paid') {
                $totals['paid'] += (float)$b['amount'];
            } else {
                $totals['unpaid'] += (float)$b['amount'];
            }
        }
    }

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
    $stmt->execute([$patientId]);
    $apptRow = $stmt->fetch();
    $activeAppointmentCount = (int)($apptRow['active_count'] ?? 0);
    $totalAppointmentAmount = (float)($apptRow['total_appt_amount'] ?? 0);
} catch (PDOException $e) {
    error_log("Patient Billing Error: " . $e->getMessage());
    $error = t('msg_error');
}

$page_title = t('bills_pat_title');
$page_subtitle = t('bills_pat_sub');
$active_nav = 'billing';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-primary"><i class="bi bi-wallet2"></i></div>
            <div class="kpi-value"><?php echo formatCurrency($totals['all']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_total_hc_expenses'); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-success"><i class="bi bi-check-circle"></i></div>
            <div class="kpi-value text-success"><?php echo formatCurrency($totals['paid']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_total_paid'); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-warning"><i class="bi bi-exclamation-circle"></i></div>
            <div class="kpi-value text-warning"><?php echo formatCurrency($totals['unpaid']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_outstanding'); ?></div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="input-group-modern" style="max-width: 340px; flex: 1;">
        <i class="bi bi-search input-icon"></i>
        <input type="text" id="patBillSearch" class="form-control-modern" placeholder="<?php echo e_t('ph_search_users'); ?>" onkeyup="filterPatBills()">
    </div>
    <?php if (!empty($bills)): ?>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?statement=1&patient_id=<?php echo (int)$patientId; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
                <i class="bi bi-printer"></i> <?php echo e_t('btn_view_statement'); ?>
            </a>
            <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?statement=1&patient_id=<?php echo (int)$patientId; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                <i class="bi bi-download"></i> <?php echo e_t('btn_download_statement'); ?>
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="card-modern">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-receipt-cutoff text-primary me-2"></i><?php echo e_t('nav_bills_expenses'); ?> (<?php echo count($bills); ?>)</h5>
        <select class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('patBillsTable', this)">
            <option value="3:date:desc"><?php echo e_t('sort_newest'); ?></option>
            <option value="3:date:asc"><?php echo e_t('sort_oldest'); ?></option>
            <option value="4:number:desc"><?php echo e_t('sort_fee_desc'); ?></option>
            <option value="4:number:asc"><?php echo e_t('sort_fee_asc'); ?></option>
        </select>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($bills)): ?>
            <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_bills'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="patBillsTable">
                    <thead>
                        <tr>
                            <th><?php echo e_t('col_bill_id'); ?></th>
                            <th><?php echo e_t('col_service_desc'); ?></th>
                            <th><?php echo e_t('col_attending_doc'); ?></th>
                            <th class="sortable-th" onclick="sortTable('patBillsTable', 3, 'date')"><?php echo e_t('label_date'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('patBillsTable', 4, 'number')"><?php echo e_t('label_amount'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('col_payment_status'); ?></th>
                            <th class="text-end"><?php echo e_t('label_download'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bills as $b): ?>
                            <tr class="pat-bill-row">
                                <td class="fw-semibold small text-slate-900"><?php echo htmlspecialchars($b['bill_number']); ?></td>
                                <td class="small">
                                    <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($b['description']); ?></div>
                                </td>
                                <td class="small">
                                    <div><?php echo htmlspecialchars($b['doctor_name']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($b['specialization'] ?? ''); ?></div>
                                </td>
                                <td data-sort="<?php echo htmlspecialchars($b['bill_date']); ?>" class="small text-muted"><?php echo formatDate($b['bill_date']); ?></td>
                                <td data-sort="<?php echo floatval($b['amount']); ?>" class="fw-bold tabular-nums"><?php echo formatCurrency($b['amount']); ?></td>
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
                                        <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?id=<?php echo (int)$b['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
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
            <div class="h5 fw-bold text-primary mb-0 tabular-nums"><?php echo formatCurrency($totalAppointmentAmount); ?></div>
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<HTML
<script>
function filterPatBills() {
    const q = (document.getElementById('patBillSearch').value || '').toLowerCase();
    document.querySelectorAll('#patBillsTable tbody tr.pat-bill-row').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
