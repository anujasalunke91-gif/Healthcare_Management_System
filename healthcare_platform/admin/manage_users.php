<?php
/**
 * Admin User Directory & Role Administration (INR & Multilingual EN/MR/HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['admin']);

$action = $_GET['action'] ?? '';
$currentAdminId = getCurrentUserId();

function ensureRoleProfileExists(PDO $pdo, int $userId, string $role) {
    if ($role === 'patient') {
        $stmt = $pdo->prepare("SELECT id FROM patient_profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO patient_profiles (user_id, dob, gender, blood_group, chronic_conditions, contact_number) VALUES (?, '1990-01-01', 'Other', 'O+', '', 'Not provided')");
            $stmt->execute([$userId]);
        }
    } elseif ($role === 'doctor') {
        $stmt = $pdo->prepare("SELECT id FROM doctor_profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO doctor_profiles (user_id, specialization, room_number, consultation_fee) VALUES (?, 'General Medicine', 'TBA', 500.00)");
            $stmt->execute([$userId]);
        }
    }
}

if ($action === 'get_user' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['id'] ?? 0);
    if ($user_id <= 0) json_response(false, ['message' => 'Invalid user ID'], 400);

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) json_response(false, ['message' => 'User not found'], 404);

        $user['patient_profile'] = null;
        $user['doctor_profile'] = null;

        if ($user['role'] === 'patient') {
            $stmt = $pdo->prepare("SELECT * FROM patient_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user['patient_profile'] = $stmt->fetch() ?: null;
        } elseif ($user['role'] === 'doctor') {
            $stmt = $pdo->prepare("SELECT * FROM doctor_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user['doctor_profile'] = $stmt->fetch() ?: null;
        }
        json_response(true, $user, 200);
    } catch (PDOException $e) {
        error_log("Get User Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

if ($action === 'update_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    $user_id = intval($_POST['user_id'] ?? 0);
    $new_role = trim($_POST['role'] ?? '');
    if ($user_id <= 0 || !in_array($new_role, ['admin', 'doctor', 'patient'], true)) {
        json_response(false, ['message' => t('msg_role_failed')], 400);
    }
    if ($user_id === $currentAdminId && $new_role !== 'admin') {
        json_response(false, ['message' => t('msg_role_failed')], 403);
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $existing = $stmt->fetch();
        if (!$existing) json_response(false, ['message' => 'User account not found'], 404);

        if ($existing['role'] !== $new_role) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$new_role, $user_id]);
            ensureRoleProfileExists($pdo, $user_id, $new_role);
            $pdo->commit();
            logActivity($currentAdminId, 'ROLE_UPDATED', "Changed role for {$existing['name']} (#$user_id) from {$existing['role']} to $new_role");
        }
        json_response(true, ['message' => t('msg_role_updated'), 'role' => $new_role], 200);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Update Role Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_role_failed')], 500);
    }
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    try {
        $pdo = getDBConnection();
        $user_id = intval($_POST['user_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? 'patient');

        if ($user_id <= 0 || empty($name) || empty($email) || !in_array($role, ['admin', 'doctor', 'patient'], true)) {
            json_response(false, ['message' => t('err_reg_required')], 400);
        }
        if ($user_id === $currentAdminId && $role !== 'admin') {
            json_response(false, ['message' => t('msg_role_failed')], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) json_response(false, ['message' => t('err_reg_exists')], 400);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
        $stmt->execute([$name, $email, $role, $user_id]);

        if ($role === 'patient') {
            $dob = sanitize($_POST['dob'] ?? '') ?: '1990-01-01';
            $gender = sanitize($_POST['gender'] ?? '') ?: 'Other';
            $blood_group = sanitize($_POST['blood_group'] ?? '') ?: 'O+';
            $contact_number = sanitize($_POST['contact_number'] ?? '') ?: 'Not provided';
            $chronic_conditions = sanitize($_POST['chronic_conditions'] ?? '');

            $stmt = $pdo->prepare("SELECT id FROM patient_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("UPDATE patient_profiles SET dob = ?, gender = ?, blood_group = ?, chronic_conditions = ?, contact_number = ? WHERE user_id = ?");
                $stmt->execute([$dob, $gender, $blood_group, $chronic_conditions, $contact_number, $user_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO patient_profiles (user_id, dob, gender, blood_group, chronic_conditions, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $dob, $gender, $blood_group, $chronic_conditions, $contact_number]);
            }
        } elseif ($role === 'doctor') {
            $specialization = sanitize($_POST['specialization'] ?? '') ?: 'General Medicine';
            $room_number = sanitize($_POST['room_number'] ?? '') ?: 'TBA';
            $consultation_fee = floatval($_POST['consultation_fee'] ?? 500.00);

            $stmt = $pdo->prepare("SELECT id FROM doctor_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("UPDATE doctor_profiles SET specialization = ?, room_number = ?, consultation_fee = ? WHERE user_id = ?");
                $stmt->execute([$specialization, $room_number, $consultation_fee, $user_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO doctor_profiles (user_id, specialization, room_number, consultation_fee) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $specialization, $room_number, $consultation_fee]);
            }
        }
        $pdo->commit();

        logActivity($currentAdminId, 'USER_UPDATED', "Updated user: $role - $name (ID: $user_id)");
        json_response(true, ['message' => t('msg_updated')], 200);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Update User Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    $user_id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($user_id <= 0 || $user_id === $currentAdminId) {
        json_response(false, ['message' => t('msg_error')], 400);
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) json_response(false, ['message' => 'User not found'], 404);

        ensureBillingTableExists($pdo);
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM bills WHERE patient_id = ? OR doctor_id = ?")->execute([$user_id, $user_id]);
        $pdo->prepare("DELETE FROM prescriptions_and_reminders WHERE patient_id = ? OR doctor_id = ?")->execute([$user_id, $user_id]);
        $pdo->prepare("DELETE FROM diagnostic_records WHERE patient_id = ? OR doctor_id = ?")->execute([$user_id, $user_id]);
        $pdo->prepare("DELETE FROM appointments WHERE patient_id = ? OR doctor_id = ?")->execute([$user_id, $user_id]);
        $pdo->prepare("DELETE FROM patient_profiles WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM doctor_profiles WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        $pdo->commit();

        logActivity($currentAdminId, 'USER_DELETED', "Deleted user: {$user['role']} - {$user['name']} (ID: $user_id)");
        json_response(true, ['message' => t('msg_updated')], 200);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Delete User Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        json_response(false, ['message' => t('err_csrf')], 403);
    }

    try {
        $pdo = getDBConnection();
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'patient');

        if (empty($name) || empty($email) || empty($password) || !in_array($role, ['admin', 'doctor', 'patient'], true)) {
            json_response(false, ['message' => t('err_reg_required')], 400);
        }
        if (strlen($password) < 8) json_response(false, ['message' => t('err_reg_pwd_len')], 400);

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) json_response(false, ['message' => t('err_reg_exists')], 400);

        $pdo->beginTransaction();
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $password_hash, $role]);
        $user_id = $pdo->lastInsertId();

        if ($role === 'patient') {
            $dob = sanitize($_POST['dob'] ?? '') ?: '1990-01-01';
            $gender = sanitize($_POST['gender'] ?? '') ?: 'Other';
            $blood_group = sanitize($_POST['blood_group'] ?? '') ?: 'O+';
            $contact_number = sanitize($_POST['contact_number'] ?? '') ?: 'Not provided';
            $chronic_conditions = sanitize($_POST['chronic_conditions'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO patient_profiles (user_id, dob, gender, blood_group, chronic_conditions, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $dob, $gender, $blood_group, $chronic_conditions, $contact_number]);
        } elseif ($role === 'doctor') {
            $specialization = sanitize($_POST['specialization'] ?? '') ?: 'General Medicine';
            $room_number = sanitize($_POST['room_number'] ?? '') ?: 'TBA';
            $consultation_fee = floatval($_POST['consultation_fee'] ?? 500.00);
            $stmt = $pdo->prepare("INSERT INTO doctor_profiles (user_id, specialization, room_number, consultation_fee) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $specialization, $room_number, $consultation_fee]);
        }
        $pdo->commit();

        logActivity($currentAdminId, 'USER_CREATED', "Created new user: $role - $name");
        json_response(true, ['message' => t('msg_updated')], 200);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Add User Error: " . $e->getMessage());
        json_response(false, ['message' => t('msg_error')], 500);
    }
}

$users = [];
$error = '';
try {
    $pdo = getDBConnection();
    $users = $pdo->query("
        SELECT u.id, u.name, u.email, u.role, u.created_at,
               pp.blood_group, pp.dob, pp.contact_number,
               dp.specialization, dp.room_number, dp.consultation_fee
        FROM users u
        LEFT JOIN patient_profiles pp ON u.id = pp.user_id
        LEFT JOIN doctor_profiles dp ON u.id = dp.user_id
        ORDER BY u.role ASC, u.name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("Manage Users Error: " . $e->getMessage());
    $error = t('msg_error');
}

$csrf_token = generateCSRFToken();
$page_title = t('users_title');
$page_subtitle = t('users_sub');
$active_nav = 'users';

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-primary filter-role-btn active" onclick="filterRole('all', this)">
            <?php echo e_t('label_all'); ?> (<?php echo count($users); ?>)
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary filter-role-btn" onclick="filterRole('patient', this)">
            <?php echo e_t('filter_patients'); ?> (<?php echo count(array_filter($users, fn($u) => $u['role'] === 'patient')); ?>)
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary filter-role-btn" onclick="filterRole('doctor', this)">
            <?php echo e_t('filter_doctors'); ?> (<?php echo count(array_filter($users, fn($u) => $u['role'] === 'doctor')); ?>)
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary filter-role-btn" onclick="filterRole('admin', this)">
            <?php echo e_t('filter_admins'); ?> (<?php echo count(array_filter($users, fn($u) => $u['role'] === 'admin')); ?>)
        </button>
    </div>

    <button type="button" class="btn-modern btn-modern-primary btn-modern-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus"></i> <?php echo e_t('btn_add_user'); ?>
    </button>
</div>

<div class="card-modern p-3 mb-4">
    <div class="row g-3 align-items-center">
        <div class="col-md-6">
            <div class="input-group-modern">
                <i class="bi bi-search input-icon"></i>
                <input type="text" class="form-control-modern" id="userSearchInput" placeholder="<?php echo e_t('ph_search_users'); ?>" onkeyup="applyUserFilters()">
            </div>
        </div>
        <div class="col-md-6 d-flex align-items-center justify-content-md-end gap-2">
            <label for="userSortSelect" class="small text-muted fw-semibold mb-0"><?php echo e_t('label_sort_by'); ?></label>
            <select id="userSortSelect" class="form-select-modern form-select-sm" style="max-width: 220px;" onchange="sortTableBySelect('usersTable', this)">
                <option value="1:text:asc"><?php echo e_t('sort_name_asc'); ?></option>
                <option value="1:text:desc"><?php echo e_t('sort_name_desc'); ?></option>
                <option value="2:text:asc"><?php echo e_t('col_role'); ?></option>
                <option value="4:date:desc"><?php echo e_t('sort_newest'); ?></option>
                <option value="4:date:asc"><?php echo e_t('sort_oldest'); ?></option>
                <option value="0:number:asc"><?php echo e_t('col_id'); ?></option>
            </select>
        </div>
    </div>
</div>

<div class="card-modern">
    <div class="card-body-modern p-0">
        <div class="table-responsive">
            <table class="table-modern" id="usersTable">
                <thead>
                    <tr>
                        <th class="sortable-th" onclick="sortTable('usersTable', 0, 'number')" style="width: 75px;"><?php echo e_t('col_id'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        <th class="sortable-th" onclick="sortTable('usersTable', 1, 'text')"><?php echo e_t('col_account_holder'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        <th class="sortable-th" onclick="sortTable('usersTable', 2, 'text')"><?php echo e_t('col_role'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        <th><?php echo e_t('col_profile_details'); ?></th>
                        <th class="sortable-th" onclick="sortTable('usersTable', 4, 'date')"><?php echo e_t('col_created_on'); ?> <span class="sort-indicator"><i class="bi bi-arrow-down-up"></i></span></th>
                        <th class="text-end" style="width: 175px;"><?php echo e_t('label_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php
                            $isSelf = (int)$user['id'] === $currentAdminId;
                            if ($user['role'] === 'patient') {
                                $age = !empty($user['dob']) ? calculateAge($user['dob']) : '--';
                                $profileSummary = trim(($user['blood_group'] ? $user['blood_group'] : '') . ($age !== '--' ? ' • ' . $age . ' ' . t('label_yrs') : ''));
                                if ($profileSummary === '') $profileSummary = t('reg_patient_profile');
                            } elseif ($user['role'] === 'doctor') {
                                $profileSummary = trim(($user['specialization'] ?: 'General') . ($user['room_number'] ? ' • ' . $user['room_number'] : '') . ' • ' . formatCurrency($user['consultation_fee'] ?? 0));
                            } else {
                                $profileSummary = t('role_admin');
                            }
                        ?>
                        <tr class="user-row" data-role="<?php echo htmlspecialchars($user['role']); ?>">
                            <td data-sort="<?php echo (int)$user['id']; ?>" class="fw-semibold text-muted small">#<?php echo (int)$user['id']; ?></td>
                            <td data-sort="<?php echo htmlspecialchars($user['name']); ?>">
                                <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($user['name']); ?></div>
                                <div class="extra-small text-muted"><?php echo htmlspecialchars($user['email']); ?></div>
                            </td>
                            <td data-sort="<?php echo htmlspecialchars($user['role']); ?>">
                                <select class="role-select-inline"
                                        aria-label="Role for <?php echo htmlspecialchars($user['name']); ?>"
                                        data-current-role="<?php echo htmlspecialchars($user['role']); ?>"
                                        <?php echo $isSelf ? 'disabled' : ''; ?>
                                        onchange="changeUserRole(<?php echo (int)$user['id']; ?>, this)">
                                    <option value="patient" <?php echo $user['role'] === 'patient' ? 'selected' : ''; ?>><?php echo e_t('role_patient'); ?></option>
                                    <option value="doctor" <?php echo $user['role'] === 'doctor' ? 'selected' : ''; ?>><?php echo e_t('role_doctor'); ?></option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>><?php echo e_t('role_admin'); ?></option>
                                </select>
                            </td>
                            <td class="small text-slate-700"><?php echo htmlspecialchars($profileSummary); ?></td>
                            <td data-sort="<?php echo htmlspecialchars($user['created_at']); ?>" class="small text-muted">
                                <?php echo formatDate($user['created_at']); ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <?php if ($user['role'] === 'patient'): ?>
                                        <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-sm btn-outline-secondary" title="<?php echo e_t('btn_view_profile'); ?>">
                                            <i class="bi bi-person-vcard"></i>
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="editUser(<?php echo (int)$user['id']; ?>)" title="<?php echo e_t('modal_edit_user'); ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?php echo (int)$user['id']; ?>)" <?php echo $isSelf ? 'disabled' : ''; ?>>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-content-modern">
            <div class="modal-header modal-header-modern">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-primary me-2"></i><?php echo e_t('modal_add_user'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="addUserForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label-modern"><?php echo e_t('label_full_name'); ?></label>
                            <input type="text" class="form-control-modern" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-modern"><?php echo e_t('label_email'); ?></label>
                            <input type="email" class="form-control-modern" name="email" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label-modern"><?php echo e_t('label_password'); ?></label>
                            <input type="password" class="form-control-modern" name="password" minlength="8" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-modern"><?php echo e_t('reg_account_role'); ?></label>
                            <select class="form-select-modern" name="role" required onchange="toggleAddUserFields()">
                                <option value="patient"><?php echo e_t('role_patient'); ?></option>
                                <option value="doctor"><?php echo e_t('role_doctor'); ?></option>
                                <option value="admin"><?php echo e_t('role_admin'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div id="addPatientFields" class="p-3 bg-slate-50 border border-slate-200 rounded-3 mb-3">
                        <div class="fw-bold extra-small text-uppercase text-slate-700 mb-2"><?php echo e_t('reg_patient_profile'); ?></div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_dob'); ?></label>
                                <input type="date" class="form-control-modern form-control-sm" name="dob" value="1990-01-01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_gender'); ?></label>
                                <select class="form-select-modern form-select-sm" name="gender">
                                    <option value="Male"><?php echo e_t('gender_male'); ?></option>
                                    <option value="Female"><?php echo e_t('gender_female'); ?></option>
                                    <option value="Other"><?php echo e_t('gender_other'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_blood_group'); ?></label>
                                <select class="form-select-modern form-select-sm" name="blood_group">
                                    <option value="O+">O+</option><option value="O-">O-</option>
                                    <option value="A+">A+</option><option value="A-">A-</option>
                                    <option value="B+">B+</option><option value="B-">B-</option>
                                    <option value="AB+">AB+</option><option value="AB-">AB-</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_contact'); ?></label>
                                <input type="tel" class="form-control-modern form-control-sm" name="contact_number" placeholder="+91-9876543210">
                            </div>
                            <div class="col-12">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_chronic'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" name="chronic_conditions">
                            </div>
                        </div>
                    </div>

                    <div id="addDoctorFields" class="p-3 bg-slate-50 border border-slate-200 rounded-3 mb-3" style="display:none;">
                        <div class="fw-bold extra-small text-uppercase text-slate-700 mb-2"><?php echo e_t('reg_practice_details'); ?></div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_specialization'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" name="specialization" placeholder="Oncology, Cardiology...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_room'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" name="room_number" placeholder="Room 304">
                            </div>
                            <div class="col-12">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_fee_inr'); ?></label>
                                <input type="number" step="0.01" class="form-control-modern form-control-sm" name="consultation_fee" value="500.00">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-slate-50 border-top p-3">
                <button type="button" class="btn-modern btn-modern-outline" data-bs-dismiss="modal"><?php echo e_t('label_cancel'); ?></button>
                <button type="button" class="btn-modern btn-modern-primary" onclick="submitAddUser()"><?php echo e_t('btn_save_account'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-content-modern">
            <div class="modal-header modal-header-modern">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i><?php echo e_t('modal_edit_user'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="editUserForm">
                    <input type="hidden" name="user_id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label-modern"><?php echo e_t('label_full_name'); ?></label>
                            <input type="text" class="form-control-modern" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-modern"><?php echo e_t('label_email'); ?></label>
                            <input type="email" class="form-control-modern" name="email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-modern"><?php echo e_t('reg_account_role'); ?></label>
                        <select class="form-select-modern" name="role" required onchange="toggleEditUserFields()">
                            <option value="patient"><?php echo e_t('role_patient'); ?></option>
                            <option value="doctor"><?php echo e_t('role_doctor'); ?></option>
                            <option value="admin"><?php echo e_t('role_admin'); ?></option>
                        </select>
                    </div>

                    <div id="editPatientFields" class="p-3 bg-slate-50 border border-slate-200 rounded-3 mb-3">
                        <div class="fw-bold extra-small text-uppercase text-slate-700 mb-2"><?php echo e_t('reg_patient_profile'); ?></div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_dob'); ?></label>
                                <input type="date" class="form-control-modern form-control-sm" name="dob">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_gender'); ?></label>
                                <select class="form-select-modern form-select-sm" name="gender">
                                    <option value="Male"><?php echo e_t('gender_male'); ?></option>
                                    <option value="Female"><?php echo e_t('gender_female'); ?></option>
                                    <option value="Other"><?php echo e_t('gender_other'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_blood_group'); ?></label>
                                <select class="form-select-modern form-select-sm" name="blood_group">
                                    <option value="O+">O+</option><option value="O-">O-</option>
                                    <option value="A+">A+</option><option value="A-">A-</option>
                                    <option value="B+">B+</option><option value="B-">B-</option>
                                    <option value="AB+">AB+</option><option value="AB-">AB-</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_contact'); ?></label>
                                <input type="tel" class="form-control-modern form-control-sm" name="contact_number">
                            </div>
                            <div class="col-12">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_chronic'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" name="chronic_conditions">
                            </div>
                        </div>
                    </div>

                    <div id="editDoctorFields" class="p-3 bg-slate-50 border border-slate-200 rounded-3 mb-3" style="display:none;">
                        <div class="fw-bold extra-small text-uppercase text-slate-700 mb-2"><?php echo e_t('reg_practice_details'); ?></div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_specialization'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" name="specialization">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_room'); ?></label>
                                <input type="text" class="form-control-modern form-control-sm" name="room_number">
                            </div>
                            <div class="col-12">
                                <label class="form-label-modern extra-small"><?php echo e_t('label_fee_inr'); ?></label>
                                <input type="number" step="0.01" class="form-control-modern form-control-sm" name="consultation_fee">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-slate-50 border-top p-3">
                <button type="button" class="btn-modern btn-modern-outline" data-bs-dismiss="modal"><?php echo e_t('label_cancel'); ?></button>
                <button type="button" class="btn-modern btn-modern-primary" onclick="updateUser()"><?php echo e_t('btn_save_changes'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
$csrfJson = json_encode($csrf_token);
$extra_scripts = <<<HTML
<script>
const ADMIN_CSRF = {$csrfJson};
let activeRoleFilter = 'all';

function filterRole(role, btn) {
    activeRoleFilter = role;
    document.querySelectorAll('.filter-role-btn').forEach(b => {
        b.classList.remove('btn-primary', 'active');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.add('btn-primary', 'active');
    btn.classList.remove('btn-outline-secondary');
    applyUserFilters();
}

function applyUserFilters() {
    const query = (document.getElementById('userSearchInput').value || '').toLowerCase();
    document.querySelectorAll('#usersTable tbody tr.user-row').forEach(row => {
        const rowRole = row.getAttribute('data-role');
        const matchesRole = activeRoleFilter === 'all' || rowRole === activeRoleFilter;
        const matchesText = row.innerText.toLowerCase().includes(query);
        row.style.display = (matchesRole && matchesText) ? '' : 'none';
    });
}

async function changeUserRole(userId, selectEl) {
    const newRole = selectEl.value;
    const prevRole = selectEl.getAttribute('data-current-role');
    selectEl.disabled = true;

    const formData = new FormData();
    formData.append('csrf_token', ADMIN_CSRF);
    formData.append('user_id', userId);
    formData.append('role', newRole);

    try {
        const res = await fetch(window.BASE_URL + '/admin/manage_users.php?action=update_role', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            selectEl.setAttribute('data-current-role', newRole);
            const row = selectEl.closest('tr');
            if (row) row.setAttribute('data-role', newRole);
            const td = selectEl.closest('td');
            if (td) td.setAttribute('data-sort', newRole);
            showToast(data.data?.message || tr('msg_role_updated'), 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            selectEl.value = prevRole;
            showToast(data.data?.message || tr('msg_role_failed'), 'error');
        }
    } catch (e) {
        selectEl.value = prevRole;
        showToast(tr('msg_role_failed'), 'error');
    } finally {
        selectEl.disabled = false;
    }
}

function toggleAddUserFields() {
    const role = document.querySelector('#addUserForm [name="role"]').value;
    document.getElementById('addPatientFields').style.display = role === 'patient' ? 'block' : 'none';
    document.getElementById('addDoctorFields').style.display = role === 'doctor' ? 'block' : 'none';
}

function toggleEditUserFields() {
    const role = document.querySelector('#editUserForm [name="role"]').value;
    document.getElementById('editPatientFields').style.display = role === 'patient' ? 'block' : 'none';
    document.getElementById('editDoctorFields').style.display = role === 'doctor' ? 'block' : 'none';
}

async function submitAddUser() {
    const form = document.getElementById('addUserForm');
    if (!form.reportValidity()) return;
    const res = await fetch(window.BASE_URL + '/admin/manage_users.php', { method: 'POST', body: new FormData(form) });
    const data = await res.json();
    if (data.success) {
        showToast(data.data?.message || tr('msg_updated'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(data.data?.message || tr('msg_error'), 'error');
    }
}

async function editUser(userId) {
    try {
        const res = await fetch(window.BASE_URL + '/admin/manage_users.php?action=get_user&id=' + userId);
        const data = await res.json();
        if (!data.success) {
            showToast(data.data?.message || tr('msg_error'), 'error');
            return;
        }
        const user = data.data;
        const form = document.getElementById('editUserForm');
        form.querySelector('[name="user_id"]').value = user.id;
        form.querySelector('[name="name"]').value = user.name;
        form.querySelector('[name="email"]').value = user.email;
        form.querySelector('[name="role"]').value = user.role;

        if (user.role === 'patient' && user.patient_profile) {
            form.querySelector('[name="dob"]').value = user.patient_profile.dob || '';
            form.querySelector('[name="gender"]').value = user.patient_profile.gender || 'Male';
            form.querySelector('[name="blood_group"]').value = user.patient_profile.blood_group || 'O+';
            form.querySelector('[name="contact_number"]').value = user.patient_profile.contact_number || '';
            form.querySelector('[name="chronic_conditions"]').value = user.patient_profile.chronic_conditions || '';
        } else if (user.role === 'doctor' && user.doctor_profile) {
            form.querySelector('[name="specialization"]').value = user.doctor_profile.specialization || '';
            form.querySelector('[name="room_number"]').value = user.doctor_profile.room_number || '';
            form.querySelector('[name="consultation_fee"]').value = user.doctor_profile.consultation_fee || '';
        }
        toggleEditUserFields();
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    } catch (err) {
        showToast(tr('msg_error'), 'error');
    }
}

async function updateUser() {
    const form = document.getElementById('editUserForm');
    if (!form.reportValidity()) return;
    const res = await fetch(window.BASE_URL + '/admin/manage_users.php?action=update', { method: 'POST', body: new FormData(form) });
    const data = await res.json();
    if (data.success) {
        showToast(data.data?.message || tr('msg_updated'), 'success');
        bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(data.data?.message || tr('msg_error'), 'error');
    }
}

async function deleteUser(userId) {
    if (!confirm(tr('msg_confirm_delete_user'))) return;
    const formData = new FormData();
    formData.append('csrf_token', ADMIN_CSRF);
    try {
        const res = await fetch(window.BASE_URL + '/admin/manage_users.php?action=delete&id=' + userId, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast(data.data?.message || tr('msg_updated'), 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.data?.message || tr('msg_error'), 'error');
        }
    } catch (e) {
        showToast(tr('msg_error'), 'error');
    }
}
</script>
HTML;

include __DIR__ . '/../includes/portal_footer.php';
?>
