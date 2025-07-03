<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('STKIZITO_SESSION');
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

// $isP1_P3 and $isP4_P7 flags are no longer needed as system is P5-P7 only.
$coreSubjectKeys = []; // For P5-P7, core subjects for aggregate/charts
$expectedSubjectKeysForClass = []; // All subjects for this class level

if ($batchSettings) {
    // Validate selected class is P5, P6, or P7 (already done in run_calculations, good to have here too for direct page access)
    if (!in_array($batchSettings['class_name'], ['P5', 'P6', 'P7'])) {
        $_SESSION['error_message'] = 'Summary Sheet Error: Invalid class (' . htmlspecialchars($batchSettings['class_name']) . ') for P5-P7 system.';
        header('Location: summary_sheet.php'); // Redirect to selection
        exit;
    }

    $coreSubjectKeys = ['ENG', 'MTC', 'SCI', 'SST']; // UNEB Core 4 for P5-P7 aggregates
    $expectedSubjectKeysForClass = ['ENG', 'MTC', 'SCI', 'SST', 'RE']; // All 5 subjects for P5-P7

    $enrichedStudentDataForBatch = [];
    if ($batch_id) {
        // This session data is set by run_calculations.php
        $enrichedStudentDataForBatch = $_SESSION['enriched_students_data_for_batch_' . $batch_id] ?? [];
    }
}

// Updated subjectDisplayNames to use new subject codes and "ENGLISH"
$subjectDisplayNames = [
    'ENG' => 'ENGLISH',
    'MTC' => 'MATHEMATICS',
    'SCI' => 'SCIENCE',
    'SST' => 'SOCIAL STUDIES',
    'RE'  => 'RELIGIOUS EDUCATION'
];

// P5-P7 Summary Data Calculation (adapting P4-P7 logic)
$divisionSummary = [ // Generic name, was $divisionSummaryP4P7
    'I' => 0, 'II' => 0, 'III' => 0, 'IV' => 0,
    'U' => 0, 'X' => 0, 'Ungraded' => 0
];
$gradeSummary = []; // Generic name, was $gradeSummaryP4P7: [subject_code => [grade => count]]

if ($batch_id && $batchSettings) { // Check if batch is selected
    // Sort students by aggregate points for P5-P7
    // $studentsSummaries already fetched, contains new field names like 'aggregate_points', 'division'
    $studentListForDisplay = $studentsSummaries;
    if (!empty($studentListForDisplay)) {
        uasort($studentListForDisplay, function($a, $b) {
            // Using new generic field names from student_term_summaries
            $aggA = $a['aggregate_points'];
            $aggB = $b['aggregate_points'];

            $isNumA = is_numeric($aggA);
            $isNumB = is_numeric($aggB);

            if ($isNumA && $isNumB) return (float)$aggA <=> (float)$aggB;
            elseif ($isNumA) return -1;
            elseif ($isNumB) return 1;
            else return 0;
        });
    }

    foreach ($studentsSummaries as $student) {
        $division = $student['division'] ?? 'Ungraded'; // Use new generic field name
        if (array_key_exists($division, $divisionSummary)) {
            $divisionSummary[$division]++;
        } else {
            $divisionSummary['Ungraded']++;
        }
    }

    // P5-P7 Grade Distribution (using session data and new $coreSubjectKeys)
    if (!empty($enrichedStudentDataForBatch) && !empty($coreSubjectKeys)) {
        foreach ($coreSubjectKeys as $coreSubKey) { // Now uses 'ENG', 'MTC', etc.
            $gradeSummary[$coreSubKey] = ['D1'=>0, 'D2'=>0, 'C3'=>0, 'C4'=>0, 'C5'=>0, 'C6'=>0, 'P7'=>0, 'P8'=>0, 'F9'=>0, 'N/A'=>0];
            foreach ($enrichedStudentDataForBatch as $studentId => $studentEnriched) {
                 $eotGrade = $studentEnriched['subjects'][$coreSubKey]['eot_grade'] ?? 'N/A'; // Access with 'ENG'
                 if(isset($gradeSummary[$coreSubKey][$eotGrade])) {
                    $gradeSummary[$coreSubKey][$eotGrade]++;
                 } else {
                    $gradeSummary[$coreSubKey]['N/A']++;
                 }
            }
        }
    }
}

