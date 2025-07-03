<?php // calculation_utils.php

if (!function_exists('getGradeFromScoreUtil')) {
    function getGradeFromScoreUtil($score) {
        if ($score === null || $score === '' || $score === 'N/A' || !is_numeric($score)) return 'N/A';
        $score = floatval($score);
        if ($score > 100 || $score < 0) return 'N/A';
        if ($score >= 90) return 'D1';
        if ($score >= 80) return 'D2';
        if ($score >= 70) return 'C3';
        if ($score >= 60) return 'C4';
        if ($score >= 55) return 'C5';
        if ($score >= 50) return 'C6';
        if ($score >= 45) return 'P7';
        if ($score >= 40) return 'P8';
        return 'F9';
    }
}

if (!function_exists('calculateP4P7_BOT_OverallPerformanceUtil')) {
    // Calculates BOT aggregate points and division for P4-P7
    function calculateP4P7_BOT_OverallPerformanceUtil($studentSubjectsDataWithScores, $coreSubjectKeys, $gradingScalePointsMap) {
        $aggregatePoints = 0;
        $coreBOTMissingOrInvalidCount = 0;
        $validCoreBOTScoresExist = false;

        if (empty($gradingScalePointsMap)) { // Fallback, though caller should provide it.
            $gradingScalePointsMap = ['D1'=>1, 'D2'=>2, 'C3'=>3, 'C4'=>4, 'C5'=>5, 'C6'=>6, 'P7'=>7, 'P8'=>8, 'F9'=>9, 'N/A'=>0];
        }

        foreach ($coreSubjectKeys as $coreSubKey) {
            $subjectInfo = $studentSubjectsDataWithScores[$coreSubKey] ?? null;
            // Use bot_score for BOT calculations
            if ($subjectInfo && isset($subjectInfo['bot_score']) && $subjectInfo['bot_score'] !== 'N/A' && is_numeric($subjectInfo['bot_score'])) {
                $validCoreBOTScoresExist = true;
                $botGrade = getGradeFromScoreUtil($subjectInfo['bot_score']);
                $aggregatePoints += getPointsFromGradeUtil($botGrade, $gradingScalePointsMap);
            } else {
                $coreBOTMissingOrInvalidCount++;
            }
        }

        if (!$validCoreBOTScoresExist && $coreBOTMissingOrInvalidCount === count($coreSubjectKeys)) {
            return ['p4p7_aggregate_bot_score' => 0, 'p4p7_division_bot' => 'X'];
        }

        $division = 'Ungraded';
        if ($aggregatePoints >= 35 && $aggregatePoints <= 36) $division = 'U';
        else if ($aggregatePoints >= 30 && $aggregatePoints <= 34) $division = 'IV';
        else if ($aggregatePoints >= 24 && $aggregatePoints <= 29) $division = 'III';
        else if ($aggregatePoints >= 13 && $aggregatePoints <= 23) $division = 'II';
        else if ($aggregatePoints >= 4 && $aggregatePoints <= 12) $division = 'I';

        return ['p4p7_aggregate_bot_score' => $aggregatePoints, 'p4p7_division_bot' => $division];
    }
}

