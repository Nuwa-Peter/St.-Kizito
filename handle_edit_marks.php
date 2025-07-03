<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = "You must be logged in to perform this action.";
    header('Location: login.php');
    exit;
}

require_once 'db_connection.php'; // Provides $pdo
require_once 'dal.php';         // Provides DAL functions

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method.";
    header('Location: index.php'); // Or dashboard or referring page
    exit;
}

$batch_id = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT);

if (!$batch_id) {
    $_SESSION['error_message'] = "Invalid Batch ID provided.";
    header('Location: index.php'); // Or a more appropriate error page
    exit;
}

$hasErrors = false;
$changesMade = 0;
$studentDataProcessed = false; // Flag to indicate if any student data was in the POST
$modalAction = filter_input(INPUT_POST, 'modal_action', FILTER_SANITIZE_STRING);

try {
    $pdo->beginTransaction();

    // === Handle Modal Submission START ===
    if (!empty($modalAction)) {
        $studentDataProcessed = true; // Data is being processed
        $modalStudentName = trim(filter_input(INPUT_POST, 'modal_student_name', FILTER_SANITIZE_STRING));
        $modalStudentLin = trim(filter_input(INPUT_POST, 'modal_student_lin', FILTER_SANITIZE_STRING));
        $modalStudentLin = empty($modalStudentLin) ? null : $modalStudentLin;
        $modalScores = $_POST['modal_scores'] ?? []; // Array of [subject_code => [bot, mot, eot, subject_id]]

        if (empty($modalStudentName)) {
            $_SESSION['error_message'] = "Student name cannot be empty when submitting from modal.";
            $hasErrors = true;
        } else {
            if ($modalAction === 'add') {
                $student_id = upsertStudent($pdo, $modalStudentName, $modalStudentLin);
                if (!$student_id) {
                    $_SESSION['error_message'] = "Failed to add new student: " . htmlspecialchars($modalStudentName);
                    $hasErrors = true;
                    error_log("Modal Add: Failed to upsert new student: $modalStudentName, LIN: $modalStudentLin for batch $batch_id");
                } else {
                    logActivity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'STUDENT_ADDED_VIA_MODAL', "Added new student '" . htmlspecialchars($modalStudentName) . "' (ID: $student_id) to batch ID $batch_id via modal.", 'student', $student_id);
                    $changesMade++;
                    // Process scores for the new student
                    foreach ($modalScores as $subject_code => $scoreData) {
                        $subject_id_from_modal = filter_var($scoreData['subject_id'] ?? null, FILTER_VALIDATE_INT);
                        if (!$subject_id_from_modal) {
                             error_log("Modal Add: Missing subject_id for $subject_code, student $student_id. Skipping score.");
                             continue;
                        }
                        $bot = isset($scoreData['bot']) ? trim($scoreData['bot']) : null;
                        $mot = isset($scoreData['mot']) ? trim($scoreData['mot']) : null;
                        $eot = isset($scoreData['eot']) ? trim($scoreData['eot']) : null;
                        $bot_s = ($bot === '' || $bot === null) ? null : (is_numeric($bot) ? (float)$bot : null);
                        $mot_s = ($mot === '' || $mot === null) ? null : (is_numeric($mot) ? (float)$mot : null);
                        $eot_s = ($eot === '' || $eot === null) ? null : (is_numeric($eot) ? (float)$eot : null);

                        if (upsertScore($pdo, $batch_id, $student_id, $subject_id_from_modal, $bot_s, $mot_s, $eot_s)) {
                            // $changesMade++; // Counted student addition already
                        }
                    }
                }
            } elseif ($modalAction === 'edit') {
                $student_id = filter_input(INPUT_POST, 'modal_student_id', FILTER_VALIDATE_INT);
                if (!$student_id) {
                    $_SESSION['error_message'] = "Invalid student ID for modal edit.";
                    $hasErrors = true;
                    error_log("Modal Edit: Invalid student_id for batch $batch_id");
                } else {
                    // Update student details
                    if (updateStudentDetails($pdo, $student_id, $modalStudentName, $modalStudentLin)) {
                        // Check if name/lin actually changed to count as a change
                        // This requires fetching current details first, or updateStudentDetails returning more info.
                        // For simplicity, we'll count score changes primarily.
                    }

                    // Process scores for the existing student
                    $scoreChangesForLog = [];
                    foreach ($modalScores as $subject_code => $scoreData) {
                        $subject_id_from_modal = filter_var($scoreData['subject_id'] ?? null, FILTER_VALIDATE_INT);
                         if (!$subject_id_from_modal) {
                             error_log("Modal Edit: Missing subject_id for $subject_code, student $student_id. Skipping score.");
                             continue;
                        }
                        $bot = isset($scoreData['bot']) ? trim($scoreData['bot']) : null;
                        $mot = isset($scoreData['mot']) ? trim($scoreData['mot']) : null;
                        $eot = isset($scoreData['eot']) ? trim($scoreData['eot']) : null;
                        $bot_s = ($bot === '' || $bot === null) ? null : (is_numeric($bot) ? (float)$bot : null);
                        $mot_s = ($mot === '' || $mot === null) ? null : (is_numeric($mot) ? (float)$mot : null);
                        $eot_s = ($eot === '' || $eot === null) ? null : (is_numeric($eot) ? (float)$eot : null);

                        // Fetch original scores for logging comparison
                        $stmtOrig = $pdo->prepare("SELECT bot_score, mot_score, eot_score FROM scores WHERE report_batch_id = :rbid AND student_id = :sid AND subject_id = :subid");
                        $stmtOrig->execute([':rbid' => $batch_id, ':sid' => $student_id, ':subid' => $subject_id_from_modal]);
                        $originalModalScores = $stmtOrig->fetch(PDO::FETCH_ASSOC);

                        if (upsertScore($pdo, $batch_id, $student_id, $subject_id_from_modal, $bot_s, $mot_s, $eot_s)) {
                            $changesMade++; // Count each score upsert as a change
                            $changedFieldsForSubject = [];
                            if ($originalModalScores === false || $originalModalScores['bot_score'] != $bot_s) $changedFieldsForSubject[] = "BOT";
                            if ($originalModalScores === false || $originalModalScores['mot_score'] != $mot_s) $changedFieldsForSubject[] = "MOT";
                            if ($originalModalScores === false || $originalModalScores['eot_score'] != $eot_s) $changedFieldsForSubject[] = "EOT";
                            if(!empty($changedFieldsForSubject)) {
                                $scoreChangesForLog[] = "$subject_code (" . implode(',', $changedFieldsForSubject) . ")";
                            }
                        }
                    }
                    if (!empty($scoreChangesForLog)) {
                         logActivity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'MARKS_EDITED_VIA_MODAL', "Edited marks for " . htmlspecialchars($modalStudentName) . " (ID: $student_id) in batch ID $batch_id via modal. Changes: " . implode('; ', $scoreChangesForLog) , 'student', $student_id);
                    } else {
                        // Log if only name/lin changed? updateStudentDetails might need to return more info
                        // For now, if no score changes, we assume name/lin change was the primary edit if $changesMade is still 0 but updateStudentDetails was successful.
                        // This needs refinement if we want to log "name changed" separately.
                    }
                }
            }
        }
    // === Handle Modal Submission END ===

    // === Handle Table Form Submission START ===
    } elseif (isset($_POST['students']) && is_array($_POST['students']) && !empty($_POST['students'])) {
        $studentDataProcessed = true;
        foreach ($_POST['students'] as $student_id_key => $studentData) {
            $student_id = filter_var($studentData['id'], FILTER_VALIDATE_INT);
            $student_name = trim(filter_var($studentData['name'], FILTER_SANITIZE_STRING));
            $lin_no = trim(filter_var($studentData['lin_no'], FILTER_SANITIZE_STRING));
            $lin_no = empty($lin_no) ? null : $lin_no; // Store as NULL if empty

            if (!$student_id || empty($student_name)) {
                // Skip if essential data is missing for an existing student update attempt
                // Potentially log this as an anomaly
                continue;
            }

            // 1. Update student basic details (name, LIN)
            // We'll use a dedicated function or enhance upsertStudent later.
            // For now, let's assume student name/LIN changes are processed by an enhanced upsertStudent if ID is known.
            // Or, a separate updateStudentDetails function.
            // Let's assume for now we might need to update student details separately if they changed.
            // This part needs a DAL function like `updateStudentDetails(pdo, student_id, name, lin_no)`
            // For the current plan, `upsertStudent` is for adding/finding. Let's make a note to add `updateStudentDetails` to DAL.
            // For now, we'll focus on scores. The current `upsertStudent` could be called if we want to ensure the student exists.
            // However, an existing student *should* exist.

            // Update student details if they changed
            // The updateStudentDetails function in DAL handles checking if an update is actually needed.
            if (updateStudentDetails($pdo, $student_id, $student_name, $lin_no)) {
                // Optionally count this as a change if rowCount > 0, but upsertScore change is more significant for "marks updated"
            } else {
                // Potential error updating student details, though function logs it.
                // Decide if this should make $hasErrors = true;
            }


            if (isset($studentData['scores']) && is_array($studentData['scores'])) {
                foreach ($studentData['scores'] as $subject_code_key => $scoreData) {
                    $subject_id = filter_var($scoreData['subject_id'] ?? null, FILTER_VALIDATE_INT);

                    // If subject_id is not directly available, try to get it using subject_code_fallback
                    if (!$subject_id && isset($scoreData['subject_code_fallback'])) {
                        $subject_code_from_fallback = trim(filter_var($scoreData['subject_code_fallback'], FILTER_SANITIZE_STRING));
                        if (!empty($subject_code_from_fallback)) {
                            $subject_id = getSubjectIdByCode($pdo, $subject_code_from_fallback);
                            if (!$subject_id) {
                                error_log("Failed to find subject_id for fallback code: $subject_code_from_fallback for student $student_id in batch $batch_id");
                            }
                        }
                    }

                    if (!$subject_id) {
                        // Cannot process score without subject_id
                        error_log("Skipping score update for student $student_id, subject code $subject_code_key: missing subject_id.");
                        continue;
                    }

                    $bot = isset($scoreData['bot']) ? trim($scoreData['bot']) : null;
                    $mot = isset($scoreData['mot']) ? trim($scoreData['mot']) : null;
                    $eot = isset($scoreData['eot']) ? trim($scoreData['eot']) : null;

                    // Convert empty strings to null, validate numeric if not null
                    $bot_score = ($bot === '' || $bot === null) ? null : (is_numeric($bot) ? (float)$bot : null);
                    $mot_score = ($mot === '' || $mot === null) ? null : (is_numeric($mot) ? (float)$mot : null);
                    $eot_score = ($eot === '' || $eot === null) ? null : (is_numeric($eot) ? (float)$eot : null);

                    // Here we'd compare with original scores to see if an update is needed.
                    // For simplicity, we'll just call upsertScore. upsertScore should ideally handle unchanged data efficiently.

                    // Before calling upsertScore, get original scores to compare for logging
                    // This is a simplified fetch; a more robust way would be to fetch all student scores once before the loop.
                    $stmtOrigScore = $pdo->prepare("SELECT bot_score, mot_score, eot_score FROM scores WHERE report_batch_id = :rbid AND student_id = :sid AND subject_id = :subid");
                    $stmtOrigScore->execute([':rbid' => $batch_id, ':sid' => $student_id, ':subid' => $subject_id]);
                    $originalScores = $stmtOrigScore->fetch(PDO::FETCH_ASSOC);

                    if (upsertScore($pdo, $batch_id, $student_id, $subject_id, $bot_score, $mot_score, $eot_score)) {
                        $changesMade++;
                        // Log if scores actually changed
                        $changedFields = [];
                        if ($originalScores === false || $originalScores['bot_score'] != $bot_score) $changedFields[] = "BOT";
                        if ($originalScores === false || $originalScores['mot_score'] != $mot_score) $changedFields[] = "MOT";
                        if ($originalScores === false || $originalScores['eot_score'] != $eot_score) $changedFields[] = "EOT";

                        if (!empty($changedFields)) {
                             // Need subject name for a better description
                            $stmtSubjName = $pdo->prepare("SELECT subject_name_full FROM subjects WHERE id = :subid");
                            $stmtSubjName->execute([':subid' => $subject_id]);
                            $subjectName = $stmtSubjName->fetchColumn() ?: "Subject ID $subject_id";

                            logActivity(
                                $pdo,
                                $_SESSION['user_id'],
                                $_SESSION['username'],
                                'MARKS_EDITED',
                                "Edited " . implode(', ', $changedFields) . " for " . htmlspecialchars($subjectName) . " - student '" . htmlspecialchars($student_name) . "' (ID: $student_id) in batch ID $batch_id.",
                                'student',
                                $student_id,
                                null // No specific user to notify for this action, it's a general log.
                            );
                        }
                    }
                }
            }
        }
    }

    // Process new students
    if (isset($_POST['new_student']) && is_array($_POST['new_student']) && !empty($_POST['new_student'])) {
        $studentDataProcessed = true; // Mark that we are processing student data
        foreach ($_POST['new_student'] as $key => $newStudentData) { // Added $key for potential logging

            // Check if 'name' key exists and its value is not empty after trimming
            if (!isset($newStudentData['name']) || trim((string)$newStudentData['name']) === '') {
                // If name is not set or is effectively empty, skip this new student entry
                // error_log("Skipping new student entry at index $key: Name is missing or empty."); // Optional: log this event
                continue;
            }
            $student_name = trim(filter_var((string)$newStudentData['name'], FILTER_SANITIZE_STRING));

            // Safely get lin_no: if 'lin_no' key doesn't exist, treat as empty string before filtering
            $lin_no_raw = isset($newStudentData['lin_no']) ? (string)$newStudentData['lin_no'] : '';
            $lin_no = trim(filter_var($lin_no_raw, FILTER_SANITIZE_STRING));
            $lin_no = empty($lin_no) ? null : $lin_no;

            // The original check `if (empty($student_name))` is now somewhat redundant due to the earlier robust check,
            // but it's harmless to keep it.
            if (empty($student_name)) {
                // This condition should ideally not be met if the first check is robust
                continue;
            }

            // 1. Add or find student
            $new_student_id = upsertStudent($pdo, $student_name, $lin_no);

            if (!$new_student_id) {
                error_log("Failed to upsert new student: $student_name, LIN: $lin_no for batch $batch_id");
                $hasErrors = true;
                continue;
            }
            // Log student addition
            logActivity(
                $pdo,
                $_SESSION['user_id'],
                $_SESSION['username'],
                'STUDENT_ADDED_TO_BATCH',
                "Added new student '" . htmlspecialchars($student_name) . "' (ID: $new_student_id) to batch ID $batch_id.", // Corrected variable
                'student',
                $new_student_id,
                null
            );
            $changesMade++;

            // 2. Add their scores
            if (isset($newStudentData['scores']) && is_array($newStudentData['scores'])) {
                foreach ($newStudentData['scores'] as $subject_code_key => $scoreData) {
                    $subject_id = filter_var($scoreData['subject_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$subject_id && isset($scoreData['subject_code_fallback'])) {
                        $subject_code_from_fallback = trim(filter_var($scoreData['subject_code_fallback'], FILTER_SANITIZE_STRING));
                        if (!empty($subject_code_from_fallback)) {
                            $subject_id = getSubjectIdByCode($pdo, $subject_code_from_fallback);
                            if (!$subject_id) {
                                error_log("Failed to find subject_id for fallback code: $subject_code_from_fallback for new student $student_name in batch $batch_id");
                            }
                        }
                    }

                    if (!$subject_id) {
                        error_log("Skipping score INSERT for new student $student_name (ID: $new_student_id), subject code $subject_code_key: missing subject_id.");
                        continue;
                    }

                    $bot = isset($scoreData['bot']) ? trim($scoreData['bot']) : null;
                    $mot = isset($scoreData['mot']) ? trim($scoreData['mot']) : null;
                    $eot = isset($scoreData['eot']) ? trim($scoreData['eot']) : null;

                    $bot_score = ($bot === '' || $bot === null) ? null : (is_numeric($bot) ? (float)$bot : null);
                    $mot_score = ($mot === '' || $mot === null) ? null : (is_numeric($mot) ? (float)$mot : null);
                    $eot_score = ($eot === '' || $eot === null) ? null : (is_numeric($eot) ? (float)$eot : null);

                    // For new students, these are always new scores.
                    if (upsertScore($pdo, $batch_id, $new_student_id, $subject_id, $bot_score, $mot_score, $eot_score)) {
                       // $changesMade++; // upsertScore itself could return a more detailed status (inserted/updated)
                    }
                }
            }
        }
    }

    $pdo->commit();

    // Set the flag if any student data was processed and there were no overriding errors during commit.
    // $changesMade can still be used for a more nuanced success message.
    if ($studentDataProcessed && !$hasErrors) { // If we attempted to process any student data and no major error stopped us
        $_SESSION['batch_data_changed_for_calc'][$batch_id] = true;
    }

    if ($changesMade > 0) {
        // The flag is already set if $studentDataProcessed was true.
        // The success message itself will prompt recalculation.
        $_SESSION['success_message'] = "Data updated successfully. Made $changesMade change(s).";
    } else if ($hasErrors) { // If $hasErrors is true, it means a significant issue occurred (e.g., failed to upsert new student)
        $_SESSION['error_message'] = "Some errors occurred during the update. Please check the data and try again. Recalculation might be needed if some changes went through partially.";
        // If errors occurred, it's safer to assume calculations are needed if any data might have been touched.
        if ($studentDataProcessed) { // Even with errors, if we started processing, flag for recalculation.
            $_SESSION['batch_data_changed_for_calc'][$batch_id] = true;
        }
    } else if ($studentDataProcessed && $changesMade == 0) { // Data was processed, but no actual changes were made to DB (e.g. submitted identical data)
         $_SESSION['info_message'] = "No effective changes were made to the data.";
         // In this specific case (data submitted was identical to DB), we might not need to force recalc.
         // However, the current logic of upsertScore returning rowCount > 0 for $changesMade handles this.
         // If $changesMade is 0, it implies no actual DB rows were affected.
         // For simplicity, if studentDataProcessed is true and no errors, we set the flag.
         // The user can decide if they want to recalculate. The warning will show.
    } else if (!$studentDataProcessed) { // No student data in POST
        $_SESSION['info_message'] = "No student data was submitted for update.";
    }


} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in handle_edit_marks.php: " . $e->getMessage());
    $_SESSION['error_message'] = "A database error occurred: " . $e->getMessage();
    $hasErrors = true; // Ensure redirect goes with error context
} catch (Exception $e) {
     if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("General error in handle_edit_marks.php: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred: " . $e->getMessage();
    $hasErrors = true;
}

header('Location: view_processed_data.php?batch_id=' . $batch_id);
exit;

?>
