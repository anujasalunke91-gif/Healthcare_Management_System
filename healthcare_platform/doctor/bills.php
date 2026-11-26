<?php
/**
 * Doctor Patient Bills Viewer (Read-Only, INR ₹, Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

$doctorId = getCurrentUserId();
$filterPatientId = intval($_GET['patient_id'] ?? 0);
$bills = [];
$totals = ['all' => 0, 'paid' => 0, 'unpaid' => 0];
$error = '';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    if ($filterPatientId > 0 && !isDoctorAuthorizedForPatient($pdo, $doctorId, $filterPatientId)) {
        $error = 'You are not authorized to view bills for the requested patient.';
    } else {
        $sql = "
            SELECT b.*, p.name AS patient_name, p.email AS patient_email
            FROM bills b
            JOIN users p ON b.patient_id = p.id
            WHERE b.doctor_id = ?
        ";
        $params = [$doctorId];
        if ($filterPatientId > 0) {
            $sql .= " AND b.patient_id = ?";
            $params[] = $filterPatientId;
        }
        $sql .= " ORDER BY b.bill_date DESC, b.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bills = $stmt->fetchAll();

        foreach ($bills as $b) {
            if ($b['payment_status'] !== 'cancelled') {
                $totals['all'] += (float)$b['amount'];
                if ($b['payment_status'] === 'paid') $totals['paid'] += (float)$b['amount'];
                else $totals['unpaid'] += (float)$b['amount'];
            }
        }
    }
} catch (PDOException $e) {
    error_log("Doctor Bills Error: " . $e->getMessage());
    $error = t('msg_error');
}

$page_title = t('bills_doc_title');
$page_subtitle = t('bills_doc_sub');
$active_nav = 'bills';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-primary"><i class="bi bi-receipt"></i></div>
            <div class="kpi-value"><?php echo formatCurrency($totals['all']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_total_billed'); ?></div>
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
            <div class="kpi-icon-wrap kpi-icon-warning"><i class="bi bi-clock-history"></i></div>
            <div class="kpi-value text-warning"><?php echo formatCurrency($totals['unpaid']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_outstanding'); ?></div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="input-group-modern" style="max-width: 340px; flex: 1;">
        <i class="bi bi-search input-icon"></i>
        <input type="text" id="docBillSearch" class="form-control-modern" placeholder="<?php echo e_t('ph_search_users'); ?>" onkeyup="filterDocBills()">
    </div>
    <?php if ($filterPatientId > 0): ?>
        <a href="<?php echo BASE_URL; ?>/doctor/bills.php" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-x-circle"></i> <?php echo e_t('label_all'); ?>
        </a>
    <?php endif; ?>
</div>

<div class="card-modern">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-receipt-cutoff text-primary me-2"></i><?php echo e_t('nav_patient_bills'); ?> (<?php echo count($bills); ?>)</h5>
        <span class="badge-modern badge-modern-neutral"><?php echo e_t('badge_readonly'); ?></span>
    </div>
    <div class="card-body-modern p-0">
        <?php if (empty($bills)): ?>
            <div class="text-center py-5 text-muted small"><?php echo e_t('hist_empty_bills'); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-modern" id="docBillsTable">
                    <thead>
                        <tr>
                            <th><?php echo e_t('col_bill_id'); ?></th>
                            <th class="sortable-th" onclick="sortTable('docBillsTable', 1, 'text')"><?php echo e_t('col_patient'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('col_service_desc'); ?></th>
                            <th class="sortable-th" onclick="sortTable('docBillsTable', 3, 'date')"><?php echo e_t('label_date'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('docBillsTable', 4, 'number')"><?php echo e_t('col_charges'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('label_status'); ?></th>
                            <th class="text-end"><?php echo e_t('label_download'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bills as $b): ?>
                            <?php $items = json_decode($b['items_json'] ?? '[]', true) ?: []; ?>
                            <tr class="doc-bill-row">
                                <td class="fw-semibold small text-slate-900"><?php echo htmlspecialchars($b['bill_number']); ?></td>
                                <td data-sort="<?php echo htmlspecialchars($b['patient_name']); ?>">
                                    <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$b['patient_id']; ?>" class="fw-semibold">
                                        <?php echo htmlspecialchars($b['patient_name']); ?>
                                    </a>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($b['patient_email']); ?></div>
                                </td>
                                <td class="small">
                                    <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($b['description']); ?></div>
                                    <?php if (!empty($items)): ?>
                                        <div class="extra-small text-muted">
                                            <?php echo htmlspecialchars(implode(', ', array_column($items, 'item'))); ?>
                                        </div>
                                    <?php endif; ?>
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
                                        <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?id=<?php echo (int)$b['id']; ?>" class="btn-modern btn-modern-outline btn-modern-sm" target="_blank" title="<?php echo e_t('label_view'); ?>">
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
</div>

<?php
$extra_scripts = <<<HTML
<script>
function filterDocBills() {
    const q = (document.getElementById('docBillSearch').value || '').toLowerCase();
    document.querySelectorAll('#docBillsTable tbody tr.doc-bill-row').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