if (!function_exists('calculateP4P7_MOT_OverallPerformanceUtil')) {
    // Calculates MOT aggregate points and division for P4-P7
    function calculateP4P7_MOT_OverallPerformanceUtil($studentSubjectsDataWithScores, $coreSubjectKeys, $gradingScalePointsMap) {
        $aggregatePoints = 0;
        $coreMOTMissingOrInvalidCount = 0;
        $validCoreMOTScoresExist = false;

        if (empty($gradingScalePointsMap)) { // Fallback, though caller should provide it.
            $gradingScalePointsMap = ['D1'=>1, 'D2'=>2, 'C3'=>3, 'C4'=>4, 'C5'=>5, 'C6'=>6, 'P7'=>7, 'P8'=>8, 'F9'=>9, 'N/A'=>0];
        }

        foreach ($coreSubjectKeys as $coreSubKey) {
            $subjectInfo = $studentSubjectsDataWithScores[$coreSubKey] ?? null;
            // Use mot_score for MOT calculations
            if ($subjectInfo && isset($subjectInfo['mot_score']) && $subjectInfo['mot_score'] !== 'N/A' && is_numeric($subjectInfo['mot_score'])) {
                $validCoreMOTScoresExist = true;
                $motGrade = getGradeFromScoreUtil($subjectInfo['mot_score']);
                $aggregatePoints += getPointsFromGradeUtil($motGrade, $gradingScalePointsMap);
            } else {
                $coreMOTMissingOrInvalidCount++;
            }
        }

        if (!$validCoreMOTScoresExist && $coreMOTMissingOrInvalidCount === count($coreSubjectKeys)) {
            return ['p4p7_aggregate_mot_score' => 0, 'p4p7_division_mot' => 'X'];
        }

        $division = 'Ungraded';
        if ($aggregatePoints >= 35 && $aggregatePoints <= 36) $division = 'U';
        else if ($aggregatePoints >= 30 && $aggregatePoints <= 34) $division = 'IV';
        else if ($aggregatePoints >= 24 && $aggregatePoints <= 29) $division = 'III';
        else if ($aggregatePoints >= 13 && $aggregatePoints <= 23) $division = 'II';
        else if ($aggregatePoints >= 4 && $aggregatePoints <= 12) $division = 'I';

        return ['p4p7_aggregate_mot_score' => $aggregatePoints, 'p4p7_division_mot' => $division];
    }
}

if (!function_exists('getPointsFromGradeUtil')) {
    function getPointsFromGradeUtil($grade, $gradingScalePointsMap) {
        if ($grade === 'N/A' && !isset($gradingScalePointsMap['N/A'])) return 0;
        return isset($gradingScalePointsMap[$grade]) ? (int)$gradingScalePointsMap[$grade] : 0;
    }
}

if (!function_exists('getSubjectEOTRemarkUtil')) {
    function getSubjectEOTRemarkUtil($eotScore, $remarksScoreMap) {
        if ($eotScore === null || $eotScore === '' || $eotScore === 'N/A' || !is_numeric($eotScore)) return 'N/A';
        $eotScore = floatval($eotScore);
        if ($eotScore < 0 || $eotScore > 100) return 'N/A';
        krsort($remarksScoreMap);
        foreach ($remarksScoreMap as $minScore => $remark) {
            if ($eotScore >= $minScore) {
                return $remark;
            }
        }
        return 'Fail';
    }
}

if (!function_exists('calculateP4P7OverallPerformanceUtil')) {
    // Note: $gradingScalePointsMap MUST be passed by the calling script (e.g., run_calculations.php)
    function calculateP4P7OverallPerformanceUtil($studentCoreSubjectsDataWithScores, $coreSubjectKeys, $gradingScalePointsMap) {
        $aggregatePoints = 0;
        $coreEOTMissingOrInvalidCount = 0;
        $validCoreEOTScoresExist = false;

        if (empty($gradingScalePointsMap)) { // Fallback, though caller should provide it.
            $gradingScalePointsMap = ['D1'=>1, 'D2'=>2, 'C3'=>3, 'C4'=>4, 'C5'=>5, 'C6'=>6, 'P7'=>7, 'P8'=>8, 'F9'=>9, 'N/A'=>0];
        }

        foreach ($coreSubjectKeys as $coreSubKey) {
            $subjectInfo = $studentCoreSubjectsDataWithScores[$coreSubKey] ?? null;
            if ($subjectInfo && isset($subjectInfo['eot_score']) && $subjectInfo['eot_score'] !== 'N/A' && is_numeric($subjectInfo['eot_score'])) {
                $validCoreEOTScoresExist = true;
                $eotGrade = getGradeFromScoreUtil($subjectInfo['eot_score']);
                $aggregatePoints += getPointsFromGradeUtil($eotGrade, $gradingScalePointsMap);
            } else {
                $coreEOTMissingOrInvalidCount++;
            }
        }

        if (!$validCoreEOTScoresExist && $coreEOTMissingOrInvalidCount === count($coreSubjectKeys)) {
            return ['p4p7_aggregate_points' => 0, 'p4p7_division' => 'X']; // MODIFIED
        }

        $division = 'Ungraded';
        if ($aggregatePoints >= 35 && $aggregatePoints <= 36) $division = 'U'; // MODIFIED
        else if ($aggregatePoints >= 30 && $aggregatePoints <= 34) $division = 'IV'; // MODIFIED
        else if ($aggregatePoints >= 24 && $aggregatePoints <= 29) $division = 'III'; // MODIFIED
        else if ($aggregatePoints >= 13 && $aggregatePoints <= 23) $division = 'II'; // MODIFIED
        else if ($aggregatePoints >= 4 && $aggregatePoints <= 12) $division = 'I'; // MODIFIED

        return ['p4p7_aggregate_points' => $aggregatePoints, 'p4p7_division' => $division];
    }
}


