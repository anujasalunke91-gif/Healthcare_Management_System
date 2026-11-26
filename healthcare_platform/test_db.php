<?php
/**
 * Database Connection Health Check (Admin Only)
 */
require_once __DIR__ . '/config/db.php';
configureSecureSession();
requireAuth();
requireRole(['admin']);

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDBConnection();
    $userCount = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    json_response(true, [
        'database' => 'connected',
        'users_count' => (int)$userCount
    ], 200);
} catch (Exception $e) {
    error_log('Health check error: ' . $e->getMessage());
    json_response(false, ['database' => 'error'], 500);
}
