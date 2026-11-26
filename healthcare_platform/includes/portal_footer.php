<?php
/**
 * Shared Portal Layout Footer
 */
if (!defined('APP_ACCESS')) {
    exit('Direct access forbidden');
}
$__role = getCurrentUserRole();
?>
        </main>

        <footer class="portal-footer">
            <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-2">
                <div class="d-flex flex-wrap align-items-center gap-2 small">
                    <span class="portal-footer-role-pill">
                        <i class="bi bi-heart-pulse-fill"></i>
                        <?php echo htmlspecialchars(t('portal_' . ($__role ?: 'patient'))); ?>
                    </span>
                    <span class="fw-medium">&copy; <?php echo date('Y'); ?> <?php echo e_t('app_name'); ?>. <?php echo e_t('footer_portal_note'); ?></span>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-3 small">
                    <a href="<?php echo getDashboardUrlForRole($__role); ?>" class="footer-link"><?php echo e_t('nav_dashboard'); ?></a>
                    <?php if ($__role === 'admin'): ?>
                        <a href="<?php echo BASE_URL; ?>/admin/manage_users.php" class="footer-link"><?php echo e_t('nav_users_roles'); ?></a>
                        <a href="<?php echo BASE_URL; ?>/admin/appointments.php" class="footer-link"><?php echo e_t('nav_appointments'); ?></a>
                        <a href="<?php echo BASE_URL; ?>/admin/bills.php" class="footer-link"><?php echo e_t('nav_billing_invoices'); ?></a>
                    <?php elseif ($__role === 'doctor'): ?>
                        <a href="<?php echo BASE_URL; ?>/doctor/patients.php" class="footer-link"><?php echo e_t('nav_my_patients'); ?></a>
                        <a href="<?php echo BASE_URL; ?>/doctor/workstation.php" class="footer-link"><?php echo e_t('nav_risk_assessment'); ?></a>
                        <a href="<?php echo BASE_URL; ?>/doctor/appointments.php" class="footer-link"><?php echo e_t('nav_appointments'); ?></a>
                        <a href="<?php echo BASE_URL; ?>/doctor/bills.php" class="footer-link"><?php echo e_t('nav_patient_bills'); ?></a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>/patient/appointments.php" class="footer-link"><?php echo e_t('nav_appointments'); ?></a>
                        <a href="<?php echo BASE_URL; ?>/patient/billing.php" class="footer-link"><?php echo e_t('nav_bills_expenses'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    window.APP_LANG = <?php echo json_encode(getCurrentLang()); ?>;
    window.I18N = <?php echo json_encode(getClientTranslations(), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
<?php if (!empty($extra_scripts)) echo $extra_scripts; ?>
</body>
</html>
