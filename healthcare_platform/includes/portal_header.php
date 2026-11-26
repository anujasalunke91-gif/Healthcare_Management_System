<?php
/**
 * Shared Portal Layout Header (Sidebar + Topbar + Language Switcher)
 * Expects: $page_title (string), $active_nav (string)
 * Optional: $page_subtitle (string), $extra_head (string)
 */
if (!defined('APP_ACCESS')) {
    exit('Direct access forbidden');
}

$__curLang = getCurrentLang();
$__langs = getSupportedLanguages();
$__curUri = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/');
$__role = getCurrentUserRole();
$__userName = $_SESSION['user_name'] ?? 'User';
$__userEmail = $_SESSION['user_email'] ?? '';
$__initials = '';
foreach (preg_split('/\s+/', trim($__userName)) as $__part) {
    if ($__part !== '' && strcasecmp($__part, 'Dr.') !== 0) {
        $__initials .= strtoupper(substr($__part, 0, 1));
    }
    if (strlen($__initials) >= 2) break;
}
if ($__initials === '') {
    $__initials = strtoupper(substr($__userName, 0, 2));
}

$__navItems = [];
$__roleLabel = t('role_' . ($__role ?: 'patient'));
$__portalLabel = t('portal_' . ($__role ?: 'patient'));

