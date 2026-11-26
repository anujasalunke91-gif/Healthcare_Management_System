<?php
/**
 * Downloadable Bill / Invoice & Patient Expenses Statement (INR ₹, Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient', 'doctor', 'admin']);

$userId = getCurrentUserId();
$role = getCurrentUserRole();
$curLang = getCurrentLang();
$billId = intval($_GET['id'] ?? 0);
$isStatement = isset($_GET['statement']) && $_GET['statement'] === '1';
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    if ($isStatement) {
        $targetPatientId = intval($_GET['patient_id'] ?? ($role === 'patient' ? $userId : 0));
        if ($targetPatientId <= 0) die('Invalid patient ID.');
        if ($role === 'patient' && $targetPatientId !== $userId) {
            http_response_code(403);
            die('Access denied.');
        }
        if ($role === 'doctor' && !isDoctorAuthorizedForPatient($pdo, $userId, $targetPatientId)) {
            http_response_code(403);
            die('Access denied.');
        }

        $stmt = $pdo->prepare("SELECT u.id, u.name, u.email, pp.contact_number FROM users u LEFT JOIN patient_profiles pp ON u.id = pp.user_id WHERE u.id = ? AND u.role = 'patient' LIMIT 1");
        $stmt->execute([$targetPatientId]);
        $patient = $stmt->fetch();
        if (!$patient) die('Patient not found.');

        $sql = "SELECT b.*, d.name AS doctor_name FROM bills b JOIN users d ON b.doctor_id = d.id WHERE b.patient_id = ?";
        $params = [$targetPatientId];
        if ($role === 'doctor') {
            $sql .= " AND b.doctor_id = ?";
            $params[] = $userId;
        }
        $sql .= " ORDER BY b.bill_date DESC, b.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bills = $stmt->fetchAll();

        $total = 0; $paid = 0; $unpaid = 0;
        foreach ($bills as $b) {
            if ($b['payment_status'] !== 'cancelled') {
                $total += (float)$b['amount'];
                if ($b['payment_status'] === 'paid') $paid += (float)$b['amount'];
                else $unpaid += (float)$b['amount'];
            }
        }

        if ($isDownload) {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="Expenses_Statement_Patient_' . $targetPatientId . '.html"');
        }
        ?>
        <!DOCTYPE html>
        <html lang="<?php echo htmlspecialchars($curLang); ?>">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo e_t('bills_pat_title'); ?> - <?php echo htmlspecialchars($patient['name']); ?></title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
                body{background:#f4f7fb;font-family:'Inter','Noto Sans Devanagari','Segoe UI',Arial,sans-serif;color:#1e293b;margin:0;}
                .toolbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px;display:flex;justify-content:space-between;align-items:center;}
                .sheet{max-width:860px;margin:28px auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:36px;box-shadow:0 8px 24px rgba(15,23,42,0.06);}
                .btn-action{display:inline-flex;align-items:center;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid #cbd5e1;cursor:pointer;background:#fff;color:#334155;}
                .btn-primary-action{background:#0284c7;border-color:#0284c7;color:#fff;}
                table{width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;}
                th,td{border:1px solid #e2e8f0;padding:10px 12px;text-align:left;}
                th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:11px;}
                .summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0;}
                .sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;}
                @media print{body{background:#fff;}.no-print{display:none!important;}.sheet{margin:0;border:none;box-shadow:none;}}
            </style>
        </head>
        <body>
        <?php if (!$isDownload): ?>
            <div class="toolbar no-print">
                <strong><?php echo e_t('bills_pat_title'); ?></strong>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="btn-action btn-primary-action" onclick="window.print()"><?php echo e_t('label_print_pdf'); ?></button>
                    <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?statement=1&patient_id=<?php echo $targetPatientId; ?>&download=1" class="btn-action"><?php echo e_t('label_download'); ?></a>
                    <a href="<?php echo getDashboardUrlForRole($role); ?>" class="btn-action"><?php echo e_t('nav_back'); ?></a>
                </div>
            </div>
        <?php endif; ?>
        <div class="sheet">
            <div style="display:flex;justify-content:space-between;border-bottom:2px solid #0284c7;padding-bottom:16px;margin-bottom:20px;">
                <div>
                    <h1 style="margin:0;font-size:22px;"><?php echo e_t('app_name'); ?> — <?php echo e_t('bills_pat_title'); ?></h1>
                    <div style="font-size:13px;color:#64748b;margin-top:4px;"><?php echo e_t('col_patient'); ?>: <strong><?php echo htmlspecialchars($patient['name']); ?></strong> (#<?php echo (int)$patient['id']; ?>) &bull; <?php echo htmlspecialchars($patient['email']); ?></div>
                </div>
                <div style="text-align:right;font-size:12px;color:#64748b;"><?php echo date('F d, Y'); ?></div>
            </div>
            <div class="summary">
                <div class="sum-card"><div style="font-size:11px;color:#64748b;text-transform:uppercase;"><?php echo e_t('kpi_total_billed'); ?></div><div style="font-size:20px;font-weight:700;"><?php echo formatCurrency($total); ?></div></div>
                <div class="sum-card"><div style="font-size:11px;color:#64748b;text-transform:uppercase;"><?php echo e_t('kpi_total_paid'); ?></div><div style="font-size:20px;font-weight:700;color:#059669;"><?php echo formatCurrency($paid); ?></div></div>
                <div class="sum-card"><div style="font-size:11px;color:#64748b;text-transform:uppercase;"><?php echo e_t('kpi_outstanding'); ?></div><div style="font-size:20px;font-weight:700;color:#d97706;"><?php echo formatCurrency($unpaid); ?></div></div>
            </div>
            <table>
                <thead><tr><th><?php echo e_t('col_bill_id'); ?></th><th><?php echo e_t('label_date'); ?></th><th><?php echo e_t('col_service_desc'); ?></th><th><?php echo e_t('col_doctor'); ?></th><th><?php echo e_t('label_status'); ?></th><th style="text-align:right;"><?php echo e_t('label_amount'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($bills as $b): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($b['bill_number']); ?></strong></td>
                            <td><?php echo formatDate($b['bill_date']); ?></td>
                            <td><?php echo htmlspecialchars($b['description']); ?></td>
                            <td><?php echo htmlspecialchars($b['doctor_name']); ?></td>
                            <td><?php echo htmlspecialchars(translateStatus($b['payment_status'])); ?></td>
                            <td style="text-align:right;"><strong><?php echo formatCurrency($b['amount']); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bills)): ?>
                        <tr><td colspan="6" style="text-align:center;color:#64748b;"><?php echo e_t('hist_empty_bills'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    if ($billId <= 0) {
        http_response_code(400);
        die('Invalid bill ID.');
    }

    $stmt = $pdo->prepare("
        SELECT b.*,
               p.name AS patient_name, p.email AS patient_email, pp.contact_number,
               d.name AS doctor_name, d.email AS doctor_email, dp.specialization, dp.room_number
        FROM bills b
        JOIN users p ON b.patient_id = p.id
        LEFT JOIN patient_profiles pp ON p.id = pp.user_id
        JOIN users d ON b.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE b.id = ?
        LIMIT 1
    ");
    $stmt->execute([$billId]);
    $bill = $stmt->fetch();

    if (!$bill) {
        http_response_code(404);
        die('Bill record not found.');
    }

    if ($role === 'patient' && intval($bill['patient_id']) !== $userId) {
        http_response_code(403);
        die('Access denied.');
    }
    if ($role === 'doctor' && intval($bill['doctor_id']) !== $userId && !isDoctorAuthorizedForPatient($pdo, $userId, intval($bill['patient_id']))) {
        http_response_code(403);
        die('Access denied.');
    }

    $items = json_decode($bill['items_json'] ?? '[]', true) ?: [];
    if (empty($items)) {
        $items[] = [
            'item' => $bill['description'],
            'category' => 'Healthcare Service',
            'qty' => 1,
            'unit_price' => (float)$bill['amount'],
            'total' => (float)$bill['amount']
        ];
    }

    $backUrl = $role === 'admin' ? BASE_URL . '/admin/bills.php' : ($role === 'doctor' ? BASE_URL . '/doctor/bills.php' : BASE_URL . '/patient/billing.php');

    if ($isDownload) {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $bill['bill_number']) . '.html"');
    }
} catch (PDOException $e) {
    error_log('Download Bill Error: ' . $e->getMessage());
    die(t('msg_error'));
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($curLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($bill['bill_number']); ?> - <?php echo e_t('app_name'); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
        body{background:#f4f7fb;font-family:'Inter','Noto Sans Devanagari','Segoe UI',Arial,sans-serif;color:#1e293b;margin:0;}
        .toolbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px;display:flex;justify-content:space-between;align-items:center;}
        .sheet{max-width:820px;margin:28px auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:38px;box-shadow:0 8px 24px rgba(15,23,42,0.06);}
        .btn-action{display:inline-flex;align-items:center;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid #cbd5e1;cursor:pointer;background:#fff;color:#334155;}
        .btn-primary-action{background:#0284c7;border-color:#0284c7;color:#fff;}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;}
        .box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;font-size:13px;line-height:1.6;}
        table{width:100%;border-collapse:collapse;margin-bottom:24px;font-size:14px;}
        th,td{border:1px solid #e2e8f0;padding:10px 12px;text-align:left;}
        th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:11px;}
        @media print{body{background:#fff;}.no-print{display:none!important;}.sheet{margin:0;border:none;box-shadow:none;}}
    </style>
</head>
<body>
<?php if (!$isDownload): ?>
<div class="toolbar no-print">
    <strong><?php echo htmlspecialchars($bill['bill_number']); ?></strong>
    <div style="display:flex;gap:8px;">
        <button type="button" class="btn-action btn-primary-action" onclick="window.print()"><?php echo e_t('label_print_pdf'); ?></button>
        <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?id=<?php echo (int)$bill['id']; ?>&download=1" class="btn-action"><?php echo e_t('label_download'); ?></a>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-action"><?php echo e_t('nav_back'); ?></a>
    </div>
</div>
<?php endif; ?>

<div class="sheet">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #0284c7;padding-bottom:18px;margin-bottom:22px;">
        <div>
            <h1 style="margin:0;font-size:22px;"><?php echo e_t('app_name'); ?></h1>
            <div style="font-size:13px;color:#64748b;margin-top:4px;"><?php echo e_t('clinical_care_system'); ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-weight:700;font-size:15px;"><?php echo htmlspecialchars($bill['bill_number']); ?></div>
            <div style="font-size:12px;color:#64748b;"><?php echo e_t('label_date'); ?>: <?php echo formatDate($bill['bill_date'], 'F d, Y'); ?></div>
            <div style="font-size:12px;margin-top:4px;"><?php echo e_t('label_status'); ?>: <strong><?php echo htmlspecialchars(translateStatus($bill['payment_status'])); ?></strong></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="box">
            <div style="font-size:11px;font-weight:700;color:#0284c7;text-transform:uppercase;margin-bottom:4px;"><?php echo e_t('receipt_patient_details'); ?></div>
            <strong><?php echo htmlspecialchars($bill['patient_name']); ?></strong> (#<?php echo (int)$bill['patient_id']; ?>)<br>
            <?php echo htmlspecialchars($bill['patient_email']); ?><br>
            <?php echo e_t('label_contact'); ?>: <?php echo htmlspecialchars($bill['contact_number'] ?? 'N/A'); ?>
        </div>
        <div class="box">
            <div style="font-size:11px;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:4px;"><?php echo e_t('receipt_attending_doc'); ?></div>
            <strong><?php echo htmlspecialchars($bill['doctor_name']); ?></strong><br>
            <?php echo htmlspecialchars($bill['specialization'] ?? ''); ?> &bull; <?php echo htmlspecialchars($bill['room_number'] ?? 'N/A'); ?><br>
            <?php echo htmlspecialchars($bill['doctor_email']); ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th><?php echo e_t('col_service_desc'); ?></th>
                <th>Category</th>
                <th style="text-align:center;">Qty</th>
                <th style="text-align:right;"><?php echo e_t('receipt_fee'); ?></th>
                <th style="text-align:right;"><?php echo e_t('label_amount'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?php echo htmlspecialchars($it['item'] ?? $bill['description']); ?></td>
                    <td><?php echo htmlspecialchars($it['category'] ?? 'Service'); ?></td>
                    <td style="text-align:center;"><?php echo intval($it['qty'] ?? 1); ?></td>
                    <td style="text-align:right;"><?php echo formatCurrency($it['unit_price'] ?? $bill['amount']); ?></td>
                    <td style="text-align:right;"><?php echo formatCurrency($it['total'] ?? $bill['amount']); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="4" style="text-align:right;font-weight:700;background:#f8fafc;"><?php echo e_t('label_amount'); ?></td>
                <td style="text-align:right;font-weight:700;background:#f8fafc;font-size:16px;"><?php echo formatCurrency($bill['amount']); ?></td>
            </tr>
        </tbody>
    </table>

    <div style="border-top:1px solid #e2e8f0;padding-top:14px;font-size:12px;color:#64748b;display:flex;justify-content:space-between;">
        <span><?php echo e_t('app_name'); ?></span>
        <span>#<?php echo (int)$bill['id']; ?></span>
    </div>
</div>
</body>
</html>
