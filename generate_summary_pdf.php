<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('STKIZITO_SESSION');
    session_start();
}
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

// System is P5-P7 only.
if (!in_array($batchSettings['class_name'], ['P5', 'P6', 'P7'])) {
    die('PDF Summary Error: Invalid class (' . htmlspecialchars($batchSettings['class_name']) . ') for P5-P7 system.');
}

$coreSubjectKeys = ['ENG', 'MTC', 'SCI', 'SST']; // Core for P5-P7 aggregates/charts
$expectedSubjectKeysForClass = ['ENG', 'MTC', 'SCI', 'SST', 'RE']; // All subjects for P5-P7

$subjectDisplayNames = [
    'ENG' => 'ENGLISH',
    'MTC' => 'MATHEMATICS',
    'SCI' => 'SCIENCE',
    'SST' => 'SOCIAL STUDIES',
    'RE'  => 'RELIGIOUS EDUCATION'
];

$divisionChartLabels = [
    'I' => 'Division I', 'II' => 'Division II', 'III' => 'Division III', 'IV' => 'Division IV',
    'U' => 'Grade U', 'X' => 'Division X', 'Ungraded' => 'Ungraded'
];

// --- P5-P7 Data Preparation (adapting P4-P7 logic) ---
$divisionSummary = ['I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0, 'U' => 0, 'X' => 0, 'Ungraded' => 0];
$gradeSummary = [];
$studentListForDisplay = []; // Was $p4p7StudentListForDisplay

if ($batch_id) { // data preparation only if batch_id is valid
    $studentListForDisplay = $studentsSummaries;
    if (!empty($studentListForDisplay)) {
        uasort($studentListForDisplay, function($a, $b) {
            $aggA = $a['aggregate_points']; $aggB = $b['aggregate_points']; // Use generic field names
            $isNumA = is_numeric($aggA); $isNumB = is_numeric($aggB);
            if ($isNumA && $isNumB) return (float)$aggA <=> (float)$aggB;
            elseif ($isNumA) return -1; elseif ($isNumB) return 1; else return 0;
        });
    }
    foreach ($studentsSummaries as $student) {
        $division = $student['division'] ?? 'Ungraded'; // Use generic field name
        if (array_key_exists($division, $divisionSummary)) { $divisionSummary[$division]++; }
        else { $divisionSummary['Ungraded']++; }
    }
    if (!empty($enrichedStudentDataForBatch) && !empty($coreSubjectKeys)) {
        foreach ($coreSubjectKeys as $coreSubKey) { // Use $coreSubjectKeys (ENG, MTC, etc.)
            $gradeSummary[$coreSubKey] = ['D1'=>0, 'D2'=>0, 'C3'=>0, 'C4'=>0, 'C5'=>0, 'C6'=>0, 'P7'=>0, 'P8'=>0, 'F9'=>0, 'N/A'=>0];
            foreach ($enrichedStudentDataForBatch as $studentEnriched) {
                 $eotGrade = $studentEnriched['subjects'][$coreSubKey]['eot_grade'] ?? 'N/A'; // Access with 'ENG'
                 if(isset($gradeSummary[$coreSubKey][$eotGrade])) { $gradeSummary[$coreSubKey][$eotGrade]++; }
                 else { $gradeSummary[$coreSubKey]['N/A']++; }
            }
        }
    }
}

// P1-P3 Data Preparation logic is removed.

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

// This section is now for P5-P7, adapting the old P4-P7 structure
if ($batchSettings) { // Check if batchSettings is available
    // Division Summary Table
    $html .= '<h3>Division Summary</h3>';
    $html .= '<table class="summary-table" style="width:50%; margin: 0 auto 15px auto;"><thead><tr><th colspan="2">Division Performance</th></tr></thead><tbody>';
    foreach ($divisionSummary as $divKey => $count) { // Use $divisionSummary
        $displayLabel = $divisionChartLabels[$divKey] ?? $divKey;
        $html .= '<tr><td>' . htmlspecialchars($displayLabel) . '</td><td>' . $count . '</td></tr>';
    }
    $html .= '</tbody></table>';

    // Student Performance List Table
    $html .= '<h3>Student Performance List</h3>';
    $html .= '<div class="table-responsive"><table class="summary-table"><thead><tr><th>#</th><th>Student Name</th>';
    // $expectedSubjectKeysForClass is now ['ENG', 'MTC', 'SCI', 'SST', 'RE']
    // Abbreviation map for PDF headers
    $subj_abbr_pdf = ['ENG'=>'ENG', 'MTC'=>'MTC', 'SCI'=>'SCI', 'SST'=>'SST', 'RE'=>'RE'];
    foreach ($expectedSubjectKeysForClass as $subjKey) {
        $abbr = $subj_abbr_pdf[$subjKey] ?? strtoupper(htmlspecialchars($subjKey));
        $html .= '<th colspan="3">' . $abbr . '</th>';
    }
    $html .= '<th>Agg.</th><th>Div.</th></tr><tr><th></th><th></th>';
    foreach ($expectedSubjectKeysForClass as $subjKey) { $html .= '<th>BOT</th><th>MOT</th><th>EOT</th>'; }
    $html .= '<th></th><th></th></tr></thead><tbody>';
    if (!empty($studentListForDisplay)) { // Use $studentListForDisplay
        $rowNum = 0;
        foreach ($studentListForDisplay as $student) {
            $rowNum++;
            $html .= '<tr><td>' . $rowNum . '</td><td class="student-name">' . htmlspecialchars($student['student_name']) . '</td>';
            foreach ($expectedSubjectKeysForClass as $subjKey) { // Use new uppercase subject keys
                $s_data = $enrichedStudentDataForBatch[$student['student_id']]['subjects'][$subjKey] ?? [];
                $bot = $s_data['bot_score'] ?? 'N/A'; $mot = $s_data['mot_score'] ?? 'N/A'; $eot = $s_data['eot_score'] ?? 'N/A';
                $html .= '<td>' . htmlspecialchars(is_numeric($bot) ? round((float)$bot) : $bot) . '</td>';
                $html .= '<td>' . htmlspecialchars(is_numeric($mot) ? round((float)$mot) : $mot) . '</td>';
                $html .= '<td>' . htmlspecialchars(is_numeric($eot) ? round((float)$eot) : $eot) . '</td>';
            }
            $html .= '<td>' . htmlspecialchars($student['aggregate_points'] ?? 'N/A') . '</td>'; // Use generic field name
            $html .= '<td>' . htmlspecialchars($student['division'] ?? 'N/A') . '</td></tr>';       // Use generic field name
        }
    } else { $html .= '<tr><td colspan="' . (2 + count($expectedSubjectKeysForClass) * 3 + 2) . '">No student summary data.</td></tr>'; }
    $html .= '</tbody></table></div>';

    // Subject Grade Distribution Tables
    $html .= '<h3>Subject Grade Distribution</h3>';
    if (!empty($gradeSummary)) { // Use $gradeSummary
        foreach ($coreSubjectKeys as $coreSubKey) { // Use $coreSubjectKeys (ENG, MTC, etc.)
            // $subjectDisplayNames uses uppercase keys
            $subjectDisplayName = htmlspecialchars($subjectDisplayNames[$coreSubKey] ?? ucfirst(strtolower($coreSubKey)));
            $html .= '<h5>' . $subjectDisplayName . '</h5>';
            $html .= '<table class="summary-table" style="width:80%; margin: 0 auto 15px auto;"><thead><tr>';
            if(isset($gradeSummary[$coreSubKey])) { foreach (array_keys($gradeSummary[$coreSubKey]) as $grade) { $html .= '<th>' . htmlspecialchars($grade) . '</th>'; } }
            $html .= '</tr></thead><tbody><tr>';
            if(isset($gradeSummary[$coreSubKey])) { foreach ($gradeSummary[$coreSubKey] as $count) { $html .= '<td>' . $count . '</td>'; } }
            else { $html .= "<td colspan='9'>No grade data.</td>";} // Assuming 9 grades D1-F9 + N/A
            $html .= '</tr></tbody></table>';
        }
    } else { $html .= '<p>Per-subject grade distribution data not available.</p>'; }
} // End if ($batchSettings)
// P1-P3 specific HTML generation is removed.

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
