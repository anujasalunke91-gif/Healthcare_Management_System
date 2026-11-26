<?php
/**
 * Simplified Doctor Risk Assessment Input Page (Step 1 of 2)
 * Clean single-focus form without redundant Result cards; redirects to assessment_result.php on completion.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['doctor']);

$doctor_id = getCurrentUserId();
$preselectedPatientId = intval($_GET['patient_id'] ?? 0);
$patients = [];
$error = '';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT u.id, u.name, pp.dob, pp.blood_group, pp.chronic_conditions, pp.contact_number FROM users u LEFT JOIN patient_profiles pp ON u.id = pp.user_id WHERE u.role = 'patient' ORDER BY u.name ASC");
    $stmt->execute();
    $patients = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Workstation Error: " . $e->getMessage());
    $error = t('msg_error');
}

$csrf_token = generateCSRFToken();
$page_title = t('risk_page_title');
$page_subtitle = t('risk_page_sub');
$active_nav = 'workstation';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-xl-9 col-lg-10">
        <div class="card-modern">
            <div class="card-header-modern">
                <h5 class="h6 fw-bold mb-0"><i class="bi bi-activity text-primary me-2"></i><?php echo e_t('risk_step1_heading'); ?></h5>
                <span class="badge-modern badge-modern-primary"><?php echo e_t('risk_step1_badge'); ?></span>
            </div>
            <div class="card-body-modern">
                <form id="diagnosticForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="patient_id" id="patient_id" value="">
                    <input type="hidden" name="doctor_id" value="<?php echo (int)$doctor_id; ?>">

                    <!-- Patient, Modality & Cancer Assessment Selection -->
                    <div class="row g-3 align-items-end mb-4">
                        <div class="col-md-4">
                            <label for="patientSelector" class="form-label-modern">
                                <span><i class="bi bi-person-fill text-primary me-1"></i> <?php echo e_t('label_select_patient'); ?></span>
                                <span class="text-danger">*</span>
                            </label>
                            <select class="form-select-modern" id="patientSelector" onchange="loadPatientInfo()">
                                <option value=""><?php echo e_t('opt_choose_patient'); ?></option>
                                <?php foreach ($patients as $patient): ?>
                                    <option value="<?php echo (int)$patient['id']; ?>"
                                            <?php echo $preselectedPatientId === (int)$patient['id'] ? 'selected' : ''; ?>
                                            data-dob="<?php echo htmlspecialchars($patient['dob'] ?? ''); ?>"
                                            data-blood-group="<?php echo htmlspecialchars($patient['blood_group'] ?? ''); ?>"
                                            data-chronic="<?php echo htmlspecialchars($patient['chronic_conditions'] ?? t('none_reported')); ?>"
                                            data-contact="<?php echo htmlspecialchars($patient['contact_number'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($patient['name']); ?> (ID #<?php echo (int)$patient['id']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="cancer_type" class="form-label-modern">
                                <span><i class="bi bi-cpu text-primary me-1"></i> <?php echo e_t('label_cancer_type'); ?></span>
                                <span class="text-danger">*</span>
                            </label>
                            <select class="form-select-modern" id="cancer_type" name="cancer_type" required>
                                <option value="Auto-Detect" selected><?php echo e_t('opt_cancer_auto'); ?></option>
                                <option value="Acute Lymphoblastic Leukemia (ALL)">Acute Lymphoblastic Leukemia (ALL)</option>
                                <option value="Brain Cancer">Brain Cancer</option>
                                <option value="Breast Cancer">Breast Cancer</option>
                                <option value="Cervical Cancer">Cervical Cancer</option>
                                <option value="Colon Cancer">Colon Cancer</option>
                                <option value="Kidney Cancer">Kidney Cancer</option>
                                <option value="Lung Cancer">Lung Cancer</option>
                                <option value="Lymphoma">Lymphoma</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="scan_type" class="form-label-modern">
                                <span><?php echo e_t('label_imaging_modality'); ?></span>
                                <span class="text-danger">*</span>
                            </label>
                            <select class="form-select-modern" id="scan_type" name="scan_type" required>
                                <option value="MRI" selected>MRI (Magnetic Resonance Imaging)</option>
                                <option value="CT-Scan">CT-Scan (Computed Tomography)</option>
                                <option value="X-Ray">X-Ray / Microscopy / Histopathology</option>
                            </select>
                        </div>
                    </div>

                    <div class="p-2 px-3 bg-primary-subtle border border-primary-subtle rounded-3 small text-primary-emphasis mb-4 d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle-fill"></i>
                        <span><?php echo e_t('ml_model_note'); ?></span>
                    </div>

                    <!-- Inline Patient Snapshot -->
                    <div id="patientInfo" class="d-none p-3 bg-slate-50 border border-slate-200 rounded-3 mb-4">
                        <div class="row g-2 align-items-center text-center text-sm-start">
                            <div class="col-6 col-sm-2">
                                <div class="text-muted extra-small fw-bold text-uppercase"><?php echo e_t('label_age'); ?></div>
                                <div class="fw-bold text-slate-900 small" id="patientAge">--</div>
                            </div>
                            <div class="col-6 col-sm-2">
                                <div class="text-muted extra-small fw-bold text-uppercase"><?php echo e_t('col_blood_group'); ?></div>
                                <div class="fw-bold text-danger small" id="patientBloodGroup">--</div>
                            </div>
                            <div class="col-6 col-sm-4">
                                <div class="text-muted extra-small fw-bold text-uppercase"><?php echo e_t('label_contact'); ?></div>
                                <div class="fw-bold text-slate-800 small text-truncate" id="patientContact">--</div>
                            </div>
                            <div class="col-6 col-sm-4">
                                <div class="text-muted extra-small fw-bold text-uppercase"><?php echo e_t('label_chronic'); ?></div>
                                <div class="fw-bold text-slate-800 small text-truncate" id="patientChronic">--</div>
                            </div>
                        </div>
                    </div>

                    <!-- Medical Scan Upload -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label-modern mb-0">
                                <span><?php echo e_t('label_scan_image'); ?></span>
                                <span class="text-danger ms-1">*</span>
                            </label>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-primary dropdown-toggle extra-small py-1 px-2" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-folder2-open me-1"></i><?php echo e_t('btn_sample_scans'); ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    <li>
                                        <button type="button" class="dropdown-item small" onclick="loadSampleScan('<?php echo BASE_URL; ?>/uploads/scans/scan_009ea33238bf4dbb958a50a8a8280176.jpeg', 'Brain_MRI_Sample_1.jpeg', 'MRI')">
                                            Cranial MRI Scan (1)
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item small" onclick="loadSampleScan('<?php echo BASE_URL; ?>/uploads/scans/scan_6ad26040489312.26797888.jpeg', 'Brain_MRI_Sample_2.jpeg', 'MRI')">
                                            Cranial MRI Scan (2)
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item small" onclick="loadSampleScan('<?php echo BASE_URL; ?>/uploads/scans/scan_6ad32dacb77495.48408853.jpeg', 'Thoracic_CT_Sample.jpeg', 'CT-Scan')">
                                            Thoracic CT-Scan
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="dropzone-modern" id="dropzone">
                            <div class="dropzone-content">
                                <div class="dropzone-icon-wrap"><i class="bi bi-cloud-arrow-up"></i></div>
                                <div class="fw-semibold text-slate-800 small mb-1"><?php echo e_t('dropzone_title'); ?></div>
                                <div class="text-muted extra-small mb-0"><?php echo e_t('dropzone_sub'); ?></div>
                                <input type="file" id="scanFile" name="scan_file" accept="image/jpeg,image/png,image/jpg" class="d-none">
                            </div>

                            <div id="previewContainer" class="d-none preview-box">
                                <img id="imagePreview" alt="<?php echo e_t('label_scan_image'); ?>">
                                <div class="position-absolute bottom-0 start-0 p-2 bg-dark bg-opacity-75 text-white extra-small w-100 d-flex justify-content-between align-items-center">
                                    <span id="fileNameBadge" class="text-truncate me-2">scan.jpg</span>
                                    <button type="button" class="btn btn-sm btn-danger py-0 px-2" onclick="clearImage(event)">
                                        <i class="bi bi-x"></i> <?php echo e_t('btn_remove'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Clinical Vitals Grid -->
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-3 mb-4">
                        <div class="fw-bold small text-slate-800 mb-3">
                            <i class="bi bi-heart-pulse text-danger me-1"></i> <?php echo e_t('vitals_heading'); ?>
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_sys_bp'); ?></label>
                                <input type="number" class="form-control-modern form-control-sm" name="systolic_bp" value="125" required>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_dia_bp'); ?></label>
                                <input type="number" class="form-control-modern form-control-sm" name="diastolic_bp" value="82" required>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_glucose'); ?></label>
                                <input type="number" class="form-control-modern form-control-sm" name="glucose" value="105" required>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_smoking'); ?></label>
                                <input type="number" step="0.1" class="form-control-modern form-control-sm" name="smoking" value="2.5">
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_family_hist'); ?></label>
                                <select class="form-select-modern form-select-sm" name="family_history">
                                    <option value="No"><?php echo e_t('opt_no_history'); ?></option>
                                    <option value="Yes" selected><?php echo e_t('opt_yes_history'); ?></option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_cea'); ?></label>
                                <input type="number" step="0.1" class="form-control-modern form-control-sm" name="tumor_marker_cea" value="3.8">
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_ca125'); ?></label>
                                <input type="number" step="0.1" class="form-control-modern form-control-sm" name="tumor_marker_ca125" value="22.4">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn-modern btn-modern-primary btn-modern-lg" id="analyzeBtn">
                            <i class="bi bi-activity me-1"></i><?php echo e_t('btn_run_assessment'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selector = document.getElementById('patientSelector');
    if (selector && selector.value) {
        loadPatientInfo();
    }
});
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
