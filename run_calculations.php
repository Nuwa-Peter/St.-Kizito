<?php
session_start();
date_default_timezone_set('Africa/Kampala');

require_once 'db_connection.php';
require_once 'dal.php';
require_once 'calculation_utils.php';

if (!isset($_GET['batch_id']) || !filter_var($_GET['batch_id'], FILTER_VALIDATE_INT)) {
    $_SESSION['error_message'] = "Invalid or missing batch ID for calculations.";
    header('Location: index.php');
    exit;
}
$batch_id = (int)$_GET['batch_id'];

$batchSettings = getReportBatchSettings($pdo, $batch_id);
if (!$batchSettings) {
    $_SESSION['error_message'] = "Could not find batch settings for Batch ID: " . htmlspecialchars($batch_id);
    header('Location: index.php');
    exit;
}

$isP4_P7 = in_array($batchSettings['class_name'], ['P4', 'P5', 'P6', 'P7']);
$isP1_P3 = in_array($batchSettings['class_name'], ['P1', 'P2', 'P3']);

$coreSubjectKeysP4_P7 = [];
$p1p3SubjectKeys = [];
$expectedSubjectKeysForClass = [];

if ($isP4_P7) {
    $coreSubjectKeysP4_P7 = ['english', 'mtc', 'science', 'sst'];
    $expectedSubjectKeysForClass = ['english', 'mtc', 'science', 'sst', 'kiswahili'];
} elseif ($isP1_P3) {
    $p1p3SubjectKeys = ['english', 'mtc', 're', 'lit1', 'lit2', 'local_lang'];
    $expectedSubjectKeysForClass = $p1p3SubjectKeys;
}

$gradingScalePointsMap = ['D1'=>1, 'D2'=>2, 'C3'=>3, 'C4'=>4, 'C5'=>5, 'C6'=>6, 'P7'=>7, 'P8'=>8, 'F9'=>9, 'N/A'=>0];
// Updated EOT remarks scale as per user request
$remarksScoreMap = [
    90 => "Excellent",     // 100-90
    80 => "Very Good",     // 89-80
    70 => "Good",          // 79-70
    60 => "Fair",          // 69-60
    50 => "Tried",         // 59-50
    0  => "Improve"        // 49-0
    // N/A for non-scores is handled by getSubjectEOTRemarkUtil directly
];

$studentsRawDataFromDB = getStudentsWithScoresForBatch($pdo, $batch_id);
$processedStudentsSummaryData = [];
$enrichedStudentDataForReportCard = [];

