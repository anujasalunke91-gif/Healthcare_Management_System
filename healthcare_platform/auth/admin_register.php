<?php
/**
 * Dedicated Admin Portal Registration Page
 * Assigns role = 'admin' strictly on the backend (never from user-submitted form fields).
 * Uses existing authentication, CSRF protection, bcrypt hashing, and public layout.
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();

if (isAuthenticated()) {
    header('Location: ' . getDashboardUrlForRole(getCurrentUserRole()));
    exit;
}

$curLang = getCurrentLang();
$error = '';
$success = '';
$formData = [
    'name' => '',
    'email' => '',
    'phone_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!validateCSRFToken($csrf_token)) {
        $error = t('err_csrf');
    } else {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $formData = [
            'name' => $name,
            'email' => $email,
            'phone_number' => $phone,
        ];

        if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
            $error = t('err_reg_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('err_reg_email');
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            $error = t('err_reg_phone');
        } elseif (strlen($password) < 8) {
            $error = t('err_reg_pwd_len');
        } elseif ($password !== $confirm_password) {
            $error = t('err_reg_pwd_match');
        } else {
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $error = t('err_reg_exists');
                } else {
                    // Server-assigned privilege: ignores any tampered POST role input
                    $assignedRole = 'admin';
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);

                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $password_hash, $assignedRole]);
                    $newAdminId = (int)$pdo->lastInsertId();

                    $logDetails = "New admin account created: $email" . ($phone !== '' ? " (Phone: $phone)" : '');
                    logActivity($newAdminId, 'ADMIN_REGISTRATION', $logDetails);

                    $success = t('reg_admin_success');
                    $formData = ['name' => '', 'email' => '', 'phone_number' => ''];
                }
            } catch (PDOException $e) {
                error_log("Admin Registration Error: " . $e->getMessage());
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
    <title><?php echo e_t('reg_admin_title'); ?> - <?php echo e_t('app_name'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
</head>
<body class="bg-light">

    <?php include __DIR__ . '/../includes/public_header.php'; ?>

    <div class="auth-wrapper">
        <div class="col-lg-5 auth-hero-side d-none d-lg-flex">
            <div class="my-auto py-4">
                <div class="badge bg-white bg-opacity-10 text-white px-3 py-2 rounded-pill small mb-3">
                    <i class="bi bi-speedometer2 me-1"></i> <?php echo e_t('reg_admin_hero_badge'); ?>
                </div>
                <h2 class="display-6 fw-bold text-white mb-3"><?php echo e_t('reg_admin_hero_title'); ?></h2>
                <p class="text-white-50 mb-4"><?php echo e_t('reg_admin_hero_desc'); ?></p>
                <div class="p-3 rounded-3 border border-white border-opacity-10 bg-white bg-opacity-10">
                    <div class="d-flex align-items-center gap-3 text-white">
                        <i class="bi bi-shield-lock text-info fs-3"></i>
                        <div>
                            <div class="fw-bold small"><?php echo e_t('reg_privacy_title'); ?></div>
                            <div class="extra-small text-white-50"><?php echo e_t('reg_admin_role_note'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-white-50 small border-top border-white border-opacity-10 pt-3">
                &copy; <?php echo date('Y'); ?> <?php echo e_t('app_name'); ?>
            </div>
        </div>

        <div class="col-12 col-lg-7 auth-form-side">
            <div class="auth-card-inner" style="max-width: 560px;">
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge-modern badge-modern-primary">
                            <i class="bi bi-speedometer2"></i> <?php echo e_t('role_admin'); ?>
                        </span>
                        <a href="<?php echo BASE_URL; ?>/auth/register.php" class="small fw-semibold text-primary">
                            <i class="bi bi-arrow-left"></i> <?php echo e_t('nav_back'); ?>
                        </a>
                    </div>
                    <h2 class="h3 fw-bold text-slate-900 mb-1"><?php echo e_t('reg_admin_title'); ?></h2>
                    <p class="text-muted small mb-0"><?php echo e_t('reg_admin_sub_desc'); ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 border-0 shadow-sm mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div class="small"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 border-0 shadow-sm mb-3" role="alert">
                        <i class="bi bi-check-circle-fill"></i>
                        <div class="small">
                            <?php echo htmlspecialchars($success); ?>
                            <a href="<?php echo BASE_URL; ?>/auth/login.php" class="fw-bold text-success text-decoration-underline ms-1"><?php echo e_t('nav_sign_in'); ?> &rarr;</a>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="p-3 bg-slate-50 rounded-3 border border-slate-200 mb-3">
                        <div class="fw-bold small text-slate-800 mb-3">
                            <i class="bi bi-person-badge text-primary me-1"></i> <?php echo e_t('reg_admin_section'); ?>
                        </div>

                        <div class="mb-3">
                            <label for="adminName" class="form-label-modern"><?php echo e_t('label_full_name'); ?> <span class="text-danger">*</span></label>
                            <div class="input-group-modern">
                                <i class="bi bi-person input-icon"></i>
                                <input type="text" class="form-control-modern" id="adminName" name="name"
                                       value="<?php echo htmlspecialchars($formData['name']); ?>"
                                       placeholder="<?php echo e_t('ph_full_name'); ?>" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="adminEmail" class="form-label-modern"><?php echo e_t('label_email'); ?> <span class="text-danger">*</span></label>
                                <div class="input-group-modern">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email" class="form-control-modern" id="adminEmail" name="email"
                                           value="<?php echo htmlspecialchars($formData['email']); ?>"
                                           placeholder="<?php echo e_t('ph_email'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="adminPhone" class="form-label-modern"><?php echo e_t('label_contact'); ?></label>
                                <div class="input-group-modern">
                                    <i class="bi bi-telephone input-icon"></i>
                                    <input type="tel" class="form-control-modern" id="adminPhone" name="phone_number"
                                           value="<?php echo htmlspecialchars($formData['phone_number']); ?>"
                                           placeholder="+91-9876543210">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="adminPassword" class="form-label-modern"><?php echo e_t('label_password'); ?> <span class="text-danger">*</span></label>
                                <div class="input-group-modern">
                                    <i class="bi bi-shield-lock input-icon"></i>
                                    <input type="password" class="form-control-modern" id="adminPassword" name="password"
                                           placeholder="<?php echo e_t('ph_min_chars'); ?>" minlength="8" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="adminConfirmPassword" class="form-label-modern"><?php echo e_t('label_confirm_password'); ?> <span class="text-danger">*</span></label>
                                <div class="input-group-modern">
                                    <i class="bi bi-shield-check input-icon"></i>
                                    <input type="password" class="form-control-modern" id="adminConfirmPassword" name="confirm_password"
                                           placeholder="<?php echo e_t('ph_confirm_pwd'); ?>" minlength="8" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn-modern btn-modern-primary btn-modern-lg">
                            <i class="bi bi-shield-check"></i> <?php echo e_t('btn_register_admin'); ?>
                        </button>
                    </div>

                    <div class="text-center text-muted small">
                        <?php echo e_t('reg_have_account'); ?>
                        <a href="<?php echo BASE_URL; ?>/auth/login.php" class="fw-semibold text-primary"><?php echo e_t('reg_sign_in_here'); ?></a>
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
