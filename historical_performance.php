<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('STKIZITO_SESSION');
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
         JOIN student_term_summaries srs ON s.id = srs.student_id -- Renamed table
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

// $subjectDisplayNames array is not used in this file, can be removed.

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
    <nav class="navbar navbar-themed sticky-top shadow-sm"> <!-- Applied navbar-themed -->
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="images/logo.png" alt="Logo" width="30" height="30" class="d-inline-block align-text-top me-2">
                ST KIZITO PREPARATORY SEMINARY RWEBISHURI - Report System
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
                    <thead class="table-light">
                        <tr>
                            <th>Academic Year</th>
                            <th>Term</th>
                            <th>Class</th>
                            <th>Aggregate</th>
                            <th>Division</th>
                            <th>Average Score (%)</th>
                            <th>Position</th>
                            <th>Class Teacher's Remark</th>
                            <th>Head Teacher's Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Variables for chart data (P5-P7 uses aggregates and average scores)
                        $aggregate_points_for_chart = [];
                        $average_scores_for_chart = [];
                        $chart_labels = [];

                        foreach ($studentHistoricalData as $record):
                            // All records are now for P5-P7 like classes
                            $chart_labels[] = $record['year_name'] . ' T' . $record['term_name'] . ' (' . $record['class_name'] . ')';
                            $aggregate_points_for_chart[] = is_numeric($record['aggregate_points']) ? (int)$record['aggregate_points'] : null;
                            $average_scores_for_chart[] = is_numeric($record['average_score']) ? (float)$record['average_score'] : null;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($record['year_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['term_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['aggregate_points'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($record['division'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($record['average_score'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php
                                        echo htmlspecialchars($record['position_in_class'] ?? 'N/A');
                                        if (!empty($record['total_students_in_class'])) {
                                            echo ' / ' . htmlspecialchars($record['total_students_in_class']);
                                        }
                                    ?>
                                </td>
                                <td style="font-size: 0.85em;"><?php echo nl2br(htmlspecialchars($record['class_teacher_remark'] ?? 'N/A')); ?></td>
                                <td style="font-size: 0.85em;"><?php echo nl2br(htmlspecialchars($record['head_teacher_remark'] ?? 'N/A')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
                // Prepare chart data
                $validAggregateDataForChart = array_filter($aggregate_points_for_chart, function($value) { return $value !== null; });
                $validAverageScoreDataForChart = array_filter($average_scores_for_chart, function($value) { return $value !== null; });
            ?>

            <?php if (count($validAverageScoreDataForChart) > 1): ?>
            <div class="chart-container">
                <h5>Overall Average Score Trend (%)</h5>
                <canvas id="averageScoreChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if (count($validAggregateDataForChart) > 1): ?>
            <div class="chart-container">
                <h5>Aggregate Points Trend</h5>
                <canvas id="aggregatePointsChart"></canvas>
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
            <span class="text-muted">&copy; <?php echo date('Y'); ?> ST KIZITO PREPARATORY SEMINARY RWEBISHURI - MANE NOBISCUM DOMINE</span>
        </div>
    </footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartLabels = <?php echo json_encode($chart_labels ?? []); ?>;

    <?php if (!empty($validAverageScoreDataForChart) && count($validAverageScoreDataForChart) > 1): ?>
    const averageScores = <?php echo json_encode(array_values($average_scores_for_chart)); ?>;
    const averageScoreChartLabels = chartLabels.filter((_, index) => <?php echo json_encode($average_scores_for_chart); ?>[index] !== null);
    const averageScoreDataPoints = averageScores.filter(value => value !== null);

    if (averageScoreDataPoints.length > 1) {
        const avgScoreCtx = document.getElementById('averageScoreChart');
        if (avgScoreCtx) {
            new Chart(avgScoreCtx, {
                type: 'line',
                data: {
                    labels: averageScoreChartLabels,
                    datasets: [{
                        label: 'Overall Average Score (%)',
                        data: averageScoreDataPoints,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        fill: false,
                        tension: 0.1,
                        spanGaps: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: false,
                            suggestedMin: 40,
                            suggestedMax: 100
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.y !== null) { label += context.parsed.y.toFixed(2) + '%'; }
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

    <?php if (!empty($validAggregateDataForChart) && count($validAggregateDataForChart) > 1): ?>
    const aggregatePoints = <?php echo json_encode(array_values($aggregate_points_for_chart)); ?>;
    const aggregatePointsChartLabels = chartLabels.filter((_, index) => <?php echo json_encode($aggregate_points_for_chart); ?>[index] !== null);
    const aggregateDataPoints = aggregatePoints.filter(value => value !== null);

    if (aggregateDataPoints.length > 1) {
        const aggPointsCtx = document.getElementById('aggregatePointsChart');
        if (aggPointsCtx) {
            new Chart(aggPointsCtx, {
                type: 'line',
                data: {
                    labels: aggregatePointsChartLabels,
                    datasets: [{
                        label: 'Aggregate Points',
                        data: aggregateDataPoints,
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
                        y: {
                            reverse: true, // Lower aggregates are better
                            beginAtZero: false,
                            suggestedMin: 4,
                        }
                    },
                     plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.y !== null) { label += context.parsed.y; }
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
