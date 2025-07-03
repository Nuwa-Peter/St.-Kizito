<?php
// dal.php - Data Access Layer

if (!file_exists('db_connection.php')) {
    // This is a critical internal error
    die("FATAL ERROR: db_connection.php not found in dal.php. System misconfigured.");
}
require_once 'db_connection.php'; // Ensures $pdo is available

/**
 * Fetches the report batch settings for a given batch ID.
 * @param PDO $pdo PDO database connection object.
 * @param int $reportBatchId The ID of the report batch.
 * @return array|false The batch settings as an associative array, or false if not found.
 */
function getReportBatchSettings(PDO $pdo, int $reportBatchId): array|false {
    $sql = "SELECT
                rbs.*,
                ay.year_name,
                t.term_name,
                c.class_name
            FROM report_batch_settings rbs
            JOIN academic_years ay ON rbs.academic_year_id = ay.id
            JOIN terms t ON rbs.term_id = t.id
            JOIN classes c ON rbs.class_id = c.id
            WHERE rbs.id = :batch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':batch_id' => $reportBatchId]);
    return $stmt->fetch(); // Default fetch mode is PDO::FETCH_ASSOC from db_connection.php
}

/**
 * Fetches all students and their scores for a given report batch ID.
 * Includes subject names.
 * @param PDO $pdo
 * @param int $reportBatchId
 * @return array Associative array of students, each containing their details and an array of subjects with scores.
 */
function getStudentsWithScoresForBatch(PDO $pdo, int $reportBatchId): array {
    $studentsData = [];
    $sql = "SELECT
                s.id as student_id,
                s.student_name,
                s.lin_no,
                subj.id as subject_id,
                subj.subject_code,
                subj.subject_name_full,
                sc.bot_score,
                sc.mot_score,
                sc.eot_score,
                sc.eot_remark
                -- We will fetch pre-calculated grades/remarks/points from student_report_summary later
                -- Or, if not storing them in scores table directly:
                -- sc.eot_grade_on_report,
                -- sc.eot_points_on_report,
                -- sc.teacher_initials_on_report
            FROM students s
            JOIN scores sc ON s.id = sc.student_id
            JOIN subjects subj ON sc.subject_id = subj.id
            WHERE sc.report_batch_id = :batch_id
            ORDER BY s.student_name ASC, subj.subject_name_full ASC"; // Order for consistent processing

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':batch_id' => $reportBatchId]);

    $results = $stmt->fetchAll();

    foreach ($results as $row) {
        $studentId = $row['student_id'];
        if (!isset($studentsData[$studentId])) {
            $studentsData[$studentId] = [
                'id' => $studentId,
                'student_name' => $row['student_name'], // Already ALL CAPS from DB
                'lin_no' => $row['lin_no'],
                'subjects' => []
                // We will merge summary data (division, rank, etc.) later
            ];
        }
        $studentsData[$studentId]['subjects'][$row['subject_code']] = [ // Use subject_code as key
            'subject_id' => $row['subject_id'],
            'subject_name_full' => $row['subject_name_full'],
            'bot_score' => $row['bot_score'],
            'mot_score' => $row['mot_score'],
            'eot_score' => $row['eot_score'],
            'eot_remark' => $row['eot_remark'] ?? null // Add the new remark
            // 'eot_grade_on_report' => $row['eot_grade_on_report'], // If fetched from scores table
            // 'eot_points_on_report' => $row['eot_points_on_report'],
            // 'teacher_initials_on_report' => $row['teacher_initials_on_report']
        ];
    }
    return $studentsData; // Keyed by student_id
}


/**
 * Fetches pre-calculated student report summaries for a given batch.
 * @param PDO $pdo
 * @param int $reportBatchId
 * @return array Associative array keyed by student_id.
 */
function getStudentReportSummariesForBatch(PDO $pdo, int $reportBatchId): array {
    $summaries = [];
    $sql = "SELECT * FROM student_report_summary WHERE report_batch_id = :batch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':batch_id' => $reportBatchId]);
    $results = $stmt->fetchAll();

    foreach($results as $row) {
        $summaries[$row['student_id']] = $row;
    }
    return $summaries;
}


/**
 * Saves or updates a student's report summary data.
 * @param PDO $pdo
 * @param array $summaryData Must include student_id and report_batch_id.
 * @return bool True on success, false on failure.
 */
