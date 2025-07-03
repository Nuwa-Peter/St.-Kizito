<?php
session_start();
require_once 'db_connection.php'; // Provides $pdo
// dal.php is not strictly needed for simple deletes if we write SQL directly,
// but good practice if we had delete functions there. For now, direct SQL.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method for deleting batch.';
    header('Location: view_report_archives.php');
    exit;
}

if (!isset($_POST['batch_id']) || !filter_var($_POST['batch_id'], FILTER_VALIDATE_INT) || $_POST['batch_id'] <= 0) {
    $_SESSION['error_message'] = 'Invalid or missing Batch ID for deletion.';
    header('Location: view_report_archives.php');
    exit;
}

$batch_id_to_delete = (int)$_POST['batch_id'];

// Optional: Add a check here to see if the batch_id actually exists
// in report_batch_settings before attempting deletions, to provide a more specific error.
// For now, we assume if it's passed, an attempt should be made.

try {
    $pdo->beginTransaction();

    // 1. Delete from scores table
    $stmt_scores = $pdo->prepare("DELETE FROM scores WHERE report_batch_id = :batch_id");
    $stmt_scores->execute([':batch_id' => $batch_id_to_delete]);
    $scores_deleted_count = $stmt_scores->rowCount();

    // 2. Delete from student_report_summary table
    $stmt_summary = $pdo->prepare("DELETE FROM student_report_summary WHERE report_batch_id = :batch_id");
    $stmt_summary->execute([':batch_id' => $batch_id_to_delete]);
    $summaries_deleted_count = $stmt_summary->rowCount();

    // 3. Delete from report_batch_settings table
    $stmt_settings = $pdo->prepare("DELETE FROM report_batch_settings WHERE id = :batch_id");
    $stmt_settings->execute([':batch_id' => $batch_id_to_delete]);
    $settings_deleted_count = $stmt_settings->rowCount();

    $pdo->commit();

    if ($settings_deleted_count > 0) {
        $_SESSION['success_message'] = "Batch ID " . htmlspecialchars($batch_id_to_delete) . " and its associated data ("
                                     . $scores_deleted_count . " scores, "
                                     . $summaries_deleted_count . " summaries) were successfully deleted.";
    } else {
        // This case means the batch_id might not have existed in report_batch_settings,
        // though related scores/summaries might have been cleaned if they somehow existed without a parent setting.
        $_SESSION['warning_message'] = "Batch ID " . htmlspecialchars($batch_id_to_delete) . " was not found in settings, but any orphaned related data was attempted to be cleaned.";
        // Or, more simply if we assume batch_id must exist if passed from a valid source:
        // $_SESSION['error_message'] = "Batch ID " . htmlspecialchars($batch_id_to_delete) . " could not be found or deleted from settings.";
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log the detailed error
    error_log("Error deleting batch ID " . $batch_id_to_delete . ": " . $e->getMessage());
    $_SESSION['error_message'] = "A database error occurred while trying to delete batch data. Details: " . $e->getMessage();
} catch (Exception $e) { // Catch other general exceptions
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("General error deleting batch ID " . $batch_id_to_delete . ": " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred: " . $e->getMessage();
}

header('Location: view_report_archives.php');
exit;
?>
