<?php
session_start();
date_default_timezone_set('Africa/Kampala');

// Ensure vendor/autoload.php exists
if (!file_exists('vendor/autoload.php')) {
    if (headers_sent()) { die('CRITICAL ERROR: Headers already sent. Composer autoload missing.'); }
    $_SESSION['error_message'] = 'Composer dependencies not installed. Please run "composer install".';
    // Assuming index.php is the dashboard, data_entry.php is the form page for uploads
    header('Location: index.php');
    exit;
}
require 'vendor/autoload.php';

// Ensure db_connection.php exists
if (!file_exists('db_connection.php')) {
    if (headers_sent()) { die('CRITICAL ERROR: Headers already sent. DB connection file missing.'); }
    $_SESSION['error_message'] = 'Database connection file missing. Please contact administrator.';
    header('Location: index.php');
    exit;
}
require 'db_connection.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$_SESSION['error_message'] = null;
$_SESSION['success_message'] = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (headers_sent()) { die('Invalid request method and headers already sent.'); }
    $_SESSION['error_message'] = 'Invalid request method.';
    header('Location: data_entry.php');
    exit;
}

$selectedClassValue = htmlspecialchars($_POST['class_selection'] ?? '');
$yearValue = htmlspecialchars($_POST['year'] ?? '');
$termValue = htmlspecialchars($_POST['term'] ?? '');
$termEndDate = htmlspecialchars($_POST['term_end_date'] ?? '');
$nextTermBeginDate = htmlspecialchars($_POST['next_term_begin_date'] ?? '');
$teacherInitialsFromForm = $_POST['teacher_initials'] ?? [];

if (empty($selectedClassValue) || empty($yearValue) || empty($termValue) || empty($termEndDate) || empty($nextTermBeginDate)) {
    if (headers_sent()) { die('Required form fields missing and headers already sent.'); }
    $_SESSION['error_message'] = 'Class, Year, Term, Term End Date, and Next Term Begin Date are required.';
    header('Location: data_entry.php');
    exit;
}

$isP4_P7 = in_array($selectedClassValue, ['P4', 'P5', 'P6', 'P7']);
$isP1_P3 = in_array($selectedClassValue, ['P1', 'P2', 'P3']);
$expectedSubjectInternalKeys = []; // These are the internal codes like 'mtc', 'lit1'
$requiredSubjectInternalKeys = [];

// Define a mapping from human-readable sheet names to internal subject codes
$sheetNameToInternalCodeMap = [
    'english' => 'english', // Case-insensitive matching for sheet names
    'maths' => 'mtc',
    'literacy one' => 'lit1',
    'literacy two' => 'lit2',
    'local language' => 'local_lang',
    'religious education' => 're',
    'science' => 'science',
    'sst' => 'sst',
    'kiswahili' => 'kiswahili'
];

if ($isP4_P7) {
    // For P4-P7, subjects are English, Maths, Science, SST, Kiswahili (optional)
    // Internal codes: english, mtc, science, sst, kiswahili
    $expectedSubjectInternalKeys = ['english', 'mtc', 'science', 'sst', 'kiswahili'];
    $requiredSubjectInternalKeys = ['english', 'mtc', 'science', 'sst']; // Kiswahili is optional
} elseif ($isP1_P3) {
    // For P1-P3, subjects are English, Maths, RE, Lit1, Lit2, Local Language
    // Internal codes: english, mtc, re, lit1, lit2, local_lang
    $expectedSubjectInternalKeys = ['english', 'mtc', 're', 'lit1', 'lit2', 'local_lang'];
    $requiredSubjectInternalKeys = $expectedSubjectInternalKeys; // All are required for P1-P3
} else {
    if (headers_sent()) { die('Invalid class selection and headers already sent.'); }
    $_SESSION['error_message'] = 'Invalid class selected: ' . htmlspecialchars($selectedClassValue);
    header('Location: data_entry.php');
    exit;
}

// --- New: Handle single file upload ---
if (!isset($_FILES['marks_excel_file']) || $_FILES['marks_excel_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error_message'] = 'Marks Excel file is required. Error code: ' . ($_FILES['marks_excel_file']['error'] ?? 'N/A');
    header('Location: data_entry.php');
    exit;
}
$uploadedFilePath = $_FILES['marks_excel_file']['tmp_name'];
// --- End New: Handle single file upload ---

