<?php
session_start();
require_once 'db_connection.php'; // Provides $pdo
require_once 'dal.php';         // Provides DAL functions
require_once 'vendor/autoload.php'; // mPDF Autoloader

// --- Input Validation ---
if (!isset($_GET['batch_id']) || !filter_var($_GET['batch_id'], FILTER_VALIDATE_INT) || $_GET['batch_id'] <= 0) {
    // Instead of redirect, which is hard to manage if PDF output started, display error or die.
    die('Invalid or missing Batch ID for PDF generation.');
}
$batch_id = (int)$_GET['batch_id'];

// --- Fetch Batch & Common Data (similar to summary_sheet.php) ---
$batchSettings = getReportBatchSettings($pdo, $batch_id);
if (!$batchSettings) {
    die('Could not find settings for Batch ID: ' . htmlspecialchars($batch_id));
}

$studentsSummaries = getAllStudentSummariesForBatchWithName($pdo, $batch_id);
$enrichedStudentDataForBatch = $_SESSION['enriched_students_data_for_batch_' . $batch_id] ?? [];

$isP1_P3 = in_array($batchSettings['class_name'], ['P1', 'P2', 'P3']);
$isP4_P7 = in_array($batchSettings['class_name'], ['P4', 'P5', 'P6', 'P7']);

$expectedSubjectKeysForClass = [];
$p1p3SubjectKeys = []; // Specifically for P1-P3 iteration order if needed
$coreSubjectKeysP4_P7 = []; // For P4-P7 grade distribution

if ($isP4_P7) {
    $coreSubjectKeysP4_P7 = ['english', 'mtc', 'science', 'sst']; // For grade dist. charts/tables
    $expectedSubjectKeysForClass = ['english', 'mtc', 'science', 'sst', 'kiswahili']; // For student list table
} elseif ($isP1_P3) {
    $p1p3SubjectKeys = ['english', 'mtc', 're', 'lit1', 'lit2', 'local_lang'];
    $expectedSubjectKeysForClass = $p1p3SubjectKeys;
}

$subjectDisplayNames = [ /* Copy from summary_sheet.php or centralize if possible */
    'english' => 'English', 'mtc' => 'Mathematics (MTC)', 'science' => 'Science',
    'sst' => 'Social Studies (SST)', 'kiswahili' => 'Kiswahili',
    're' => 'Religious Education (R.E)', 'lit1' => 'Literacy I',
    'lit2' => 'Literacy II', 'local_lang' => 'Local Language'
];

$divisionChartLabels = [ /* For P4-P7 Division Summary Table - Copy from summary_sheet.php */
    'I' => 'Division I', 'II' => 'Division II', 'III' => 'Division III', 'IV' => 'Division IV',
    'U' => 'Grade U', 'X' => 'Division X', 'Ungraded' => 'Ungraded'
];

