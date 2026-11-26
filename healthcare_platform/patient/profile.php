<?php
/**
 * Redesigned Patient Profile & Medical Records Workspace
 * - Clean, spacious unified two-column workspace (no stacked/nested card congestion)
 * - Left Rail: Concise Patient Summary + Clean Section Navigation
 * - Right Main Panel: Active Section (Profile Overview w/ View & Edit Modes,
 *   Appointments, Prescriptions, Risk Assessments, Medical Scans & Reports, Billing & Charges)
 * - Summary Preview (View Summary) & Download Summary using shared generator
 * - INR Currency (₹) & Multilingual (EN / MR / HI)
 */

require_once __DIR__ . '/../config/db.php';
configureSecureSession();
requireAuth();
requireRole(['patient', 'doctor', 'admin']);

$currentUserId = getCurrentUserId();
$currentRole = getCurrentUserRole();
$targetPatientId = intval($_GET['id'] ?? ($currentRole === 'patient' ? $currentUserId : 0));
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
$isViewSummary = (isset($_GET['view_summary']) && $_GET['view_summary'] === '1') || (isset($_GET['view']) && $_GET['view'] === '1');

$validSections = ['overview', 'appointments', 'prescriptions', 'assessments', 'records', 'billing'];
$activeSection = $_GET['section'] ?? 'overview';
if (!in_array($activeSection, $validSections, true)) {
    $activeSection = 'overview';
}

if ($targetPatientId <= 0) {
    header('Location: ' . getDashboardUrlForRole($currentRole));
    exit;
}

if ($currentRole === 'patient' && $targetPatientId !== $currentUserId) {
    http_response_code(403);
    die('Access denied: You can only view your own profile.');
}