if (!function_exists('generateClassTeacherRemarkUtil')) {
    function generateClassTeacherRemarkUtil($performanceData, $isP4_P7) {
        if ($isP4_P7) {
            $division = $performanceData['p4p7_division'] ?? 'Ungraded';
            $aggregate = $performanceData['p4p7_aggregate_points'] ?? 0;
            switch ($division) {
                case 'I': return ($aggregate <= 6) ? "Wonderful work! You are a star. Keep up this great effort." : "Excellent job! You worked hard and did very well. Keep it up!";
                case 'II': return ($aggregate >= 13 && $aggregate <= 15) ? "Very good effort! You're close to the top. Keep trying your best!" : "Good job! Keep working hard to do even better next time.";
                case 'III': return "Fair effort. Try to focus more in class to improve.";
                case 'IV': return "You need to try harder. Ask for help if you need it.";
                case 'U': return "Please work much harder and ask your teachers for help.";
                case 'X': return "You missed some exams. It's important to do them to see how you are doing.";
                default: return "Try to focus on all subjects to get better results.";
            }
        } else { // P1-P3
            $average = $performanceData['p1p3_average_eot_score'] ?? 0;
            if ($average >= 85) return "Excellent work this term! You did wonderfully.";
            if ($average >= 70) return "Very good job! Keep up the great work.";
            if ($average >= 60) return "Good effort! Keep trying your best.";
            if ($average >= 50) return "Nice try. Work a bit harder to do even better.";
            return "Please try harder next term. Ask for help if you need it.";
        }
    }
}

if (!function_exists('generateHeadTeacherRemarkUtil')) {
    function generateHeadTeacherRemarkUtil($performanceData, $isP4_P7) {
        if ($isP4_P7) {
            $division = $performanceData['p4p7_division'] ?? 'Ungraded';
            $aggregate = $performanceData['p4p7_aggregate_points'] ?? 0;
            switch ($division) {
                case 'I': return ($aggregate <= 6) ? "Amazing work! The school is proud of you. Keep being a good example!" : "Well done on getting First Grade! Your hard work is great to see.";
                case 'II': return ($aggregate >= 13 && $aggregate <= 15) ? "Great result! You can reach First Grade. Keep aiming high!" : "Good job on this Second Grade. Listen to your teachers to do even better.";
                case 'III': return "A fair result. The school wants you to work harder for a better grade next time.";
                case 'IV': return "You need to work harder. The school wants you to do well.";
                case 'U': return "This needs to be much better. Ask for help from teachers and parents.";
                case 'X': return "Exams are important. The school expects you to try your best in all school work.";
                default: return "Please work hard in all subjects. The school is here to help you.";
            }
        } else { // P1-P3
            $average = $performanceData['p1p3_average_eot_score'] ?? 0;
            if ($average >= 85) return "Wonderful job! The school is very proud of you. Keep it up!";
            if ($average >= 70) return "Very good work! Keep trying hard and aim higher.";
            if ($average >= 50) return "Good effort. Keep working hard, you can do even better."; // Covers 50-69
            return "Please try to improve next term. Working hard in class helps a lot."; // Covers below 50
        }
    }
}

?>