if ($__role === 'admin') {
    $__navItems = [
        ['key' => 'dashboard', 'label' => t('nav_overview'), 'icon' => 'bi-grid-1x2', 'href' => BASE_URL . '/admin/dashboard.php'],
        ['key' => 'users', 'label' => t('nav_users_roles'), 'icon' => 'bi-people', 'href' => BASE_URL . '/admin/manage_users.php'],
        ['key' => 'appointments', 'label' => t('nav_appointments'), 'icon' => 'bi-calendar2-check', 'href' => BASE_URL . '/admin/appointments.php'],
        ['key' => 'bills', 'label' => t('nav_billing_invoices'), 'icon' => 'bi-receipt-cutoff', 'href' => BASE_URL . '/admin/bills.php'],
    ];
} elseif ($__role === 'doctor') {
    $__navItems = [
        ['key' => 'dashboard', 'label' => t('nav_dashboard'), 'icon' => 'bi-grid-1x2', 'href' => BASE_URL . '/doctor/dashboard.php'],
        ['key' => 'patients', 'label' => t('nav_my_patients'), 'icon' => 'bi-people', 'href' => BASE_URL . '/doctor/patients.php'],
        ['key' => 'workstation', 'label' => t('nav_risk_assessment'), 'icon' => 'bi-activity', 'href' => BASE_URL . '/doctor/workstation.php'],
        ['key' => 'appointments', 'label' => t('nav_appointments'), 'icon' => 'bi-calendar2-check', 'href' => BASE_URL . '/doctor/appointments.php'],
        ['key' => 'bills', 'label' => t('nav_patient_bills'), 'icon' => 'bi-receipt', 'href' => BASE_URL . '/doctor/bills.php'],
    ];
} else {
    $__navItems = [
        ['key' => 'dashboard', 'label' => t('nav_overview'), 'icon' => 'bi-grid-1x2', 'href' => BASE_URL . '/patient/dashboard.php'],
        ['key' => 'profile', 'label' => t('nav_my_profile'), 'icon' => 'bi-person-vcard', 'href' => BASE_URL . '/patient/profile.php'],
        ['key' => 'appointments', 'label' => t('nav_appointments'), 'icon' => 'bi-calendar2-check', 'href' => BASE_URL . '/patient/appointments.php'],
        ['key' => 'billing', 'label' => t('nav_bills_expenses'), 'icon' => 'bi-wallet2', 'href' => BASE_URL . '/patient/billing.php'],
    ];
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($__curLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? t('nav_dashboard')); ?> - <?php echo e_t('app_name'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
    <?php if (!empty($extra_head)) echo $extra_head; ?>
</head>
<body class="portal-body">

<div class="portal-shell">
    <!-- Desktop Sidebar -->
    <aside class="portal-sidebar d-none d-lg-flex flex-column" id="portalSidebar">
        <div class="sidebar-brand">
            <a href="<?php echo getDashboardUrlForRole($__role); ?>" class="navbar-brand-modern">
                <div class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></div>
                <div>
                    <div class="brand-title"><?php echo e_t('app_name'); ?></div>
                    <div class="brand-sub"><?php echo e_t('clinical_care_system'); ?></div>
                </div>
            </a>
        </div>

        <div class="sidebar-role-pill">
            <span class="role-dot role-dot-<?php echo htmlspecialchars($__role); ?>"></span>
            <span><?php echo htmlspecialchars($__portalLabel); ?></span>
        </div>

        <nav class="sidebar-nav flex-grow-1" aria-label="<?php echo e_t('nav_navigation'); ?>">
            <div class="sidebar-section-label"><?php echo e_t('nav_navigation'); ?></div>
            <?php foreach ($__navItems as $__item): ?>
                <a href="<?php echo htmlspecialchars($__item['href']); ?>"
                   class="sidebar-link <?php echo ($active_nav ?? '') === $__item['key'] ? 'active' : ''; ?>">
                    <i class="bi <?php echo htmlspecialchars($__item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($__item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user-card">
                <div class="user-avatar-circle"><?php echo htmlspecialchars($__initials); ?></div>
                <div class="min-w-0 flex-grow-1">
                    <div class="sidebar-user-name text-truncate"><?php echo htmlspecialchars($__userName); ?></div>
                    <div class="sidebar-user-meta text-truncate"><?php echo htmlspecialchars($__roleLabel); ?> &bull; <?php echo htmlspecialchars($__userEmail); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Mobile Offcanvas Sidebar -->
    <div class="offcanvas offcanvas-start portal-offcanvas d-lg-none" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
        <div class="offcanvas-header border-bottom">
            <a href="<?php echo getDashboardUrlForRole($__role); ?>" class="navbar-brand-modern" id="mobileSidebarLabel">
                <div class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></div>
                <span><?php echo e_t('app_name'); ?></span>
            </a>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-3">
            <div class="sidebar-role-pill mb-3">
                <span class="role-dot role-dot-<?php echo htmlspecialchars($__role); ?>"></span>
                <span><?php echo htmlspecialchars($__portalLabel); ?></span>
            </div>
            <nav class="sidebar-nav flex-grow-1 p-0">
                <?php foreach ($__navItems as $__item): ?>
                    <a href="<?php echo htmlspecialchars($__item['href']); ?>"
                       class="sidebar-link <?php echo ($active_nav ?? '') === $__item['key'] ? 'active' : ''; ?>">
                        <i class="bi <?php echo htmlspecialchars($__item['icon']); ?>"></i>
                        <span><?php echo htmlspecialchars($__item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="pt-3 mt-3 border-top">
                <div class="sidebar-user-card">
                    <div class="user-avatar-circle"><?php echo htmlspecialchars($__initials); ?></div>
                    <div class="min-w-0 flex-grow-1">
                        <div class="sidebar-user-name text-truncate"><?php echo htmlspecialchars($__userName); ?></div>
                        <div class="sidebar-user-meta text-truncate"><?php echo htmlspecialchars($__roleLabel); ?> &bull; <?php echo htmlspecialchars($__userEmail); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Workspace Column -->
    <div class="portal-main-wrap">
        <header class="portal-topbar">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <button class="btn btn-light border d-lg-none px-2 py-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-label="Open menu">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="min-w-0">
                    <h1 class="topbar-title text-truncate mb-0"><?php echo htmlspecialchars($page_title ?? t('nav_dashboard')); ?></h1>
                    <?php if (!empty($page_subtitle)): ?>
                        <p class="topbar-subtitle text-truncate mb-0 d-none d-sm-block"><?php echo htmlspecialchars($page_subtitle); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2 flex-shrink-0">
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

                <a href="<?php echo BASE_URL; ?>/auth/logout.php" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('nav_sign_out'); ?>">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="d-none d-sm-inline"><?php echo e_t('nav_sign_out'); ?></span>
                </a>
            </div>
        </header>

        <main class="portal-content">
