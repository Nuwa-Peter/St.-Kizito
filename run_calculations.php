<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('STKIZITO_SESSION');
    session_start();
}
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

// System is now P5-P7 only.
// Validate selected class is P5, P6, or P7 (though batchSettings should come from a valid batch)
if (!in_array($batchSettings['class_name'], ['P5', 'P6', 'P7'])) {
    $_SESSION['error_message'] = 'Calculations can only be run for P5, P6, or P7. Invalid class: ' . htmlspecialchars($batchSettings['class_name']);
    header('Location: index.php'); // Or data_entry.php or view_processed_data.php
    exit;
}

// Define subject keys for P5-P7
// Core subjects for aggregate calculation (UNEB standard 4)
$coreSubjectKeys = ['ENG', 'MTC', 'SCI', 'SST'];
// All subjects expected to be processed for scores, grades, remarks, and averages
$expectedSubjectKeysForClass = ['ENG', 'MTC', 'SCI', 'SST', 'RE'];

$gradingScalePointsMap = ['D1'=>1, 'D2'=>2, 'C3'=>3, 'C4'=>4, 'C5'=>5, 'C6'=>6, 'P7'=>7, 'P8'=>8, 'F9'=>9, 'N/A'=>0];
// $remarksScoreMap is no longer needed here as getSubjectEOTRemarkUtil uses an internal map.

$studentsRawDataFromDB = getStudentsWithScoresForBatch($pdo, $batch_id); // DAL function already updated
$processedStudentsSummaryData = [];
$enrichedStudentDataForReportCard = [];

