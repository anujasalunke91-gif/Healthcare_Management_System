<?php
/**
 * Shared Public Footer
 */
if (!defined('APP_ACCESS')) {
    exit('Direct access forbidden');
}
?>
<footer class="public-footer">
    <div class="container-xl">
        <div class="row g-4 align-items-start pb-4 public-footer-top">
            <div class="col-lg-5">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="brand-icon" style="width:34px;height:34px;font-size:1rem;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-heart-pulse-fill text-white"></i>
                    </div>
                    <span class="fw-bold fs-5 footer-heading font-heading"><?php echo e_t('app_name'); ?></span>
                </div>
                <p class="footer-muted small mb-0" style="max-width: 400px;">
                    <?php echo e_t('footer_public_desc'); ?>
                </p>
            </div>
            <div class="col-6 col-lg-3">
                <div class="fw-semibold small footer-heading mb-2"><?php echo e_t('footer_platform'); ?></div>
                <ul class="list-unstyled small mb-0 d-flex flex-column gap-1">
                    <li><a href="<?php echo BASE_URL; ?>/#overview" class="footer-link"><?php echo e_t('nav_overview'); ?></a></li>
                    <li><a href="<?php echo BASE_URL; ?>/#portals" class="footer-link"><?php echo e_t('nav_portals'); ?></a></li>
                    <li><a href="<?php echo BASE_URL; ?>/#capabilities" class="footer-link"><?php echo e_t('nav_workflow'); ?></a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-4">
                <div class="fw-semibold small footer-heading mb-2"><?php echo e_t('footer_portal_access'); ?></div>
                <ul class="list-unstyled small mb-0 d-flex flex-column gap-1">
                    <li><a href="<?php echo BASE_URL; ?>/auth/login.php" class="footer-link"><?php echo e_t('footer_sign_in_portal'); ?></a></li>
                    <li><a href="<?php echo BASE_URL; ?>/auth/register.php" class="footer-link"><?php echo e_t('footer_reg_link'); ?></a></li>
                </ul>
            </div>
        </div>
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center pt-3 gap-2 small footer-muted">
            <div>&copy; <?php echo date('Y'); ?> <?php echo e_t('app_name'); ?>. <?php echo e_t('footer_rights'); ?></div>
            <div><?php echo e_t('footer_role_note'); ?></div>
        </div>
    </div>
</footer>
