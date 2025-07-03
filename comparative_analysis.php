<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db_connection.php';
require_once 'dal.php';
require_once 'calculation_utils.php'; // For getGradeFromScoreUtil

$selectedStudentId = null;
$selectedBatchId = null;
$selectedSubjectIdForTrend = null;

$studentDetails = null;
$batchDetails = null;
$subjectDetailsForTrend = null;

$allStudentsWithProcessedData = [];
$processedBatchesForStudent = []; // Batches the selected student has data in
$subjectsInSelectedBatchForStudent = []; // Subjects for the selected student in the selected batch
$subjectsForStudentOverall = []; // All subjects a student has ever taken, for trend analysis selection

$comparisonDataSingleBatch = []; // For comparing subjects within one batch
$subjectTrendData = []; // For comparing one subject across multiple batches

// Fetch all students who have data in student_report_summary
try {
    $stmtStudents = $pdo->query(
        "SELECT DISTINCT s.id, s.student_name, s.lin_no
         FROM students s
         JOIN student_report_summary srs ON s.id = srs.student_id
         ORDER BY s.student_name ASC"
    );
    $allStudentsWithProcessedData = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching student list: " . $e->getMessage();
}

if (isset($_GET['student_id']) && filter_var($_GET['student_id'], FILTER_VALIDATE_INT)) {
    $selectedStudentId = (int)$_GET['student_id'];

    // Fetch student details
    $stmtStudent = $pdo->prepare("SELECT student_name, lin_no FROM students WHERE id = :student_id");
    $stmtStudent->execute([':student_id' => $selectedStudentId]);
    $studentDetails = $stmtStudent->fetch(PDO::FETCH_ASSOC);

    if ($studentDetails) {
        // Fetch batches this student has data in
        $sqlBatches = "SELECT DISTINCT rbs.id as batch_id, c.class_name, ay.year_name, t.term_name
                       FROM report_batch_settings rbs
                       JOIN student_report_summary srs ON rbs.id = srs.report_batch_id
                       JOIN classes c ON rbs.class_id = c.id
                       JOIN academic_years ay ON rbs.academic_year_id = ay.id
                       JOIN terms t ON rbs.term_id = t.id
                       WHERE srs.student_id = :student_id
                       ORDER BY ay.year_name DESC, t.id DESC";
        $stmtBatches = $pdo->prepare($sqlBatches);
        $stmtBatches->execute([':student_id' => $selectedStudentId]);
        $processedBatchesForStudent = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all subjects this student has ever had scores for (for trend analysis dropdown)
        $sqlAllSubjects = "SELECT DISTINCT subj.id as subject_id, subj.subject_name_full, subj.subject_code
                           FROM subjects subj
                           JOIN scores sc ON subj.id = sc.subject_id
                           WHERE sc.student_id = :student_id
                           ORDER BY subj.subject_name_full ASC";
        $stmtAllSubjects = $pdo->prepare($sqlAllSubjects);
        $stmtAllSubjects->execute([':student_id' => $selectedStudentId]);
        $subjectsForStudentOverall = $stmtAllSubjects->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $_SESSION['error_message'] = "Student not found.";
        $selectedStudentId = null; // Reset
    }
}

// Mode 1: Compare subjects within a single batch
if ($selectedStudentId && isset($_GET['batch_id']) && filter_var($_GET['batch_id'], FILTER_VALIDATE_INT)) {
    $selectedBatchId = (int)$_GET['batch_id'];
    $batchDetails = getReportBatchSettings($pdo, $selectedBatchId); // Fetch details for display

    if ($batchDetails) {
        // Validate this student actually has data in this batch
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM student_report_summary WHERE student_id = :student_id AND report_batch_id = :batch_id");
        $stmtCheck->execute([':student_id' => $selectedStudentId, ':batch_id' => $selectedBatchId]);
        if ($stmtCheck->fetchColumn() > 0) {
            $comparisonDataSingleBatch = getStudentScoresForBatchDetailed($pdo, $selectedStudentId, $selectedBatchId);
            // We need grades, calculate them if not in DB
            foreach ($comparisonDataSingleBatch as $key => $subjectScore) {
                $comparisonDataSingleBatch[$key]['bot_grade'] = getGradeFromScoreUtil($subjectScore['bot_score']);
                $comparisonDataSingleBatch[$key]['mot_grade'] = getGradeFromScoreUtil($subjectScore['mot_score']);
                $comparisonDataSingleBatch[$key]['eot_grade'] = getGradeFromScoreUtil($subjectScore['eot_score']);
            }
        } else {
            $_SESSION['error_message'] = "Selected student does not have data for the selected batch.";
            $selectedBatchId = null; // Reset
        }
    } else {
        $_SESSION['error_message'] = "Batch not found.";
        $selectedBatchId = null; // Reset
    }
}

