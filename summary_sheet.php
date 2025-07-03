<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Optional: Set a flash message to explain why they are on the login page
    // $_SESSION['login_error_message'] = "You must be logged in to access this page.";
    header('Location: login.php');
    exit;
}
require_once 'db_connection.php';
require_once 'dal.php';
// calculation_utils.php is not directly used here, as calculations are assumed done by run_calculations.php

$batch_id = null;
$batchSettings = null;
$studentsSummaries = []; // Holds data from student_report_summary
$allScoresForBatch = []; // Placeholder - for detailed grade distribution if fetched from scores table

$allProcessedBatches = getAllProcessedBatches($pdo);

if (isset($_GET['batch_id']) && filter_var($_GET['batch_id'], FILTER_VALIDATE_INT)) {
    $batch_id = (int)$_GET['batch_id'];
    $batchSettings = getReportBatchSettings($pdo, $batch_id);
    if (!$batchSettings) {
        $_SESSION['error_message'] = "Summary Sheet: Could not find details for Batch ID: " . htmlspecialchars($batch_id);
        // Redirect to selection if batch not found, or show error on page
        header('Location: summary_sheet.php');
        exit;
    }
    $studentsSummaries = getAllStudentSummariesForBatchWithName($pdo, $batch_id);
    // For P4-P7 grade distribution, run_calculations.php stores enriched data in session.
    // This is an interim solution. Ideally, this data would be queried from the 'scores' table
    // once run_calculations.php updates it with bot_grade, mot_grade, eot_grade.
}

$isP1_P3 = false;
$isP4_P7 = false;
$coreSubjectKeysP4_P7 = [];
$p1p3SubjectKeys = [];

if ($batchSettings) {
    $isP1_P3 = in_array($batchSettings['class_name'], ['P1', 'P2', 'P3']);
    $isP4_P7 = in_array($batchSettings['class_name'], ['P4', 'P5', 'P6', 'P7']);

    $expectedSubjectKeysForClass = []; // Initialize
    if ($isP4_P7) {
        $coreSubjectKeysP4_P7 = ['english', 'mtc', 'science', 'sst']; // Used for charts
        $expectedSubjectKeysForClass = ['english', 'mtc', 'science', 'sst', 'kiswahili']; // For P4-P7 tables
    } elseif ($isP1_P3) {
        // $p1p3SubjectKeys is defined correctly in the P1-P3 block later.
        // $expectedSubjectKeysForClass will be populated with $p1p3SubjectKeys in that block for consistency if needed,
        // but currently P1-P3 tables directly use $p1p3SubjectKeys.
        $expectedSubjectKeysForClass = ['english', 'mtc', 're', 'lit1', 'lit2', 'local_lang']; // Also set for P1-P3 here for global availability if needed
    }

    $enrichedStudentDataForBatch = [];
    if ($batch_id) {
        $enrichedStudentDataForBatch = $_SESSION['enriched_students_data_for_batch_' . $batch_id] ?? [];
    }
}

$subjectDisplayNames = [
    'english' => 'English', 'mtc' => 'Mathematics (MTC)', 'science' => 'Science',
    'sst' => 'Social Studies (SST)', 'kiswahili' => 'Kiswahili',
    're' => 'Religious Education (R.E)', 'lit1' => 'Literacy I',
    'lit2' => 'Literacy II', 'local_lang' => 'Local Language'
];

// P4-P7 Summary Data Calculation (if batch is selected)
// UPDATED KEYS to match Roman numeral/letter output
$divisionSummaryP4P7 = [
    'I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0,
    'U' => 0, 'X' => 0, 'Ungraded' => 0
];
$gradeSummaryP4P7 = []; // [subject_code => [grade => count]]