$pdo->beginTransaction();
try {
    $spreadsheet = IOFactory::load($uploadedFilePath); // Load the entire workbook

    // Validate required sheets are present
    $presentSheetNames = $spreadsheet->getSheetNames();
    $presentInternalSubjectKeys = [];
    foreach ($presentSheetNames as $sheetName) {
        $normalizedSheetName = strtolower(trim($sheetName));
        if (isset($sheetNameToInternalCodeMap[$normalizedSheetName])) {
            $presentInternalSubjectKeys[] = $sheetNameToInternalCodeMap[$normalizedSheetName];
        }
    }

    foreach ($requiredSubjectInternalKeys as $reqKey) {
        if (!in_array($reqKey, $presentInternalSubjectKeys)) {
            $_SESSION['error_message'] = "Required subject sheet for '" . ucfirst(str_replace('_', ' ', $reqKey)) . "' is missing from the uploaded Excel file for class " . htmlspecialchars($selectedClassValue) . ".";
            header('Location: data_entry.php');
            exit;
        }
    }
    // --- End Sheet Validation ---

    $academicYearId = findOrCreateLookup($pdo, 'academic_years', 'year_name', $yearValue);
    $termId = findOrCreateLookup($pdo, 'terms', 'term_name', $termValue);
    $classId = findOrCreateLookup($pdo, 'classes', 'class_name', $selectedClassValue);

    $stmtBatch = $pdo->prepare("SELECT id FROM report_batch_settings WHERE academic_year_id = :year_id AND term_id = :term_id AND class_id = :class_id");
    $stmtBatch->execute([':year_id' => $academicYearId, ':term_id' => $termId, ':class_id' => $classId]);
    $reportBatchId = $stmtBatch->fetchColumn();

    if ($reportBatchId) {
        $stmtUpdateBatch = $pdo->prepare("UPDATE report_batch_settings SET term_end_date = :term_end, next_term_begin_date = :next_term_begin, import_date = CURRENT_TIMESTAMP WHERE id = :id");
        $stmtUpdateBatch->execute([':term_end' => $termEndDate, ':next_term_begin' => $nextTermBeginDate, ':id' => $reportBatchId]);
        $stmtDeleteOldScores = $pdo->prepare("DELETE FROM scores WHERE report_batch_id = :batch_id");
        $stmtDeleteOldScores->execute([':batch_id' => $reportBatchId]);
        $stmtDeleteOldSummaries = $pdo->prepare("DELETE FROM student_report_summary WHERE report_batch_id = :batch_id");
        $stmtDeleteOldSummaries->execute([':batch_id' => $reportBatchId]);
    } else {
        $stmtInsertBatch = $pdo->prepare("INSERT INTO report_batch_settings (academic_year_id, term_id, class_id, term_end_date, next_term_begin_date) VALUES (:year_id, :term_id, :class_id, :term_end, :next_term_begin)");
        $stmtInsertBatch->execute([':year_id' => $academicYearId, ':term_id' => $termId, ':class_id' => $classId, ':term_end' => $termEndDate, ':next_term_begin' => $nextTermBeginDate]);
        $reportBatchId = $pdo->lastInsertId();
    }

    if (!$reportBatchId) {
        throw new Exception("Could not create or retrieve report batch ID.");
    }

    // Iterate through each sheet in the loaded workbook
    foreach ($spreadsheet->getSheetNames() as $sheetName) {
        $normalizedSheetName = strtolower(trim($sheetName));

        // Skip the "Instructions" sheet or any other non-subject sheets
        if ($normalizedSheetName === 'instructions' || !isset($sheetNameToInternalCodeMap[$normalizedSheetName])) {
            continue;
        }

        $subjectInternalKey = $sheetNameToInternalCodeMap[$normalizedSheetName];
        $currentSheetObject = $spreadsheet->getSheetByName($sheetName); // Get sheet by its original name

        // Check if this subject is expected for the selected class
        if (!in_array($subjectInternalKey, $expectedSubjectInternalKeys)) {
            // This sheet might be for a different class level or an extra sheet.
            // If it's not Kiswahili for P4-P7 (which is optional), we can log a warning or ignore.
            // If it's Kiswahili and class is P1-P3, it's unexpected.
            if ($subjectInternalKey === 'kiswahili' && $isP1_P3) {
                 error_log("Warning: Kiswahili sheet found for class $selectedClassValue, but it's not expected. Skipping.");
                 continue;
            }
            // For other unexpected sheets, just skip.
            if ($subjectInternalKey !== 'kiswahili' || !$isP4_P7) { // if not (kiswahili AND p4-p7)
                 error_log("Warning: Sheet '$sheetName' (maps to '$subjectInternalKey') is not expected for class $selectedClassValue. Skipping.");
                 continue;
            }
        }


        // Fetch or create subject ID
        // Use the original sheet name for user-facing messages if needed, but internal key for DB.
        $subjectDisplayNameForError = $sheetName; // Or derive from internal key if preferred for consistency

        // Get the full subject name from DB or construct it
        $stmtSubjectName = $pdo->prepare("SELECT subject_name_full FROM subjects WHERE subject_code = :code");
        $stmtSubjectName->execute([':code' => $subjectInternalKey]);
        $dbSubjectNameFull = $stmtSubjectName->fetchColumn();

        $subjectNameForLookup = $dbSubjectNameFull ?: ucfirst(str_replace('_', ' ', $subjectInternalKey));


        $subjectId = findOrCreateLookup($pdo, 'subjects', 'subject_code', $subjectInternalKey, ['subject_name_full' => $subjectNameForLookup]);
        if (!$subjectId) {
            throw new Exception("Failed to find or create subject ID for: " . htmlspecialchars($subjectDisplayNameForError) . " (using internal code: " . htmlspecialchars($subjectInternalKey) . ")");
        }

        // Headers are LIN, Names/Name, BOT, MOT, EOT in row 1
        $headerLIN = trim(strtoupper(strval($currentSheetObject->getCell('A1')->getValue())));
        $headerName = trim(strtoupper(strval($currentSheetObject->getCell('B1')->getValue())));
        $headerBOT = trim(strtoupper(strval($currentSheetObject->getCell('C1')->getValue())));
        $headerMOT = trim(strtoupper(strval($currentSheetObject->getCell('D1')->getValue())));
        $headerEOT = trim(strtoupper(strval($currentSheetObject->getCell('E1')->getValue())));

        if ($headerLIN !== 'LIN' || !in_array($headerName, ['NAMES', 'NAME']) || $headerBOT !== 'BOT' || $headerMOT !== 'MOT' || $headerEOT !== 'EOT') {
            throw new Exception("Invalid headers in sheet '" . htmlspecialchars($sheetName) . "'. Expected A1='LIN', B1='Names/Name', C1='BOT', D1='MOT', E1='EOT'. Found: $headerLIN, $headerName, $headerBOT, $headerMOT, $headerEOT");
        }

        $highestRow = $currentSheetObject->getHighestDataRow();
        $startRow = 2; // Data starts from row 2

        if ($highestRow < $startRow) {
            error_log("Warning: No student data rows found in sheet '" . htmlspecialchars($sheetName) . "'. Sheet might be empty after row 1.");
            // If this subject is required, this might be an issue.
            // For now, just continue. The earlier check for required sheets handles missing sheets.
            // This handles empty-but-present sheets.
            if (in_array($subjectInternalKey, $requiredSubjectInternalKeys) && ($subjectInternalKey !== 'kiswahili' || !$isP4_P7) ) {
                 // Kiswahili is optional for P4-P7, so an empty sheet is fine.
                 // For other required subjects, an empty sheet could be an error or warning.
                 // Let's throw an exception if a *required* sheet is empty to alert the user.
                 // Allow Kiswahili for P4-P7 to be empty.
                 if (!($subjectInternalKey === 'kiswahili' && $isP4_P7)) {
                    throw new Exception("Required subject sheet '" . htmlspecialchars($sheetName) . "' is present but contains no student data.");
                 }
            }
            continue;
        }

        for ($row = $startRow; $row <= $highestRow; $row++) {
            $linValue = trim(strval($currentSheetObject->getCell('A' . $row)->getValue()));
            $studentNameRaw = trim(strval($currentSheetObject->getCell('B' . $row)->getValue()));

            if (empty($studentNameRaw)) continue; // Skip if no student name
            $studentNameAllCaps = strtoupper($studentNameRaw);
            $linToStore = !empty($linValue) ? $linValue : null;

            $studentId = null;
            $studentInfo = null;

            // Try to find student by LIN first, as it should be unique
            if ($linToStore) {
                $stmtStudentByLin = $pdo->prepare("SELECT id, student_name, lin_no FROM students WHERE lin_no = :lin_no");
                $stmtStudentByLin->execute([':lin_no' => $linToStore]);
                $studentInfo = $stmtStudentByLin->fetch(PDO::FETCH_ASSOC);
                if ($studentInfo) {
                    $studentId = $studentInfo['id'];
                    // LIN matches. Check if name also matches or if student name needs update.
                    if ($studentInfo['student_name'] !== $studentNameAllCaps) {
                        // Update student name if it's different for the same LIN
                        $stmtUpdateName = $pdo->prepare("UPDATE students SET student_name = :name, current_class_id = :class_id WHERE id = :id");
                        $stmtUpdateName->execute([':name' => $studentNameAllCaps, ':class_id' => $classId, ':id' => $studentId]);
                    } else {
                        // LIN and Name match, just update class if needed
                        $stmtUpdateClass = $pdo->prepare("UPDATE students SET current_class_id = :class_id WHERE id = :id");
                        $stmtUpdateClass->execute([':class_id' => $classId, ':id' => $studentId]);
                    }
                }
            }

            // If not found by LIN, try by name
            if (!$studentId) {
                $stmtStudentByName = $pdo->prepare("SELECT id, student_name, lin_no FROM students WHERE student_name = :name");
                $stmtStudentByName->execute([':name' => $studentNameAllCaps]);
                $studentInfo = $stmtStudentByName->fetch(PDO::FETCH_ASSOC);
                if ($studentInfo) {
                    $studentId = $studentInfo['id'];
                    // Student found by name. Now, handle LIN.
                    if ($linToStore) {
                        if ($studentInfo['lin_no'] !== $linToStore) {
                            // LIN in sheet is different from DB LIN for this student.
                            // Check if the new LIN from sheet is already used by *another* student.
                            $stmtCheckLinConflict = $pdo->prepare("SELECT id FROM students WHERE lin_no = :lin_no AND id != :current_student_id");
                            $stmtCheckLinConflict->execute([':lin_no' => $linToStore, ':current_student_id' => $studentId]);
                            if ($stmtCheckLinConflict->fetchColumn()) {
                                // LIN conflict! The LIN from the sheet is already assigned to a different student.
                                throw new Exception("Data conflict in sheet '" . htmlspecialchars($sheetName) . "', row " . $row . " for student '" . htmlspecialchars($studentNameRaw) . "': The LIN '" . htmlspecialchars($linToStore) . "' is already assigned to another student in the database. Please correct the Excel file.");
                            }
                            // No conflict, safe to update LIN for this student.
                            $stmtUpdateLinAndClass = $pdo->prepare("UPDATE students SET lin_no = :lin_no, current_class_id = :class_id WHERE id = :id");
                            $stmtUpdateLinAndClass->execute([':lin_no' => $linToStore, ':class_id' => $classId, ':id' => $studentId]);
                        } else {
                            // LIN in sheet is same as DB LIN, just update class
                            $stmtUpdateClassOnly = $pdo->prepare("UPDATE students SET current_class_id = :class_id WHERE id = :id");
                            $stmtUpdateClassOnly->execute([':class_id' => $classId, ':id' => $studentId]);
                        }
                    } else { // No LIN in sheet, but student found by name. Just update class.
                        $stmtUpdateClassOnly = $pdo->prepare("UPDATE students SET current_class_id = :class_id WHERE id = :id");
                        $stmtUpdateClassOnly->execute([':class_id' => $classId, ':id' => $studentId]);
                    }
                }
            }

            // If still no studentId, then insert new student
            if (!$studentId) {
                // Before inserting, if a LIN is provided, ensure it's not already in the database AT ALL
                // (This check is implicitly covered by unique constraint if insert fails, but explicit check gives better error before attempting insert)
                if ($linToStore) {
                    $stmtCheckLinExists = $pdo->prepare("SELECT id FROM students WHERE lin_no = :lin_no");
                    $stmtCheckLinExists->execute([':lin_no' => $linToStore]);
                    if ($stmtCheckLinExists->fetchColumn()) {
                         throw new Exception("Data conflict in sheet '" . htmlspecialchars($sheetName) . "', row " . $row . " for new student '" . htmlspecialchars($studentNameRaw) . "': The LIN '" . htmlspecialchars($linToStore) . "' is already assigned to another student in the database. Cannot create new student with this LIN.");
                    }
                }
                $stmtInsertStudent = $pdo->prepare("INSERT INTO students (student_name, current_class_id, lin_no) VALUES (:name, :class_id, :lin_no)");
                $stmtInsertStudent->execute([':name' => $studentNameAllCaps, ':class_id' => $classId, ':lin_no' => $linToStore]);
                $studentId = $pdo->lastInsertId();
            }

            // Get scores
            $botScore = $currentSheetObject->getCell('C' . $row)->getValue();
            $motScore = $currentSheetObject->getCell('D' . $row)->getValue();
            $eotScore = $currentSheetObject->getCell('E' . $row)->getValue();

            // Insert/Update scores
            $sqlScore = "INSERT INTO scores (student_id, subject_id, report_batch_id, bot_score, mot_score, eot_score)
                         VALUES (:student_id, :subject_id, :report_batch_id, :bot, :mot, :eot)
                         ON DUPLICATE KEY UPDATE
                            bot_score = VALUES(bot_score),
                            mot_score = VALUES(mot_score),
                            eot_score = VALUES(eot_score)";
            $stmtScore = $pdo->prepare($sqlScore);
            $stmtScore->execute([
                ':student_id' => $studentId,
                ':subject_id' => $subjectId,
                ':report_batch_id' => $reportBatchId,
                ':bot' => (is_numeric($botScore) ? (float)$botScore : null),
                ':mot' => (is_numeric($motScore) ? (float)$motScore : null),
                ':eot' => (is_numeric($eotScore) ? (float)$eotScore : null)
            ]);
        }
    } // End of loop through sheets

    $pdo->commit();
    $_SESSION['success_message'] = 'Data imported successfully from the Excel file for class ' . htmlspecialchars($selectedClassValue) . ' (Batch ID: ' . htmlspecialchars($reportBatchId) . '). Ready for next steps (calculations).';
    $_SESSION['current_teacher_initials'] = $teacherInitialsFromForm;
    $_SESSION['last_processed_batch_id'] = $reportBatchId;
    $_SESSION['batch_data_changed_for_calc'][$reportBatchId] = true; // Set flag for this batch

    // Log successful import
    if (!function_exists('logActivity')) { // Ensure dal.php was required if not already
        require_once 'dal.php';
    }
    $logDescription = "Imported data for class " . htmlspecialchars($selectedClassValue) .
                      ", term " . htmlspecialchars($termValue) .
                      ", year " . htmlspecialchars($yearValue) .
                      " (Batch ID: " . htmlspecialchars($reportBatchId) . ").";
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? null, // user_id from session
        $_SESSION['username'] ?? 'System', // username from session or 'System'
        'BATCH_DATA_IMPORTED',
        $logDescription,
        'batch',
        $reportBatchId,
        null // No specific user to notify for this, it's a general log
    );

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // For production, use session messages.
    $_SESSION['error_message'] = "Database error during import: " . htmlspecialchars($e->getMessage()) . " (Details logged or check code at Line: " . $e->getLine() . " in " . basename($e->getFile()) . ")";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error_message'] = "Processing error during import: " . htmlspecialchars($e->getMessage()) . " (Details logged or check code at Line: " . $e->getLine() . " in " . basename($e->getFile()) . ")";
}

// Redirect back to data_entry.php for normal operation
header('Location: data_entry.php');
exit;
?>