function saveStudentReportSummary(PDO $pdo, array $summaryData): bool {
    // Ensure required keys are present
    if (!isset($summaryData['student_id']) || !isset($summaryData['report_batch_id'])) {
        // Log error: missing required keys for summary
        error_log("DAL: Missing student_id or report_batch_id in summaryData for saveStudentReportSummary.");
        return false;
    }

    // Check if a summary already exists
    $stmtCheck = $pdo->prepare("SELECT id FROM student_report_summary WHERE student_id = :student_id AND report_batch_id = :report_batch_id");
    $stmtCheck->execute([
        ':student_id' => $summaryData['student_id'],
        ':report_batch_id' => $summaryData['report_batch_id']
    ]);
    $existingId = $stmtCheck->fetchColumn();

    $fields = [
        'student_id', 'report_batch_id',
        'p4p7_aggregate_points', 'p4p7_division',
        'p1p3_total_eot_score', 'p1p3_average_eot_score',
        'p1p3_position_in_class', 'p1p3_total_students_in_class',
        'auto_classteachers_remark_text', 'auto_headteachers_remark_text',
        'p1p3_total_bot_score', 'p1p3_position_total_bot',
        'p1p3_total_mot_score', 'p1p3_position_total_mot',
        'p1p3_position_total_eot',
        // ADDED/VERIFY New fields for P1-P3 overall BOT/MOT averages
        'p1p3_average_bot_score',
        'p1p3_average_mot_score',
        // ADD P4-P7 BOT/MOT fields
        'p4p7_aggregate_bot_score',
        'p4p7_division_bot',
        'p4p7_aggregate_mot_score',
        'p4p7_division_mot'
    ];

    $dataToSave = [];
    foreach($fields as $field) {
        // Use array_key_exists to allow NULL values to be explicitly set if they are in $summaryData
        if(array_key_exists($field, $summaryData)){
            $dataToSave[$field] = $summaryData[$field];
        } elseif ($field !== 'student_id' && $field !== 'report_batch_id') {
            // For optional fields not present in input, set to NULL to avoid unset errors
            // if they are not nullable in DB with no default, this might need adjustment.
            // Assuming optional fields are nullable.
            $dataToSave[$field] = null;
        }
    }
    // Ensure required keys are still there after filtering
     if (!isset($dataToSave['student_id']) || !isset($dataToSave['report_batch_id'])) {
        error_log("DAL: student_id or report_batch_id became unset after filtering fields for saveStudentReportSummary.");
        return false;
    }


    if ($existingId) { // Update
        $updateParts = [];
        // Restore full $updateParts logic
        foreach (array_keys($dataToSave) as $key) {
            if ($key !== 'student_id' && $key !== 'report_batch_id' && $key !== 'id') { // 'id' is not in $dataToSave at this point
                $updateParts[] = "`$key` = :$key";
            }
        }
        if (empty($updateParts)) return true; // Nothing to update

        $sql = "UPDATE student_report_summary SET " . implode(', ', $updateParts) .
               " WHERE id = :existing_id";

        // Ensure existing_id is part of the array passed to execute() for the WHERE clause
        $dataToSave['existing_id'] = $existingId;

        $stmt = $pdo->prepare($sql);

        // Bind parameters for the UPDATE SET clause and WHERE clause
        $keysInSetClause = [];
        foreach (array_keys($dataToSave) as $key) {
            if ($key !== 'student_id' && $key !== 'report_batch_id' && $key !== 'id' && $key !== 'existing_id') {
                $keysInSetClause[] = $key;
            }
        }
        foreach ($keysInSetClause as $keyToBind) {
            // Check if placeholder exists in SQL to prevent errors with bindValue
            // This is a good practice, though $updateParts should ensure they exist.
            if (strpos($sql, ":$keyToBind") !== false) {
                 $stmt->bindValue(":$keyToBind", $dataToSave[$keyToBind]);
            }
        }
        $stmt->bindValue(":existing_id", $dataToSave['existing_id']);
        // $executionPayload variable removed, direct execute after binding for UPDATE

    } else { // Insert
        $colsString = implode(', ', array_map(function($col) { return "`$col`"; }, array_keys($dataToSave)));
        $placeholdersString = implode(', ', array_map(function($col) { return ":$col"; }, array_keys($dataToSave)));
        $sql = "INSERT INTO student_report_summary ($colsString) VALUES ($placeholdersString)";
        $stmt = $pdo->prepare($sql);
        // For INSERT, $dataToSave is still passed to execute directly
    }

    try {
        if ($existingId) {
            return $stmt->execute(); // Execute with bound parameters for UPDATE
        } else {
            return $stmt->execute($dataToSave); // Execute with array for INSERT
        }
    } catch (PDOException $e) {
        $errorMsg = "DAL Base Error: " . $e->getMessage();
        $sqlState = "N/A";
        $driverCode = "N/A";
        $driverMsg = "N/A";
        $queryStringText = "N/A";
        $jsonData = "Error encoding data_to_save or it was not set at point of logging."; // Changed to data_to_save

        if (isset($stmt) && $stmt instanceof PDOStatement) {
            $pdoErrorInfo = $stmt->errorInfo();
            if ($pdoErrorInfo && is_array($pdoErrorInfo)) {
                $sqlState = $pdoErrorInfo[0] ?? "N/A";
                $driverCode = $pdoErrorInfo[1] ?? "N/A";
                $driverMsg = $pdoErrorInfo[2] ?? "N/A";
            }
            if (property_exists($stmt, 'queryString')) {
                 $queryStringText = $stmt->queryString ?? "N/A";
            }
        }

        if (isset($dataToSave)) { // Log the full $dataToSave
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $jsonData = json_encode($dataToSave, JSON_INVALID_UTF8_SUBSTITUTE);
            } else {
                $cleanedData = array_map(function($value) {
                    return is_string($value) ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
                }, $dataToSave); // Use $dataToSave
                $jsonData = json_encode($cleanedData);
            }
            if ($jsonData === false) {
                $jsonData = "Failed to json_encode data_to_save. JSON Error: " . json_last_error_msg();
            }
        }

        $finalConsolidatedError = sprintf(
            "Caught Exception: %s | SQLSTATE: %s | Driver Error Code: %s | Driver Error Message: %s",
            $errorMsg, $sqlState, $driverCode, $driverMsg
        );

        error_log("DAL Critical Error in saveStudentReportSummary: " . $finalConsolidatedError . " | Attempted Query Context: " . $queryStringText . " | Data Package: " . $jsonData);
        throw new Exception($finalConsolidatedError);
    }
}

