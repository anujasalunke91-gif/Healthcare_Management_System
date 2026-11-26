<?php
/**
 * Admin Billing & Invoices Management (INR ₹ & Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['admin']);

$action = $_GET['action'] ?? '';
$adminId = getCurrentUserId();

if ($action === 'update_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    $billId = intval($_POST['bill_id'] ?? 0);
    $status = trim($_POST['payment_status'] ?? '');
    if ($billId <= 0 || !in_array($status, ['unpaid', 'paid', 'partial', 'cancelled'], true)) {
        json_response(false, ['message' => t('msg_error')], 400);
    }

    try {
        $pdo = getDBConnection();
        ensureBillingTableExists($pdo);
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare("UPDATE bills SET payment_status = ?, paid_at = ? WHERE id = ?");
        $stmt->execute([$status, $paidAt, $billId]);
        logActivity($adminId, 'BILL_UPDATED', "Updated bill #$billId payment status to $status");
        json_response(true, ['message' => t('msg_updated')], 200);
    } catch (Exception $e) {
        error_log("Bill Update Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

if ($action === 'add_bill' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    $patientId = intval($_POST['patient_id'] ?? 0);
    $doctorId = intval($_POST['doctor_id'] ?? 0);
    $description = sanitize($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $billDate = sanitize($_POST['bill_date'] ?? date('Y-m-d'));
    $status = trim($_POST['payment_status'] ?? 'unpaid');

    if ($patientId <= 0 || $doctorId <= 0 || empty($description) || $amount <= 0 || !in_array($status, ['unpaid', 'paid', 'partial'], true)) {
        json_response(false, ['message' => t('err_reg_required')], 400);
    }

    try {
        $pdo = getDBConnection();
        ensureBillingTableExists($pdo);
        $billNumber = 'BILL-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $items = json_encode([[
            'item' => $description,
            'category' => 'Hospital Service',
            'qty' => 1,
            'unit_price' => $amount,
            'total' => $amount
        ]]);
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare("INSERT INTO bills (bill_number, patient_id, doctor_id, description, items_json, amount, payment_status, bill_date, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$billNumber, $patientId, $doctorId, $description, $items, $amount, $status, $billDate, $paidAt]);
        logActivity($adminId, 'BILL_CREATED', "Created bill $billNumber for patient #$patientId ($amount)");
        json_response(true, ['message' => t('msg_updated')], 200);
    } catch (Exception $e) {
        error_log("Create Bill Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

$bills = [];
$patients = [];
$doctors = [];
$totals = ['all' => 0, 'paid' => 0, 'unpaid' => 0];
$error = '';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);
    $bills = $pdo->query("
        SELECT b.*, p.name AS patient_name, d.name AS doctor_name
        FROM bills b
        JOIN users p ON b.patient_id = p.id
        JOIN users d ON b.doctor_id = d.id
        ORDER BY b.bill_date DESC, b.id DESC
    ")->fetchAll();

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

    $patients = $pdo->query("SELECT id, name FROM users WHERE role = 'patient' ORDER BY name ASC")->fetchAll();
    $doctors = $pdo->query("SELECT id, name FROM users WHERE role = 'doctor' ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Admin Bills Error: " . $e->getMessage());
    $error = t('msg_error');
}

$csrf_token = generateCSRFToken();
$page_title = t('bills_adm_title');
$page_subtitle = t('bills_adm_sub');
$active_nav = 'bills';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-primary"><i class="bi bi-receipt-cutoff"></i></div>
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
            <div class="kpi-icon-wrap kpi-icon-warning"><i class="bi bi-hourglass-split"></i></div>
            <div class="kpi-value text-warning"><?php echo formatCurrency($totals['unpaid']); ?></div>
            <div class="kpi-label"><?php echo e_t('kpi_outstanding'); ?></div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="input-group-modern" style="max-width: 340px; flex: 1;">
        <i class="bi bi-search input-icon"></i>
        <input type="text" id="billSearch" class="form-control-modern" placeholder="<?php echo e_t('ph_search_users'); ?>" onkeyup="filterBills()">
    </div>
    <button type="button" class="btn-modern btn-modern-primary btn-modern-sm" data-bs-toggle="modal" data-bs-target="#addBillModal">
        <i class="bi bi-plus-circle"></i> <?php echo e_t('btn_create_bill'); ?>
    </button>
</div>

<div class="card-modern">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-receipt text-primary me-2"></i><?php echo e_t('nav_billing_invoices'); ?> (<?php echo count($bills); ?>)</h5>
        <select class="form-select-modern form-select-sm" style="width:auto;" onchange="sortTableBySelect('adminBillsTable', this)">
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
                <table class="table-modern" id="adminBillsTable">
                    <thead>
                        <tr>
                            <th><?php echo e_t('col_bill_id'); ?></th>
                            <th class="sortable-th" onclick="sortTable('adminBillsTable', 1, 'text')"><?php echo e_t('col_patient'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('col_service_desc'); ?></th>
                            <th class="sortable-th" onclick="sortTable('adminBillsTable', 3, 'date')"><?php echo e_t('label_date'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th class="sortable-th" onclick="sortTable('adminBillsTable', 4, 'number')"><?php echo e_t('label_amount'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                            <th><?php echo e_t('col_payment_status'); ?></th>
                            <th class="text-end"><?php echo e_t('label_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bills as $b): ?>
                            <tr class="bill-row">
                                <td class="fw-semibold small text-slate-900"><?php echo htmlspecialchars($b['bill_number']); ?></td>
                                <td data-sort="<?php echo htmlspecialchars($b['patient_name']); ?>">
                                    <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$b['patient_id']; ?>" class="fw-semibold">
                                        <?php echo htmlspecialchars($b['patient_name']); ?>
                                    </a>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($b['doctor_name']); ?></div>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($b['description']); ?></td>
                                <td data-sort="<?php echo htmlspecialchars($b['bill_date']); ?>" class="small text-muted"><?php echo formatDate($b['bill_date']); ?></td>
                                <td data-sort="<?php echo floatval($b['amount']); ?>" class="fw-bold tabular-nums"><?php echo formatCurrency($b['amount']); ?></td>
                                <td>
                                    <select class="role-select-inline" onchange="updateBillStatus(<?php echo (int)$b['id']; ?>, this)">
                                        <?php foreach (['unpaid', 'paid', 'partial', 'cancelled'] as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo $b['payment_status'] === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars(translateStatus($st)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
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

<!-- Add Bill Modal -->
<div class="modal fade" id="addBillModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-modern">
            <div class="modal-header modal-header-modern">
                <h5 class="modal-title fw-bold"><i class="bi bi-receipt-cutoff text-primary me-2"></i><?php echo e_t('btn_create_bill'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="addBillForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('col_patient'); ?></label>
                        <select name="patient_id" class="form-select-modern" required>
                            <option value=""><?php echo e_t('opt_choose_patient'); ?></option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('col_attending_doc'); ?></label>
                        <select name="doctor_id" class="form-select-modern" required>
                            <option value=""><?php echo e_t('opt_choose_doctor'); ?></option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('col_service_desc'); ?></label>
                        <input type="text" name="description" class="form-control-modern" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label-modern"><?php echo e_t('label_amount'); ?> (₹ INR)</label>
                            <input type="number" step="0.01" min="1" name="amount" class="form-control-modern" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label-modern"><?php echo e_t('label_date'); ?></label>
                            <input type="date" name="bill_date" class="form-control-modern" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div>
                        <label class="form-label-modern"><?php echo e_t('col_payment_status'); ?></label>
                        <select name="payment_status" class="form-select-modern">
                            <option value="unpaid"><?php echo e_t('status_unpaid'); ?></option>
                            <option value="paid"><?php echo e_t('status_paid'); ?></option>
                            <option value="partial"><?php echo e_t('status_partial'); ?></option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-slate-50 border-top p-3">
                <button type="button" class="btn-modern btn-modern-outline" data-bs-dismiss="modal"><?php echo e_t('label_cancel'); ?></button>
                <button type="button" class="btn-modern btn-modern-primary" onclick="submitNewBill()"><?php echo e_t('label_save'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
$csrfJson = json_encode($csrf_token);
$extra_scripts = <<<HTML
<script>
const BILL_CSRF = {$csrfJson};

function filterBills() {
    const q = (document.getElementById('billSearch').value || '').toLowerCase();
    document.querySelectorAll('#adminBillsTable tbody tr.bill-row').forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}

async function updateBillStatus(billId, sel) {
    const fd = new FormData();
    fd.append('csrf_token', BILL_CSRF);
    fd.append('bill_id', billId);
    fd.append('payment_status', sel.value);
    const res = await fetch(window.BASE_URL + '/admin/bills.php?action=update_payment', { method: 'POST', body: fd });
    const data = await res.json();
    showToast(data.data?.message || (data.success ? tr('msg_updated') : tr('msg_error')), data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 650);
}

async function submitNewBill() {
    const form = document.getElementById('addBillForm');
    if (!form.reportValidity()) return;
    const res = await fetch(window.BASE_URL + '/admin/bills.php?action=add_bill', { method: 'POST', body: new FormData(form) });
    const data = await res.json();
    if (data.success) {
        showToast(data.data?.message || tr('msg_updated'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('addBillModal')).hide();
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(data.data?.message || tr('msg_error'), 'error');
    }
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
