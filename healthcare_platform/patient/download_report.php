<?php
/**
 * Clinical Diagnostic & Risk Assessment Report (No Grad-CAM, INR, Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient', 'doctor', 'admin']);

$current_user_id = getCurrentUserId();
$current_user_role = getCurrentUserRole();
$curLang = getCurrentLang();
$diagnostic_id = intval($_GET['id'] ?? 0);
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

if ($diagnostic_id <= 0) {
    http_response_code(400);
    die('Invalid diagnostic record ID specified.');
}

try {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT dr.*,
               u.name AS patient_name, u.email AS patient_email,
               pp.dob, pp.gender, pp.blood_group, pp.contact_number, pp.chronic_conditions,
               d.name AS doctor_name, d.email AS doctor_email,
               dp.specialization, dp.room_number
        FROM diagnostic_records dr
        JOIN users u ON dr.patient_id = u.id
        LEFT JOIN patient_profiles pp ON u.id = pp.user_id
        JOIN users d ON dr.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE dr.id = ?
        LIMIT 1
    ");
    $stmt->execute([$diagnostic_id]);
    $diagnostic = $stmt->fetch();

    if (!$diagnostic) {
        http_response_code(404);
        die('Diagnostic record not found.');
    }

    if ($current_user_role === 'patient' && intval($diagnostic['patient_id']) !== $current_user_id) {
        http_response_code(403);
        die('Access denied to this diagnostic record.');
    }
    if ($current_user_role === 'doctor' && intval($diagnostic['doctor_id']) !== $current_user_id && !isDoctorAuthorizedForPatient($pdo, $current_user_id, intval($diagnostic['patient_id']))) {
        http_response_code(403);
        die('Access denied to this diagnostic record.');
    }

    $stmt = $pdo->prepare("SELECT * FROM prescriptions_and_reminders WHERE diagnostic_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$diagnostic_id]);
    $prescription = $stmt->fetch();

    $vitals = json_decode($diagnostic['vitals_snapshot'] ?? '{}', true) ?: [];
} catch (PDOException $e) {
    error_log("Download Report Error: " . $e->getMessage());
    die(t('msg_error'));
}

$age = !empty($diagnostic['dob']) ? calculateAge($diagnostic['dob']) : '--';
$localScanPath = resolveScanFilePath($diagnostic['scan_file_path']);
$scanImageSrc = null;

if ($localScanPath && is_readable($localScanPath)) {
    if ($isDownload) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($localScanPath) ?: 'image/jpeg';
        $scanImageSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localScanPath));
    } else {
        $scanImageSrc = BASE_URL . '/includes/serve_scan.php?id=' . $diagnostic_id;
    }
}

$reportRef = 'IH-' . str_pad((string)$diagnostic_id, 6, '0', STR_PAD_LEFT);
$backUrl = getDashboardUrlForRole($current_user_role);

if ($isDownload) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="Diagnostic_Report_' . $reportRef . '.html"');
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($curLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e_t('col_report'); ?> #<?php echo $reportRef; ?> - <?php echo htmlspecialchars($diagnostic['patient_name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
        body { background: #f4f7fb; font-family: 'Inter', 'Noto Sans Devanagari', 'Segoe UI', Arial, sans-serif; color: #1e293b; margin: 0; }
        .doc-toolbar { background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .report-paper { max-width: 880px; margin: 28px auto; background: #ffffff; padding: 40px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
        .report-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0284c7; padding-bottom: 20px; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        .info-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; font-size: 13px; line-height: 1.6; }
        .section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #475569; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin: 0 0 12px; }
        .vitals-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-bottom: 24px; text-align: center; }
        .vital-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 6px; font-size: 12px; }
        .scan-box { background: #0f172a; border-radius: 10px; padding: 16px; text-align: center; color: #f8fafc; margin-bottom: 24px; }
        .scan-box img { max-width: 100%; max-height: 300px; object-fit: contain; border-radius: 6px; }
        .conclusion-box { border-radius: 10px; padding: 20px; margin-bottom: 24px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .conclusion-normal { background: #ecfdf5; border-color: #a7f3d0; }
        .conclusion-alert { background: #fef2f2; border-color: #fecaca; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 13px; }
        th, td { border: 1px solid #e2e8f0; padding: 9px 12px; text-align: left; }
        th { background: #f8fafc; color: #475569; }
        .btn-action { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid #cbd5e1; cursor: pointer; background: #fff; color: #334155; }
        .btn-primary-action { background: #0284c7; border-color: #0284c7; color: #fff; }
        @media (max-width: 720px) { .grid-2 { grid-template-columns: 1fr; } .vitals-grid { grid-template-columns: repeat(3, 1fr); } .report-paper { margin: 12px; padding: 20px; } }
        @media print { body { background: #fff; } .no-print { display: none !important; } .report-paper { margin: 0; border: none; box-shadow: none; padding: 16px; } }
    </style>
</head>
<body>

<?php if (!$isDownload): ?>
<div class="doc-toolbar no-print">
    <div style="font-weight:700; color:#0f172a;"><?php echo e_t('col_report'); ?> #<?php echo $reportRef; ?></div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn-action btn-primary-action" onclick="window.print()"><?php echo e_t('label_print_pdf'); ?></button>
        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo $diagnostic_id; ?>&download=1" class="btn-action"><?php echo e_t('btn_download_report'); ?></a>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-action"><?php echo e_t('nav_back_dashboard'); ?></a>
    </div>
</div>
<?php endif; ?>

<div class="report-paper">
    <div class="report-header">
        <div>
            <h1 style="margin:0; font-size:22px; color:#0f172a;"><?php echo e_t('app_name'); ?> — <?php echo e_t('result_page_title'); ?></h1>
            <div style="font-size:13px; color:#64748b; margin-top:4px;"><?php echo e_t('clinical_care_system'); ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:13px; font-weight:700;">#<?php echo $reportRef; ?></div>
            <div style="font-size:12px; color:#64748b;"><?php echo e_t('label_date'); ?>: <?php echo formatDate($diagnostic['created_at'], 'F d, Y • h:i A'); ?></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="info-card">
            <div style="font-size:11px; font-weight:700; color:#0284c7; text-transform:uppercase; margin-bottom:6px;"><?php echo e_t('receipt_patient_details'); ?></div>
            <div style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:4px;"><?php echo htmlspecialchars($diagnostic['patient_name']); ?></div>
            <div><strong><?php echo e_t('label_age'); ?>:</strong> <?php echo $age !== '--' ? $age . ' ' . e_t('label_years') : 'N/A'; ?> &bull; <strong><?php echo e_t('label_gender'); ?>:</strong> <?php echo htmlspecialchars(translateStatus($diagnostic['gender'] ?? 'Other')); ?></div>
            <div><strong><?php echo e_t('col_blood_group'); ?>:</strong> <?php echo htmlspecialchars($diagnostic['blood_group'] ?? 'N/A'); ?> &bull; <strong><?php echo e_t('label_contact'); ?>:</strong> <?php echo htmlspecialchars($diagnostic['contact_number'] ?? 'N/A'); ?></div>
            <div><strong><?php echo e_t('label_update_chronic'); ?>:</strong> <?php echo htmlspecialchars($diagnostic['chronic_conditions'] ?: t('none_reported')); ?></div>
        </div>
        <div class="info-card">
            <div style="font-size:11px; font-weight:700; color:#0d9488; text-transform:uppercase; margin-bottom:6px;"><?php echo e_t('receipt_attending_doc'); ?></div>
            <div style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:4px;"><?php echo htmlspecialchars($diagnostic['doctor_name']); ?></div>
            <div><strong><?php echo e_t('col_specialty'); ?>:</strong> <?php echo htmlspecialchars($diagnostic['specialization'] ?? ''); ?></div>
            <div><strong><?php echo e_t('col_room'); ?>:</strong> <?php echo htmlspecialchars($diagnostic['room_number'] ?? 'N/A'); ?></div>
            <div><strong><?php echo e_t('label_email'); ?>:</strong> <?php echo htmlspecialchars($diagnostic['doctor_email']); ?></div>
        </div>
    </div>

    <div class="section-title"><?php echo e_t('result_clinical_section'); ?></div>
    <p style="font-size:12px; color:#64748b; margin-top:-4px; margin-bottom:10px;"><?php echo e_t('result_clinical_explain'); ?></p>
    <div class="vitals-grid">
        <div class="vital-box"><div style="color:#64748b;"><?php echo e_t('result_vital_bp'); ?></div><strong><?php echo htmlspecialchars((string)($vitals['systolic_bp'] ?? '--')); ?>/<?php echo htmlspecialchars((string)($vitals['diastolic_bp'] ?? '--')); ?></strong><div style="color:#94a3b8;">mmHg</div></div>
        <div class="vital-box"><div style="color:#64748b;"><?php echo e_t('result_vital_glucose'); ?></div><strong><?php echo htmlspecialchars((string)($vitals['glucose'] ?? '--')); ?></strong><div style="color:#94a3b8;">mg/dL</div></div>
        <div class="vital-box"><div style="color:#64748b;"><?php echo e_t('result_vital_smoking'); ?></div><strong><?php echo htmlspecialchars((string)($vitals['smoking'] ?? '0')); ?></strong><div style="color:#94a3b8;">pack-yrs</div></div>
        <div class="vital-box"><div style="color:#64748b;"><?php echo e_t('result_vital_family'); ?></div><strong><?php echo htmlspecialchars(translateStatus($vitals['family_history'] ?? 'No')); ?></strong></div>
        <div class="vital-box"><div style="color:#64748b;"><?php echo e_t('result_vital_cea'); ?></div><strong><?php echo htmlspecialchars((string)($vitals['tumor_marker_cea'] ?? '0')); ?></strong><div style="color:#94a3b8;">ng/mL</div></div>
        <div class="vital-box"><div style="color:#64748b;"><?php echo e_t('result_vital_ca125'); ?></div><strong><?php echo htmlspecialchars((string)($vitals['tumor_marker_ca125'] ?? '0')); ?></strong><div style="color:#94a3b8;">U/mL</div></div>
    </div>

    <div class="section-title"><?php echo e_t('result_scan_card'); ?> (<?php echo htmlspecialchars($diagnostic['scan_type']); ?>)</div>
    <div class="scan-box">
        <?php if ($scanImageSrc): ?>
            <img src="<?php echo htmlspecialchars($scanImageSrc); ?>" alt="<?php echo e_t('result_scan_card'); ?>">
        <?php else: ?>
            <div style="padding:32px; color:#cbd5e1; font-size:14px;"><?php echo e_t('scan_unavailable'); ?></div>
        <?php endif; ?>
    </div>

    <?php
        $mlMeta = is_array($vitals['ml_assessment'] ?? null) ? $vitals['ml_assessment'] : null;
        $isBenignRep = ($mlMeta !== null && isset($mlMeta['is_benign']))
            ? (bool)$mlMeta['is_benign']
            : ($diagnostic['predicted_condition'] === 'Normal' || stripos((string)$diagnostic['predicted_condition'], 'Benign') !== false);
    ?>
    <div class="conclusion-box <?php echo $isBenignRep ? 'conclusion-normal' : 'conclusion-alert'; ?>">
        <div style="max-width:72%;">
            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#475569;"><?php echo e_t('result_predicted_cond'); ?></div>
            <h2 style="margin:4px 0; font-size:21px; color:#0f172a;"><?php echo htmlspecialchars($diagnostic['predicted_condition']); ?></h2>
            <div style="font-size:12px; color:#334155; margin-bottom:6px;">
                <?php echo e_t($isBenignRep ? 'result_image_analysis_note_benign' : 'result_image_analysis_note_abnormal'); ?>
            </div>
            <div style="font-size:12px; color:#475569;">
                <?php if (!empty($mlMeta['cancer_type'])): ?>
                    <?php echo e_t('ml_cancer_assessed'); ?>: <strong><?php echo htmlspecialchars($mlMeta['cancer_type']); ?></strong> &bull;
                <?php endif; ?>
                <?php echo e_t('result_stage'); ?>: <strong><?php echo e_t($isBenignRep ? 'result_status_benign' : 'result_status_abnormal'); ?></strong> &bull;
                <?php echo e_t('result_confidence'); ?>: <strong><?php echo number_format((float)$diagnostic['confidence_score'], 1); ?>%</strong>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#475569;"><?php echo e_t('result_risk_score'); ?></div>
            <div style="font-size:26px; font-weight:800; color:#0f172a;"><?php echo number_format((float)$diagnostic['clinical_risk_score'], 1); ?>%</div>
        </div>
    </div>

    <?php if (!empty($mlMeta['top_predictions']) && is_array($mlMeta['top_predictions'])): ?>
        <div class="info-card" style="margin-bottom:18px;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#0f172a; margin-bottom:4px;"><?php echo e_t('ml_top_probabilities'); ?></div>
            <div style="font-size:11px; color:#64748b; margin-bottom:10px;"><?php echo e_t('result_patterns_explain'); ?></div>
            <?php foreach ($mlMeta['top_predictions'] as $tp):
                $tpPct = min(100, max(0, (float)($tp['probability'] ?? 0)));
            ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:3px;">
                        <span><?php echo htmlspecialchars((string)($tp['label'] ?? $tp['raw_class'] ?? '')); ?></span>
                        <strong><?php echo number_format($tpPct, 1); ?>%</strong>
                    </div>
                    <div style="height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo round($tpPct, 1); ?>%; background:#0284c7;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 14px; font-size:12px; color:#78350f; margin-bottom:20px;">
        <div style="font-weight:700; margin-bottom:3px;"><?php echo e_t('result_safety_title'); ?></div>
        <?php echo e_t('ml_decision_disclaimer'); ?>
    </div>

    <?php if ($prescription): ?>
        <?php $medList = json_decode($prescription['medication_list'] ?? '[]', true) ?: []; ?>
        <div class="section-title"><?php echo e_t('rx_builder_title'); ?></div>
        <?php if (!empty($medList)): ?>
            <table>
                <thead>
                    <tr>
                        <th><?php echo e_t('col_medication'); ?></th>
                        <th><?php echo e_t('col_dosage'); ?></th>
                        <th><?php echo e_t('col_frequency'); ?></th>
                        <th><?php echo e_t('col_duration'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medList as $med): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($med['drug_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($med['dosage']); ?></td>
                            <td><?php echo htmlspecialchars($med['frequency']); ?></td>
                            <td><?php echo htmlspecialchars($med['duration']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($prescription['clinical_remedies_advice'])): ?>
            <div class="info-card" style="margin-bottom:12px;">
                <strong><?php echo e_t('label_clinical_advice'); ?>:</strong> <?php echo htmlspecialchars($prescription['clinical_remedies_advice']); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($prescription['next_followup_date'])): ?>
            <div style="font-size:13px; color:#334155;">
                <strong><?php echo e_t('label_followup_date'); ?>:</strong> <?php echo formatDate($prescription['next_followup_date'], 'F d, Y'); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div style="border-top:1px solid #e2e8f0; margin-top:28px; padding-top:16px; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:16px;">
        <div style="font-size:11px; color:#64748b; max-width:540px;">
            <?php echo substr(hash('sha256', $diagnostic['id'] . $diagnostic['created_at']), 0, 20); ?>
        </div>
        <div style="text-align:center;">
            <div style="border-bottom:1px solid #0f172a; padding-bottom:4px; font-weight:700; font-size:13px;">
                <?php echo htmlspecialchars($diagnostic['doctor_name']); ?>
            </div>
            <div style="font-size:11px; color:#64748b;"><?php echo e_t('receipt_attending_doc'); ?></div>
        </div>
    </div>
</div>

</body>
</html>