/**
 * Updates the last dismissed admin activity timestamp for a user.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @param string $timestamp The timestamp to set.
 * @return bool True on success, false on failure.
 */
function updateUserLastDismissedAdminActivityTimestamp(PDO $pdo, int $userId, string $timestamp): bool {
    // Assuming 'users' table has a column 'last_dismissed_admin_activity_ts DATETIME NULL'
    $sql = "UPDATE users SET last_dismissed_admin_activity_ts = :timestamp WHERE id = :user_id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':timestamp' => $timestamp, ':user_id' => $userId]);
        return true; // Or $stmt->rowCount() > 0 if you want to confirm a change happened
    } catch (PDOException $e) {
        error_log("DAL Error: updateUserLastDismissedAdminActivityTimestamp failed for User ID $userId. Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches the last dismissed admin activity timestamp for a user.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $userId The ID of the user.
 * @return string|null The timestamp string or null if not set/error.
 */
function getUserLastDismissedAdminActivityTimestamp(PDO $pdo, int $userId): ?string {
    $sql = "SELECT last_dismissed_admin_activity_ts FROM users WHERE id = :user_id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetchColumn();
        return $result ?: null; // Return null if false (not found) or null
    } catch (PDOException $e) {
        error_log("DAL Error: getUserLastDismissedAdminActivityTimestamp failed for User ID $userId. Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Fetches teacher initials for specific subjects for a given report batch.
 * This is a placeholder, as teacher initials are currently passed from the form via session.
 * If initials were stored per subject per batch in DB, this function would fetch them.
 * For now, it's not strictly needed if using session as planned.
 * @param PDO $pdo
 * @param int $reportBatchId
 * @return array
 */
// function getTeacherInitialsForBatch(PDO $pdo, int $reportBatchId): array {
//     // Example: SELECT subject_id, teacher_initials FROM batch_subject_teachers WHERE report_batch_id = ...
//     return [];
// }

// More functions can be added here as needed, e.g., for fetching data for summary sheets,
// specific student lookups, etc.

/**
 * Fetches a single student's report summary data (from student_report_summary)
 * AND their basic details (name, lin_no from students table) for a given batch.
 * @param PDO $pdo
 * @param int $studentId
 * @param int $reportBatchId
 * @return array|false Associative array of the merged summary and student details, or false if not found.
 */
function getStudentSummaryAndDetailsForReport(PDO $pdo, int $studentId, int $reportBatchId): array|false {
    $sql = "SELECT srs.*, s.student_name, s.lin_no
            FROM student_report_summary srs
            JOIN students s ON srs.student_id = s.id
            WHERE srs.student_id = :student_id AND srs.report_batch_id = :report_batch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':student_id' => $studentId, ':report_batch_id' => $reportBatchId]);
    return $stmt->fetch(); // Default is PDO::FETCH_ASSOC from db_connection.php
}

/**
 * Fetches all student summaries for a given report batch ID.
 * Includes student name via a JOIN.
 * @param PDO $pdo
 * @param int $reportBatchId
 * @return array Array of student summary records, ordered by student name or position if available.
 */
function getAllStudentSummariesForBatchWithName(PDO $pdo, int $reportBatchId): array {
    $sql = "SELECT srs.*, s.student_name
            FROM student_report_summary srs
            JOIN students s ON srs.student_id = s.id
            WHERE srs.report_batch_id = :report_batch_id
            ORDER BY s.student_name ASC"; // Default order, can be adjusted
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':report_batch_id' => $reportBatchId]);
    return $stmt->fetchAll();
}