$pdo->beginTransaction();
try {
    foreach ($studentsRawDataFromDB as $studentId => $studentDataFromDB) {
        $currentStudentSubjectsEnriched = [];
        $studentPerformanceInputForOverallRemarks = [];

        $summaryDataForDB = [
            'student_id' => $studentId,
            'report_batch_id' => $batch_id,
            'p4p7_aggregate_points' => null, 'p4p7_division' => null,
            'p1p3_total_eot_score' => null, 'p1p3_average_eot_score' => null,
            'p1p3_position_in_class' => null, 'p1p3_total_students_in_class' => null,
            'auto_classteachers_remark_text' => null, 'auto_headteachers_remark_text' => null,
            'p1p3_total_bot_score' => null, 'p1p3_position_total_bot' => null,
            'p1p3_total_mot_score' => null, 'p1p3_position_total_mot' => null,
            'p1p3_position_total_eot' => null,
            // Initialize new fields for P1-P3 overall BOT/MOT averages
            'p1p3_average_bot_score' => null,
            'p1p3_average_mot_score' => null,
            // Initialize new fields for P4-P7 BOT/MOT aggregates & divisions
            'p4p7_aggregate_bot_score' => null,
            'p4p7_division_bot' => null,
            'p4p7_aggregate_mot_score' => null,
            'p4p7_division_mot' => null
        ];

        $p1p3StudentTotalBot = 0; $p1p3SubjectsWithBot = 0;
        $p1p3StudentTotalMot = 0; $p1p3SubjectsWithMot = 0;
        $p1p3StudentTotalEotForAvgAndRank = 0;
        $p1p3SubjectsWithEotForAvg = 0;

        foreach ($expectedSubjectKeysForClass as $subjectKey) {
            $subjectScores = $studentDataFromDB['subjects'][$subjectKey] ?? [
                'subject_name_full' => ucfirst($subjectKey),
                'bot_score' => 'N/A', 'mot_score' => 'N/A', 'eot_score' => 'N/A'
            ];

            $botScore = $subjectScores['bot_score'] ?? 'N/A';
            $motScore = $subjectScores['mot_score'] ?? 'N/A';
            $eotScore = $subjectScores['eot_score'] ?? 'N/A';

            $currentStudentSubjectsEnriched[$subjectKey] = [
                'subject_name_full' => $subjectScores['subject_name_full'] ?? ucfirst($subjectKey),
                'bot_score' => $botScore,
                'bot_grade' => getGradeFromScoreUtil($botScore),
                'mot_score' => $motScore,
                'mot_grade' => getGradeFromScoreUtil($motScore),
                'eot_score' => $eotScore,
                'eot_grade' => getGradeFromScoreUtil($eotScore),
                'eot_remark' => getSubjectEOTRemarkUtil($eotScore, $remarksScoreMap), // Calculated remark
                'eot_points' => ($isP4_P7) ? getPointsFromGradeUtil(getGradeFromScoreUtil($eotScore), $gradingScalePointsMap) : null
            ];

            // Save the calculated eot_remark to the scores table
            $remarkToSave = $currentStudentSubjectsEnriched[$subjectKey]['eot_remark'];
            // We need the actual subject_id for this specific score record.
            // $subjectScores comes from $studentDataFromDB['subjects'][$subjectKey]
            // and $studentDataFromDB is populated by getStudentsWithScoresForBatch which joins to get subj.id as subject_id
            $subjectIdForUpdate = $subjectScores['subject_id'] ?? null;

            if ($subjectIdForUpdate !== null && $studentId !== null && $batch_id !== null) {
                $stmtUpdateRemark = $pdo->prepare(
                    "UPDATE scores
                     SET eot_remark = :eot_remark
                     WHERE report_batch_id = :batch_id
                       AND student_id = :student_id
                       AND subject_id = :subject_id"
                );
                $stmtUpdateRemark->execute([
                    ':eot_remark' => $remarkToSave,
                    ':batch_id' => $batch_id,
                    ':student_id' => $studentId,
                    ':subject_id' => $subjectIdForUpdate
                ]);
            } else {
                // Log if we couldn't update a remark due to missing IDs
                if ($subjectIdForUpdate === null) {
                    error_log("RunCalculations: Could not save eot_remark for student $studentId, subject code $subjectKey in batch $batch_id because subject_id was missing.");
                }
            }

            if ($isP1_P3) {
                if (is_numeric($botScore) && $botScore !== 'N/A') {
                    $p1p3StudentTotalBot += (float)$botScore;
                    $p1p3SubjectsWithBot++;
                }
                if (is_numeric($motScore) && $motScore !== 'N/A') {
                    $p1p3StudentTotalMot += (float)$motScore;
                    $p1p3SubjectsWithMot++;
                }

                $validScoresForSubjectAvg = [];
                if (is_numeric($botScore)) $validScoresForSubjectAvg[] = (float)$botScore;
                if (is_numeric($motScore)) $validScoresForSubjectAvg[] = (float)$motScore;
                if (is_numeric($eotScore)) $validScoresForSubjectAvg[] = (float)$eotScore;

                if (count($validScoresForSubjectAvg) > 0) {
                    $currentStudentSubjectsEnriched[$subjectKey]['subject_term_average'] = round(array_sum($validScoresForSubjectAvg) / count($validScoresForSubjectAvg), 1);
                } else {
                    $currentStudentSubjectsEnriched[$subjectKey]['subject_term_average'] = 'N/A';
                }

                if (is_numeric($eotScore) && $eotScore !== 'N/A') {
                    $p1p3StudentTotalEotForAvgAndRank += (float)$eotScore;
                    $p1p3SubjectsWithEotForAvg++;
                }
            }
        }
        $enrichedStudentDataForReportCard[$studentId] = [
            'student_name' => $studentDataFromDB['student_name'],
            'lin_no' => $studentDataFromDB['lin_no'],
            'subjects' => $currentStudentSubjectsEnriched
        ];

        if ($isP4_P7) {
            // Calculate BOT aggregates and division
            $p4p7_bot_results = calculateP4P7_BOT_OverallPerformanceUtil($currentStudentSubjectsEnriched, $coreSubjectKeysP4_P7, $gradingScalePointsMap);
            $summaryDataForDB['p4p7_aggregate_bot_score'] = $p4p7_bot_results['p4p7_aggregate_bot_score'];
            $summaryDataForDB['p4p7_division_bot'] = $p4p7_bot_results['p4p7_division_bot'];

            // Calculate MOT aggregates and division
            $p4p7_mot_results = calculateP4P7_MOT_OverallPerformanceUtil($currentStudentSubjectsEnriched, $coreSubjectKeysP4_P7, $gradingScalePointsMap);
            $summaryDataForDB['p4p7_aggregate_mot_score'] = $p4p7_mot_results['p4p7_aggregate_mot_score'];
            $summaryDataForDB['p4p7_division_mot'] = $p4p7_mot_results['p4p7_division_mot'];

            // Calculate EOT aggregates and division (existing logic)
            $p4p7_eot_results = calculateP4P7OverallPerformanceUtil($currentStudentSubjectsEnriched, $coreSubjectKeysP4_P7, $gradingScalePointsMap);
            $summaryDataForDB['p4p7_aggregate_points'] = $p4p7_eot_results['p4p7_aggregate_points'];
            $summaryDataForDB['p4p7_division'] = $p4p7_eot_results['p4p7_division'];

            // For remarks, EOT performance is primary
            $studentPerformanceInputForOverallRemarks = $p4p7_eot_results;
        } elseif ($isP1_P3) {
            $summaryDataForDB['p1p3_total_bot_score'] = $p1p3StudentTotalBot;
            $summaryDataForDB['p1p3_average_bot_score'] = ($p1p3SubjectsWithBot > 0) ? round($p1p3StudentTotalBot / $p1p3SubjectsWithBot, 2) : 0; // NEW

            $summaryDataForDB['p1p3_total_mot_score'] = $p1p3StudentTotalMot;
            $summaryDataForDB['p1p3_average_mot_score'] = ($p1p3SubjectsWithMot > 0) ? round($p1p3StudentTotalMot / $p1p3SubjectsWithMot, 2) : 0; // NEW

            $summaryDataForDB['p1p3_total_eot_score'] = $p1p3StudentTotalEotForAvgAndRank;
            $avgEotP1P3 = ($p1p3SubjectsWithEotForAvg > 0) ? round($p1p3StudentTotalEotForAvgAndRank / $p1p3SubjectsWithEotForAvg, 2) : 0;
            $summaryDataForDB['p1p3_average_eot_score'] = $avgEotP1P3;

            // Populate for remarks (average EOT is primary for P1-P3 remarks)
            $studentPerformanceInputForOverallRemarks['p1p3_average_eot_score'] = $avgEotP1P3;
        }

        $summaryDataForDB['auto_classteachers_remark_text'] = generateClassTeacherRemarkUtil($studentPerformanceInputForOverallRemarks, $isP4_P7);
        $summaryDataForDB['auto_headteachers_remark_text'] = generateHeadTeacherRemarkUtil($studentPerformanceInputForOverallRemarks, $isP4_P7);

        $processedStudentsSummaryData[$studentId] = $summaryDataForDB;
    }

    if ($isP1_P3 && !empty($processedStudentsSummaryData)) {
        $totalStudentsInClass = count($processedStudentsSummaryData);
        // Update all students with total number in class first
        foreach ($processedStudentsSummaryData as $studId => &$detailsRef) { // Use reference
            $detailsRef['p1p3_total_students_in_class'] = $totalStudentsInClass;
        }
        unset($detailsRef);


        // Rank by Average EOT (p1p3_position_in_class)
        $studentAveragesEOT = [];
        foreach ($processedStudentsSummaryData as $studId => $details) { $studentAveragesEOT[$studId] = $details['p1p3_average_eot_score'] ?? 0; }
        arsort($studentAveragesEOT, SORT_NUMERIC);
        $rank_display = 0; $previous_score = -1; $students_processed = 0;
        foreach ($studentAveragesEOT as $studId => $score) {
            $students_processed++;
            if ($score != $previous_score) { $rank_display = $students_processed; }
            $processedStudentsSummaryData[$studId]['p1p3_position_in_class'] = $rank_display;
            $previous_score = $score;
        }

        // Rank by Total BOT (p1p3_position_total_bot)
        $studentTotalsBOT = [];
        foreach ($processedStudentsSummaryData as $studId => $details) { $studentTotalsBOT[$studId] = $details['p1p3_total_bot_score'] ?? 0; }
        arsort($studentTotalsBOT, SORT_NUMERIC);
        $rank_display = 0; $previous_score = -1; $students_processed = 0;
        foreach ($studentTotalsBOT as $studId => $score) {
            $students_processed++;
            if ($score != $previous_score) { $rank_display = $students_processed; }
            $processedStudentsSummaryData[$studId]['p1p3_position_total_bot'] = $rank_display;
            $previous_score = $score;
        }

        // Rank by Total MOT (p1p3_position_total_mot)
        $studentTotalsMOT = [];
        foreach ($processedStudentsSummaryData as $studId => $details) { $studentTotalsMOT[$studId] = $details['p1p3_total_mot_score'] ?? 0; }
        arsort($studentTotalsMOT, SORT_NUMERIC);
        $rank_display = 0; $previous_score = -1; $students_processed = 0;
        foreach ($studentTotalsMOT as $studId => $score) {
            $students_processed++;
            if ($score != $previous_score) { $rank_display = $students_processed; }
            $processedStudentsSummaryData[$studId]['p1p3_position_total_mot'] = $rank_display;
            $previous_score = $score;
        }

        // Rank by Total EOT (p1p3_position_total_eot)
        $studentTotalsEOT = [];
        foreach ($processedStudentsSummaryData as $studId => $details) { $studentTotalsEOT[$studId] = $details['p1p3_total_eot_score'] ?? 0;  }
        arsort($studentTotalsEOT, SORT_NUMERIC);
        $rank_display = 0; $previous_score = -1; $students_processed = 0;
        foreach ($studentTotalsEOT as $studId => $score) {
            $students_processed++;
            if ($score != $previous_score) { $rank_display = $students_processed; }
            $processedStudentsSummaryData[$studId]['p1p3_position_total_eot'] = $rank_display;
            $previous_score = $score;
        }
    }

    foreach ($processedStudentsSummaryData as $studentId => $summaryDataToSave) {
        if (!saveStudentReportSummary($pdo, $summaryDataToSave)) {
            throw new Exception("Failed to save report summary for student ID: " . $studentId);
        }
    }

    $_SESSION['enriched_students_data_for_batch_' . $batch_id] = $enrichedStudentDataForReportCard;

    $pdo->commit();
    $_SESSION['success_message'] = "Calculations, summaries, and remarks generated and saved successfully for Batch ID: " . htmlspecialchars($batch_id);
    unset($_SESSION['batch_data_changed_for_calc'][$batch_id]); // Clear the flag

    // Log successful calculation
    $logDescriptionCalc = "Re-calculated summaries and remarks for batch '" . htmlspecialchars($batchSettings['class_name'] . " " . $batchSettings['term_name'] . " " . $batchSettings['year_name']) . "' (ID: " . $batch_id . ").";
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? null,
        $_SESSION['username'] ?? 'System',
        'BATCH_RECALCULATED',
        $logDescriptionCalc,
        'batch',
        $batch_id
    );

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['error_message'] = "Error during calculations for Batch ID " . htmlspecialchars($batch_id) . ": " . $e->getMessage() . " (File: " . basename($e->getFile()) . ", Line: " . $e->getLine() .")";
}

header('Location: view_processed_data.php?batch_id=' . $batch_id);
exit;
?>
