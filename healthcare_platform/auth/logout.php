<?php
/**
 * Logout Handler
 * Intelligent Healthcare Management Platform
 * Secure session termination
 */

require_once __DIR__ . '/../config/db.php';


configureSecureSession();

if (isAuthenticated()) {
    $user_id = getCurrentUserId();
    $user_role = getCurrentUserRole();
    
    // Log activity before destroying session
    logActivity($user_id, 'LOGOUT', 'User logged out');
    
    // Destroy session
    $_SESSION = array();
    
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    
    session_destroy();
}

// Redirect to login page
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
