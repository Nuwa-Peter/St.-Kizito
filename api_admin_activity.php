<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Strict Superadmin Access Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. Superadmin privileges required.']);
    exit;
}

require_once 'db_connection.php'; // Provides $pdo
require_once 'dal.php';         // Provides DAL functions

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, [
    'options' => ['default' => 15, 'min_range' => 1, 'max_range' => 50]
]);

if ($limit === false || $limit === null) { // filter_input returns false on failure, null if not set (though default handles not set)
    $limit = 15; // Fallback to default if filter fails unexpectedly
}

$activities = [];
try {
    $activities = getRecentActivities($pdo, $limit);
    // Sanitize descriptions before outputting if they might contain user-input HTML/JS
    // For now, assuming descriptions are system-generated or sanitized on input for logging.
    // If descriptions can contain raw user input that's later displayed as HTML, XSS is a risk.
    // Example basic sanitization for display (if needed, apply selectively):
    // foreach ($activities as &$activity) {
    //     if (isset($activity['description'])) {
    //         $activity['description'] = htmlspecialchars($activity['description'], ENT_QUOTES, 'UTF-8');
    //     }
    // }
    // unset($activity);

    echo json_encode($activities);

} catch (Exception $e) {
    error_log("API Error in api_admin_activity.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An error occurred while fetching activities.']);
}
exit;
?>