// P1-P3 specific summary data calculation is removed.

// Log viewing action if a batch is successfully loaded and displayed
if ($batch_id && $batchSettings) {
    $logDescriptionSummary = "Viewed summary sheet for batch '" . htmlspecialchars($batchSettings['class_name'] . " " . $batchSettings['term_name'] . " " . $batchSettings['year_name']) . "' (ID: " . $batch_id . ").";
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? null,
        $_SESSION['username'] ?? 'System',
        'SUMMARY_SHEET_VIEWED',
        $logDescriptionSummary
        // ip_address is null by default in logActivity signature
        // entity_type and entity_id are removed from logActivity signature
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
                ST KIZITO PREPARATORY SEMINARY RWEBISHURI - Report System
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

            <!-- This section is now for P5-P7, adapting the old P4-P7 structure -->
            <h3>Division Summary</h3>
            <div class="row">
                <div class="col-md-6 table-responsive">
                    <table class="table table-bordered table-sm summary-table">
                        <thead class="table-dark"><tr><th colspan="2">Division Performance</th></tr></thead>
                        <tbody>
                            <?php
                            foreach ($divisionSummary as $divKey => $count): // Use $divisionSummary
                                $displayLabel = $divisionChartLabels[$divKey] ?? $divKey;
                            ?>
                                <tr><td><?php echo htmlspecialchars($displayLabel); ?></td><td><?php echo $count; ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <canvas id="divisionChart"></canvas> <!-- Generic ID, was p4p7DivisionChart -->
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
                                    // $expectedSubjectKeysForClass is now ['ENG', 'MTC', 'SCI', 'SST', 'RE']
                                    // Abbreviation map might need adjustment if keys are now uppercase.
                                    // For simplicity, directly use the uppercase keys or map them.
                                    $subj_abbr_map = ['ENG'=>'ENG', 'MTC'=>'MTC', 'SCI'=>'SCI', 'SST'=>'SST', 'RE'=>'RE'];
                                    foreach ($expectedSubjectKeysForClass as $subjKey):
                                        $abbr = $subj_abbr_map[$subjKey] ?? strtoupper(htmlspecialchars($subjKey)); ?>
                                        <th colspan="3"><?php echo $abbr; ?></th>
                                    <?php endforeach; ?>
                                    <th>Agg.</th>
                                    <th>Div.</th>
                                </tr>
                                <tr>
                                    <th></th><th></th>
                                    <?php foreach ($expectedSubjectKeysForClass as $subjKey): ?>
                                        <th>BOT</th><th>MOT</th><th>EOT</th>
                                    <?php endforeach; ?>
                                    <th></th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($studentListForDisplay)): ?>
                                    <?php $rowNum = 0; foreach ($studentListForDisplay as $student): $rowNum++; ?>
                                    <tr>
                                        <td><?php echo $rowNum; ?></td>
                                        <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                        <?php foreach ($expectedSubjectKeysForClass as $subjKey): ?>
                                            <?php
                                                // $enrichedStudentDataForBatch keys are uppercase: ENG, MTC, etc.
                                                $s_data = $enrichedStudentDataForBatch[$student['student_id']]['subjects'][$subjKey] ?? [];
                                                $bot = $s_data['bot_score'] ?? 'N/A';
                                                $mot = $s_data['mot_score'] ?? 'N/A';
                                                $eot = $s_data['eot_score'] ?? 'N/A';
                                            ?>
                                            <td><?php echo htmlspecialchars(is_numeric($bot) ? round((float)$bot) : $bot); ?></td>
                                            <td><?php echo htmlspecialchars(is_numeric($mot) ? round((float)$mot) : $mot); ?></td>
                                            <td><?php echo htmlspecialchars(is_numeric($eot) ? round((float)$eot) : $eot); ?></td>
                                        <?php endforeach; ?>
                                        <td><?php echo htmlspecialchars($student['aggregate_points'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($student['division'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php $colspan = 2 + (count($expectedSubjectKeysForClass) * 3) + 2; ?>
                                    <tr><td colspan="<?php echo $colspan; ?>">No student summary data available to display.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <h3>Subject Grade Distribution</h3>
            <?php if (!empty($gradeSummary)): ?>
                <?php foreach ($coreSubjectKeys as $coreSubKey): // $coreSubjectKeys is now ['ENG', 'MTC', 'SCI', 'SST'] ?>
                     <?php $subjectDisplayName = htmlspecialchars($subjectDisplayNames[$coreSubKey] ?? ucfirst(strtolower($coreSubKey))); ?>
                    <h5><?php echo $subjectDisplayName; ?></h5>
                    <div class="row">
                        <div class="col-md-7 table-responsive">
                            <table class="table table-sm table-bordered summary-table">
                                <thead class="table-light">
                                    <tr>
                                        <?php if(isset($gradeSummary[$coreSubKey])) { foreach (array_keys($gradeSummary[$coreSubKey]) as $grade): ?>
                                            <th><?php echo htmlspecialchars($grade); ?></th>
                                        <?php endforeach; } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <?php if(isset($gradeSummary[$coreSubKey])) { foreach ($gradeSummary[$coreSubKey] as $count): ?>
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
            <!-- P1-P3 specific sections are removed -->
        <?php else: ?>
            <!-- ... select batch message (unchanged) ... -->
        <?php endif; ?>
    </div>
    <!-- ... footer ... -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($batch_id && $batchSettings): // P5-P7 is the only case now ?>
    const divCtx = document.getElementById('divisionChart'); // Use generic ID
    if (divCtx) {
        let rawDivisionKeys = <?php echo json_encode(array_keys($divisionSummary)); ?>; // Use $divisionSummary
        let divisionData = <?php echo json_encode(array_values($divisionSummary)); ?>; // Use $divisionSummary
        let descriptiveDivisionLabelsMap = <?php echo json_encode($divisionChartLabels); ?>;

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
    // P5-P7 Grade Chart JS (using new variable names $gradeSummary and $coreSubjectKeys)
    <?php if (!empty($gradeSummary)): ?>
        <?php foreach ($coreSubjectKeys as $coreSubKey): // $coreSubjectKeys is now ['ENG', 'MTC', 'SCI', 'SST'] ?>
            <?php if (isset($gradeSummary[$coreSubKey])):
                $grades = array_keys($gradeSummary[$coreSubKey]);
                $counts = array_values($gradeSummary[$coreSubKey]);
                $filteredGrades = []; $filteredCounts = [];
                foreach($grades as $idx => $gradeKey) {
                    if($counts[$idx] > 0) { $filteredGrades[] = $gradeKey; $filteredCounts[] = $counts[$idx];}
                }
            ?>
            const gradeCtx_<?php echo $coreSubKey; ?> = document.getElementById('chart_<?php echo $coreSubKey; ?>');
            if (gradeCtx_<?php echo $coreSubKey; ?> && <?php echo json_encode(!empty($filteredGrades)); ?>) {
                new Chart(gradeCtx_<?php echo $coreSubKey; ?>, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($filteredGrades); ?>,
                        datasets: [{
                            // $subjectDisplayNames uses uppercase keys e.g. 'ENG'
                            label: 'Grade Distribution for <?php echo htmlspecialchars($subjectDisplayNames[$coreSubKey] ?? ucfirst(strtolower($coreSubKey))); ?>',
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
    <?php endif; // End of if ($batch_id && $batchSettings) ?>
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
