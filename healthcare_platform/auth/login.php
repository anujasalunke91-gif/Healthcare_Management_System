<?php
/**
 * User Sign-In Page (Demo credentials removed, Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();

if (isAuthenticated()) {
    header('Location: ' . getDashboardUrlForRole(getCurrentUserRole()));
    exit;
}

$curLang = getCurrentLang();
$error = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!validateCSRFToken($csrf_token)) {
        $error = t('err_csrf');
    } else {
        $email = sanitize($_POST['email'] ?? '');
        $emailValue = $email;
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = t('err_login_empty');
        } else {
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['last_activity'] = time();

                    regenerateCSRFToken();
                    logActivity($user['id'], 'LOGIN', 'User authenticated successfully');

                    header('Location: ' . getDashboardUrlForRole($user['role']));
                    exit;
                } else {
                    $error = t('err_login_invalid');
                }
            } catch (PDOException $e) {
                error_log("Login Error: " . $e->getMessage());
                $error = t('err_login_system');
            }
        }
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($curLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e_t('nav_sign_in'); ?> - <?php echo e_t('app_name'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
</head>
<body class="bg-light">

    <?php include __DIR__ . '/../includes/public_header.php'; ?>

    <div class="auth-wrapper">
        <div class="col-lg-6 auth-hero-side d-none d-lg-flex">
            <div class="my-auto py-4">
                <div class="badge bg-white bg-opacity-10 text-white px-3 py-2 rounded-pill small mb-3">
                    <i class="bi bi-shield-check me-1"></i> <?php echo e_t('auth_hero_badge'); ?>
                </div>
                <h2 class="display-6 fw-bold text-white mb-3">
                    <?php echo e_t('auth_hero_title'); ?>
                </h2>
                <p class="text-white-50 mb-4" style="max-width: 480px;">
                    <?php echo e_t('auth_hero_desc'); ?>
                </p>

                <div class="row g-3 text-white-50">
                    <div class="col-4">
                        <div class="p-3 rounded-3 border border-white border-opacity-10 bg-white bg-opacity-10">
                            <i class="bi bi-activity text-info fs-4 mb-2 d-block"></i>
                            <div class="fw-bold text-white small"><?php echo e_t('auth_feat_risk'); ?></div>
                            <div class="extra-small"><?php echo e_t('auth_feat_risk_sub'); ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3 border border-white border-opacity-10 bg-white bg-opacity-10">
                            <i class="bi bi-calendar2-check text-warning fs-4 mb-2 d-block"></i>
                            <div class="fw-bold text-white small"><?php echo e_t('auth_feat_appts'); ?></div>
                            <div class="extra-small"><?php echo e_t('auth_feat_appts_sub'); ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3 border border-white border-opacity-10 bg-white bg-opacity-10">
                            <i class="bi bi-receipt text-success fs-4 mb-2 d-block"></i>
                            <div class="fw-bold text-white small"><?php echo e_t('auth_feat_billing'); ?></div>
                            <div class="extra-small"><?php echo e_t('auth_feat_billing_sub'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center text-white-50 small border-top border-white border-opacity-10 pt-3">
                <span>&copy; <?php echo date('Y'); ?> <?php echo e_t('app_name'); ?></span>
                <span><?php echo e_t('auth_rbac_footer'); ?></span>
            </div>
        </div>

        <div class="col-12 col-lg-6 auth-form-side">
            <div class="auth-card-inner">
                <div class="mb-4">
                    <h2 class="h3 fw-bold text-slate-900 mb-1"><?php echo e_t('login_title'); ?></h2>
                    <p class="text-muted small mb-0"><?php echo e_t('login_sub'); ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 border-0 shadow-sm mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div class="small"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="mb-3">
                        <label for="email" class="form-label-modern"><?php echo e_t('label_email'); ?></label>
                        <div class="input-group-modern">
                            <i class="bi bi-envelope input-icon"></i>
                            <input type="email" class="form-control-modern" id="email" name="email"
                                   value="<?php echo htmlspecialchars($emailValue); ?>"
                                   placeholder="<?php echo e_t('ph_email'); ?>" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label-modern"><?php echo e_t('label_password'); ?></label>
                        <div class="input-group-modern">
                            <i class="bi bi-shield-lock input-icon"></i>
                            <input type="password" class="form-control-modern" id="password" name="password"
                                   placeholder="<?php echo e_t('ph_password'); ?>" required>
                            <button type="button" class="input-toggle-pwd" onclick="togglePasswordVisibility('password', this)" title="Toggle password visibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn-modern btn-modern-primary btn-modern-lg">
                            <i class="bi bi-box-arrow-in-right"></i> <?php echo e_t('btn_sign_in'); ?>
                        </button>
                    </div>

                    <div class="text-center text-muted small">
                        <?php echo e_t('login_no_account'); ?>
                        <a href="<?php echo BASE_URL; ?>/auth/register.php" class="fw-semibold text-primary"><?php echo e_t('login_create_account'); ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/public_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.APP_LANG = <?php echo json_encode($curLang); ?>;
        window.I18N = <?php echo json_encode(getClientTranslations(), JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>