if ($isP4_P7 && $batch_id) {
    // Sort students by aggregate points for P4-P7
    $p4p7StudentListForDisplay = $studentsSummaries; // Copy
    if (!empty($p4p7StudentListForDisplay)) { // Ensure array is not empty before sorting
        uasort($p4p7StudentListForDisplay, function($a, $b) {
            $aggA = $a['p4p7_aggregate_points'];
            $aggB = $b['p4p7_aggregate_points'];

            // Handle non-numeric cases (like 'N/A', null, or empty strings)
            $isNumA = is_numeric($aggA);
            $isNumB = is_numeric($aggB);

            if ($isNumA && $isNumB) {
                return (float)$aggA <=> (float)$aggB; // Numeric comparison
            } elseif ($isNumA) {
                return -1; // Numeric is better than non-numeric
            } elseif ($isNumB) {
                return 1;  // Non-numeric is worse than numeric
            } else {
                return 0;  // Both non-numeric, treat as equal for sorting
            }
        });
    }

    foreach ($studentsSummaries as $student) {
        // $student['p4p7_division'] will now be 'I', 'II', 'U', 'X' etc. from the database
        $division = $student['p4p7_division'] ?? 'Ungraded';
        if (array_key_exists($division, $divisionSummaryP4P7)) { // Use array_key_exists for safety
            $divisionSummaryP4P7[$division]++;
        } else {
            $divisionSummaryP4P7['Ungraded']++;
        }
    }

    // P4-P7 Grade Distribution (using session data as interim)
    // $enrichedStudentDataForBatch = $_SESSION['enriched_students_data_for_batch_' . $batch_id] ?? []; // Moved higher
    if (!empty($enrichedStudentDataForBatch) && !empty($coreSubjectKeysP4_P7)) {
        foreach ($coreSubjectKeysP4_P7 as $coreSubKey) {
            $gradeSummaryP4P7[$coreSubKey] = ['D1'=>0, 'D2'=>0, 'C3'=>0, 'C4'=>0, 'C5'=>0, 'C6'=>0, 'P7'=>0, 'P8'=>0, 'F9'=>0, 'N/A'=>0];
            foreach ($enrichedStudentDataForBatch as $studentId => $studentEnriched) {
                 // Check if subject exists for student to avoid undefined index
                 $eotGrade = $studentEnriched['subjects'][$coreSubKey]['eot_grade'] ?? 'N/A';
                 if(isset($gradeSummaryP4P7[$coreSubKey][$eotGrade])) {
                    $gradeSummaryP4P7[$coreSubKey][$eotGrade]++;
                 } else {
                    $gradeSummaryP4P7[$coreSubKey]['N/A']++;
                 }
            }
        }
    }
}

// P1-P3 Summary Data Calculation (if batch is selected)
$p1p3StudentListForDisplay = [];
$classAverageP1P3 = 0;
$p1p3SubjectScoreDistribution = []; // Initialize for P1-P3 subject scores

if ($isP1_P3 && $batch_id) {
    $p1p3SubjectKeys = ['english', 'mtc', 're', 'lit1', 'lit2', 'local_lang']; // Define P1-P3 subjects
    $scoreBands = [
        '0-39' => 0, '40-49' => 0, '50-59' => 0, '60-69' => 0,
        '70-79' => 0, '80-89' => 0, '90-100' => 0, 'N/A' => 0
    ];

    if (!empty($enrichedStudentDataForBatch) && !empty($p1p3SubjectKeys)) {
        foreach ($p1p3SubjectKeys as $subjectKey) {
            $p1p3SubjectScoreDistribution[$subjectKey] = $scoreBands; // Initialize for each subject
            foreach ($enrichedStudentDataForBatch as $studentId => $studentEnrichedData) {
                // Ensure subject data exists for the student to avoid undefined index errors
                $eotScore = $studentEnrichedData['subjects'][$subjectKey]['eot_score'] ?? 'N/A';
                $band = 'N/A';
                if (is_numeric($eotScore)) {
                    $eotScoreNum = (float)$eotScore; // Use a different var name to avoid confusion
                    if ($eotScoreNum >= 90) $band = '90-100';
                    else if ($eotScoreNum >= 80) $band = '80-89';
                    else if ($eotScoreNum >= 70) $band = '70-79';
                    else if ($eotScoreNum >= 60) $band = '60-69';
                    else if ($eotScoreNum >= 50) $band = '50-59';
                    else if ($eotScoreNum >= 40) $band = '40-49';
                    else $band = '0-39';
                }
                // Ensure the band exists before incrementing
                if (isset($p1p3SubjectScoreDistribution[$subjectKey][$band])) {
                     $p1p3SubjectScoreDistribution[$subjectKey][$band]++;
                } else {
                     $p1p3SubjectScoreDistribution[$subjectKey]['N/A']++; // Fallback for safety
                }
            }
        }
    }

    $p1p3StudentListForDisplay = $studentsSummaries;
    uasort($p1p3StudentListForDisplay, function($a, $b) {
        return ($a['p1p3_position_in_class'] ?? PHP_INT_MAX) <=> ($b['p1p3_position_in_class'] ?? PHP_INT_MAX);
    });
    $totalClassAverageEotP1P3 = 0;
    $validStudentsForClassAverageP1P3 = 0;
    foreach ($studentsSummaries as $student) {
        if (isset($student['p1p3_average_eot_score']) && is_numeric($student['p1p3_average_eot_score'])) {
            $totalClassAverageEotP1P3 += $student['p1p3_average_eot_score'];
            $validStudentsForClassAverageP1P3++;
        }
    }
    $classAverageP1P3 = ($validStudentsForClassAverageP1P3 > 0) ? round($totalClassAverageEotP1P3 / $validStudentsForClassAverageP1P3, 2) : 0;
}

