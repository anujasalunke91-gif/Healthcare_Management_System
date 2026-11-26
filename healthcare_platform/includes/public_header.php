<?php
/**
 * Shared Public Header (Landing & Auth Pages) with Language Switcher
 */
if (!defined('APP_ACCESS')) {
    exit('Direct access forbidden');
}
$__curLang = getCurrentLang();
$__langs = getSupportedLanguages();
$__curUri = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/');
?>
<header class="navbar-modern">
    <div class="container-xl d-flex align-items-center justify-content-between gap-3">
        <a class="navbar-brand-modern" href="<?php echo BASE_URL; ?>/">
            <div class="brand-icon">
                <i class="bi bi-heart-pulse-fill"></i>
            </div>
            <div>
                <div class="brand-title"><?php echo e_t('app_name'); ?></div>
                <div class="brand-sub d-none d-sm-block"><?php echo e_t('app_tagline'); ?></div>
            </div>
        </a>

        <nav class="d-none d-lg-flex align-items-center gap-1" aria-label="Primary">
            <a href="<?php echo BASE_URL; ?>/#overview" class="nav-link-modern"><?php echo e_t('nav_overview'); ?></a>
            <a href="<?php echo BASE_URL; ?>/#portals" class="nav-link-modern"><?php echo e_t('nav_portals'); ?></a>
            <a href="<?php echo BASE_URL; ?>/#capabilities" class="nav-link-modern"><?php echo e_t('nav_workflow'); ?></a>
        </nav>

        <div class="d-flex align-items-center gap-2">
            <form method="GET" action="<?php echo BASE_URL; ?>/includes/set_lang.php" class="d-flex align-items-center m-0">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($__curUri); ?>">
                <select name="lang" class="lang-switcher-select" aria-label="<?php echo e_t('language_label'); ?>" onchange="this.form.submit()">
                    <?php foreach ($__langs as $__code => $__info): ?>
                        <option value="<?php echo htmlspecialchars($__code); ?>" <?php echo $__curLang === $__code ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($__info['native']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn-modern btn-modern-outline btn-modern-sm">
                <i class="bi bi-box-arrow-in-right"></i> <span class="d-none d-sm-inline"><?php echo e_t('nav_sign_in'); ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>/auth/register.php" class="btn-modern btn-modern-primary btn-modern-sm">
                <i class="bi bi-person-plus"></i> <span class="d-none d-sm-inline"><?php echo e_t('nav_register'); ?></span>
            </a>
        </div>
    </div>
</header>
