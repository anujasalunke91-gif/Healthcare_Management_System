<?php
/**
 * Centralized Internationalization (i18n) System
 * Supports English (en - default), Marathi (mr), and Hindi (hi).
 */

if (!defined('APP_ACCESS')) {
    exit('Direct access forbidden');
}

function getSupportedLanguages() {
    return [
        'en' => ['code' => 'en', 'name' => 'English', 'native' => 'English'],
        'mr' => ['code' => 'mr', 'name' => 'Marathi', 'native' => 'मराठी'],
        'hi' => ['code' => 'hi', 'name' => 'Hindi', 'native' => 'हिन्दी'],
    ];
}

function getCurrentLang() {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $supported = ['en', 'mr', 'hi'];
    $lang = 'en';

    if (isset($_GET['lang']) && in_array($_GET['lang'], $supported, true)) {
        $lang = $_GET['lang'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lang'] = $lang;
        }
        if (!headers_sent()) {
            setcookie('hp_lang', $lang, time() + 86400 * 30, '/', '', false, true);
        }
    } elseif (isset($_SESSION['lang']) && in_array($_SESSION['lang'], $supported, true)) {
        $lang = $_SESSION['lang'];
    } elseif (isset($_COOKIE['hp_lang']) && in_array($_COOKIE['hp_lang'], $supported, true)) {
        $lang = $_COOKIE['hp_lang'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lang'] = $lang;
        }
    }

    $resolved = $lang;
    return $resolved;
}

function loadTranslations($lang = null) {
    static $cache = [];
    $lang = $lang ?: getCurrentLang();
    if (!isset($cache['en'])) {
        $enFile = __DIR__ . '/lang/en.php';
        $cache['en'] = file_exists($enFile) ? require $enFile : [];
    }
    if ($lang === 'en') {
        return $cache['en'];
    }
    if (!isset($cache[$lang])) {
        $file = __DIR__ . '/lang/' . $lang . '.php';
        $cache[$lang] = file_exists($file) ? require $file : [];
    }
    return $cache[$lang];
}

function t($key, $params = []) {
    $lang = getCurrentLang();
    $dict = loadTranslations($lang);
    $fallback = loadTranslations('en');
    $text = $dict[$key] ?? $fallback[$key] ?? $key;

    if (!empty($params) && is_array($params)) {
        foreach ($params as $pKey => $pVal) {
            $text = str_replace('{' . $pKey . '}', (string)$pVal, $text);
        }
    }
    return $text;
}

function e_t($key, $params = []) {
    return htmlspecialchars(t($key, $params), ENT_QUOTES, 'UTF-8');
}

function translateStatus($value) {
    $norm = strtolower(trim((string)$value));
    $map = [
        'pending' => 'status_pending',
        'confirmed' => 'status_confirmed',
        'completed' => 'status_completed',
        'cancelled' => 'status_cancelled',
        'rejected' => 'status_rejected',
        'unpaid' => 'status_unpaid',
        'paid' => 'status_paid',
        'partial' => 'status_partial',
        'analyzed' => 'status_analyzed',
        'reviewed_by_doctor' => 'status_reviewed',
        'admin' => 'role_admin',
        'doctor' => 'role_doctor',
        'patient' => 'role_patient',
        'male' => 'gender_male',
        'female' => 'gender_female',
        'other' => 'gender_other',
        'yes' => 'yes',
        'no' => 'no',
    ];
    if (isset($map[$norm])) {
        return t($map[$norm]);
    }
    return (string)$value;
}

function getClientTranslations() {
    $keys = [
        'msg_select_patient', 'msg_upload_scan', 'msg_invalid_image', 'msg_file_too_large',
        'msg_risk_completed', 'msg_risk_failed', 'msg_risk_error', 'msg_running_assessment',
        'msg_run_assessment', 'msg_run_assessment_first', 'msg_one_medication_required',
        'msg_complete_medication', 'msg_rx_saved', 'msg_rx_failed', 'msg_rx_error',
        'msg_saving', 'msg_appt_booked', 'msg_appt_failed', 'msg_appt_error',
        'msg_sample_loaded', 'msg_sample_failed', 'msg_confirm_delete_user',
        'msg_role_updated', 'msg_role_failed', 'msg_updated', 'msg_error',
        'risk_low', 'risk_low_desc', 'risk_moderate', 'risk_moderate_desc',
        'risk_high', 'risk_high_desc', 'risk_critical', 'risk_critical_desc',
        'ph_med_name', 'ph_dosage', 'ph_frequency', 'ph_duration',
        'btn_remove_row', 'label_yrs', 'none_reported', 'not_recorded', 'btn_save_rx'
    ];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = t($k);
    }
    return $out;
}
