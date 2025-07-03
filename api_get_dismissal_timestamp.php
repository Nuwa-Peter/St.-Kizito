<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Access Check: Ensure user is logged in and is a superadmin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'error' => 'Access denied. Superadmin privileges required.']);
    exit;
}

require_once 'db_connection.php'; // Provides $pdo
require_once 'dal.php';         // Provides DAL functions

$userId = $_SESSION['user_id'];

try {
    $timestamp = getUserLastDismissedAdminActivityTimestamp($pdo, $userId);
    echo json_encode(['success' => true, 'timestamp' => $timestamp]);
} catch (Exception $e) {
    error_log("API Error in api_get_dismissal_timestamp.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'error' => 'An internal error occurred while fetching dismissal timestamp.']);
}
exit;
?>