// --- P4-P7 Data Preparation (copied & adapted from summary_sheet.php) ---
$divisionSummaryP4P7 = ['I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, 'U' => 0, 'X' => 0, 'Ungraded' => 0];
$gradeSummaryP4P7 = [];
$p4p7StudentListForDisplay = [];

if ($isP4_P7 && $batch_id) {
    $p4p7StudentListForDisplay = $studentsSummaries;
    if (!empty($p4p7StudentListForDisplay)) {
        uasort($p4p7StudentListForDisplay, function($a, $b) { /* ... sort logic from summary_sheet ... */
            $aggA = $a['p4p7_aggregate_points']; $aggB = $b['p4p7_aggregate_points'];
            $isNumA = is_numeric($aggA); $isNumB = is_numeric($aggB);
            if ($isNumA && $isNumB) return (float)$aggA <=> (float)$aggB;
            elseif ($isNumA) return -1; elseif ($isNumB) return 1; else return 0;
        });
    }
    foreach ($studentsSummaries as $student) {
        $division = $student['p4p7_division'] ?? 'Ungraded';
        if (array_key_exists($division, $divisionSummaryP4P7)) { $divisionSummaryP4P7[$division]++; }
        else { $divisionSummaryP4P7['Ungraded']++; }
    }
    if (!empty($enrichedStudentDataForBatch) && !empty($coreSubjectKeysP4_P7)) {
        foreach ($coreSubjectKeysP4_P7 as $coreSubKey) {
            $gradeSummaryP4P7[$coreSubKey] = ['D1'=>0, 'D2'=>0, 'C3'=>0, 'C4'=>0, 'C5'=>0, 'C6'=>0, 'P7'=>0, 'P8'=>0, 'F9'=>0, 'N/A'=>0];
            foreach ($enrichedStudentDataForBatch as $studentEnriched) {
                 $eotGrade = $studentEnriched['subjects'][$coreSubKey]['eot_grade'] ?? 'N/A';
                 if(isset($gradeSummaryP4P7[$coreSubKey][$eotGrade])) { $gradeSummaryP4P7[$coreSubKey][$eotGrade]++; }
                 else { $gradeSummaryP4P7[$coreSubKey]['N/A']++; }
            }
        }
    }
}

// --- P1-P3 Data Preparation (copied & adapted from summary_sheet.php) ---
$p1p3StudentListForDisplay = [];
$classAverageP1P3 = 0;
$p1p3SubjectScoreDistribution = [];
$scoreBands = ['0-39'=>0, '40-49'=>0, '50-59'=>0, '60-69'=>0, '70-79'=>0, '80-89'=>0, '90-100'=>0, 'N/A'=>0];

if ($isP1_P3 && $batch_id) {
    // $p1p3SubjectKeys is already defined as $expectedSubjectKeysForClass in this case
    if (!empty($enrichedStudentDataForBatch) && !empty($expectedSubjectKeysForClass)) {
        foreach ($expectedSubjectKeysForClass as $subjectKey) {
            $p1p3SubjectScoreDistribution[$subjectKey] = $scoreBands;
            foreach ($enrichedStudentDataForBatch as $studentEnrichedData) {
                $eotScore = $studentEnrichedData['subjects'][$subjectKey]['eot_score'] ?? 'N/A';
                $band = 'N/A';
                if (is_numeric($eotScore)) {
                    $eotScoreNum = (float)$eotScore;
                    if ($eotScoreNum >= 90) $band = '90-100'; else if ($eotScoreNum >= 80) $band = '80-89';
                    else if ($eotScoreNum >= 70) $band = '70-79'; else if ($eotScoreNum >= 60) $band = '60-69';
                    else if ($eotScoreNum >= 50) $band = '50-59'; else if ($eotScoreNum >= 40) $band = '40-49';
                    else $band = '0-39';
                }
                if (isset($p1p3SubjectScoreDistribution[$subjectKey][$band])) { $p1p3SubjectScoreDistribution[$subjectKey][$band]++; }
                else { $p1p3SubjectScoreDistribution[$subjectKey]['N/A']++; }
            }
        }
    }
    $p1p3StudentListForDisplay = $studentsSummaries;
    uasort($p1p3StudentListForDisplay, function($a, $b) { /* ... sort logic ... */
        return ($a['p1p3_position_in_class'] ?? PHP_INT_MAX) <=> ($b['p1p3_position_in_class'] ?? PHP_INT_MAX);
    });
    $totalClassAverageEotP1P3 = 0; $validStudentsForClassAverageP1P3 = 0;
    foreach ($studentsSummaries as $student) {
        if (isset($student['p1p3_average_eot_score']) && is_numeric($student['p1p3_average_eot_score'])) {
            $totalClassAverageEotP1P3 += $student['p1p3_average_eot_score'];
            $validStudentsForClassAverageP1P3++;
        }
    }
    $classAverageP1P3 = ($validStudentsForClassAverageP1P3 > 0) ? round($totalClassAverageEotP1P3 / $validStudentsForClassAverageP1P3, 2) : 0;
}

// --- mPDF Initialization ---
$mpdf = new \Mpdf\Mpdf(['orientation' => 'L', 'format' => 'A4-L']); // A4 Landscape
$mpdf->SetDisplayMode('fullpage');

// --- Build HTML Content for PDF ---
$html = '';

// Basic CSS for PDF tables (can be expanded)
$html .= '<style>
    body { font-family: sans-serif; font-size: 9pt; }
    .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    .summary-table th, .summary-table td { border: 1px solid #000; padding: 3px 5px; text-align: center; font-size: 10pt; } /* User request for 10pt */
    .summary-table th { background-color: #f2f2f2; font-weight: bold; }
    .summary-table td.student-name { text-align: left; }
    h2, h3, h4, h5 { text-align: center; color: #0056b3; margin-top:10px; margin-bottom:5px;}
    h3 {font-size: 14pt;} h4 {font-size: 12pt;} h5 {font-size: 11pt;}
</style>';

$html .= '<div class="main-content">'; // Similar to summary_sheet main content div
$html .= '<div style="text-align:center; margin-bottom:20px;">';
$html .= '<h2>Class Performance Summary</h2>';
if ($batchSettings) {
    $html .= '<h4>' . htmlspecialchars($batchSettings['class_name']) . ' - Term ' . htmlspecialchars($batchSettings['term_name']) . ', ' . htmlspecialchars($batchSettings['year_name']) . '</h4>';
}
$html .= '</div>';

if ($isP4_P7) {
    // P4-P7 Division Summary Table
    $html .= '<h3>Division Summary</h3>';
    $html .= '<table class="summary-table" style="width:50%; margin: 0 auto 15px auto;"><thead><tr><th colspan="2">Division Performance</th></tr></thead><tbody>';
    foreach ($divisionSummaryP4P7 as $divKey => $count) {
        $displayLabel = $divisionChartLabels[$divKey] ?? $divKey;
        $html .= '<tr><td>' . htmlspecialchars($displayLabel) . '</td><td>' . $count . '</td></tr>';
    }
    $html .= '</tbody></table>';

    // P4-P7 Student Performance List Table
    $html .= '<h3>Student Performance List</h3>';
    $html .= '<div class="table-responsive"><table class="summary-table"><thead><tr><th>#</th><th>Student Name</th>';
    $p4p7_subj_abbr_pdf = ['ENG', 'MTC', 'SCI', 'SST', 'KISW']; // Match subtask header generation
    foreach ($p4p7_subj_abbr_pdf as $abbr) { $html .= '<th colspan="3">' . $abbr . '</th>'; }
    $html .= '<th>Agg.</th><th>Div.</th></tr><tr><th></th><th></th>';
    foreach ($p4p7_subj_abbr_pdf as $abbr) { $html .= '<th>BOT</th><th>MOT</th><th>EOT</th>'; }
    $html .= '<th></th><th></th></tr></thead><tbody>';
    if (!empty($p4p7StudentListForDisplay)) {
        $rowNum = 0;
        foreach ($p4p7StudentListForDisplay as $student) {
            $rowNum++;
            $html .= '<tr><td>' . $rowNum . '</td><td class="student-name">' . htmlspecialchars($student['student_name']) . '</td>';
            foreach ($expectedSubjectKeysForClass as $subjKey) {
                $s_data = $enrichedStudentDataForBatch[$student['student_id']]['subjects'][$subjKey] ?? [];
                $bot = $s_data['bot_score'] ?? 'N/A'; $mot = $s_data['mot_score'] ?? 'N/A'; $eot = $s_data['eot_score'] ?? 'N/A';
                $html .= '<td>' . htmlspecialchars(is_numeric($bot) ? round((float)$bot) : $bot) . '</td>';
                $html .= '<td>' . htmlspecialchars(is_numeric($mot) ? round((float)$mot) : $mot) . '</td>';
                $html .= '<td>' . htmlspecialchars(is_numeric($eot) ? round((float)$eot) : $eot) . '</td>';
            }
            $html .= '<td>' . htmlspecialchars($student['p4p7_aggregate_points'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($student['p4p7_division'] ?? 'N/A') . '</td></tr>';
        }
    } else { $html .= '<tr><td colspan="' . (2 + count($expectedSubjectKeysForClass) * 3 + 2) . '">No student summary data.</td></tr>'; }
    $html .= '</tbody></table></div>';

    // P4-P7 Subject Grade Distribution Tables
    $html .= '<h3>Subject Grade Distribution</h3>';
    if (!empty($gradeSummaryP4P7)) {
        foreach ($coreSubjectKeysP4_P7 as $coreSubKey) {
            $subjectDisplayName = htmlspecialchars($subjectDisplayNames[$coreSubKey] ?? ucfirst($coreSubKey));
            $html .= '<h5>' . $subjectDisplayName . '</h5>';
            $html .= '<table class="summary-table" style="width:80%; margin: 0 auto 15px auto;"><thead><tr>';
            if(isset($gradeSummaryP4P7[$coreSubKey])) { foreach (array_keys($gradeSummaryP4P7[$coreSubKey]) as $grade) { $html .= '<th>' . htmlspecialchars($grade) . '</th>'; } }
            $html .= '</tr></thead><tbody><tr>';
            if(isset($gradeSummaryP4P7[$coreSubKey])) { foreach ($gradeSummaryP4P7[$coreSubKey] as $count) { $html .= '<td>' . $count . '</td>'; } }
            else { $html .= "<td colspan='9'>No grade data.</td>";}
            $html .= '</tr></tbody></table>';
        }
    } else { $html .= '<p>Per-subject grade distribution data not available.</p>'; }

} elseif ($isP1_P3) {
    // P1-P3 Overall Class Average
    $html .= '<p style="text-align:center;"><strong>Overall Class Average End of Term Score:</strong> ' . htmlspecialchars($classAverageP1P3) . '%</p>';

    // P1-P3 Student List Table
    $html .= '<h3>Student Performance List</h3>'; // Added heading for consistency
    $html .= '<div class="table-responsive"><table class="summary-table"><thead><tr><th>#</th><th>Student Name</th>';
    $p1p3_subj_abbr_pdf = ['ENG', 'MTC', 'RE', 'LIT1', 'LIT2', 'LLANG'];
    foreach ($p1p3_subj_abbr_pdf as $abbr) { $html .= '<th colspan="3">' . $abbr . '</th>'; }
    $html .= '<th>Total EOT</th><th>Avg EOT (%)</th><th>Pos</th></tr><tr><th></th><th></th>';
    foreach ($p1p3_subj_abbr_pdf as $abbr) { $html .= '<th>BOT</th><th>MOT</th><th>EOT</th>'; }
    $html .= '<th></th><th></th><th></th></tr></thead><tbody>';
    if (!empty($p1p3StudentListForDisplay)) {
        $rowNum = 0;
        foreach ($p1p3StudentListForDisplay as $student) {
            $rowNum++;
            $html .= '<tr><td>' . $rowNum . '</td><td class="student-name">' . htmlspecialchars($student['student_name']) . '</td>';
            foreach ($expectedSubjectKeysForClass as $subjKey) { // $expectedSubjectKeysForClass is $p1p3SubjectKeys here
                $s_data = $enrichedStudentDataForBatch[$student['student_id']]['subjects'][$subjKey] ?? [];
                $bot = $s_data['bot_score'] ?? 'N/A'; $mot = $s_data['mot_score'] ?? 'N/A'; $eot = $s_data['eot_score'] ?? 'N/A';
                $html .= '<td>' . htmlspecialchars(is_numeric($bot) ? round((float)$bot) : $bot) . '</td>';
                $html .= '<td>' . htmlspecialchars(is_numeric($mot) ? round((float)$mot) : $mot) . '</td>';
                $html .= '<td>' . htmlspecialchars(is_numeric($eot) ? round((float)$eot) : $eot) . '</td>';
            }
            $html .= '<td>' . htmlspecialchars($student['p1p3_total_eot_score'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($student['p1p3_average_eot_score'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($student['p1p3_position_in_class'] ?? 'N/A') . '</td></tr>';
        }
    } else { $html .= '<tr><td colspan="' . (2 + count($expectedSubjectKeysForClass) * 3 + 3) . '">No student summary data.</td></tr>'; }
    $html .= '</tbody></table></div>';

    // P1-P3 Per-Subject Score Distribution Tables
    if (!empty($p1p3SubjectScoreDistribution)) {
        $html .= '<h3 style="margin-top:15px;">Per-Subject End of Term Score Distribution</h3>';
        foreach ($expectedSubjectKeysForClass as $subjectKey) { // $expectedSubjectKeysForClass is $p1p3SubjectKeys here
            $subjectDisplayName = htmlspecialchars($subjectDisplayNames[$subjectKey] ?? ucfirst($subjectKey));
            $html .= '<h5>' . $subjectDisplayName . '</h5>';
            $html .= '<table class="summary-table" style="width:80%; margin: 0 auto 15px auto;"><thead><tr>';
            foreach (array_keys($scoreBands) as $bandLabel) { $html .= '<th>' . $bandLabel . '</th>'; }
            $html .= '</tr></thead><tbody><tr>';
            foreach ($scoreBands as $bandLabel => $defaultCount) {
                $html .= '<td>' . ($p1p3SubjectScoreDistribution[$subjectKey][$bandLabel] ?? 0) . '</td>';
            }
            $html .= '</tr></tbody></table>';
        }
    }
}
$html .= '</div>'; // End main-content

try {
    $mpdf->WriteHTML($html);
    $pdfFileName = 'Summary_Sheet_Batch_' . $batch_id . '_' . date('YmdHis') . '.pdf';
    $mpdf->Output($pdfFileName, \Mpdf\Output\Destination::DOWNLOAD); // 'D' for download
} catch (\Mpdf\MpdfException $e) {
    die('mPDF Error: ' . $e->getMessage());
}
exit;
?>