// Mode 2: Compare a single subject across terms
if ($selectedStudentId && isset($_GET['subject_id_trend']) && filter_var($_GET['subject_id_trend'], FILTER_VALIDATE_INT)) {
    $selectedSubjectIdForTrend = (int)$_GET['subject_id_trend'];
    $subjectTrendData = getStudentSubjectPerformanceAcrossTerms($pdo, $selectedStudentId, $selectedSubjectIdForTrend);

    if (!empty($subjectTrendData)) {
        $subjectDetailsForTrend = [
            'subject_name_full' => $subjectTrendData[0]['subject_name_full'],
            'subject_code' => $subjectTrendData[0]['subject_code']
        ];
        // Calculate grades for trend data
        foreach ($subjectTrendData as $key => $termData) {
            $subjectTrendData[$key]['eot_grade'] = getGradeFromScoreUtil($termData['eot_score']);
        }
    } else {
        // Check if subject exists for student
        $stmtCheckSubj = $pdo->prepare("SELECT subject_name_full FROM subjects WHERE id = :subject_id");
        $stmtCheckSubj->execute([':subject_id' => $selectedSubjectIdForTrend]);
        $subjInfo = $stmtCheckSubj->fetch();
        if ($subjInfo) {
            $_SESSION['info_message'] = "No performance data found for " . htmlspecialchars($subjInfo['subject_name_full']) . " for this student across terms.";
        } else {
            $_SESSION['error_message'] = "Selected subject for trend analysis not found.";
        }
        $selectedSubjectIdForTrend = null; // Reset
    }
}


