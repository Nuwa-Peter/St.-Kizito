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
require_once 'calculation_utils.php'; // For potential grade calculations if needed

$selectedStudentId = null;
$studentHistoricalData = [];
$studentDetails = null;
$allStudentsWithProcessedData = []; // To populate student selection dropdown

// Fetch all students who have at least one entry in student_report_summary
// This is an approximation. A more precise list might query students who appear in `student_report_summary`.
// For simplicity, let's get all students. Admins can search.
// A better approach for large schools would be a search-as-you-type input.
try {
    // Get all students who have entries in student_report_summary table
    $stmt = $pdo->query(
        "SELECT DISTINCT s.id, s.student_name, s.lin_no
         FROM students s
         JOIN student_report_summary srs ON s.id = srs.student_id
         ORDER BY s.student_name ASC"
    );
    $allStudentsWithProcessedData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching student list: " . $e->getMessage();
    // Log error
}


if (isset($_GET['student_id']) && filter_var($_GET['student_id'], FILTER_VALIDATE_INT)) {
    $selectedStudentId = (int)$_GET['student_id'];
    $studentHistoricalData = getStudentHistoricalPerformance($pdo, $selectedStudentId);

    if (!empty($studentHistoricalData)) {
        // Fetch student's current name and LIN for display using the first record (any record would do)
        // Or, more robustly, fetch directly from students table
        $stmtStudent = $pdo->prepare("SELECT student_name, lin_no FROM students WHERE id = :student_id");
        $stmtStudent->execute([':student_id' => $selectedStudentId]);
        $studentDetails = $stmtStudent->fetch(PDO::FETCH_ASSOC);
    } else {
        // Check if student exists but has no data, or if student ID is invalid
        $stmtCheckStudent = $pdo->prepare("SELECT id FROM students WHERE id = :student_id");
        $stmtCheckStudent->execute([':student_id' => $selectedStudentId]);
        if (!$stmtCheckStudent->fetch()) {
            $_SESSION['info_message'] = "No student found with ID: " . htmlspecialchars($selectedStudentId) . ".";
            $selectedStudentId = null; // Reset if student not found
        } else {
             $_SESSION['info_message'] = "No historical performance data found for the selected student.";
        }
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
    <title>Historical Performance Tracking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container.main-content { background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 20px; }
        /* .table .table-dark th rule removed as table-light is now used */
        .student-info-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #dee2e6;}
        .chart-container { max-height: 400px; margin-bottom: 2rem; }
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
        <h2 class="text-center mb-4">Student Historical Performance</h2>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info"><?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
        <?php endif; ?>

        <form method="GET" action="historical_performance.php" class="row g-3 align-items-end mb-4">
            <div class="col-md-6">
                <label for="student_id_select" class="form-label">Select Student:</label>
                <select name="student_id" id="student_id_select" class="form-select" required>
                    <option value="">-- Select a Student --</option>
                    <?php foreach ($allStudentsWithProcessedData as $studentOption): ?>
                        <option value="<?php echo $studentOption['id']; ?>" <?php if ($selectedStudentId == $studentOption['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($studentOption['student_name'] . ($studentOption['lin_no'] ? ' (LIN: ' . $studentOption['lin_no'] . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> View History</button>
            </div>
        </form>
        <hr>

        <?php if ($selectedStudentId && $studentDetails && !empty($studentHistoricalData)): ?>
            <div class="student-info-header">
                <h3><?php echo htmlspecialchars(strtoupper($studentDetails['student_name'])); ?></h3>
                <?php if ($studentDetails['lin_no']): ?>
                    <p class="lead">LIN: <?php echo htmlspecialchars($studentDetails['lin_no']); ?></p>
                <?php endif; ?>
            </div>

            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover table-bordered">
                    <thead class="table-light"> <!-- Changed from table-dark to table-light -->
                        <tr>
                            <th>Academic Year</th>
                            <th>Term</th>
                            <th>Class</th>
                            <th>Avg. Score (P1-P3)</th>
                            <th>Total Score (P1-P3)</th>
                            <th>Position (P1-P3)</th>
                            <th>Aggregates (P4-P7)</th>
                            <th>Division (P4-P7)</th>
                            <th>Class Teacher's Remark</th>
                            <th>Head Teacher's Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $p1p3_avg_scores_for_chart = [];
                        $p4p7_aggregates_for_chart = [];
                        $chart_labels = [];

                        foreach ($studentHistoricalData as $record):
                            $isP1P3 = in_array($record['class_name'], ['P1', 'P2', 'P3']);
                            $isP4P7 = in_array($record['class_name'], ['P4', 'P5', 'P6', 'P7']);
                            $chart_labels[] = $record['year_name'] . ' T' . $record['term_name'] . ' (' . $record['class_name'] . ')';

                            if ($isP1P3) {
                                $p1p3_avg_scores_for_chart[] = is_numeric($record['p1p3_average_eot_score']) ? (float)$record['p1p3_average_eot_score'] : null;
                                $p4p7_aggregates_for_chart[] = null; // No aggregate for P1-P3
                            } elseif ($isP4P7) {
                                $p4p7_aggregates_for_chart[] = is_numeric($record['p4p7_aggregate_points']) ? (int)$record['p4p7_aggregate_points'] : null;
                                $p1p3_avg_scores_for_chart[] = null; // No average score focus for P4-P7 chart in this context
                            } else {
                                $p1p3_avg_scores_for_chart[] = null;
                                $p4p7_aggregates_for_chart[] = null;
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($record['year_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['term_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                <td><?php echo $isP1P3 ? htmlspecialchars($record['p1p3_average_eot_score'] ?? 'N/A') : 'N/A'; ?></td>
                                <td><?php echo $isP1P3 ? htmlspecialchars($record['p1p3_total_eot_score'] ?? 'N/A') : 'N/A'; ?></td>
                                <td>
                                    <?php
                                    if ($isP1P3) {
                                        echo htmlspecialchars($record['p1p3_position_in_class'] ?? 'N/A');
                                        if (!empty($record['p1p3_total_students_in_class'])) {
                                            echo ' / ' . htmlspecialchars($record['p1p3_total_students_in_class']);
                                        }
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td><?php echo $isP4P7 ? htmlspecialchars($record['p4p7_aggregate_points'] ?? 'N/A') : 'N/A'; ?></td>
                                <td><?php echo $isP4P7 ? htmlspecialchars($record['p4p7_division'] ?? 'N/A') : 'N/A'; ?></td>
                                <td style="font-size: 0.85em;"><?php echo nl2br(htmlspecialchars($record['auto_classteachers_remark_text'] ?? 'N/A')); ?></td>
                                <td style="font-size: 0.85em;"><?php echo nl2br(htmlspecialchars($record['auto_headteachers_remark_text'] ?? 'N/A')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
                // Prepare chart data - filter out terms where student might not have relevant data
                $validP1P3DataForChart = array_filter($p1p3_avg_scores_for_chart, function($value) { return $value !== null; });
                $validP4P7DataForChart = array_filter($p4p7_aggregates_for_chart, function($value) { return $value !== null; });
            ?>

            <?php if (count($validP1P3DataForChart) > 1): ?>
            <div class="chart-container">
                <h5>P1-P3 Average Score Trend</h5>
                <canvas id="p1p3AvgScoreChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if (count($validP4P7DataForChart) > 1): ?>
            <div class="chart-container">
                <h5>P4-P7 Aggregate Points Trend</h5>
                <canvas id="p4p7AggregateChart"></canvas>
            </div>
            <?php endif; ?>


        <?php elseif ($selectedStudentId && (empty($studentHistoricalData) || !$studentDetails)): ?>
            <div class="alert alert-warning mt-4">
                <?php
                    if (!$studentDetails) echo "Could not retrieve details for the selected student.";
                    // The "No historical data" message is handled by session info_message at the top.
                ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info mt-4">Please select a student to view their historical performance.</div>
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
    const chartLabels = <?php echo json_encode($chart_labels ?? []); ?>;

    <?php if (!empty($validP1P3DataForChart) && count($validP1P3DataForChart) > 1): ?>
    const p1p3AvgScores = <?php echo json_encode(array_values($p1p3_avg_scores_for_chart)); ?>;
    // Filter labels for P1-P3 chart to match available data points
    const p1p3ChartLabels = chartLabels.filter((_, index) => <?php echo json_encode($p1p3_avg_scores_for_chart); ?>[index] !== null);
    const p1p3DataPoints = p1p3AvgScores.filter(value => value !== null);

    if (p1p3DataPoints.length > 1) {
        const p1p3Ctx = document.getElementById('p1p3AvgScoreChart');
        if (p1p3Ctx) {
            new Chart(p1p3Ctx, {
                type: 'line',
                data: {
                    labels: p1p3ChartLabels,
                    datasets: [{
                        label: 'P1-P3 Average EOT Score (%)',
                        data: p1p3DataPoints,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        fill: false,
                        tension: 0.1,
                        spanGaps: true // Connect lines even if some data points are null in the original full array
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: false, // Scores might not start at 0
                            suggestedMin: 40,
                            suggestedMax: 100
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += context.parsed.y.toFixed(2) + '%';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    <?php endif; ?>

    <?php if (!empty($validP4P7DataForChart) && count($validP4P7DataForChart) > 1): ?>
    const p4p7Aggregates = <?php echo json_encode(array_values($p4p7_aggregates_for_chart)); ?>;
    // Filter labels for P4-P7 chart
    const p4p7ChartLabels = chartLabels.filter((_, index) => <?php echo json_encode($p4p7_aggregates_for_chart); ?>[index] !== null);
    const p4p7DataPoints = p4p7Aggregates.filter(value => value !== null);

    if (p4p7DataPoints.length > 1) {
        const p4p7Ctx = document.getElementById('p4p7AggregateChart');
        if (p4p7Ctx) {
            new Chart(p4p7Ctx, {
                type: 'line',
                data: {
                    labels: p4p7ChartLabels,
                    datasets: [{
                        label: 'P4-P7 Aggregate Points',
                        data: p4p7DataPoints,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        fill: false,
                        tension: 0.1,
                        spanGaps: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { // Aggregates are better when lower
                            reverse: true,
                            beginAtZero: false, // Aggregates don't start at 0
                            suggestedMin: 4, // Best possible aggregate
                            // suggestedMax: 36 // Worst typical aggregate for Div U
                        }
                    },
                     plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += context.parsed.y;
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    <?php endif; ?>
});
</script>
</body>
</html>