// Log viewing action if a batch is successfully loaded and displayed
if ($batch_id && $batchSettings) {
    $logDescriptionSummary = "Viewed summary sheet for batch '" . htmlspecialchars($batchSettings['class_name'] . " " . $batchSettings['term_name'] . " " . $batchSettings['year_name']) . "' (ID: " . $batch_id . ").";
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? null,
        $_SESSION['username'] ?? 'System',
        'SUMMARY_SHEET_VIEWED',
        $logDescriptionSummary,
        'batch',
        $batch_id
    );
}

// For Chart Labels - map internal keys to more descriptive labels
$divisionChartLabels = [
    'I' => 'Division I', 'II' => 'Division II', 'III' => 'Division III', 'IV' => 'Division IV',
    'U' => 'Grade U', 'X' => 'Division X', 'Ungraded' => 'Ungraded'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Summary Sheet<?php if ($batchSettings) echo " - " . htmlspecialchars($batchSettings['class_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #e0f7fa; }
        .container.main-content { background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 20px; }
        .summary-table th, .summary-table td {
            text-align: center;
            vertical-align: middle;
            font-size: 8.5pt; /* Adjusted font size */
            padding: 0.2rem 0.2rem; /* Keep padding */
        }
        .table-responsive { margin-bottom: 2rem; }
        h3, h4, h5 { margin-top: 1.5rem; color: #0056b3; }
        .print-button-container { margin-top: 20px; margin-bottom: 20px; text-align: right; }
        @media print {
            @page {
                size: landscape;
                margin: 7mm; /* Adjusted margin */
            }
            body { background-color: #fff; }
            .non-printable { display: none !important; }
            .container.main-content {
                box-shadow:none;
                border:none;
                margin-top:0;
                padding:5mm;
            }
            /* Removed the general .table th, .table td rule for print to avoid conflicts */

            .summary-table th, .summary-table td {
                font-size: 10pt !important;
                padding: 0.15rem !important;
                overflow-wrap: break-word; /* Ensure content wraps */
            }
            h3, h4, h5 {
                font-size: 10pt !important;
                margin-top: 0.5rem;
                margin-bottom: 0.5rem;
            }
            canvas {
                max-width:100% !important;
                height:auto !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-light bg-light sticky-top shadow-sm non-printable">
        <!-- ... Navbar content (unchanged) ... -->
         <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="images/logo.png" alt="Logo" width="30" height="30" class="d-inline-block align-text-top me-2" onerror="this.style.display='none';">
                Maria Ow'embabazi P/S - Report System
            </a>
            <div>
                <a href="index.php" class="btn btn-outline-secondary me-2"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <a href="logout.php" class="btn btn-outline-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container main-content">
        <div class="text-center mb-4">
            <h2>Class Performance Summary</h2>
            <?php if ($batchSettings): ?>
                <h4><?php echo htmlspecialchars($batchSettings['class_name']); ?> - Term <?php echo htmlspecialchars($batchSettings['term_name']); ?>, <?php echo htmlspecialchars($batchSettings['year_name']); ?></h4>
            <?php endif; ?>
        </div>

        <div class="non-printable">
            <!-- ... Batch selection form (unchanged) ... -->
            <form method="GET" action="summary_sheet.php" class="row g-3 align-items-end mb-4">
                <div class="col-md-4">
                    <label for="batch_id_select" class="form-label">Select Processed Batch:</label>
                    <select name="batch_id" id="batch_id_select" class="form-select" required>
                        <option value="">-- Select a Batch --</option>
                        <?php foreach ($allProcessedBatches as $batchOption): ?>
                            <option value="<?php echo $batchOption['batch_id']; ?>" <?php if ($batch_id == $batchOption['batch_id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($batchOption['class_name'] . " - " . $batchOption['year_name'] . " Term " . $batchOption['term_name'] . " (ID: " . $batchOption['batch_id'] . ")"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-eye"></i> View Summary</button>
                </div>
            </form>
             <hr>
        </div>
        <!-- ... Session messages (unchanged) ... -->

        <?php if ($batch_id && $batchSettings): // Only display if a batch is selected and found ?>
            <div class="print-button-container non-printable">
                <button onclick="window.print();" class="btn btn-info"><i class="fas fa-print"></i> Print Summary</button>
                <a href="generate_summary_pdf.php?batch_id=<?php echo htmlspecialchars($batch_id); ?>" class="btn btn-danger ms-2" target="_blank" title="Download Landscape PDF Summary">
                    <i class="fas fa-file-pdf"></i> Download PDF Summary
                </a>
            </div>

            <?php if ($isP4_P7): ?>
                <h3>Division Summary</h3> <!-- Removed (P4-P7) from title for cleaner look -->
                <div class="row">
                    <div class="col-md-6 table-responsive">
                        <table class="table table-bordered table-sm summary-table">
                            <thead class="table-dark"><tr><th colspan="2">Division Performance</th></tr></thead>
                            <tbody>
                                <?php // UPDATED to use $divisionChartLabels for display
                                foreach ($divisionSummaryP4P7 as $divKey => $count):
                                    $displayLabel = $divisionChartLabels[$divKey] ?? $divKey; // Use descriptive label
                                ?>
                                    <tr><td><?php echo htmlspecialchars($displayLabel); ?></td><td><?php echo $count; ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <canvas id="p4p7DivisionChart"></canvas>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-9 mx-auto">
                        <h3>Student Performance List</h3>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover summary-table">
                                <thead class="table-primary">
                                    <tr>
                                        <th>#</th>
                                        <th>Student Name</th>
                                        <?php
                                        // Use $expectedSubjectKeysForClass which should be set for P4-P7
                                        $p4p7_subj_abbr_map = ['english'=>'ENG', 'mtc'=>'MTC', 'science'=>'SCI', 'sst'=>'SST', 'kiswahili'=>'KISW'];
                                        foreach ($expectedSubjectKeysForClass as $subjKey):
                                            $abbr = $p4p7_subj_abbr_map[$subjKey] ?? strtoupper(htmlspecialchars($subjKey)); ?>
                                            <th colspan="3"><?php echo $abbr; ?></th>
                                        <?php endforeach; ?>
                                        <th>Agg.</th>
                                        <th>Div.</th>
                                    </tr>
                                    <tr>
                                        <th></th><th></th> <!-- Empty for #, Name -->
                                        <?php foreach ($expectedSubjectKeysForClass as $subjKey): ?>
                                            <th>BOT</th><th>MOT</th><th>EOT</th>
                                        <?php endforeach; ?>
                                        <th></th><th></th> <!-- Empty for Agg, Div -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php /* Check if $p4p7StudentListForDisplay is not empty before looping */ ?>
                                    <?php if (!empty($p4p7StudentListForDisplay)): ?>
                                        <?php $rowNum = 0; foreach ($p4p7StudentListForDisplay as $student): $rowNum++; ?>
                                        <tr>
                                            <td><?php echo $rowNum; ?></td>
                                            <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                            <?php foreach ($expectedSubjectKeysForClass as $subjKey): ?>
                                                <?php
                                                    $s_data = $enrichedStudentDataForBatch[$student['student_id']]['subjects'][$subjKey] ?? [];
                                                    $bot = $s_data['bot_score'] ?? 'N/A';
                                                    $mot = $s_data['mot_score'] ?? 'N/A';
                                                    $eot = $s_data['eot_score'] ?? 'N/A';
                                                ?>
                                                <td><?php echo htmlspecialchars(is_numeric($bot) ? round((float)$bot) : $bot); ?></td>
                                                <td><?php echo htmlspecialchars(is_numeric($mot) ? round((float)$mot) : $mot); ?></td>
                                                <td><?php echo htmlspecialchars(is_numeric($eot) ? round((float)$eot) : $eot); ?></td>
                                            <?php endforeach; ?>
                                            <td><?php echo htmlspecialchars($student['p4p7_aggregate_points'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($student['p4p7_division'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php $p4p7Colspan = 2 + (count($expectedSubjectKeysForClass) * 3) + 2; /* #, Name + (subjects*3) + Agg, Div */ ?>
                                        <tr><td colspan="<?php echo $p4p7Colspan; ?>">No student summary data available to display.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ... P4-P7 Grade Distribution section (largely unchanged, uses D1-F9 grades) ... -->
                 <h3>Subject Grade Distribution</h3>
                <?php if (!empty($gradeSummaryP4P7)): ?>
                    <?php foreach ($coreSubjectKeysP4_P7 as $coreSubKey): ?>
                         <?php $subjectDisplayName = htmlspecialchars($subjectDisplayNames[$coreSubKey] ?? ucfirst($coreSubKey)); ?>
                        <h5><?php echo $subjectDisplayName; ?></h5>
                        <div class="row">
                            <div class="col-md-7 table-responsive">
                                <table class="table table-sm table-bordered summary-table">
                                    <thead class="table-light">
                                        <tr>
                                            <?php if(isset($gradeSummaryP4P7[$coreSubKey])) { foreach (array_keys($gradeSummaryP4P7[$coreSubKey]) as $grade): ?>
                                                <th><?php echo htmlspecialchars($grade); ?></th>
                                            <?php endforeach; } ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <?php if(isset($gradeSummaryP4P7[$coreSubKey])) { foreach ($gradeSummaryP4P7[$coreSubKey] as $count): ?>
                                                <td><?php echo $count; ?></td>
                                            <?php endforeach; } else { echo "<td colspan='9'>No grade data.</td>";} ?>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-5">
                                <canvas id="chart_<?php echo $coreSubKey; ?>"></canvas>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">Per-subject grade distribution data not available. Ensure calculations ran and data is in session.</p>
                <?php endif; ?>

            <?php elseif ($isP1_P3): ?>
                <!-- ... P1-P3 summary table and chart canvas (unchanged) ... -->
                 <h3>Performance Summary (P1-P3)</h3>
                <p><strong>Overall Class Average End of Term Score:</strong> <?php echo htmlspecialchars($classAverageP1P3); ?>%</p>

                <!-- Student List Table in its own centered row -->
                <div class="row justify-content-center">
                    <div class="col-md-9">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover summary-table">
                                <thead class="table-primary">
                                    <tr>
                                        <th>#</th>
                                        <th>Student Name</th>
                                        <?php
                                        // $p1p3SubjectKeys is defined in the P1-P3 block
                                        $p1p3_subj_abbr_map = ['english'=>'ENG', 'mtc'=>'MTC', 're'=>'RE', 'lit1'=>'LIT1', 'lit2'=>'LIT2', 'local_lang'=>'LLANG'];
                                        foreach ($p1p3SubjectKeys as $subjKey):
                                            $abbr = $p1p3_subj_abbr_map[$subjKey] ?? strtoupper(htmlspecialchars($subjKey)); ?>
                                            <th colspan="3"><?php echo $abbr; ?></th>
                                        <?php endforeach; ?>
                                        <th>Total EOT</th>
                                        <th>Avg EOT (%)</th>
                                        <th>Pos</th>
                                    </tr>
                                    <tr>
                                        <th></th><th></th> <!-- Empty for #, Name -->
                                        <?php foreach ($p1p3SubjectKeys as $subjKey): ?>
                                            <th>BOT</th><th>MOT</th><th>EOT</th>
                                        <?php endforeach; ?>
                                        <th></th><th></th><th></th> <!-- Empty for Total, Avg, Pos -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rowNum = 0; foreach ($p1p3StudentListForDisplay as $student): $rowNum++; ?>
                                    <tr>
                                        <td><?php echo $rowNum; ?></td>
                                        <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                        <?php foreach ($p1p3SubjectKeys as $subjKey): ?>
                                            <?php
                                                $s_data = $enrichedStudentDataForBatch[$student['student_id']]['subjects'][$subjKey] ?? [];
                                                $bot = $s_data['bot_score'] ?? 'N/A';
                                                $mot = $s_data['mot_score'] ?? 'N/A';
                                                $eot = $s_data['eot_score'] ?? 'N/A';
                                            ?>
                                            <td><?php echo htmlspecialchars(is_numeric($bot) ? round((float)$bot) : $bot); ?></td>
                                            <td><?php echo htmlspecialchars(is_numeric($mot) ? round((float)$mot) : $mot); ?></td>
                                            <td><?php echo htmlspecialchars(is_numeric($eot) ? round((float)$eot) : $eot); ?></td>
                                        <?php endforeach; ?>
                                        <td><?php echo htmlspecialchars($student['p1p3_total_eot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($student['p1p3_average_eot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($student['p1p3_position_in_class'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($p1p3StudentListForDisplay)): ?>
                                        <?php $p1p3Colspan = 2 + (count($p1p3SubjectKeys) * 3) + 3; /* #, Name + (subjects*3) + Total, Avg, Pos */ ?>
                                        <tr><td colspan="<?php echo $p1p3Colspan; ?>">No student summary data available to display.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if (!empty($p1p3SubjectScoreDistribution)): ?>
                    <h3 class="mt-4">Per-Subject End of Term Score Distribution</h3>
                    <?php foreach ($p1p3SubjectKeys as $subjectKey): ?>
                        <?php $subjectDisplayName = htmlspecialchars($subjectDisplayNames[$subjectKey] ?? ucfirst($subjectKey)); ?>
                        <h5><?php echo $subjectDisplayName; ?></h5>
                        <div class="row">
                            <div class="col-md-7 table-responsive">
                                <table class="table table-sm table-bordered summary-table">
                                    <thead class="table-light">
                                        <tr>
                                            <?php foreach (array_keys($scoreBands) as $bandLabel): ?>
                                                <th><?php echo $bandLabel; ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <?php foreach ($scoreBands as $bandLabel => $defaultCount): ?>
                                                <td><?php echo $p1p3SubjectScoreDistribution[$subjectKey][$bandLabel] ?? 0; ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-5">
                                <canvas id="p1p3_subject_chart_<?php echo $subjectKey; ?>"></canvas>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <!-- ... select batch message (unchanged) ... -->
        <?php endif; ?>
    </div>
    <!-- ... footer ... -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($batch_id && $batchSettings && $isP4_P7): ?>
    const divCtx = document.getElementById('p4p7DivisionChart');
    if (divCtx) {
        let rawDivisionKeys = <?php echo json_encode(array_keys($divisionSummaryP4P7)); ?>;
        let divisionData = <?php echo json_encode(array_values($divisionSummaryP4P7)); ?>;
        let descriptiveDivisionLabelsMap = <?php echo json_encode($divisionChartLabels); ?>; // PHP map

        let filteredDisplayLabels = [];
        let filteredData = [];
        rawDivisionKeys.forEach((key, index) => {
            if (divisionData[index] > 0) {
                filteredDisplayLabels.push(descriptiveDivisionLabelsMap[key] || key); // Use descriptive label
                filteredData.push(divisionData[index]);
            }
        });

        if (filteredData.length > 0) {
            new Chart(divCtx, {
                type: 'pie',
                data: {
                    labels: filteredDisplayLabels, // UPDATED to use descriptive labels
                    datasets: [{
                        label: 'Division Distribution',
                        data: filteredData,
                        backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6f42c1', '#dc3545', '#adb5bd'],
                        hoverOffset: 4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } } }
            });
        }
    }
    // ... P4-P7 Grade Chart JS (unchanged, uses D1-F9 which is fine) ...
    <?php if (!empty($gradeSummaryP4P7)): ?>
        <?php foreach ($coreSubjectKeysP4_P7 as $coreSubKey): ?>
            <?php if (isset($gradeSummaryP4P7[$coreSubKey])):
                $grades = array_keys($gradeSummaryP4P7[$coreSubKey]);
                $counts = array_values($gradeSummaryP4P7[$coreSubKey]);
                $filteredGrades = []; $filteredCounts = [];
                foreach($grades as $idx => $gradeKey) {
                    if($counts[$idx] > 0) { $filteredGrades[] = $gradeKey; $filteredCounts[] = $counts[$idx];}
                }
            ?>
            const gradeCtx_<?php echo $coreSubKey; ?> = document.getElementById('chart_<?php echo $coreSubKey; ?>');
            if (gradeCtx_<?php echo $coreSubKey; ?> && <?php echo json_encode(!empty($filteredGrades)); ?>) {
                new Chart(gradeCtx_<?php echo $coreSubKey; ?>, { /* ... chart config ... */
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($filteredGrades); ?>,
                        datasets: [{
                            label: 'Grade Distribution for <?php echo htmlspecialchars($subjectDisplayNames[$coreSubKey] ?? ucfirst($coreSubKey)); ?>',
                            data: <?php echo json_encode($filteredCounts); ?>,
                            backgroundColor: 'rgba(0, 123, 255, 0.5)',
                            borderColor: 'rgba(0, 123, 255, 1)',
                            borderWidth: 1
                        }]
                    }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                });
            }
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php elseif ($batch_id && $batchSettings && $isP1_P3 && !empty($p1p3StudentListForDisplay)): ?>
    // ... P1-P3 Chart JS (Overall Average Line Chart REMOVED) ...

    <?php /* JavaScript for P1-P3 Per-Subject EOT Score Distribution Charts */ ?>
    <?php if (!empty($p1p3SubjectScoreDistribution)): ?>
        <?php foreach ($p1p3SubjectKeys as $subjectKey): ?>
            <?php
                $chartDataForSubject = $p1p3SubjectScoreDistribution[$subjectKey] ?? $scoreBands;
                $chartLabels = array_keys($chartDataForSubject);
                $chartCounts = array_values($chartDataForSubject);
            ?>
            const p1p3SubjectCtx_<?php echo $subjectKey; ?> = document.getElementById('p1p3_subject_chart_<?php echo $subjectKey; ?>');
            if (p1p3SubjectCtx_<?php echo $subjectKey; ?>) {
                const subjectCounts_<?php echo $subjectKey; ?> = <?php echo json_encode($chartCounts); ?>;
                const maxCount_<?php echo $subjectKey; ?> = Math.max(0, ...subjectCounts_<?php echo $subjectKey; ?>);
                let suggestedMaxY_<?php echo $subjectKey; ?>;
                if (maxCount_<?php echo $subjectKey; ?> === 0) {
                    suggestedMaxY_<?php echo $subjectKey; ?> = 5;
                } else if (maxCount_<?php echo $subjectKey; ?> <= 2) {
                    suggestedMaxY_<?php echo $subjectKey; ?> = maxCount_<?php echo $subjectKey; ?> + 2;
                } else if (maxCount_<?php echo $subjectKey; ?> <= 5) {
                    suggestedMaxY_<?php echo $subjectKey; ?> = maxCount_<?php echo $subjectKey; ?> + Math.ceil(maxCount_<?php echo $subjectKey; ?> * 0.4);
                } else {
                    suggestedMaxY_<?php echo $subjectKey; ?> = maxCount_<?php echo $subjectKey; ?> + Math.ceil(maxCount_<?php echo $subjectKey; ?> * 0.2);
                }
                suggestedMaxY_<?php echo $subjectKey; ?> = Math.ceil(suggestedMaxY_<?php echo $subjectKey; ?>);

                if (subjectCounts_<?php echo $subjectKey; ?>.some(count => count > 0)) { // Only create chart if there's data
                    new Chart(p1p3SubjectCtx_<?php echo $subjectKey; ?>, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($chartLabels); ?>,
                            datasets: [{
                                label: 'Score Distribution for <?php echo htmlspecialchars($subjectDisplayNames[$subjectKey] ?? ucfirst($subjectKey)); ?>',
                                data: subjectCounts_<?php echo $subjectKey; ?>,
                                backgroundColor: 'rgba(23, 162, 184, 0.5)', // Bootstrap info color, semi-transparent
                                borderColor: 'rgba(23, 162, 184, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    suggestedMax: suggestedMaxY_<?php echo $subjectKey; ?>,
                                    ticks: { precision: 0 }
                                }
                            }
                        }
                    });
                }
            }
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