$pdo->beginTransaction();
try {
    foreach ($studentsRawDataFromDB as $studentId => $studentDataFromDB) {
        $currentStudentSubjectsEnriched = [];
        $studentPerformanceInputForOverallRemarks = [];

        // Initialize summary data structure for the new 'student_term_summaries' table
        $summaryDataForDB = [
            'student_id' => $studentId,
            'report_batch_id' => $batch_id,
            'aggregate_points' => null,
            'division' => null,
            'average_score' => null,
            'position_in_class' => null,
            'total_students_in_class' => null, // This will be calculated later for all students in the batch
            'class_teacher_remark' => null,
            'head_teacher_remark' => null
            // BOT/MOT aggregates/divisions are not in the new student_term_summaries schema.
            // If needed for display, they would be calculated and stored in session's enriched data,
            // but not persisted in student_term_summaries unless schema is extended.
        ];

        // P1-P3 specific accumulators are removed.
        // We will need accumulators for overall average score calculation for P5-P7.
        $studentTotalEotForAvg = 0;
        $subjectsWithEotForAvg = 0;

        foreach ($expectedSubjectKeysForClass as $subjectKey) { // $expectedSubjectKeysForClass is now ['ENG', 'MTC', 'SCI', 'SST', 'RE']
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
                'eot_remark' => getSubjectEOTRemarkUtil($eotScore), // Call updated: no remarks map param
                'eot_points' => getPointsFromGradeUtil(getGradeFromScoreUtil($eotScore), $gradingScalePointsMap) // P5-P7 uses points like P4-P7
            ];

            // Save the calculated eot_remark to the scores table
            $remarkToSave = $currentStudentSubjectsEnriched[$subjectKey]['eot_remark'];
            $subjectIdForUpdate = $subjectScores['subject_id'] ?? null; // From getStudentsWithScoresForBatch

            if ($subjectIdForUpdate !== null && $studentId !== null && $batch_id !== null) {
                // This direct update to scores.eot_remark can remain.
                // Alternatively, upsertScore could be called again, but this is more targeted.
                $stmtUpdateRemark = $pdo->prepare(
                    "UPDATE scores SET eot_remark = :eot_remark
                     WHERE report_batch_id = :batch_id AND student_id = :student_id AND subject_id = :subject_id"
                );
                $stmtUpdateRemark->execute([
                    ':eot_remark' => $remarkToSave, ':batch_id' => $batch_id,
                    ':student_id' => $studentId, ':subject_id' => $subjectIdForUpdate
                ]);
            } else {
                if ($subjectIdForUpdate === null) {
                    error_log("RunCalculations: Could not save eot_remark for student $studentId, subject code $subjectKey in batch $batch_id because subject_id was missing.");
                }
            }

            // Accumulate EOT scores for overall average calculation (for all expected subjects)
            if (is_numeric($eotScore) && $eotScore !== 'N/A') {
                $studentTotalEotForAvg += (float)$eotScore;
                $subjectsWithEotForAvg++;
            }
        } // End subject loop

        $enrichedStudentDataForReportCard[$studentId] = [
            'student_name' => $studentDataFromDB['student_name'],
            'lin_no' => $studentDataFromDB['lin_no'],
            'subjects' => $currentStudentSubjectsEnriched
        ];

        // --- P5-P7 Overall Performance Calculation (using the 4 core subjects) ---
        // $coreSubjectKeys is ['ENG', 'MTC', 'SCI', 'SST']

        // Calculate EOT aggregates and division
        $p5p7_eot_results = calculateP4P7OverallPerformanceUtil($currentStudentSubjectsEnriched, $coreSubjectKeys, $gradingScalePointsMap);
        $summaryDataForDB['aggregate_points'] = $p5p7_eot_results['p4p7_aggregate_points'];
        $summaryDataForDB['division'] = $p5p7_eot_results['p4p7_division'];

        // Calculate Overall Average Score (across all $expectedSubjectKeysForClass - which is ENG, MTC, SCI, SST, RE)
        $summaryDataForDB['average_score'] = ($subjectsWithEotForAvg > 0) ? round($studentTotalEotForAvg / $subjectsWithEotForAvg, 2) : 0;

        // For remarks, EOT performance (aggregate and division) is primary
        $studentPerformanceInputForOverallRemarks = [
            'aggregate_points' => $summaryDataForDB['aggregate_points'],
            'division' => $summaryDataForDB['division']
        ];

        // The $isP4_P7 parameter in remark utils can be effectively considered true or removed from util if only one path.
        $summaryDataForDB['class_teacher_remark'] = generateClassTeacherRemarkUtil($studentPerformanceInputForOverallRemarks, true);
        $summaryDataForDB['head_teacher_remark'] = generateHeadTeacherRemarkUtil($studentPerformanceInputForOverallRemarks, true);

        // BOT/MOT aggregates are not stored in the new student_term_summaries schema.
        // If needed for display on report card from session, they would be calculated here and added to $enrichedStudentDataForReportCard.
        // For now, omitting their calculation to align with student_term_summaries persistence.

        $processedStudentsSummaryData[$studentId] = $summaryDataForDB;
    } // End student loop

    // --- P5-P7 Ranking Logic (replaces P1-P3 ranking) ---
    // Rank by Aggregate Points, then by Average Score as tie-breaker
    if (!empty($processedStudentsSummaryData)) {
        $totalStudentsInClass = count($processedStudentsSummaryData);
        foreach ($processedStudentsSummaryData as $studId => &$detailsRef) {
            $detailsRef['total_students_in_class'] = $totalStudentsInClass;
        }
        unset($detailsRef);

        // Sort students: primary key aggregate_points (lower is better), secondary key average_score (higher is better)
        uasort($processedStudentsSummaryData, function($a, $b) {
            $aggA = $a['aggregate_points'] ?? PHP_INT_MAX;
            $aggB = $b['aggregate_points'] ?? PHP_INT_MAX;

            if ($aggA != $aggB) {
                return $aggA <=> $aggB; // Lower aggregate is better
            }

            // Tie-breaking with average_score (higher is better)
            $avgA = $a['average_score'] ?? 0;
            $avgB = $b['average_score'] ?? 0;
            return $avgB <=> $avgA; // Higher average is better
        });

        $rank = 0;
        $previousAggregate = -1;
        $previousAverage = -1; // Tie-breaker
        $studentsProcessedForRank = 0;
        foreach (array_keys($processedStudentsSummaryData) as $studId) {
            $studentsProcessedForRank++;
            $currentAggregate = $processedStudentsSummaryData[$studId]['aggregate_points'] ?? PHP_INT_MAX;
            $currentAverage = $processedStudentsSummaryData[$studId]['average_score'] ?? 0;

            if ($currentAggregate != $previousAggregate || $currentAverage != $previousAverage) {
                $rank = $studentsProcessedForRank;
            }
            $processedStudentsSummaryData[$studId]['position_in_class'] = $rank;
            $previousAggregate = $currentAggregate;
            $previousAverage = $currentAverage;
        }
    }
    // --- End P5-P7 Ranking Logic ---


    foreach ($processedStudentsSummaryData as $studentId => $summaryDataToSave) {
        if (!saveStudentReportSummary($pdo, $summaryDataToSave)) { // saveStudentReportSummary DAL function already updated
            throw new Exception("Failed to save report summary for student ID: " . $studentId);
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
        $logDescriptionCalc
        // ip_address is null by default in logActivity signature
        // entity_type and entity_id are removed from logActivity signature
    );

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['error_message'] = "Error during calculations for Batch ID " . htmlspecialchars($batch_id) . ": " . $e->getMessage() . " (File: " . basename($e->getFile()) . ", Line: " . $e->getLine() .")";
}

header('Location: view_processed_data.php?batch_id=' . $batch_id);
exit;
?>