/**
 * Fetches all scores (including calculated grades per subject) for all students in a batch.
 * This is more detailed than just the summary and might be needed for grade distribution counts.
 * @param PDO $pdo
 * @param int $reportBatchId
 * @return array Array of score records.
 */
function getAllScoresWithGradesForBatch(PDO $pdo, int $reportBatchId): array {
    $sql = "SELECT sc.*, s.subject_code, s.subject_name_full
            FROM scores sc
            JOIN subjects s ON sc.subject_id = s.id
            WHERE sc.report_batch_id = :report_batch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':report_batch_id' => $reportBatchId]);
    return $stmt->fetchAll();
}

/**
 * Fetches a list of all processed report batches (class, year, term).
 * @param PDO $pdo
 * @return array List of distinct batches.
 */
function getAllProcessedBatches(PDO $pdo): array {
    $sql = "SELECT DISTINCT rbs.id as batch_id, c.class_name, ay.year_name, t.term_name
            FROM report_batch_settings rbs
            JOIN classes c ON rbs.class_id = c.id
            JOIN academic_years ay ON rbs.academic_year_id = ay.id
            JOIN terms t ON rbs.term_id = t.id
            ORDER BY ay.year_name DESC, t.term_name ASC, c.class_name ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Finds a student by name and LIN, or creates a new student if not found.
 * Student names are stored in UPPERCASE.
 * @param PDO $pdo PDO database connection object.
 * @param string $studentName The name of the student.
 * @param string|null $linNo The LIN number of the student (optional).
 * @return int|null The student's ID, or null on failure.
 */
function upsertStudent(PDO $pdo, string $studentName, ?string $linNo): ?int {
    $studentNameUpper = strtoupper(trim($studentName));
    $linNoClean = !empty($linNo) ? trim($linNo) : null;

    // Try to find student by LIN first if provided, as it's more unique
    if ($linNoClean) {
        $sqlFind = "SELECT id FROM students WHERE lin_no = :lin_no";
        $stmtFind = $pdo->prepare($sqlFind);
        $stmtFind->execute([':lin_no' => $linNoClean]);
        $studentId = $stmtFind->fetchColumn();
        if ($studentId) {
            // Optional: Update name if it differs, though be cautious
            // $sqlUpdateName = "UPDATE students SET student_name = :student_name WHERE id = :id AND student_name != :student_name";
            // $stmtUpdateName = $pdo->prepare($sqlUpdateName);
            // $stmtUpdateName->execute([':student_name' => $studentNameUpper, ':id' => $studentId]);
            return (int)$studentId;
        }
    }

    // Try to find by name (especially if LIN was not provided or didn't match)
    // This is a bit riskier for duplicates if names are common and LINs are not used consistently
    $sqlFindByName = "SELECT id FROM students WHERE student_name = :student_name";
    $paramsFindByName = [':student_name' => $studentNameUpper];
    // If LIN was provided but didn't match, we might still want to check by name *without* LIN
    // or by name *and* LIN is NULL, depending on desired strictness.
    // For now, simple name check:
    $stmtFindByName = $pdo->prepare($sqlFindByName);
    $stmtFindByName->execute($paramsFindByName);
    $studentId = $stmtFindByName->fetchColumn();

    if ($studentId) {
        // Found by name. If LIN was provided and is different, update it.
        if ($linNoClean) {
            $stmtUpdateLin = $pdo->prepare("UPDATE students SET lin_no = :lin_no WHERE id = :id AND (lin_no IS NULL OR lin_no != :lin_no)");
            $stmtUpdateLin->execute([':lin_no' => $linNoClean, ':id' => $studentId]);
        }
        return (int)$studentId;
    }

    // Not found, so insert new student
    try {
        // Removed created_at and updated_at as they are not in the user's table schema
        $sqlInsert = "INSERT INTO students (student_name, lin_no) VALUES (:student_name, :lin_no)";
        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->execute([
            ':student_name' => $studentNameUpper,
            ':lin_no' => $linNoClean
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("DAL Error: upsertStudent failed to insert. Name: $studentNameUpper, LIN: $linNoClean. Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Inserts or updates a score record for a student in a specific batch and subject.
 * Assumes the `scores` table has a composite primary key or unique constraint on
 * (report_batch_id, student_id, subject_id).
 * @param PDO $pdo
 * @param int $reportBatchId
 * @param int $studentId
 * @param int $subjectId
 * @param float|null $botScore
 * @param float|null $motScore
 * @param float|null $eotScore
 * @return bool True on success, false on failure.
 */
function upsertScore(PDO $pdo, int $reportBatchId, int $studentId, int $subjectId, ?float $botScore, ?float $motScore, ?float $eotScore): bool {
    // Removed created_at and updated_at as they are likely not in the user's scores table schema
    $sql = "INSERT INTO scores (report_batch_id, student_id, subject_id, bot_score, mot_score, eot_score)
            VALUES (:report_batch_id, :student_id, :subject_id, :bot_score, :mot_score, :eot_score)
            ON DUPLICATE KEY UPDATE
                bot_score = VALUES(bot_score),
                mot_score = VALUES(mot_score),
                eot_score = VALUES(eot_score)";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':report_batch_id' => $reportBatchId,
            ':student_id' => $studentId,
            ':subject_id' => $subjectId,
            ':bot_score' => $botScore,
            ':mot_score' => $motScore,
            ':eot_score' => $eotScore
        ]);
        return $stmt->rowCount() > 0; // Returns true if a row was inserted or updated
    } catch (PDOException $e) {
        error_log("DAL Error: upsertScore failed. Batch: $reportBatchId, Student: $studentId, Subject: $subjectId. Error: " . $e->getMessage());
        // You might want to check $e->errorInfo[1] for specific error codes like 1062 for duplicate if not using ON DUPLICATE KEY
        return false;
    }
}

/**
 * Fetches the ID of a subject by its subject code.
 * @param PDO $pdo
 * @param string $subjectCode
 * @return int|null Subject ID or null if not found.
 */
function getSubjectIdByCode(PDO $pdo, string $subjectCode): ?int {
    $sql = "SELECT id FROM subjects WHERE subject_code = :subject_code LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':subject_code' => trim($subjectCode)]);
    $result = $stmt->fetchColumn();
    return $result ? (int)$result : null;
}

/**
 * Updates basic details of an existing student.
 * @param PDO $pdo
 * @param int $studentId
 * @param string $studentName
 * @param string|null $linNo
 * @return bool True on success, false on failure or if no changes were made.
 */
function updateStudentDetails(PDO $pdo, int $studentId, string $studentName, ?string $linNo): bool {
    $studentNameUpper = strtoupper(trim($studentName));
    $linNoClean = !empty($linNo) ? trim($linNo) : null;

    // Check if student exists
    $stmtCheck = $pdo->prepare("SELECT student_name, lin_no FROM students WHERE id = :id");
    $stmtCheck->execute([':id' => $studentId]);
    $currentDetails = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$currentDetails) {
        error_log("DAL Error: updateStudentDetails failed. Student ID $studentId not found.");
        return false; // Student not found
    }

    // Only update if details have changed
    if ($currentDetails['student_name'] === $studentNameUpper && $currentDetails['lin_no'] === $linNoClean) {
        return true; // No changes needed, considered success
    }

    try {
        // Removed updated_at = NOW() as it's likely not in the user's table schema
        $sql = "UPDATE students SET student_name = :student_name, lin_no = :lin_no WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':student_name' => $studentNameUpper,
            ':lin_no' => $linNoClean,
            ':id' => $studentId
        ]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("DAL Error: updateStudentDetails failed for Student ID $studentId. Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Searches for students within a specific batch by name.
 * @param PDO $pdo
 * @param string $searchTerm
 * @param int $batchId
 * @param int $limit
 * @param bool $fetchDetails If true, fetches full details including LIN and scores.
 * @return array
 */
function searchStudentsByNameInBatch(PDO $pdo, string $searchTerm, int $batchId, int $limit = 10, bool $fetchDetails = false): array {
    $searchTermPattern = '%' . trim($searchTerm) . '%';

    if (!$fetchDetails) {
        // Original simple search for just names and IDs
        $sql = "SELECT DISTINCT s.id, s.student_name
                FROM students s
                JOIN scores sc ON s.id = sc.student_id
                WHERE sc.report_batch_id = :batch_id
                AND s.student_name LIKE :search_term
                ORDER BY s.student_name ASC
                LIMIT :limit_val";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $stmt->bindValue(':search_term', $searchTermPattern, PDO::PARAM_STR);
        $stmt->bindValue(':limit_val', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch detailed information including LIN and scores
    // This is more complex as we need to aggregate scores per student.
    // First, get matching student IDs
    $sqlStudentIds = "SELECT DISTINCT s.id
                      FROM students s
                      JOIN scores sc ON s.id = sc.student_id
                      WHERE sc.report_batch_id = :batch_id
                      AND s.student_name LIKE :search_term
                      ORDER BY s.student_name ASC
                      LIMIT :limit_val";
    $stmtStudentIds = $pdo->prepare($sqlStudentIds);
    $stmtStudentIds->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
    $stmtStudentIds->bindValue(':search_term', $searchTermPattern, PDO::PARAM_STR);
    $stmtStudentIds->bindValue(':limit_val', $limit, PDO::PARAM_INT);
    $stmtStudentIds->execute();
    $studentIds = $stmtStudentIds->fetchAll(PDO::FETCH_COLUMN);

    if (empty($studentIds)) {
        return [];
    }

    // Now fetch details for these students
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $sqlDetails = "SELECT
                        s.id as student_id,
                        s.student_name,
                        s.lin_no,
                        subj.id as subject_id,
                        subj.subject_code,
                        subj.subject_name_full,
                        sc.bot_score,
                        sc.mot_score,
                        sc.eot_score
                   FROM students s
                   LEFT JOIN scores sc ON s.id = sc.student_id AND sc.report_batch_id = ?
                   LEFT JOIN subjects subj ON sc.subject_id = subj.id
                   WHERE s.id IN ($placeholders)
                   ORDER BY s.student_name ASC, subj.subject_name_full ASC";

    $params = array_merge([$batchId], $studentIds);
    $stmtDetails = $pdo->prepare($sqlDetails);
    $stmtDetails->execute($params);
    $rows = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $studentId = $row['student_id'];
        if (!isset($results[$studentId])) {
            $results[$studentId] = [
                'id' => $studentId,
                'student_name' => $row['student_name'],
                'lin_no' => $row['lin_no'],
                'scores' => [] // Initialize scores array
            ];
        }
        if ($row['subject_code']) { // If there are scores for this subject
            $results[$studentId]['scores'][$row['subject_code']] = [
                'subject_id' => $row['subject_id'],
                'subject_name_full' => $row['subject_name_full'], // Good for display in modal if needed
                'bot_score' => $row['bot_score'],
                'mot_score' => $row['mot_score'],
                'eot_score' => $row['eot_score']
            ];
        }
    }
    return array_values($results); // Return as a simple array for JSON encoding

}


/**
    } catch (PDOException $e) {
        error_log("DAL Error: searchStudentsByNameInBatch failed. Batch: $batchId, Term: $searchTerm. Error: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Logs an activity to the activity_log table.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int|null $userId ID of the user performing the action. Can be null for system actions.
 * @param string $username Username of the user performing the action.
 * @param string $actionType A code representing the type of action (e.g., 'USER_LOGIN', 'MARKS_EDITED').
 * @param string $description A human-readable description of the action.
 * @param string|null $entityType Optional: Type of the entity related to the action (e.g., 'student', 'batch').
 * @param int|null $entityId Optional: ID of the related entity.
 * @param int|null $notifiedUserId Optional: ID of the user to be notified. If null, it's a general log entry.
 *                                 is_read will remain 0 for this user until they view it.
 * @return bool True on successful logging, false otherwise.
 */
function logActivity(
    PDO $pdo,
    ?int $userId,
    string $username,
    string $actionType,
    string $description,
    ?string $entityType = null,
    ?int $entityId = null,
    ?int $notifiedUserId = null
): bool {
    $sql = "INSERT INTO activity_log (user_id, username, action_type, description, entity_type, entity_id, notified_user_id, is_read)
            VALUES (:user_id, :username, :action_type, :description, :entity_type, :entity_id, :notified_user_id, :is_read)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':action_type', $actionType, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        $stmt->bindValue(':entity_type', $entityType, $entityType ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':entity_id', $entityId, $entityId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':notified_user_id', $notifiedUserId, $notifiedUserId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        // is_read should be 0 (false) by default if it's a notification for someone.
        // If notifiedUserId is NULL, it's a general log, is_read can be considered irrelevant or true (already "seen" by system).
        // The table default is 0, so we can explicitly set it based on notifiedUserId.
        $stmt->bindValue(':is_read', $notifiedUserId ? 0 : 1, PDO::PARAM_INT); // 0 for unread if it's a notification, 1 if general log

        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("DAL Error: logActivity failed. Action: $actionType, User: $username. Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetches the most recent activity logs, typically for an admin feed.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $limit The maximum number of records to fetch.
 * @return array An array of activity log records.
 */
function getRecentActivities(PDO $pdo, int $limit = 15): array {
    // Timestamp is expected to be 'Africa/Kampala' for display.
    // The format '%d/%m/%Y %H:%i:%s' is what the JS `parseEatTimestampToDateObject` expects.
    // IF activity_log.timestamp is stored in UTC (recommended):
    $timestamp_conversion_sql = "DATE_FORMAT(CONVERT_TZ(timestamp, 'UTC', 'Africa/Kampala'), '%d/%m/%Y %H:%i:%s')";
    // IF activity_log.timestamp is ALREADY stored in 'Africa/Kampala' (EAT):
    // $timestamp_conversion_sql = "DATE_FORMAT(timestamp, '%d/%m/%Y %H:%i:%s')";

    $sql = "SELECT
                id,
                username,
                action_type,
                description,
                {$timestamp_conversion_sql} AS timestamp,
                entity_type,
                entity_id
            FROM activity_log
            ORDER BY timestamp DESC
            LIMIT :limit_val";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit_val', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Ensure timestamp is always a string, even if CONVERT_TZ or DATE_FORMAT returns NULL
        // (e.g. if original timestamp in DB was NULL or invalid)
        foreach ($activities as &$activity) {
            if (!isset($activity['timestamp'])) {
                $activity['timestamp'] = 'N/A';
            }
        }
        unset($activity); // break the reference with the last element
        return $activities;
    } catch (PDOException $e) {
        error_log("DAL Error: getRecentActivities failed. Error: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Fetches a paginated list of all global activity logs.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $limit The maximum number of records to fetch per page.
 * @param int $offset The number of records to skip (for pagination).
 * @return array An array of activity log records.
 */
function getGlobalActivityLog(PDO $pdo, int $limit = 25, int $offset = 0): array {
    $sql = "SELECT id, timestamp, username, action_type, description, entity_type, entity_id
            FROM activity_log
            ORDER BY timestamp DESC
            LIMIT :limit_val OFFSET :offset_val";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit_val', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset_val', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DAL Error: getGlobalActivityLog failed. Error: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Fetches the total count of all global activity logs.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return int The total number of activity log records.
 */
function getGlobalActivityLogCount(PDO $pdo): int {
    $sql = "SELECT COUNT(*) FROM activity_log";
    try {
        $stmt = $pdo->query($sql);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("DAL Error: getGlobalActivityLogCount failed. Error: " . $e->getMessage());
        return 0; // Return 0 on error
    }
}

/**
 * Deletes activity logs.
 * If $olderThanTimestamp is null, all logs are deleted.
 * Otherwise, logs older than the given timestamp are deleted.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string|null $olderThanTimestamp Timestamp string (e.g., 'YYYY-MM-DD HH:MM:SS').
 * @return int Number of rows deleted, or -1 on error.
 */
function deleteActivityLogs(PDO $pdo, ?string $olderThanTimestamp = null): int {
    if ($olderThanTimestamp === null) {
        // Delete all logs
        $sql = "DELETE FROM activity_log";
    } else {
        // Delete logs older than a specific timestamp
        // Ensure the timestamp format is valid for SQL comparison.
        // This assumes $olderThanTimestamp is already in a format SQL understands (e.g., 'YYYY-MM-DD HH:MM:SS')
        $sql = "DELETE FROM activity_log WHERE timestamp < :older_than_timestamp";
    }

    try {
        $stmt = $pdo->prepare($sql);
        if ($olderThanTimestamp !== null) {
            $stmt->bindValue(':older_than_timestamp', $olderThanTimestamp, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("DAL Error: deleteActivityLogs failed. Error: " . $e->getMessage());
        return -1; // Indicate an error
    }
}

/**
 * Fetches all historical performance summaries for a given student.
 * Includes term, year, and class information for each summary.
 * Ordered by year and term.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $studentId The ID of the student.
 * @return array An array of historical performance records.
 */
function getStudentHistoricalPerformance(PDO $pdo, int $studentId): array {
    $sql = "SELECT
                srs.*,
                rbs.term_end_date,
                ay.year_name,
                t.term_name,
                c.class_name
            FROM student_report_summary srs
            JOIN report_batch_settings rbs ON srs.report_batch_id = rbs.id
            JOIN academic_years ay ON rbs.academic_year_id = ay.id
            JOIN terms t ON rbs.term_id = t.id
            JOIN classes c ON rbs.class_id = c.id
            WHERE srs.student_id = :student_id
            ORDER BY ay.year_name ASC, t.id ASC"; // Order by term ID assuming it reflects term sequence
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':student_id' => $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DAL Error: getStudentHistoricalPerformance failed for Student ID $studentId. Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches a student's scores for a specific subject across multiple terms/batches.
 * Includes term, year, and class information.
 * Ordered by year and term.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $studentId The ID of the student.
 * @param int $subjectId The ID of the subject.
 * @return array An array of scores for the subject across terms.
 */
function getStudentSubjectPerformanceAcrossTerms(PDO $pdo, int $studentId, int $subjectId): array {
    $sql = "SELECT
                sc.bot_score,
                sc.mot_score,
                sc.eot_score,
                -- If grades are stored in scores table, fetch them here e.g., sc.eot_grade
                rbs.term_end_date,
                ay.year_name,
                t.term_name,
                c.class_name,
                subj.subject_name_full,
                subj.subject_code
            FROM scores sc
            JOIN subjects subj ON sc.subject_id = subj.id
            JOIN report_batch_settings rbs ON sc.report_batch_id = rbs.id
            JOIN academic_years ay ON rbs.academic_year_id = ay.id
            JOIN terms t ON rbs.term_id = t.id
            JOIN classes c ON rbs.class_id = c.id
            WHERE sc.student_id = :student_id AND sc.subject_id = :subject_id
            ORDER BY ay.year_name ASC, t.id ASC"; // Order by term ID
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':student_id' => $studentId, ':subject_id' => $subjectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DAL Error: getStudentSubjectPerformanceAcrossTerms failed for Student ID $studentId, Subject ID $subjectId. Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches all subjects a student has scores for in a specific batch.
 * Useful for populating a subject dropdown for comparative analysis.
 *
 * @param PDO $pdo
 * @param integer $studentId
 * @param integer $batchId
 * @return array
 */
function getStudentSubjectsForBatch(PDO $pdo, int $studentId, int $batchId): array {
    $sql = "SELECT DISTINCT
                subj.id as subject_id,
                subj.subject_name_full,
                subj.subject_code
            FROM scores sc
            JOIN subjects subj ON sc.subject_id = subj.id
            WHERE sc.student_id = :student_id AND sc.report_batch_id = :batch_id
            ORDER BY subj.subject_name_full ASC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':student_id' => $studentId, ':batch_id' => $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DAL Error: getStudentSubjectsForBatch failed for Student ID $studentId, Batch ID $batchId. Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches all scores for a student within a specific batch, detailed by subject.
 * This is similar to parts of getStudentsWithScoresForBatch but focused on a single student.
 *
 * @param PDO $pdo
 * @param integer $studentId
 * @param integer $batchId
 * @return array
 */
function getStudentScoresForBatchDetailed(PDO $pdo, int $studentId, int $batchId): array {
    $sql = "SELECT
                subj.id as subject_id,
                subj.subject_code,
                subj.subject_name_full,
                sc.bot_score,
                sc.mot_score,
                sc.eot_score,
                sc.eot_remark
                -- Add sc.bot_grade, sc.mot_grade, sc.eot_grade here if they are added to the 'scores' table
            FROM scores sc
            JOIN subjects subj ON sc.subject_id = subj.id
            WHERE sc.student_id = :student_id AND sc.report_batch_id = :batch_id
            ORDER BY subj.subject_name_full ASC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':student_id' => $studentId, ':batch_id' => $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC); // Returns an array of subjects with their scores
    } catch (PDOException $e) {
        error_log("DAL Error: getStudentScoresForBatchDetailed failed for Student ID $studentId, Batch ID $batchId. Error: " . $e->getMessage());
        return [];
    }
}

?>
