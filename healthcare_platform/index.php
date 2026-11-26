<?php
/**
 * MedCore Health - Landing Page (Translated: EN / MR / HI)
 */

require_once __DIR__ . '/config/db.php';
configureSecureSession();

if (isAuthenticated()) {
    header('Location: ' . getDashboardUrlForRole(getCurrentUserRole()));
    exit;
}
$curLang = getCurrentLang();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($curLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e_t('app_name'); ?> - <?php echo e_t('landing_title_full'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
</head>
<body>

    <?php include __DIR__ . '/includes/public_header.php'; ?>

    <section id="overview" class="landing-hero">
        <div class="container-xl">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <div class="landing-badge">
                        <span class="status-dot status-dot-active"></span>
                        <?php echo e_t('landing_badge'); ?>
                    </div>
                    <h1 class="landing-title">
                        <?php echo e_t('landing_h1_start'); ?> <span><?php echo e_t('landing_h1_highlight'); ?></span> <?php echo e_t('landing_h1_end'); ?>
                    </h1>
                    <p class="landing-subtitle">
                        <?php echo e_t('landing_subtitle'); ?>
                    </p>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn-modern btn-modern-primary btn-modern-lg">
                            <i class="bi bi-box-arrow-in-right"></i> <?php echo e_t('landing_cta_signin'); ?>
                        </a>
                        <a href="<?php echo BASE_URL; ?>/auth/register.php" class="btn-modern btn-modern-outline btn-modern-lg">
                            <i class="bi bi-person-plus"></i> <?php echo e_t('landing_cta_register'); ?>
                        </a>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-4 text-muted small">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span><?php echo e_t('landing_check_1'); ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span><?php echo e_t('landing_check_2'); ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span><?php echo e_t('landing_check_3'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card-modern p-4 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <span class="status-dot status-dot-active"></span>
                                <span class="fw-bold small text-uppercase text-slate-700"><?php echo e_t('landing_preview_title'); ?></span>
                            </div>
                            <span class="badge-modern badge-modern-primary"><?php echo e_t('landing_preview_badge'); ?></span>
                        </div>

                        <div class="scan-viewer-card mb-3">
                            <div class="scan-viewer-header small">
                                <span><i class="bi bi-file-earmark-medical me-1 text-info"></i> <?php echo e_t('landing_preview_scan'); ?></span>
                                <span class="badge bg-secondary bg-opacity-50 fw-normal">DICOM / JPEG</span>
                            </div>
                            <div class="p-3 text-center">
                                <img src="<?php echo BASE_URL; ?>/uploads/scans/scan_009ea33238bf4dbb958a50a8a8280176.jpeg"
                                     alt="<?php echo e_t('landing_preview_scan'); ?>"
                                     class="img-fluid rounded"
                                     style="max-height: 200px; object-fit: contain;">
                            </div>
                        </div>

                        <div class="p-3 bg-slate-50 border border-slate-200 rounded-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small fw-semibold text-slate-700"><?php echo e_t('landing_preview_sub'); ?></span>
                                <span class="badge-modern badge-modern-info"><?php echo e_t('landing_preview_struct'); ?></span>
                            </div>
                            <div class="row g-2 text-center small">
                                <div class="col-4">
                                    <div class="p-2 bg-white border rounded-2">
                                        <div class="text-muted extra-small"><?php echo e_t('landing_modality'); ?></div>
                                        <div class="fw-bold text-slate-900">MRI</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="p-2 bg-white border rounded-2">
                                        <div class="text-muted extra-small"><?php echo e_t('landing_risk_fusion'); ?></div>
                                        <div class="fw-bold text-primary"><?php echo e_t('landing_vitals_scan'); ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="p-2 bg-white border rounded-2">
                                        <div class="text-muted extra-small"><?php echo e_t('landing_output'); ?></div>
                                        <div class="fw-bold text-success"><?php echo e_t('landing_pdf_report'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid">
                            <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn-modern btn-modern-secondary">
                                <i class="bi bi-arrow-right-circle"></i> <?php echo e_t('landing_access_portals'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="portals" class="py-5 bg-white border-top border-bottom">
        <div class="container-xl py-2">
            <div class="text-center mx-auto mb-5" style="max-width: 640px;">
                <div class="landing-badge mb-2"><?php echo e_t('landing_portals_badge'); ?></div>
                <h2 class="h2 fw-bold text-slate-900 mb-2"><?php echo e_t('landing_portals_heading'); ?></h2>
                <p class="text-muted mb-0"><?php echo e_t('landing_portals_sub'); ?></p>
            </div>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="portal-access-card">
                        <div>
                            <div class="kpi-icon-wrap kpi-icon-primary"><i class="bi bi-clipboard2-pulse"></i></div>
                            <h4 class="h5 fw-bold mb-2"><?php echo e_t('landing_doc_title'); ?></h4>
                            <p class="text-muted small mb-3"><?php echo e_t('landing_doc_desc'); ?></p>
                            <ul class="list-unstyled small text-slate-600 mb-4 d-flex flex-column gap-2">
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_doc_f1'); ?></li>
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_doc_f2'); ?></li>
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_doc_f3'); ?></li>
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_doc_f4'); ?></li>
                            </ul>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn-modern btn-modern-primary w-100">
                            <?php echo e_t('landing_doc_btn'); ?> <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="portal-access-card">
                        <div>
                            <div class="kpi-icon-wrap kpi-icon-success"><i class="bi bi-person-heart"></i></div>
                            <h4 class="h5 fw-bold mb-2"><?php echo e_t('landing_pat_title'); ?></h4>
                            <p class="text-muted small mb-3"><?php echo e_t('landing_pat_desc'); ?></p>
                            <ul class="list-unstyled small text-slate-600 mb-4 d-flex flex-column gap-2">
                                <li><i class="bi bi-check2 text-success me-2"></i><?php echo e_t('landing_pat_f1'); ?></li>
                                <li><i class="bi bi-check2 text-success me-2"></i><?php echo e_t('landing_pat_f2'); ?></li>
                                <li><i class="bi bi-check2 text-success me-2"></i><?php echo e_t('landing_pat_f3'); ?></li>
                                <li><i class="bi bi-check2 text-success me-2"></i><?php echo e_t('landing_pat_f4'); ?></li>
                            </ul>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn-modern btn-modern-secondary w-100">
                            <?php echo e_t('landing_pat_btn'); ?> <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="portal-access-card">
                        <div>
                            <div class="kpi-icon-wrap kpi-icon-accent"><i class="bi bi-speedometer2"></i></div>
                            <h4 class="h5 fw-bold mb-2"><?php echo e_t('landing_adm_title'); ?></h4>
                            <p class="text-muted small mb-3"><?php echo e_t('landing_adm_desc'); ?></p>
                            <ul class="list-unstyled small text-slate-600 mb-4 d-flex flex-column gap-2">
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_adm_f1'); ?></li>
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_adm_f2'); ?></li>
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_adm_f3'); ?></li>
                                <li><i class="bi bi-check2 text-primary me-2"></i><?php echo e_t('landing_adm_f4'); ?></li>
                            </ul>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn-modern btn-modern-outline w-100">
                            <?php echo e_t('landing_adm_btn'); ?> <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="capabilities" class="py-5">
        <div class="container-xl py-2">
            <div class="row g-4 align-items-center">
                <div class="col-lg-5">
                    <div class="landing-badge mb-2"><?php echo e_t('landing_cap_badge'); ?></div>
                    <h2 class="h2 fw-bold text-slate-900 mb-3"><?php echo e_t('landing_cap_heading'); ?></h2>
                    <p class="text-muted mb-0"><?php echo e_t('landing_cap_sub'); ?></p>
                </div>
                <div class="col-lg-7">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="card-modern p-3 h-100">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-calendar2-check text-primary fs-5"></i>
                                    <h6 class="fw-bold mb-0"><?php echo e_t('landing_cap1_title'); ?></h6>
                                </div>
                                <p class="text-muted small mb-0"><?php echo e_t('landing_cap1_desc'); ?></p>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card-modern p-3 h-100">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-activity text-success fs-5"></i>
                                    <h6 class="fw-bold mb-0"><?php echo e_t('landing_cap2_title'); ?></h6>
                                </div>
                                <p class="text-muted small mb-0"><?php echo e_t('landing_cap2_desc'); ?></p>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card-modern p-3 h-100">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-receipt-cutoff text-warning fs-5"></i>
                                    <h6 class="fw-bold mb-0"><?php echo e_t('landing_cap3_title'); ?></h6>
                                </div>
                                <p class="text-muted small mb-0"><?php echo e_t('landing_cap3_desc'); ?></p>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="card-modern p-3 h-100">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-shield-lock text-info fs-5"></i>
                                    <h6 class="fw-bold mb-0"><?php echo e_t('landing_cap4_title'); ?></h6>
                                </div>
                                <p class="text-muted small mb-0"><?php echo e_t('landing_cap4_desc'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/includes/public_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.APP_LANG = <?php echo json_encode($curLang); ?>;
        window.I18N = <?php echo json_encode(getClientTranslations(), JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>