$subjectDisplayNames = [ /* As in summary_sheet.php - can be centralized later */
    'english' => 'English', 'mtc' => 'Mathematics (MTC)', 'science' => 'Science',
    'sst' => 'Social Studies (SST)', 'kiswahili' => 'Kiswahili',
    're' => 'Religious Education (R.E)', 'lit1' => 'Literacy I',
    'lit2' => 'Literacy II', 'local_lang' => 'Local Language'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Performance Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container.main-content { background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 20px; }
        .table th { background-color: #e9ecef; }
        .form-section { margin-bottom: 2rem; padding: 1.5rem; border: 1px solid #dee2e6; border-radius: .25rem; background-color: #fdfdff; }
        .chart-container { max-height: 350px; margin-bottom: 2rem; }
        .nav-pills .nav-link.active { background-color: #007bff; }
        .nav-pills .nav-link { color: #007bff; }
    </style>
</head>
<body>
    <nav class="navbar navbar-light bg-light sticky-top shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="images/logo.png" alt="Logo" width="30" height="30" class="d-inline-block align-text-top me-2">
                Maria Ow'embabazi P/S - Report System
            </a>
            <div>
                <a href="index.php" class="btn btn-outline-secondary me-2"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <a href="logout.php" class="btn btn-outline-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container main-content">
        <h2 class="text-center mb-4">Comparative Performance Analysis</h2>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info"><?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
        <?php endif; ?>

        <!-- Student Selector -->
        <form method="GET" action="comparative_analysis.php" class="row g-3 align-items-end mb-4" id="studentSelectForm">
            <div class="col-md-8">
                <label for="student_id_select" class="form-label">Select Student:</label>
                <select name="student_id" id="student_id_select" class="form-select" required onchange="document.getElementById('studentSelectForm').submit();">
                    <option value="">-- Select a Student --</option>
                    <?php foreach ($allStudentsWithProcessedData as $studentOption): ?>
                        <option value="<?php echo $studentOption['id']; ?>" <?php if ($selectedStudentId == $studentOption['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($studentOption['student_name'] . ($studentOption['lin_no'] ? ' (LIN: ' . $studentOption['lin_no'] . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Hidden fields to carry over other selections if student changes -->
            <?php if ($selectedBatchId): ?> <input type="hidden" name="batch_id" value="<?php echo $selectedBatchId; ?>"> <?php endif; ?>
            <?php if ($selectedSubjectIdForTrend): ?> <input type="hidden" name="subject_id_trend" value="<?php echo $selectedSubjectIdForTrend; ?>"> <?php endif; ?>
        </form>
        <hr>

        <?php if ($selectedStudentId && $studentDetails): ?>
            <h3 class="mb-3">Analyzing for: <?php echo htmlspecialchars(strtoupper($studentDetails['student_name'])); ?></h3>

            <ul class="nav nav-pills mb-3" id="analysisTypeTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($selectedBatchId || (!$selectedBatchId && !$selectedSubjectIdForTrend)) ? 'active' : ''; ?>" id="compare-subjects-tab" data-bs-toggle="pill" data-bs-target="#compare-subjects" type="button" role="tab" aria-controls="compare-subjects" aria-selected="<?php echo ($selectedBatchId || (!$selectedBatchId && !$selectedSubjectIdForTrend)) ? 'true' : 'false'; ?>">Compare Subjects (Single Term)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $selectedSubjectIdForTrend ? 'active' : ''; ?>" id="subject-trend-tab" data-bs-toggle="pill" data-bs-target="#subject-trend" type="button" role="tab" aria-controls="subject-trend" aria-selected="<?php echo $selectedSubjectIdForTrend ? 'true' : 'false'; ?>">Track Subject (Across Terms)</button>
                </li>
            </ul>

            <div class="tab-content" id="analysisTypeTabContent">
                <!-- Tab 1: Compare Subjects in a Single Term -->
                <div class="tab-pane fade <?php echo ($selectedBatchId || (!$selectedBatchId && !$selectedSubjectIdForTrend)) ? 'show active' : ''; ?>" id="compare-subjects" role="tabpanel" aria-labelledby="compare-subjects-tab">
                    <form method="GET" action="comparative_analysis.php" class="form-section">
                        <input type="hidden" name="student_id" value="<?php echo $selectedStudentId; ?>">
                        <h4>1. Compare Subject Performance in a Specific Term</h4>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label for="batch_id_select" class="form-label">Select Term/Batch:</label>
                                <select name="batch_id" id="batch_id_select" class="form-select" required>
                                    <option value="">-- Select Term/Batch --</option>
                                    <?php foreach ($processedBatchesForStudent as $batchOption): ?>
                                        <option value="<?php echo $batchOption['batch_id']; ?>" <?php if ($selectedBatchId == $batchOption['batch_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($batchOption['class_name'] . " - " . $batchOption['year_name'] . " Term " . $batchOption['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-info w-100"><i class="fas fa-chart-bar"></i> Analyze Batch</button>
                            </div>
                        </div>
                    </form>

                    <?php if ($selectedBatchId && $batchDetails && !empty($comparisonDataSingleBatch)): ?>
                        <h4 class="mt-4">Performance in <?php echo htmlspecialchars($batchDetails['class_name'] . " - " . $batchDetails['year_name'] . " Term " . $batchDetails['term_name']); ?></h4>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Subject</th>
                                        <th>B.O.T Score</th>
                                        <th>B.O.T Grade</th>
                                        <th>M.O.T Score</th>
                                        <th>M.O.T Grade</th>
                                        <th>E.O.T Score</th>
                                        <th>E.O.T Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $subjectNamesForChart = [];
                                    $eotScoresForChart = [];
                                    foreach ($comparisonDataSingleBatch as $data):
                                        $subjectNamesForChart[] = htmlspecialchars($data['subject_name_full']);
                                        $eotScoresForChart[] = is_numeric($data['eot_score']) ? (float)$data['eot_score'] : null;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($data['subject_name_full']); ?></td>
                                        <td><?php echo htmlspecialchars($data['bot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['bot_grade'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['mot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['mot_grade'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['eot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['eot_grade'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if(count(array_filter($eotScoresForChart, 'is_numeric')) > 0) : // Only show chart if there's data ?>
                        <div class="chart-container">
                            <canvas id="eotScoresChartSingleBatch"></canvas>
                        </div>
                        <?php endif; ?>
                    <?php elseif($selectedBatchId): ?>
                         <div class="alert alert-info mt-3">No detailed subject scores found for this student in the selected batch.</div>
                    <?php endif; ?>
                </div>

                <!-- Tab 2: Track a Single Subject Across Terms -->
                <div class="tab-pane fade <?php echo $selectedSubjectIdForTrend ? 'show active' : ''; ?>" id="subject-trend" role="tabpanel" aria-labelledby="subject-trend-tab">
                    <form method="GET" action="comparative_analysis.php" class="form-section">
                        <input type="hidden" name="student_id" value="<?php echo $selectedStudentId; ?>">
                        <h4>2. Track Performance in a Single Subject Across Terms</h4>
                         <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label for="subject_id_trend_select" class="form-label">Select Subject:</label>
                                <select name="subject_id_trend" id="subject_id_trend_select" class="form-select" required>
                                    <option value="">-- Select Subject --</option>
                                    <?php foreach ($subjectsForStudentOverall as $subjectOption): ?>
                                        <option value="<?php echo $subjectOption['subject_id']; ?>" <?php if ($selectedSubjectIdForTrend == $subjectOption['subject_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($subjectOption['subject_name_full'] . ' (' . $subjectOption['subject_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-info w-100"><i class="fas fa-chart-line"></i> Analyze Subject Trend</button>
                            </div>
                        </div>
                    </form>

                    <?php if ($selectedSubjectIdForTrend && $subjectDetailsForTrend && !empty($subjectTrendData)): ?>
                        <h4 class="mt-4">Trend for <?php echo htmlspecialchars($subjectDetailsForTrend['subject_name_full']); ?> (<?php echo htmlspecialchars($subjectDetailsForTrend['subject_code']); ?>)</h4>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Year</th>
                                        <th>Term</th>
                                        <th>Class</th>
                                        <th>B.O.T Score</th>
                                        <th>M.O.T Score</th>
                                        <th>E.O.T Score</th>
                                        <th>E.O.T Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $termLabelsForTrendChart = [];
                                    $eotScoresForTrendChart = [];
                                    foreach ($subjectTrendData as $data):
                                        $termLabelsForTrendChart[] = $data['year_name'] . ' T' . $data['term_name'] . ' (' . $data['class_name'] . ')';
                                        $eotScoresForTrendChart[] = is_numeric($data['eot_score']) ? (float)$data['eot_score'] : null;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($data['year_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['term_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($data['bot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['mot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['eot_score'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($data['eot_grade'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                         <?php if(count(array_filter($eotScoresForTrendChart, 'is_numeric')) > 1) : // Only show chart if there's data for a trend ?>
                        <div class="chart-container">
                            <canvas id="subjectEotScoresTrendChart"></canvas>
                        </div>
                        <?php endif; ?>
                    <?php elseif($selectedSubjectIdForTrend): ?>
                        <div class="alert alert-info mt-3">No performance data found for the selected subject across terms for this student.</div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif (!$selectedStudentId): ?>
            <div class="alert alert-info mt-4">Please select a student to begin comparative analysis.</div>
        <?php endif; ?>
    </div>

    <footer class="mt-auto py-3 bg-light text-center">
        <div class="container">
            <span class="text-muted">&copy; 2025 Maria Ow'embabazi Primary School - Good Christian, Good Citizen</span>
        </div>
    </footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Chart for Single Batch EOT Scores
    <?php if ($selectedBatchId && !empty($comparisonDataSingleBatch) && count(array_filter($eotScoresForChart, 'is_numeric')) > 0): ?>
    const eotScoresCtx = document.getElementById('eotScoresChartSingleBatch');
    if (eotScoresCtx) {
        const subjectNames = <?php echo json_encode($subjectNamesForChart); ?>;
        const eotScores = <?php echo json_encode($eotScoresForChart); ?>;
        new Chart(eotScoresCtx, {
            type: 'bar',
            data: {
                labels: subjectNames,
                datasets: [{
                    label: 'End of Term Scores (%)',
                    data: eotScores,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, suggestedMax: 100 } },
                plugins: { legend: { display: true } }
            }
        });
    }
    <?php endif; ?>

    // Chart for Subject EOT Score Trend Across Terms
    <?php if ($selectedSubjectIdForTrend && !empty($subjectTrendData) && count(array_filter($eotScoresForTrendChart, 'is_numeric')) > 1): ?>
    const trendCtx = document.getElementById('subjectEotScoresTrendChart');
    if (trendCtx) {
        const termLabels = <?php echo json_encode($termLabelsForTrendChart); ?>;
        const trendScores = <?php echo json_encode($eotScoresForTrendChart); ?>;
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: termLabels,
                datasets: [{
                    label: 'EOT Score for <?php echo htmlspecialchars($subjectDetailsForTrend['subject_name_full'] ?? 'Selected Subject'); ?> (%)',
                    data: trendScores,
                    borderColor: 'rgb(255, 159, 64)',
                    backgroundColor: 'rgba(255, 159, 64, 0.2)',
                    fill: false,
                    tension: 0.1,
                    spanGaps: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: false, suggestedMax: 100, suggestedMin: 0 } },
                plugins: { legend: { display: true } }
            }
        });
    }
    <?php endif; ?>

    // Preserve active tab on form submission (if forms were POST, this would be easier)
    // For GET, we can check URL parameters or which data is loaded.
    // Simplified: if subject_id_trend is set, make that tab active. Otherwise, the first.
    <?php if ($selectedSubjectIdForTrend): ?>
        const subjectTrendTab = document.getElementById('subject-trend-tab');
        if (subjectTrendTab) new bootstrap.Tab(subjectTrendTab).show();
    <?php elseif ($selectedBatchId || (!$selectedBatchId && !$selectedSubjectIdForTrend && $selectedStudentId)): ?>
        const compareSubjectsTab = document.getElementById('compare-subjects-tab');
        if (compareSubjectsTab) new bootstrap.Tab(compareSubjectsTab).show();
    <?php endif; ?>
});
</script>
</body>
</html>
