<?php
// Start session if not already started (optional, but good for consistency if auth is ever needed here)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in (important if search reveals sensitive data, though student names might be less so)
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'User not authenticated.']);
    exit;
}

require_once 'db_connection.php'; // Provides $pdo
require_once 'dal.php';         // Provides DAL functions

header('Content-Type: application/json');

$searchTerm = trim(filter_input(INPUT_GET, 'term', FILTER_SANITIZE_STRING));
$batchId = filter_input(INPUT_GET, 'batch_id', FILTER_VALIDATE_INT);
$fetchDetails = filter_input(INPUT_GET, 'details', FILTER_VALIDATE_BOOLEAN);

if (!$batchId) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Batch ID is required.']);
    exit;
}

if (empty($searchTerm) || strlen($searchTerm) < 2) { // Minimum search term length
    echo json_encode([]); // Return empty array if search term is too short or empty
    exit;
}

$results = [];
try {
    // Pass the $fetchDetails flag to the DAL function
    $results = searchStudentsByNameInBatch($pdo, $searchTerm, $batchId, 10, $fetchDetails);
} catch (Exception $e) {
    // DAL function already logs specific PDO errors
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An error occurred while searching.']);
    exit;
}

echo json_encode($results);
exit;
?>
