<?php
/**
 * User Registration Page (INR Currency & Multilingual EN/MR/HI)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!validateCSRFToken($csrf_token)) {
        $error = t('err_csrf');
    } else {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $role = sanitize($_POST['role'] ?? 'patient');

        if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
            $error = t('err_reg_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('err_reg_email');
        } elseif (strlen($password) < 8) {
            $error = t('err_reg_pwd_len');
        } elseif ($password !== $confirm_password) {
            $error = t('err_reg_pwd_match');
        } elseif (!in_array($role, ['patient', 'doctor'], true)) {
            $error = t('err_reg_role');
        } else {
            try {
                $pdo = getDBConnection();

                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = t('err_reg_exists');
                } else {
                    $pdo->beginTransaction();
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);

                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $password_hash, $role]);
                    $user_id = $pdo->lastInsertId();

                    if ($role === 'doctor') {
                        $specialization = sanitize($_POST['specialization'] ?? '');
                        $room_number = sanitize($_POST['room_number'] ?? '');
                        $consultation_fee = floatval($_POST['consultation_fee'] ?? 500);

                        if (empty($specialization) || empty($room_number)) {
                            $error = t('err_reg_doc_fields');
                            $pdo->rollBack();
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO doctor_profiles (user_id, specialization, room_number, consultation_fee) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$user_id, $specialization, $room_number, $consultation_fee]);
                            $pdo->commit();
                        }
                    }

                    if ($role === 'patient') {
                        $dob = sanitize($_POST['dob'] ?? '');
                        $gender = sanitize($_POST['gender'] ?? '');
                        $blood_group = sanitize($_POST['blood_group'] ?? '');
                        $chronic_conditions = sanitize($_POST['chronic_conditions'] ?? '');
                        $contact_number = sanitize($_POST['contact_number'] ?? '');

                        if (empty($dob) || empty($gender) || empty($blood_group) || empty($contact_number)) {
                            $error = t('err_reg_pat_fields');
                            $pdo->rollBack();
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO patient_profiles (user_id, dob, gender, blood_group, chronic_conditions, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$user_id, $dob, $gender, $blood_group, $chronic_conditions, $contact_number]);
                            $pdo->commit();
                        }
                    }

                    if (!$error) {
                        $success = t('reg_success');
                        logActivity($user_id, 'REGISTRATION', 'New ' . $role . ' account created: ' . $email);
                    }
                }
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Registration Error: " . $e->getMessage());
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
    <title><?php echo e_t('reg_title'); ?> - <?php echo e_t('app_name'); ?></title>
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
                    <i class="bi bi-person-plus me-1"></i> <?php echo e_t('reg_hero_badge'); ?>
                </div>
                <h2 class="display-6 fw-bold text-white mb-3"><?php echo e_t('reg_hero_title'); ?></h2>
                <p class="text-white-50 mb-4"><?php echo e_t('reg_hero_desc'); ?></p>
                <div class="p-3 rounded-3 border border-white border-opacity-10 bg-white bg-opacity-10">
                    <div class="d-flex align-items-center gap-3 text-white">
                        <i class="bi bi-shield-check text-success fs-3"></i>
                        <div>
                            <div class="fw-bold small"><?php echo e_t('reg_privacy_title'); ?></div>
                            <div class="extra-small text-white-50"><?php echo e_t('reg_privacy_desc'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-white-50 small border-top border-white border-opacity-10 pt-3">
                &copy; <?php echo date('Y'); ?> <?php echo e_t('app_name'); ?>
            </div>
        </div>

        <div class="col-12 col-lg-7 auth-form-side">
            <div class="auth-card-inner" style="max-width: 580px;">
                <div class="mb-4">
                    <h2 class="h3 fw-bold text-slate-900 mb-1"><?php echo e_t('reg_title'); ?></h2>
                    <p class="text-muted small mb-0"><?php echo e_t('reg_sub'); ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 border-0 shadow-sm" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div class="small"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 border-0 shadow-sm" role="alert">
                        <i class="bi bi-check-circle-fill"></i>
                        <div class="small">
                            <?php echo htmlspecialchars($success); ?>
                            <a href="<?php echo BASE_URL; ?>/auth/login.php" class="fw-bold text-success text-decoration-underline ms-1"><?php echo e_t('nav_sign_in'); ?> &rarr;</a>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('reg_account_role'); ?></label>
                        <div class="row g-2">
                            <div class="col-12 col-sm-4">
                                <label class="w-100 h-100 p-3 rounded-3 border border-2 text-center" id="labelRolePatient" style="cursor: pointer; border-color: var(--primary) !important; background: var(--primary-subtle);">
                                    <input type="radio" name="role" id="rolePatient" value="patient" checked class="d-none" onchange="handleRoleSwitch('patient')">
                                    <i class="bi bi-person-heart text-primary fs-4 d-block mb-1"></i>
                                    <span class="fw-bold text-slate-800 d-block small"><?php echo e_t('role_patient'); ?></span>
                                    <span class="text-muted extra-small"><?php echo e_t('reg_patient_sub'); ?></span>
                                </label>
                            </div>
                            <div class="col-12 col-sm-4">
                                <label class="w-100 h-100 p-3 rounded-3 border text-center" id="labelRoleDoctor" style="cursor: pointer;">
                                    <input type="radio" name="role" id="roleDoctor" value="doctor" class="d-none" onchange="handleRoleSwitch('doctor')">
                                    <i class="bi bi-clipboard2-pulse text-secondary fs-4 d-block mb-1"></i>
                                    <span class="fw-bold text-slate-800 d-block small"><?php echo e_t('role_doctor'); ?></span>
                                    <span class="text-muted extra-small"><?php echo e_t('reg_doctor_sub'); ?></span>
                                </label>
                            </div>
                            <div class="col-12 col-sm-4">
                                <a href="<?php echo BASE_URL; ?>/auth/admin_register.php" id="cardRoleAdmin" class="w-100 h-100 p-3 rounded-3 border text-center d-block text-decoration-none" style="cursor: pointer; background: #ffffff;">
                                    <i class="bi bi-speedometer2 text-primary fs-4 d-block mb-1"></i>
                                    <span class="fw-bold text-slate-800 d-block small"><?php echo e_t('role_admin'); ?></span>
                                    <span class="text-muted extra-small"><?php echo e_t('reg_admin_sub'); ?></span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label-modern"><?php echo e_t('label_full_name'); ?></label>
                            <div class="input-group-modern">
                                <i class="bi bi-person input-icon"></i>
                                <input type="text" class="form-control-modern" id="name" name="name" placeholder="<?php echo e_t('ph_full_name'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label-modern"><?php echo e_t('label_email'); ?></label>
                            <div class="input-group-modern">
                                <i class="bi bi-envelope input-icon"></i>
                                <input type="email" class="form-control-modern" id="email" name="email" placeholder="<?php echo e_t('ph_email'); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="password" class="form-label-modern"><?php echo e_t('label_password'); ?></label>
                            <div class="input-group-modern">
                                <i class="bi bi-shield-lock input-icon"></i>
                                <input type="password" class="form-control-modern" id="password" name="password" placeholder="<?php echo e_t('ph_min_chars'); ?>" minlength="8" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label-modern"><?php echo e_t('label_confirm_password'); ?></label>
                            <div class="input-group-modern">
                                <i class="bi bi-shield-check input-icon"></i>
                                <input type="password" class="form-control-modern" id="confirm_password" name="confirm_password" placeholder="<?php echo e_t('ph_confirm_pwd'); ?>" minlength="8" required>
                            </div>
                        </div>
                    </div>

                    <div id="patientFields" class="p-3 bg-slate-50 rounded-3 border border-slate-200 mb-3">
                        <div class="fw-bold small text-slate-800 mb-2"><i class="bi bi-person-lines-fill text-primary me-1"></i> <?php echo e_t('reg_patient_profile'); ?></div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label for="dob" class="form-label-modern extra-small"><?php echo e_t('label_dob'); ?></label>
                                <input type="date" class="form-control-modern form-control-sm" id="dob" name="dob">
                            </div>
                            <div class="col-md-6">
                                <label for="gender" class="form-label-modern extra-small"><?php echo e_t('label_gender'); ?></label>
                                <select class="form-select-modern form-select-sm" id="gender" name="gender">
                                    <option value=""><?php echo e_t('select_gender'); ?></option>
                                    <option value="Male"><?php echo e_t('gender_male'); ?></option>
                                    <option value="Female"><?php echo e_t('gender_female'); ?></option>
                                    <option value="Other"><?php echo e_t('gender_other'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="blood_group" class="form-label-modern extra-small"><?php echo e_t('label_blood_group'); ?></label>
                                <select class="form-select-modern form-select-sm" id="blood_group" name="blood_group">
                                    <option value=""><?php echo e_t('select_blood_group'); ?></option>
                                    <option value="A+">A+</option><option value="A-">A-</option>
                                    <option value="B+">B+</option><option value="B-">B-</option>
                                    <option value="O+">O+</option><option value="O-">O-</option>
                                    <option value="AB+">AB+</option><option value="AB-">AB-</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="contact_number" class="form-label-modern extra-small"><?php echo e_t('label_contact'); ?></label>
                                <input type="tel" class="form-control-modern form-control-sm" id="contact_number" name="contact_number" placeholder="+91-9876543210">
                            </div>
                            <div class="col-12">
                                <label for="chronic_conditions" class="form-label-modern extra-small"><?php echo e_t('label_chronic'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" id="chronic_conditions" name="chronic_conditions" placeholder="<?php echo e_t('ph_chronic'); ?>">
                            </div>
                        </div>
                    </div>

                    <div id="doctorFields" class="p-3 bg-slate-50 rounded-3 border border-slate-200 mb-3 d-none">
                        <div class="fw-bold small text-slate-800 mb-2"><i class="bi bi-hospital text-secondary me-1"></i> <?php echo e_t('reg_practice_details'); ?></div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label for="specialization" class="form-label-modern extra-small"><?php echo e_t('label_specialization'); ?></label>
                                <select class="form-select-modern form-select-sm" id="specialization" name="specialization">
                                    <option value="Oncology">Oncology</option>
                                    <option value="Radiology">Radiology</option>
                                    <option value="Neurology">Neurology</option>
                                    <option value="Cardiology">Cardiology</option>
                                    <option value="Internal Medicine">Internal Medicine</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="room_number" class="form-label-modern extra-small"><?php echo e_t('label_room'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" id="room_number" name="room_number" placeholder="Room 304">
                            </div>
                            <div class="col-12">
                                <label for="consultation_fee" class="form-label-modern extra-small"><?php echo e_t('label_fee_inr'); ?></label>
                                <input type="number" step="0.01" class="form-control-modern form-control-sm" id="consultation_fee" name="consultation_fee" value="500.00">
                            </div>
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn-modern btn-modern-primary btn-modern-lg">
                            <i class="bi bi-person-check-fill"></i> <?php echo e_t('btn_complete_reg'); ?>
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
    <script>
        function handleRoleSwitch(role) {
            const pField = document.getElementById('patientFields');
            const dField = document.getElementById('doctorFields');
            const pLabel = document.getElementById('labelRolePatient');
            const dLabel = document.getElementById('labelRoleDoctor');

            if (role === 'patient') {
                pField.classList.remove('d-none');
                dField.classList.add('d-none');
                pLabel.style.borderColor = 'var(--primary)';
                pLabel.style.background = 'var(--primary-subtle)';
                dLabel.style.borderColor = 'var(--slate-200)';
                dLabel.style.background = '#ffffff';
            } else {
                pField.classList.add('d-none');
                dField.classList.remove('d-none');
                dLabel.style.borderColor = 'var(--secondary)';
                dLabel.style.background = 'var(--secondary-subtle)';
                pLabel.style.borderColor = 'var(--slate-200)';
                pLabel.style.background = '#ffffff';
            }
        }
    </script>
</body>
</html>
