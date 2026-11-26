<?php
/**
 * Dedicated Risk Assessment Result & Prescription Page (Step 2 of 2)
 * Displays stored model prediction, clinical vitals, medical scan (no Grad-CAM),
 * report download, and prescription builder that returns to doctor/patients.php on save.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

$doctorId = getCurrentUserId();
$diagnosticId = intval($_GET['id'] ?? 0);

if ($diagnosticId <= 0) {
    header('Location: ' . BASE_URL . '/doctor/workstation.php');
    exit;
}

$record = null;
$vitals = [];
$existingRx = null;
$error = '';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT dr.*, u.name AS patient_name, u.email AS patient_email,
               pp.dob, pp.gender, pp.blood_group, pp.contact_number, pp.chronic_conditions
        FROM diagnostic_records dr
        JOIN users u ON dr.patient_id = u.id
        LEFT JOIN patient_profiles pp ON u.id = pp.user_id
        WHERE dr.id = ?
        LIMIT 1
    ");
    $stmt->execute([$diagnosticId]);
    $record = $stmt->fetch();

    if (!$record) {
        http_response_code(404);
        die('Diagnostic record not found.');
    }

    if (intval($record['doctor_id']) !== $doctorId && !isDoctorAuthorizedForPatient($pdo, $doctorId, intval($record['patient_id']))) {
        http_response_code(403);
        die('Access denied to this assessment result.');
    }

    $vitals = json_decode($record['vitals_snapshot'] ?? '{}', true) ?: [];

    $stmt = $pdo->prepare("SELECT * FROM prescriptions_and_reminders WHERE diagnostic_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$diagnosticId]);
    $existingRx = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Assessment Result Error: ' . $e->getMessage());
    $error = t('msg_error');
}

$age = !empty($record['dob']) ? calculateAge($record['dob']) : '--';
$scanUrl = getScanWebUrl($record['id'], $record['scan_file_path']);
$risk = (float)($record['clinical_risk_score'] ?? 0);
$cond = $record['predicted_condition'] ?? 'Normal';
$stage = $record['predicted_stage'] ?? 'N/A';
$ml = is_array($vitals['ml_assessment'] ?? null) ? $vitals['ml_assessment'] : null;
$cancerCategory = $ml['cancer_type'] ?? null;
$isBenignResult = ($ml !== null && isset($ml['is_benign']))
    ? (bool)$ml['is_benign']
    : ($cond === 'Normal' || stripos($cond, 'Benign') !== false);

// Use stored ML decision-support actions if available, otherwise build guidance dynamically
$actions = (!empty($ml['actions']) && is_array($ml['actions'])) ? $ml['actions'] : [];
if (empty($actions)) {
    if ($isBenignResult) {
        $actions[] = 'Correlate findings with patient clinical history and routine screening guidelines';
        $actions[] = 'Maintain healthy lifestyle and regular physician follow-ups';
        $actions[] = 'Clinical review remains required before finalizing patient care';
    } else {
        $actions[] = 'Specialist oncology / radiology consultation advised';
        $actions[] = 'Confirmatory histopathology or targeted diagnostic workup recommended before formal diagnosis';
        if ($risk > 50) {
            $actions[] = 'Elevated clinical biomarker/vital risk factors present — prioritize follow-up';
        }
    }
}

$csrf_token = generateCSRFToken();
$page_title = t('result_page_title');
$page_subtitle = t('result_page_sub');
$active_nav = 'workstation';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Top Navigation & Report Actions -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge-modern badge-modern-primary"><?php echo e_t('result_step2_badge'); ?></span>
        <span class="small text-muted">#IH-<?php echo str_pad((string)$record['id'], 6, '0', STR_PAD_LEFT); ?> &bull; <?php echo formatDate($record['created_at'], 'M d, Y h:i A'); ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$record['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-eye"></i> <?php echo e_t('btn_view_report'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$record['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
            <i class="bi bi-download"></i> <?php echo e_t('btn_download_report'); ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/doctor/workstation.php" class="btn-modern btn-modern-outline btn-modern-sm">
            <i class="bi bi-plus-circle"></i> <?php echo e_t('btn_new_risk'); ?>
        </a>
    </div>
</div>

<!-- A. Concise Assessment Information Strip -->
<div class="ra-info-strip mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pb-2 mb-3 border-bottom">
        <span class="ra-section-label mb-0"><i class="bi bi-clipboard2-pulse text-primary me-1"></i> <?php echo e_t('result_assessment_info'); ?></span>
        <span class="ra-status-pill <?php echo $isBenignResult ? 'ra-pill-benign' : 'ra-pill-attention'; ?>">
            <i class="bi <?php echo $isBenignResult ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
            <?php echo e_t($isBenignResult ? 'result_status_benign' : 'result_status_abnormal'); ?>
        </span>
    </div>
    <div class="ra-info-grid">
        <div>
            <div class="ra-info-label"><?php echo e_t('nav_patients'); ?></div>
            <div class="ra-info-value"><?php echo htmlspecialchars($record['patient_name']); ?></div>
            <div class="extra-small text-muted mt-1">
                <?php echo e_t('label_patient_id'); ?> #<?php echo (int)$record['patient_id']; ?> &bull;
                <?php echo e_t('label_age'); ?>: <?php echo $age !== '--' ? $age . ' ' . e_t('label_yrs') : '--'; ?>
            </div>
        </div>
        <div>
            <div class="ra-info-label"><?php echo e_t('col_blood_group'); ?> &amp; <?php echo e_t('label_contact'); ?></div>
            <div class="ra-info-value text-danger"><?php echo htmlspecialchars($record['blood_group'] ?? 'N/A'); ?></div>
            <div class="extra-small text-muted mt-1"><?php echo htmlspecialchars($record['contact_number'] ?? 'N/A'); ?></div>
        </div>
        <div>
            <div class="ra-info-label"><?php echo e_t('ml_cancer_assessed'); ?></div>
            <div class="ra-info-value"><?php echo htmlspecialchars($cancerCategory ?: $stage); ?></div>
            <div class="extra-small text-muted mt-1"><?php echo htmlspecialchars($stage); ?></div>
        </div>
        <div>
            <div class="ra-info-label"><?php echo e_t('label_imaging_modality'); ?></div>
            <div class="ra-info-value"><?php echo htmlspecialchars($record['scan_type']); ?></div>
            <div class="extra-small text-muted mt-1">#IH-<?php echo str_pad((string)$record['id'], 6, '0', STR_PAD_LEFT); ?></div>
        </div>
    </div>
</div>

<!-- B, C, D. Primary Image Screening & Scan Viewer Card -->
<div class="card-modern mb-4">
    <div class="card-body-modern">
        <div class="row g-4 align-items-stretch">
            <!-- Left: Uploaded Medical Scan -->
            <div class="col-lg-5">
                <div class="scan-viewer-card h-100 d-flex flex-column">
                    <div class="scan-viewer-header extra-small">
                        <span><i class="bi bi-image me-1 text-info"></i> <?php echo e_t('result_scan_card'); ?></span>
                        <span class="badge bg-secondary bg-opacity-50 fw-normal"><?php echo htmlspecialchars($record['scan_type']); ?></span>
                    </div>
                    <div class="scan-display-area flex-grow-1">
                        <?php if ($scanUrl): ?>
                            <img src="<?php echo htmlspecialchars($scanUrl); ?>" alt="<?php echo e_t('result_scan_card'); ?>" class="img-fluid">
                        <?php else: ?>
                            <div class="text-white-50 small text-center"><?php echo e_t('scan_unavailable'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Image Analysis Result, Prediction Confidence & Possible Image Patterns -->
            <div class="col-lg-7 d-flex flex-column gap-3">
                <!-- B. Main Result -->
                <div class="ra-hero-result <?php echo $isBenignResult ? 'ra-benign' : 'ra-attention'; ?>">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <span class="ra-section-label"><?php echo e_t('result_predicted_cond'); ?></span>
                        <span class="ra-status-pill <?php echo $isBenignResult ? 'ra-pill-benign' : 'ra-pill-attention'; ?>">
                            <i class="bi <?php echo $isBenignResult ? 'bi-shield-check' : 'bi-search-heart'; ?>"></i>
                            <?php echo e_t($isBenignResult ? 'result_status_benign' : 'result_status_abnormal'); ?>
                        </span>
                    </div>
                    <div class="ra-result-title mb-2"><?php echo htmlspecialchars($cond); ?></div>
                    <p class="small text-slate-700 mb-0 lh-base">
                        <?php echo e_t($isBenignResult ? 'result_image_analysis_note_benign' : 'result_image_analysis_note_abnormal'); ?>
                    </p>
                </div>

                <!-- C. Prediction Confidence -->
                <?php $confPct = min(100, max(0, (float)$record['confidence_score'])); ?>
                <div class="ra-sub-panel">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold small text-slate-800"><?php echo e_t('result_confidence'); ?></span>
                        <span class="fw-bold fs-6 text-primary tabular-nums"><?php echo number_format($confPct, 1); ?>%</span>
                    </div>
                    <div class="ra-pattern-track mb-2">
                        <div class="ra-pattern-fill" style="width: <?php echo round($confPct, 1); ?>%"></div>
                    </div>
                    <p class="extra-small text-muted mb-0 lh-sm">
                        <i class="bi bi-info-circle me-1"></i><?php echo e_t('result_conf_explain'); ?>
                    </p>
                </div>

                <!-- D. Possible Image Patterns -->
                <?php if (!empty($ml['top_predictions']) && is_array($ml['top_predictions'])): ?>
                    <div class="ra-sub-panel">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold small text-slate-800"><?php echo e_t('ml_top_probabilities'); ?></span>
                        </div>
                        <p class="extra-small text-muted mb-3 lh-sm"><?php echo e_t('result_patterns_explain'); ?></p>
                        <div>
                            <?php foreach ($ml['top_predictions'] as $idx => $tp):
                                $pct = min(100, max(0, (float)($tp['probability'] ?? 0)));
                                $label = (string)($tp['label'] ?? $tp['raw_class'] ?? '');
                            ?>
                                <div class="ra-pattern-row">
                                    <div class="d-flex justify-content-between align-items-center small">
                                        <span class="<?php echo $idx === 0 ? 'fw-semibold text-slate-900' : 'text-slate-700'; ?>"><?php echo htmlspecialchars($label); ?></span>
                                        <span class="fw-bold tabular-nums text-slate-800"><?php echo number_format($pct, 1); ?>%</span>
                                    </div>
                                    <div class="ra-pattern-track">
                                        <div class="ra-pattern-fill <?php echo $idx === 0 ? '' : 'ra-fill-muted'; ?>" style="width: <?php echo round($pct, 1); ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- E. Clinical Risk Indicators & Recorded Health Vitals (Separate from Image Analysis) -->
<div class="card-modern mb-4">
    <div class="card-header-modern">
        <div>
            <h5 class="h6 fw-bold text-slate-900 mb-0">
                <i class="bi bi-heart-pulse text-danger me-2"></i><?php echo e_t('result_clinical_section'); ?>
            </h5>
            <div class="extra-small text-muted mt-1"><?php echo e_t('result_clinical_explain'); ?></div>
        </div>
    </div>
    <div class="card-body-modern">
        <div class="ra-sub-panel bg-white mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <span class="fw-bold small text-slate-800"><?php echo e_t('result_risk_score'); ?></span>
                <span class="fw-bold fs-5 text-slate-900 tabular-nums"><?php echo number_format($risk, 1); ?>%</span>
            </div>
            <div class="progress mb-2" style="height: 16px; border-radius: var(--radius-full);">
                <div class="progress-bar fw-bold <?php echo $risk < 30 ? 'bg-success' : ($risk < 60 ? 'bg-warning text-dark' : 'bg-danger'); ?>"
                     style="width: <?php echo min(100, max(0, round($risk))); ?>%">
                </div>
            </div>
            <div class="small text-slate-700">
                <?php if ($risk < 20): ?>
                    <span class="badge-modern badge-modern-success me-1"><?php echo e_t('risk_low'); ?></span> <?php echo e_t('risk_low_desc'); ?>
                <?php elseif ($risk < 40): ?>
                    <span class="badge-modern badge-modern-warning me-1"><?php echo e_t('risk_moderate'); ?></span> <?php echo e_t('risk_moderate_desc'); ?>
                <?php elseif ($risk < 60): ?>
                    <span class="badge-modern badge-modern-danger me-1"><?php echo e_t('risk_high'); ?></span> <?php echo e_t('risk_high_desc'); ?>
                <?php else: ?>
                    <span class="badge-modern badge-modern-danger me-1"><?php echo e_t('risk_critical'); ?></span> <?php echo e_t('risk_critical_desc'); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="ra-section-label mb-2"><?php echo e_t('result_vitals_recorded'); ?></div>
        <div class="row g-2 text-center small">
            <div class="col-6 col-sm-4 col-md-2">
                <div class="ra-vital-tile">
                    <div class="extra-small text-muted mb-1"><?php echo e_t('result_vital_bp'); ?></div>
                    <strong class="text-slate-900"><?php echo htmlspecialchars((string)($vitals['systolic_bp'] ?? '--')); ?>/<?php echo htmlspecialchars((string)($vitals['diastolic_bp'] ?? '--')); ?></strong>
                    <div class="extra-small text-muted">mmHg</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="ra-vital-tile">
                    <div class="extra-small text-muted mb-1"><?php echo e_t('result_vital_glucose'); ?></div>
                    <strong class="text-slate-900"><?php echo htmlspecialchars((string)($vitals['glucose'] ?? '--')); ?></strong>
                    <div class="extra-small text-muted">mg/dL</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="ra-vital-tile">
                    <div class="extra-small text-muted mb-1"><?php echo e_t('result_vital_smoking'); ?></div>
                    <strong class="text-slate-900"><?php echo htmlspecialchars((string)($vitals['smoking'] ?? '0')); ?></strong>
                    <div class="extra-small text-muted">pack-yrs</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="ra-vital-tile">
                    <div class="extra-small text-muted mb-1"><?php echo e_t('result_vital_family'); ?></div>
                    <strong class="text-slate-900"><?php echo htmlspecialchars(translateStatus($vitals['family_history'] ?? 'No')); ?></strong>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="ra-vital-tile">
                    <div class="extra-small text-muted mb-1"><?php echo e_t('result_vital_cea'); ?></div>
                    <strong class="text-slate-900"><?php echo htmlspecialchars((string)($vitals['tumor_marker_cea'] ?? '0')); ?></strong>
                    <div class="extra-small text-muted">ng/mL</div>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="ra-vital-tile">
                    <div class="extra-small text-muted mb-1"><?php echo e_t('result_vital_ca125'); ?></div>
                    <strong class="text-slate-900"><?php echo htmlspecialchars((string)($vitals['tumor_marker_ca125'] ?? '0')); ?></strong>
                    <div class="extra-small text-muted">U/mL</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- F. Medical Safety Notice & Recommended Next Care Steps -->
<div class="ra-notice-box mb-4 d-flex align-items-start gap-3">
    <i class="bi bi-shield-exclamation text-warning fs-4 mt-1"></i>
    <div>
        <div class="fw-bold small mb-1"><?php echo e_t('result_safety_title'); ?></div>
        <div class="small lh-base"><?php echo e_t('ml_decision_disclaimer'); ?></div>
    </div>
</div>

<div class="card-modern mb-4">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold text-slate-900 mb-0">
            <i class="bi bi-journal-medical text-primary me-2"></i><?php echo e_t('result_protocol'); ?>
        </h5>
    </div>
    <div class="card-body-modern">
        <div class="row g-2">
            <?php foreach ($actions as $act): ?>
                <div class="col-md-6">
                    <div class="d-flex align-items-start gap-2 p-2 px-3 bg-slate-50 border rounded-3 small h-100">
                        <i class="bi bi-check-circle-fill text-success mt-1"></i>
                        <span class="text-slate-800"><?php echo htmlspecialchars($act); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Prescription Builder (Navigates back to doctor/patients.php on save) -->
<div class="card-modern">
    <div class="card-header-modern">
        <h5 class="h6 fw-bold mb-0"><i class="bi bi-prescription2 text-secondary me-2"></i><?php echo e_t('rx_builder_title'); ?></h5>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPrescriptionRow()">
            <i class="bi bi-plus-lg me-1"></i> <?php echo e_t('btn_add_med'); ?>
        </button>
    </div>
    <div class="card-body-modern">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" id="diagnosticId" value="<?php echo (int)$record['id']; ?>">
        <input type="hidden" id="patient_id" value="<?php echo (int)$record['patient_id']; ?>">
        <input type="hidden" name="doctor_id" value="<?php echo (int)$doctorId; ?>">

        <div class="table-responsive mb-3">
            <table class="table-modern bg-white rounded-3 border" id="prescriptionTable">
                <thead>
                    <tr>
                        <th><?php echo e_t('col_medication'); ?></th>
                        <th><?php echo e_t('col_dosage'); ?></th>
                        <th><?php echo e_t('col_frequency'); ?></th>
                        <th><?php echo e_t('col_duration'); ?></th>
                        <th class="text-center" style="width: 48px;"></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-7">
                <label for="clinicalAdvice" class="form-label-modern extra-small"><?php echo e_t('label_clinical_advice'); ?></label>
                <textarea class="form-control-modern form-control-sm" id="clinicalAdvice" rows="2" placeholder="<?php echo e_t('ph_clinical_advice'); ?>"></textarea>
            </div>
            <div class="col-md-5">
                <label for="followupDate" class="form-label-modern extra-small"><?php echo e_t('label_followup_date'); ?></label>
                <input type="date" class="form-control-modern form-control-sm" id="followupDate">
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button type="button" id="savePrescriptionBtn" class="btn-modern btn-modern-success" onclick="savePrescription()">
                <i class="bi bi-check2-circle"></i> <?php echo e_t('btn_save_rx'); ?>
            </button>
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    addPrescriptionRow();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const followupDateInput = document.getElementById('followupDate');
    if (followupDateInput) {
        followupDateInput.min = tomorrow.toISOString().split('T')[0];
    }
});
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