$patient = null;
$appointments = [];
$diagnostics = [];
$prescriptions = [];
$bills = [];
$expenseTotals = ['total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0];
$error = '';
$successMsg = '';
$editModeOpen = false;
$formValues = ['age' => '', 'contact_number' => '', 'chronic_conditions' => ''];

try {
    $pdo = getDBConnection();
    ensureBillingTableExists($pdo);

    if ($currentRole === 'doctor' && !isDoctorAuthorizedForPatient($pdo, $currentUserId, $targetPatientId)) {
        http_response_code(403);
        die('Access denied: You are not authorized to view this patient profile.');
    }

    // Handle self-profile update (including Age -> DOB sync) for Patient
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentRole === 'patient' && $targetPatientId === $currentUserId) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = t('err_csrf');
            $editModeOpen = true;
        } else {
            $contact = sanitize($_POST['contact_number'] ?? '');
            $chronic = sanitize($_POST['chronic_conditions'] ?? '');
            $rawAge = trim($_POST['age'] ?? '');
            $formValues = [
                'age' => $rawAge,
                'contact_number' => $contact,
                'chronic_conditions' => $chronic,
            ];

            $stmt = $pdo->prepare("SELECT id, dob FROM patient_profiles WHERE user_id = ? LIMIT 1");
            $stmt->execute([$targetPatientId]);
            $existingProfile = $stmt->fetch();
            $existingDob = $existingProfile['dob'] ?? null;

            $newDob = $existingDob ?: '1990-01-01';
            if ($rawAge !== '') {
                if (!ctype_digit($rawAge) || intval($rawAge) < 0 || intval($rawAge) > 125) {
                    $error = t('err_invalid_age');
                    $editModeOpen = true;
                } else {
                    $ageInt = intval($rawAge);
                    $existingAge = $existingDob ? calculateAge($existingDob) : null;
                    if ($existingAge === null || $existingAge !== $ageInt) {
                        $newDob = computeDobFromAge($ageInt, $existingDob);
                    }
                }
            }

            if ($error === '') {
                if ($existingProfile) {
                    $stmt = $pdo->prepare("UPDATE patient_profiles SET dob = ?, contact_number = ?, chronic_conditions = ? WHERE user_id = ?");
                    $stmt->execute([$newDob, $contact, $chronic, $targetPatientId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO patient_profiles (user_id, dob, gender, blood_group, chronic_conditions, contact_number) VALUES (?, ?, 'Other', 'O+', ?, ?)");
                    $stmt->execute([$targetPatientId, $newDob, $chronic, $contact]);
                }
                logActivity($targetPatientId, 'PROFILE_UPDATED', "Patient updated profile (age/contact/conditions)");
                $successMsg = t('profile_updated_msg');
                $editModeOpen = false;
                $activeSection = 'overview';
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role, u.created_at,
               pp.dob, pp.gender, pp.blood_group, pp.chronic_conditions, pp.contact_number
        FROM users u
        LEFT JOIN patient_profiles pp ON u.id = pp.user_id
        WHERE u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->execute([$targetPatientId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(404);
        die('Patient record not found.');
    }

    $stmt = $pdo->prepare("
        SELECT a.*, d.name AS doctor_name, dp.specialization, dp.room_number
        FROM appointments a
        JOIN users d ON a.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute([$targetPatientId]);
    $appointments = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT dr.*, d.name AS doctor_name, dp.specialization
        FROM diagnostic_records dr
        JOIN users d ON dr.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        WHERE dr.patient_id = ?
        ORDER BY dr.created_at DESC
    ");
    $stmt->execute([$targetPatientId]);
    $diagnostics = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT pr.*, d.name AS doctor_name, dp.specialization, dr.predicted_condition
        FROM prescriptions_and_reminders pr
        JOIN users d ON pr.doctor_id = d.id
        LEFT JOIN doctor_profiles dp ON d.id = dp.user_id
        LEFT JOIN diagnostic_records dr ON pr.diagnostic_id = dr.id
        WHERE pr.patient_id = ?
        ORDER BY pr.created_at DESC
    ");
    $stmt->execute([$targetPatientId]);
    $prescriptions = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT b.*, d.name AS doctor_name
        FROM bills b
        JOIN users d ON b.doctor_id = d.id
        WHERE b.patient_id = ?
        ORDER BY b.bill_date DESC, b.id DESC
    ");
    $stmt->execute([$targetPatientId]);
    $bills = $stmt->fetchAll();

    foreach ($bills as $b) {
        if ($b['payment_status'] !== 'cancelled') {
            $expenseTotals['total'] += (float)$b['amount'];
            if ($b['payment_status'] === 'paid') {
                $expenseTotals['paid'] += (float)$b['amount'];
            } else {
                $expenseTotals['unpaid'] += (float)$b['amount'];
            }
        }
    }
} catch (PDOException $e) {
    error_log("Patient Profile Error: " . $e->getMessage());
    $error = t('msg_error');
}

$age = !empty($patient['dob']) ? calculateAge($patient['dob']) : '--';
if (!$editModeOpen && $patient) {
    $formValues = [
        'age' => is_numeric($age) ? (string)$age : '',
        'contact_number' => (string)($patient['contact_number'] ?? ''),
        'chronic_conditions' => (string)($patient['chronic_conditions'] ?? ''),
    ];
}

/**
 * Shared patient medical summary renderer used by both View Summary and Download Summary.
 */
function renderPatientSummaryDocument(array $patient, $age, array $appointments, array $prescriptions, array $diagnostics, array $bills, array $expenseTotals, bool $isDownload): string
{
    $curLang = getCurrentLang();
    $patientId = (int)$patient['id'];
    $backUrl = BASE_URL . '/patient/profile.php' . (getCurrentUserRole() === 'patient' ? '' : ('?id=' . $patientId));
    $downloadUrl = BASE_URL . '/patient/profile.php?id=' . $patientId . '&download=1';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo htmlspecialchars($curLang); ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e_t('summary_preview_title'); ?> - <?php echo htmlspecialchars($patient['name']); ?></title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap');
            body{background:#f4f7fb;font-family:'Inter','Noto Sans Devanagari','Segoe UI',Arial,sans-serif;color:#1e293b;margin:0;line-height:1.5;}
            .toolbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;}
            .sheet{max-width:880px;margin:28px auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:34px;box-shadow:0 8px 24px rgba(15,23,42,0.06);}
            .btn-action{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid #cbd5e1;cursor:pointer;background:#fff;color:#334155;}
            .btn-primary-action{background:#0284c7;border-color:#0284c7;color:#fff;}
            h1{margin:0;font-size:22px;color:#0f172a;}
            h2{margin:24px 0 10px;font-size:15px;color:#0369a1;text-transform:uppercase;letter-spacing:0.04em;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}
            .meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin:16px 0;font-size:13px;}
            .meta-label{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;}
            .meta-val{font-weight:600;color:#0f172a;margin-top:2px;}
            table{width:100%;border-collapse:collapse;margin:10px 0 18px;font-size:13px;}
            th,td{border:1px solid #cbd5e1;padding:8px 10px;text-align:left;vertical-align:top;}
            th{background:#f1f5f9;color:#334155;font-size:12px;}
            @media print{body{background:#fff;}.no-print{display:none!important;}.sheet{margin:0;border:none;box-shadow:none;padding:16px;}}
            @media (max-width:640px){.meta{grid-template-columns:1fr;}}
        </style>
    </head>
    <body>
    <?php if (!$isDownload): ?>
        <div class="toolbar no-print">
            <strong><?php echo e_t('summary_preview_title'); ?></strong>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn-action" onclick="window.print()"><?php echo e_t('label_print_pdf'); ?></button>
                <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn-action btn-primary-action"><?php echo e_t('btn_download_summary'); ?></a>
                <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn-action"><?php echo e_t('nav_back'); ?></a>
            </div>
        </div>
    <?php endif; ?>
    <div class="sheet">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #0284c7;padding-bottom:14px;">
            <div>
                <h1><?php echo e_t('app_name'); ?> — <?php echo e_t('summary_preview_title'); ?></h1>
                <div style="font-size:13px;color:#64748b;margin-top:4px;"><?php echo htmlspecialchars($patient['name']); ?> (#<?php echo $patientId; ?>) &bull; <?php echo htmlspecialchars($patient['email']); ?></div>
            </div>
            <div style="text-align:right;font-size:12px;color:#64748b;"><?php echo date('M d, Y h:i A'); ?></div>
        </div>

        <div class="meta">
            <div><div class="meta-label"><?php echo e_t('label_age'); ?> / <?php echo e_t('label_gender'); ?></div><div class="meta-val"><?php echo $age !== '--' ? htmlspecialchars((string)$age) . ' ' . e_t('label_years') : 'N/A'; ?> &bull; <?php echo htmlspecialchars(translateStatus($patient['gender'] ?? 'Other')); ?></div></div>
            <div><div class="meta-label"><?php echo e_t('col_blood_group'); ?></div><div class="meta-val"><?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A'); ?></div></div>
            <div><div class="meta-label"><?php echo e_t('label_contact'); ?></div><div class="meta-val"><?php echo htmlspecialchars($patient['contact_number'] ?: t('not_recorded')); ?></div></div>
            <div style="grid-column:1 / -1;"><div class="meta-label"><?php echo e_t('label_update_chronic'); ?></div><div class="meta-val"><?php echo htmlspecialchars($patient['chronic_conditions'] ?: t('none_reported')); ?></div></div>
        </div>

        <h2><?php echo e_t('hist_tab_appts'); ?> (<?php echo count($appointments); ?>)</h2>
        <table>
            <thead><tr><th>#</th><th><?php echo e_t('col_doctor'); ?></th><th><?php echo e_t('col_schedule'); ?></th><th><?php echo e_t('label_status'); ?></th><th><?php echo e_t('col_notes'); ?></th></tr></thead>
            <tbody>
                <?php if (empty($appointments)): ?>
                    <tr><td colspan="5"><?php echo e_t('hist_empty_appts'); ?></td></tr>
                <?php else: foreach ($appointments as $a): ?>
                    <tr>
                        <td>#APT-<?php echo str_pad((string)$a['id'], 5, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo htmlspecialchars($a['doctor_name']); ?></td>
                        <td><?php echo formatDate($a['appointment_date']); ?> <?php echo date('h:i A', strtotime($a['appointment_time'])); ?></td>
                        <td><?php echo htmlspecialchars(translateStatus($a['status'])); ?></td>
                        <td><?php echo htmlspecialchars($a['symptom_notes'] ?: '-'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2><?php echo e_t('hist_tab_rx'); ?> (<?php echo count($prescriptions); ?>)</h2>
        <table>
            <thead><tr><th>#</th><th><?php echo e_t('col_doctor'); ?></th><th><?php echo e_t('col_medication'); ?></th><th><?php echo e_t('label_followup_date'); ?></th></tr></thead>
            <tbody>
                <?php if (empty($prescriptions)): ?>
                    <tr><td colspan="4"><?php echo e_t('hist_empty_rx'); ?></td></tr>
                <?php else: foreach ($prescriptions as $rx):
                    $meds = json_decode($rx['medication_list'] ?? '[]', true) ?: [];
                    $medParts = [];
                    foreach ($meds as $m) {
                        $medParts[] = trim(($m['drug_name'] ?? '') . ' (' . ($m['dosage'] ?? '') . ', ' . ($m['frequency'] ?? '') . ', ' . ($m['duration'] ?? '') . ')');
                    }
                    ?>
                    <tr>
                        <td>Rx #<?php echo (int)$rx['id']; ?></td>
                        <td><?php echo htmlspecialchars($rx['doctor_name']); ?></td>
                        <td><?php echo htmlspecialchars(implode('; ', $medParts) ?: '-'); ?></td>
                        <td><?php echo $rx['next_followup_date'] ? formatDate($rx['next_followup_date']) : '-'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2><?php echo e_t('hist_tab_risk'); ?> (<?php echo count($diagnostics); ?>)</h2>
        <table>
            <thead><tr><th><?php echo e_t('label_date'); ?></th><th><?php echo e_t('col_modality'); ?></th><th><?php echo e_t('col_finding'); ?></th><th><?php echo e_t('result_stage'); ?></th><th><?php echo e_t('col_latest_risk'); ?></th></tr></thead>
            <tbody>
                <?php if (empty($diagnostics)): ?>
                    <tr><td colspan="5"><?php echo e_t('hist_empty_risk'); ?></td></tr>
                <?php else: foreach ($diagnostics as $d): ?>
                    <tr>
                        <td><?php echo formatDate($d['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($d['scan_type']); ?></td>
                        <td><?php echo htmlspecialchars($d['predicted_condition']); ?></td>
                        <td><?php echo htmlspecialchars($d['predicted_stage'] ?? 'N/A'); ?></td>
                        <td><?php echo number_format((float)$d['clinical_risk_score'], 1); ?>%</td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2><?php echo e_t('hist_tab_bills'); ?> (<?php echo formatCurrency($expenseTotals['total']); ?>)</h2>
        <table>
            <thead><tr><th><?php echo e_t('col_bill_id'); ?></th><th><?php echo e_t('label_date'); ?></th><th><?php echo e_t('col_service_desc'); ?></th><th><?php echo e_t('label_amount'); ?></th><th><?php echo e_t('label_status'); ?></th></tr></thead>
            <tbody>
                <?php if (empty($bills)): ?>
                    <tr><td colspan="5"><?php echo e_t('hist_empty_bills'); ?></td></tr>
                <?php else: foreach ($bills as $b): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($b['bill_number']); ?></td>
                        <td><?php echo formatDate($b['bill_date']); ?></td>
                        <td><?php echo htmlspecialchars($b['description']); ?></td>
                        <td><?php echo formatCurrency($b['amount']); ?></td>
                        <td><?php echo htmlspecialchars(translateStatus($b['payment_status'])); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    </body>
    </html>
    <?php
    return (string)ob_get_clean();
}

if (($isDownload || $isViewSummary) && $patient) {
    $htmlDoc = renderPatientSummaryDocument($patient, $age, $appointments, $prescriptions, $diagnostics, $bills, $expenseTotals, $isDownload);
    if ($isDownload) {
        $filename = 'Patient_Summary_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $patient['name']) . '_ID' . $patient['id'] . '.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    echo $htmlDoc;
    exit;
}

$csrf_token = generateCSRFToken();
$page_title = $patient['name'] . ' — ' . t('profile_title');
$page_subtitle = t('profile_sub', ['id' => $patient['id']]);
$active_nav = $currentRole === 'patient' ? 'profile' : ($currentRole === 'doctor' ? 'patients' : 'dashboard');

include __DIR__ . '/../includes/portal_header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div class="small fw-semibold"><?php echo htmlspecialchars($error); ?></div>
    </div>
<?php endif; ?>
<?php if ($successMsg): ?>
    <div class="alert alert-success border-0 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div class="small fw-semibold"><?php echo htmlspecialchars($successMsg); ?></div>
    </div>
<?php endif; ?>

<!-- Unified Two-Column Patient Profile Workspace -->
<div class="profile-workspace">
    <!-- Clean Top Patient Banner -->
    <div class="profile-workspace-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="user-avatar-circle" style="width: 54px; height: 54px; font-size: 1.25rem;">
                    <?php echo strtoupper(substr($patient['name'], 0, 2)); ?>
                </div>
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h2 class="h4 fw-bold mb-0 text-slate-900"><?php echo htmlspecialchars($patient['name']); ?></h2>
                        <span class="badge-modern badge-modern-primary"><?php echo e_t('label_patient_id'); ?> #<?php echo (int)$patient['id']; ?></span>
                    </div>
                    <div class="small text-muted mt-1">
                        <?php echo htmlspecialchars($patient['email']); ?>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($currentRole === 'patient'): ?>
                    <button type="button" id="toggleEditProfileBtn" class="btn-modern btn-modern-primary btn-modern-sm <?php echo $editModeOpen ? 'd-none' : ''; ?>" onclick="openOverviewAndEdit()">
                        <i class="bi bi-pencil-square"></i> <?php echo e_t('btn_edit_profile'); ?>
                    </button>
                <?php elseif ($currentRole === 'doctor'): ?>
                    <a href="<?php echo BASE_URL; ?>/doctor/patient_history.php?id=<?php echo (int)$patient['id']; ?>" class="btn-modern btn-modern-primary btn-modern-sm">
                        <i class="bi bi-clock-history"></i> <?php echo e_t('btn_view_history'); ?>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/doctor/patients.php" class="btn-modern btn-modern-outline btn-modern-sm">
                        <i class="bi bi-arrow-left"></i> <?php echo e_t('nav_my_patients'); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$patient['id']; ?>&view_summary=1" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
                    <i class="bi bi-eye"></i> <?php echo e_t('btn_view_summary'); ?>
                </a>
                <a href="<?php echo BASE_URL; ?>/patient/profile.php?id=<?php echo (int)$patient['id']; ?>&download=1" class="btn-modern btn-modern-outline btn-modern-sm">
                    <i class="bi bi-download"></i> <?php echo e_t('btn_download_summary'); ?>
                </a>
            </div>
        </div>
    </div>

    <div class="row g-0">
        <!-- Left Navigation & Quick Patient Summary Rail -->
        <aside class="col-lg-4 col-xl-3 profile-workspace-rail">
            <div class="mb-4">
                <div class="profile-kv-label mb-2"><?php echo e_t('profile_quick_summary'); ?></div>
                <div class="profile-quick-row">
                    <span class="text-muted"><?php echo e_t('label_age'); ?></span>
                    <span class="fw-semibold text-slate-900"><?php echo $age !== '--' ? htmlspecialchars((string)$age) . ' ' . e_t('label_yrs') : 'N/A'; ?></span>
                </div>
                <div class="profile-quick-row">
                    <span class="text-muted"><?php echo e_t('label_gender'); ?></span>
                    <span class="fw-semibold text-slate-900"><?php echo htmlspecialchars(translateStatus($patient['gender'] ?? 'Other')); ?></span>
                </div>
                <div class="profile-quick-row">
                    <span class="text-muted"><?php echo e_t('col_blood_group'); ?></span>
                    <span class="fw-bold text-danger"><?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A'); ?></span>
                </div>
                <div class="profile-quick-row">
                    <span class="text-muted"><?php echo e_t('label_contact'); ?></span>
                    <span class="fw-semibold text-slate-900 text-end"><?php echo htmlspecialchars($patient['contact_number'] ?: t('not_recorded')); ?></span>
                </div>
            </div>

            <div class="profile-kv-label mb-2"><?php echo e_t('profile_records_nav'); ?></div>
            <div class="nav profile-section-nav" id="historyTabs" role="tablist">
                <button class="profile-section-btn <?php echo $activeSection === 'overview' ? 'active' : ''; ?>"
                        id="tab-overview-btn" data-bs-toggle="pill" data-bs-target="#tab-overview" type="button" role="tab">
                    <span><i class="bi bi-person-vcard me-2"></i><?php echo e_t('hist_tab_overview'); ?></span>
                </button>
                <button class="profile-section-btn <?php echo $activeSection === 'appointments' ? 'active' : ''; ?>"
                        id="tab-appts-btn" data-bs-toggle="pill" data-bs-target="#tab-appts" type="button" role="tab">
                    <span><i class="bi bi-calendar2-check me-2"></i><?php echo e_t('hist_tab_appts'); ?></span>
                    <span class="profile-section-count"><?php echo count($appointments); ?></span>
                </button>
                <button class="profile-section-btn <?php echo $activeSection === 'prescriptions' ? 'active' : ''; ?>"
                        id="tab-rx-btn" data-bs-toggle="pill" data-bs-target="#tab-rx" type="button" role="tab">
                    <span><i class="bi bi-prescription2 me-2"></i><?php echo e_t('hist_tab_rx'); ?></span>
                    <span class="profile-section-count"><?php echo count($prescriptions); ?></span>
                </button>
                <button class="profile-section-btn <?php echo $activeSection === 'assessments' ? 'active' : ''; ?>"
                        id="tab-risk-btn" data-bs-toggle="pill" data-bs-target="#tab-risk" type="button" role="tab">
                    <span><i class="bi bi-clipboard2-pulse me-2"></i><?php echo e_t('hist_tab_risk'); ?></span>
                    <span class="profile-section-count"><?php echo count($diagnostics); ?></span>
                </button>
                <button class="profile-section-btn <?php echo $activeSection === 'records' ? 'active' : ''; ?>"
                        id="tab-records-btn" data-bs-toggle="pill" data-bs-target="#tab-records" type="button" role="tab">
                    <span><i class="bi bi-file-earmark-medical me-2"></i><?php echo e_t('hist_tab_records'); ?></span>
                    <span class="profile-section-count"><?php echo count($diagnostics); ?></span>
                </button>
                <button class="profile-section-btn <?php echo $activeSection === 'billing' ? 'active' : ''; ?>"
                        id="tab-bills-btn" data-bs-toggle="pill" data-bs-target="#tab-bills" type="button" role="tab">
                    <span><i class="bi bi-receipt me-2"></i><?php echo e_t('hist_tab_bills'); ?></span>
                    <span class="profile-section-count"><?php echo count($bills); ?></span>
                </button>
            </div>
        </aside>

        <!-- Right Spacious Active Section Pane -->
        <div class="col-lg-8 col-xl-9 profile-workspace-main">
            <div class="tab-content" id="historyTabContent">
                <!-- Section 1: Profile Overview (View Mode / Edit Mode) -->
                <div class="tab-pane fade <?php echo $activeSection === 'overview' ? 'show active' : ''; ?>" id="tab-overview" role="tabpanel">
                    <div class="profile-stat-strip">
                        <div class="profile-stat-cell">
                            <div class="profile-kv-label"><?php echo e_t('hist_tab_appts'); ?></div>
                            <div class="h5 fw-bold text-slate-900 mb-0"><?php echo count($appointments); ?></div>
                        </div>
                        <div class="profile-stat-cell">
                            <div class="profile-kv-label"><?php echo e_t('hist_tab_rx'); ?></div>
                            <div class="h5 fw-bold text-slate-900 mb-0"><?php echo count($prescriptions); ?></div>
                        </div>
                        <div class="profile-stat-cell">
                            <div class="profile-kv-label"><?php echo e_t('hist_tab_risk'); ?></div>
                            <div class="h5 fw-bold text-slate-900 mb-0"><?php echo count($diagnostics); ?></div>
                        </div>
                        <div class="profile-stat-cell">
                            <div class="profile-kv-label"><?php echo e_t('kpi_total_billed'); ?></div>
                            <div class="h5 fw-bold text-primary mb-0 tabular-nums"><?php echo formatCurrency($expenseTotals['total']); ?></div>
                        </div>
                    </div>

                    <!-- Clean Read-Only View Mode -->
                    <div id="profileViewSection" class="<?php echo $editModeOpen ? 'd-none' : ''; ?>">
                        <h3 class="h6 fw-bold text-slate-900 mb-3"><?php echo e_t('profile_personal_section'); ?></h3>
                        <div class="profile-kv-grid mb-4">
                            <div>
                                <div class="profile-kv-label"><?php echo e_t('label_age'); ?></div>
                                <div class="profile-kv-value" id="viewProfileAge"><?php echo $age !== '--' ? htmlspecialchars((string)$age) . ' ' . e_t('label_yrs') : 'N/A'; ?></div>
                            </div>
                            <div>
                                <div class="profile-kv-label"><?php echo e_t('label_gender'); ?></div>
                                <div class="profile-kv-value"><?php echo htmlspecialchars(translateStatus($patient['gender'] ?? 'Other')); ?></div>
                            </div>
                            <div>
                                <div class="profile-kv-label"><?php echo e_t('col_blood_group'); ?></div>
                                <div class="profile-kv-value text-danger"><?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="profile-kv-label"><?php echo e_t('label_contact'); ?></div>
                                <div class="profile-kv-value" id="viewProfileContact"><?php echo htmlspecialchars($patient['contact_number'] ?: t('not_recorded')); ?></div>
                            </div>
                            <div>
                                <div class="profile-kv-label"><?php echo e_t('label_member_since'); ?></div>
                                <div class="profile-kv-value"><?php echo formatDate($patient['created_at']); ?></div>
                            </div>
                            <div>
                                <div class="profile-kv-label"><?php echo e_t('label_update_chronic'); ?></div>
                                <div class="profile-kv-value" id="viewProfileChronic"><?php echo htmlspecialchars($patient['chronic_conditions'] ?: t('none_reported')); ?></div>
                            </div>
                        </div>

                        <?php if ($currentRole === 'patient'): ?>
                            <div class="p-3 bg-slate-50 rounded-3 small text-muted d-flex align-items-center gap-2 mb-4">
                                <i class="bi bi-info-circle text-primary"></i>
                                <span><?php echo e_t('profile_view_only_note'); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($appointments) || !empty($diagnostics)): ?>
                            <div class="pt-3 border-top">
                                <h3 class="h6 fw-bold text-slate-900 mb-3"><?php echo e_t('profile_latest_activity'); ?></h3>
                                <div class="row g-3 small">
                                    <?php if (!empty($appointments)): $lastAppt = $appointments[0]; ?>
                                        <div class="col-md-6">
                                            <div class="p-3 bg-slate-50 rounded-3 h-100">
                                                <div class="profile-kv-label"><?php echo e_t('hist_tab_appts'); ?></div>
                                                <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($lastAppt['doctor_name']); ?></div>
                                                <div class="text-muted"><?php echo formatDate($lastAppt['appointment_date']); ?> &bull; <?php echo htmlspecialchars(translateStatus($lastAppt['status'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($diagnostics)): $lastDiag = $diagnostics[0]; ?>
                                        <div class="col-md-6">
                                            <div class="p-3 bg-slate-50 rounded-3 h-100">
                                                <div class="profile-kv-label"><?php echo e_t('hist_tab_risk'); ?></div>
                                                <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($lastDiag['predicted_condition']); ?> (<?php echo htmlspecialchars($lastDiag['scan_type']); ?>)</div>
                                                <div class="text-muted"><?php echo formatDate($lastDiag['created_at']); ?> &bull; <?php echo number_format((float)$lastDiag['clinical_risk_score'], 1); ?>%</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($currentRole === 'patient'): ?>
                    <!-- Edit Profile Mode (Hidden until Edit Profile is clicked) -->
                    <div id="profileEditSection" class="<?php echo $editModeOpen ? '' : 'd-none'; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="h6 fw-bold text-slate-900 mb-0"><i class="bi bi-pencil-square text-primary me-2"></i><?php echo e_t('profile_edit_title'); ?></h3>
                            <button type="button" id="cancelEditProfileBtn" class="btn-modern btn-modern-outline btn-modern-sm" onclick="exitProfileEditMode()">
                                <?php echo e_t('label_cancel'); ?>
                            </button>
                        </div>
                        <form method="POST" id="patientProfileEditForm" class="row g-3 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="col-sm-4 col-md-3">
                                <label for="patientAgeInput" class="form-label-modern"><?php echo e_t('label_update_age'); ?></label>
                                <input type="number" id="patientAgeInput" name="age" min="0" max="125" class="form-control-modern" value="<?php echo htmlspecialchars($formValues['age']); ?>" required>
                            </div>
                            <div class="col-sm-8 col-md-4">
                                <label for="patientContactInput" class="form-label-modern"><?php echo e_t('label_update_contact'); ?></label>
                                <input type="text" id="patientContactInput" name="contact_number" class="form-control-modern" value="<?php echo htmlspecialchars($formValues['contact_number']); ?>">
                            </div>
                            <div class="col-md-5">
                                <label for="patientChronicInput" class="form-label-modern"><?php echo e_t('label_update_chronic'); ?></label>
                                <input type="text" id="patientChronicInput" name="chronic_conditions" class="form-control-modern" value="<?php echo htmlspecialchars($formValues['chronic_conditions']); ?>">
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2 pt-2">
                                <button type="button" class="btn-modern btn-modern-outline" onclick="exitProfileEditMode()"><?php echo e_t('label_cancel'); ?></button>
                                <button type="submit" class="btn-modern btn-modern-primary">
                                    <i class="bi bi-check2-circle"></i> <?php echo e_t('btn_save_profile'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Section 2: Appointments -->
                <div class="tab-pane fade <?php echo $activeSection === 'appointments' ? 'show active' : ''; ?>" id="tab-appts" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h6 fw-bold text-slate-900 mb-0"><?php echo e_t('hist_tab_appts'); ?> (<?php echo count($appointments); ?>)</h3>
                    </div>
                    <?php if (empty($appointments)): ?>
                        <div class="text-center py-5 text-muted small bg-slate-50 rounded-3"><?php echo e_t('hist_empty_appts'); ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th><?php echo e_t('col_schedule'); ?></th>
                                        <th><?php echo e_t('col_doctor'); ?></th>
                                        <th><?php echo e_t('col_notes'); ?></th>
                                        <th><?php echo e_t('label_status'); ?></th>
                                        <th class="text-end"><?php echo e_t('col_receipt'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $apt): ?>
                                        <?php $isConfirmed = in_array($apt['status'], ['confirmed', 'completed'], true); ?>
                                        <tr>
                                            <td class="fw-semibold small">#APT-<?php echo str_pad((string)$apt['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                            <td class="small">
                                                <div class="fw-semibold"><?php echo formatDate($apt['appointment_date']); ?></div>
                                                <div class="extra-small text-muted"><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></div>
                                            </td>
                                            <td class="small">
                                                <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($apt['doctor_name']); ?></div>
                                                <div class="extra-small text-muted"><?php echo htmlspecialchars($apt['specialization'] ?? ''); ?></div>
                                            </td>
                                            <td class="small text-muted" style="max-width:240px;"><?php echo htmlspecialchars($apt['symptom_notes'] ?: '-'); ?></td>
                                            <td>
                                                <span class="badge-modern <?php echo $isConfirmed ? 'badge-modern-success' : ($apt['status'] === 'cancelled' ? 'badge-modern-danger' : 'badge-modern-warning'); ?>">
                                                    <?php echo htmlspecialchars(translateStatus($apt['status'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($isConfirmed): ?>
                                                    <div class="d-inline-flex gap-1">
                                                        <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('label_view'); ?>">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="<?php echo BASE_URL; ?>/patient/appointment_receipt.php?id=<?php echo (int)$apt['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                                            <i class="bi bi-download"></i> <?php echo e_t('btn_receipt'); ?>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="extra-small text-muted"><?php echo e_t('receipt_unavailable_short'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Section 3: Prescriptions -->
                <div class="tab-pane fade <?php echo $activeSection === 'prescriptions' ? 'show active' : ''; ?>" id="tab-rx" role="tabpanel">
                    <h3 class="h6 fw-bold text-slate-900 mb-3"><?php echo e_t('hist_tab_rx'); ?> (<?php echo count($prescriptions); ?>)</h3>
                    <?php if (empty($prescriptions)): ?>
                        <div class="text-center py-5 text-muted small bg-slate-50 rounded-3"><?php echo e_t('hist_empty_rx'); ?></div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($prescriptions as $rx): ?>
                                <?php $meds = json_decode($rx['medication_list'] ?? '[]', true) ?: []; ?>
                                <div class="pb-3 border-bottom">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <div>
                                            <span class="fw-bold small text-slate-900">Rx #<?php echo (int)$rx['id']; ?> &bull; <?php echo htmlspecialchars($rx['doctor_name']); ?></span>
                                            <span class="extra-small text-muted ms-2"><?php echo htmlspecialchars($rx['specialization'] ?? ''); ?><?php if (!empty($rx['predicted_condition'])) echo ' • ' . htmlspecialchars($rx['predicted_condition']); ?></span>
                                        </div>
                                        <span class="badge-modern badge-modern-neutral"><?php echo formatDate($rx['created_at']); ?></span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered small mb-2">
                                            <thead class="table-light"><tr><th><?php echo e_t('col_medication'); ?></th><th><?php echo e_t('col_dosage'); ?></th><th><?php echo e_t('col_frequency'); ?></th><th><?php echo e_t('col_duration'); ?></th></tr></thead>
                                            <tbody>
                                                <?php foreach ($meds as $m): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars($m['drug_name'] ?? ''); ?></td>
                                                        <td><?php echo htmlspecialchars($m['dosage'] ?? ''); ?></td>
                                                        <td><?php echo htmlspecialchars($m['frequency'] ?? ''); ?></td>
                                                        <td><?php echo htmlspecialchars($m['duration'] ?? ''); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex flex-wrap gap-3 extra-small text-muted">
                                        <?php if (!empty($rx['clinical_remedies_advice'])): ?>
                                            <span><strong><?php echo e_t('label_clinical_advice'); ?>:</strong> <?php echo htmlspecialchars($rx['clinical_remedies_advice']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($rx['next_followup_date'])): ?>
                                            <span class="text-primary"><strong><?php echo e_t('label_followup_date'); ?>:</strong> <?php echo formatDate($rx['next_followup_date']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Section 4: Risk Assessments -->
                <div class="tab-pane fade <?php echo $activeSection === 'assessments' ? 'show active' : ''; ?>" id="tab-risk" role="tabpanel">
                    <h3 class="h6 fw-bold text-slate-900 mb-3"><?php echo e_t('hist_tab_risk'); ?> (<?php echo count($diagnostics); ?>)</h3>
                    <?php if (empty($diagnostics)): ?>
                        <div class="text-center py-5 text-muted small bg-slate-50 rounded-3"><?php echo e_t('hist_empty_risk'); ?></div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($diagnostics as $diag): ?>
                                <?php $v = json_decode($diag['vitals_snapshot'] ?? '{}', true) ?: []; ?>
                                <div class="pb-3 border-bottom">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="badge-modern <?php echo $diag['predicted_condition'] === 'Normal' ? 'badge-modern-success' : 'badge-modern-danger'; ?>">
                                                <?php echo htmlspecialchars($diag['predicted_condition']); ?>
                                            </span>
                                            <span class="badge-modern badge-modern-info"><?php echo htmlspecialchars($diag['scan_type']); ?></span>
                                            <span class="small fw-semibold"><?php echo e_t('result_stage'); ?>: <?php echo htmlspecialchars($diag['predicted_stage'] ?? 'N/A'); ?></span>
                                        </div>
                                        <span class="extra-small text-muted"><?php echo formatDate($diag['created_at'], 'M d, Y h:i A'); ?> &bull; <?php echo htmlspecialchars($diag['doctor_name']); ?></span>
                                    </div>
                                    <div class="row g-2 small mb-2 text-slate-700">
                                        <div class="col-6 col-sm-3"><span class="text-muted"><?php echo e_t('result_risk_score'); ?>:</span> <strong><?php echo number_format((float)$diag['clinical_risk_score'], 1); ?>%</strong></div>
                                        <div class="col-6 col-sm-3"><span class="text-muted"><?php echo e_t('result_confidence'); ?>:</span> <strong><?php echo number_format((float)$diag['confidence_score'], 1); ?>%</strong></div>
                                        <div class="col-6 col-sm-3"><span class="text-muted">BP:</span> <strong><?php echo htmlspecialchars((string)($v['systolic_bp'] ?? '--')); ?>/<?php echo htmlspecialchars((string)($v['diastolic_bp'] ?? '--')); ?></strong></div>
                                        <div class="col-6 col-sm-3"><span class="text-muted">Glucose:</span> <strong><?php echo htmlspecialchars((string)($v['glucose'] ?? '--')); ?> mg/dL</strong></div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
                                            <i class="bi bi-eye"></i> <?php echo e_t('btn_view_report'); ?>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                            <i class="bi bi-download"></i> <?php echo e_t('btn_download_report'); ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Section 5: Medical Scans & Reports -->
                <div class="tab-pane fade <?php echo $activeSection === 'records' ? 'show active' : ''; ?>" id="tab-records" role="tabpanel">
                    <h3 class="h6 fw-bold text-slate-900 mb-3"><?php echo e_t('hist_tab_records'); ?> (<?php echo count($diagnostics); ?>)</h3>
                    <?php if (empty($diagnostics)): ?>
                        <div class="text-center py-5 text-muted small bg-slate-50 rounded-3"><?php echo e_t('hist_empty_records'); ?></div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($diagnostics as $diag): ?>
                                <?php $scanUrl = getScanWebUrl($diag['id'], $diag['scan_file_path']); ?>
                                <div class="col-md-6">
                                    <div class="p-3 bg-slate-50 rounded-3 h-100 d-flex gap-3">
                                        <div class="flex-shrink-0" style="width: 96px;">
                                            <?php if ($scanUrl): ?>
                                                <img src="<?php echo htmlspecialchars($scanUrl); ?>" alt="Medical Scan" class="img-fluid rounded border bg-dark" style="width:96px;height:96px;object-fit:cover;">
                                            <?php else: ?>
                                                <div class="rounded border bg-white d-flex flex-column align-items-center justify-content-center text-muted extra-small text-center p-2" style="width:96px;height:96px;">
                                                    <i class="bi bi-image fs-4 mb-1"></i>
                                                    <span><?php echo e_t('scan_unavailable'); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                                                <span class="badge-modern badge-modern-info"><?php echo htmlspecialchars($diag['scan_type']); ?></span>
                                                <span class="extra-small text-muted"><?php echo formatDate($diag['created_at']); ?></span>
                                            </div>
                                            <div class="small fw-bold text-slate-900 mb-1"><?php echo htmlspecialchars($diag['predicted_condition']); ?></div>
                                            <div class="extra-small text-muted mb-2"><?php echo htmlspecialchars($diag['doctor_name']); ?></div>
                                            <div class="d-flex gap-1">
                                                <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
                                                    <i class="bi bi-eye"></i> <?php echo e_t('label_view'); ?>
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>/patient/download_report.php?id=<?php echo (int)$diag['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                                    <i class="bi bi-download"></i> <?php echo e_t('label_download'); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Section 6: Billing & Charges -->
                <div class="tab-pane fade <?php echo $activeSection === 'billing' ? 'show active' : ''; ?>" id="tab-bills" role="tabpanel">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h3 class="h6 fw-bold text-slate-900 mb-0"><?php echo e_t('hist_tab_bills'); ?> (<?php echo count($bills); ?>)</h3>
                        <?php if (!empty($bills)): ?>
                            <div class="d-flex gap-2">
                                <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?statement=1&patient_id=<?php echo (int)$patient['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm">
                                    <i class="bi bi-eye"></i> <?php echo e_t('btn_view_statement'); ?>
                                </a>
                                <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?statement=1&patient_id=<?php echo (int)$patient['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm">
                                    <i class="bi bi-download"></i> <?php echo e_t('btn_download_statement'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-4"><div class="profile-stat-cell"><div class="profile-kv-label"><?php echo e_t('kpi_total_billed'); ?></div><div class="fw-bold text-slate-900 tabular-nums"><?php echo formatCurrency($expenseTotals['total']); ?></div></div></div>
                        <div class="col-sm-4"><div class="profile-stat-cell"><div class="profile-kv-label"><?php echo e_t('kpi_total_paid'); ?></div><div class="fw-bold text-success tabular-nums"><?php echo formatCurrency($expenseTotals['paid']); ?></div></div></div>
                        <div class="col-sm-4"><div class="profile-stat-cell"><div class="profile-kv-label"><?php echo e_t('kpi_outstanding'); ?></div><div class="fw-bold text-warning tabular-nums"><?php echo formatCurrency($expenseTotals['unpaid']); ?></div></div></div>
                    </div>

                    <?php if (empty($bills)): ?>
                        <div class="text-center py-5 text-muted small bg-slate-50 rounded-3"><?php echo e_t('hist_empty_bills'); ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th><?php echo e_t('col_bill_id'); ?></th>
                                        <th><?php echo e_t('col_service_desc'); ?></th>
                                        <th><?php echo e_t('col_doctor'); ?></th>
                                        <th><?php echo e_t('label_date'); ?></th>
                                        <th><?php echo e_t('label_amount'); ?></th>
                                        <th><?php echo e_t('label_status'); ?></th>
                                        <th class="text-end"><?php echo e_t('label_actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bills as $b): ?>
                                        <tr>
                                            <td class="fw-semibold small"><?php echo htmlspecialchars($b['bill_number']); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($b['description']); ?></td>
                                            <td class="small text-muted"><?php echo htmlspecialchars($b['doctor_name']); ?></td>
                                            <td class="small text-muted"><?php echo formatDate($b['bill_date']); ?></td>
                                            <td class="fw-bold tabular-nums"><?php echo formatCurrency($b['amount']); ?></td>
                                            <td>
                                                <span class="badge-modern <?php echo $b['payment_status'] === 'paid' ? 'badge-modern-success' : ($b['payment_status'] === 'cancelled' ? 'badge-modern-danger' : 'badge-modern-warning'); ?>">
                                                    <?php echo htmlspecialchars(translateStatus($b['payment_status'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-inline-flex gap-1">
                                                    <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?id=<?php echo (int)$b['id']; ?>" target="_blank" class="btn-modern btn-modern-outline btn-modern-sm" title="<?php echo e_t('label_view'); ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="<?php echo BASE_URL; ?>/patient/download_bill.php?id=<?php echo (int)$b['id']; ?>&download=1" class="btn-modern btn-modern-primary btn-modern-sm" title="<?php echo e_t('label_download'); ?>">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($currentRole === 'patient'): ?>
<?php
$extra_scripts = <<<HTML
<script>
function openOverviewAndEdit() {
    const overviewBtn = document.getElementById('tab-overview-btn');
    if (overviewBtn && typeof bootstrap !== 'undefined') {
        bootstrap.Tab.getOrCreateInstance(overviewBtn).show();
    }
    enterProfileEditMode();
}
function enterProfileEditMode() {
    const viewSec = document.getElementById('profileViewSection');
    const editSec = document.getElementById('profileEditSection');
    const editBtn = document.getElementById('toggleEditProfileBtn');
    if (viewSec) viewSec.classList.add('d-none');
    if (editSec) editSec.classList.remove('d-none');
    if (editBtn) editBtn.classList.add('d-none');
    const ageInput = document.getElementById('patientAgeInput');
    if (ageInput) ageInput.focus();
}
function exitProfileEditMode() {
    const viewSec = document.getElementById('profileViewSection');
    const editSec = document.getElementById('profileEditSection');
    const editBtn = document.getElementById('toggleEditProfileBtn');
    if (editSec) editSec.classList.add('d-none');
    if (viewSec) viewSec.classList.remove('d-none');
    if (editBtn) editBtn.classList.remove('d-none');
}
</script>
HTML;
?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
