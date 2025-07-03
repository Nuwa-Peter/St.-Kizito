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
require_once 'db_connection.php'; // Provides $pdo
require_once 'dal.php';         // Provides DAL functions (getAllProcessedBatches)

// Filtering logic
$filter_year_id = isset($_GET['filter_year_id']) && $_GET['filter_year_id'] !== '' ? (int)$_GET['filter_year_id'] : null;
$filter_term_id = isset($_GET['filter_term_id']) && $_GET['filter_term_id'] !== '' ? (int)$_GET['filter_term_id'] : null;
$filter_class_id = isset($_GET['filter_class_id']) && $_GET['filter_class_id'] !== '' ? (int)$_GET['filter_class_id'] : null;

// Prepare lists for dropdowns
$years = [];
$terms = [];
$classes = [];

try {
    $stmt_years = $pdo->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC");
    $years = $stmt_years->fetchAll();
    $stmt_terms = $pdo->query("SELECT id, term_name FROM terms ORDER BY term_name ASC");
    $terms = $stmt_terms->fetchAll();
    $stmt_classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
    $classes = $stmt_classes->fetchAll();

    // Build WHERE clause for filtering batches
    $whereClauses = [];
    $executeParams = [];
    if ($filter_year_id !== null) {
        $whereClauses[] = "rbs.academic_year_id = :year_id";
        $executeParams[':year_id'] = $filter_year_id;
    }
    if ($filter_term_id !== null) {
        $whereClauses[] = "rbs.term_id = :term_id";
        $executeParams[':term_id'] = $filter_term_id;
    }
    if ($filter_class_id !== null) {
        $whereClauses[] = "rbs.class_id = :class_id";
        $executeParams[':class_id'] = $filter_class_id;
    }

    $sqlBatches = "SELECT rbs.id as batch_id, c.class_name, ay.year_name, t.term_name, rbs.import_date
                     FROM report_batch_settings rbs
                     JOIN classes c ON rbs.class_id = c.id
                     JOIN academic_years ay ON rbs.academic_year_id = ay.id
                     JOIN terms t ON rbs.term_id = t.id";
    if (!empty($whereClauses)) {
        $sqlBatches .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $sqlBatches .= " ORDER BY rbs.import_date DESC, ay.year_name DESC, t.term_name ASC, c.class_name ASC";

    $stmtFilteredBatches = $pdo->prepare($sqlBatches);
    $stmtFilteredBatches->execute($executeParams);
    $filteredBatches = $stmtFilteredBatches->fetchAll();

} catch (PDOException $e) {
    // Log error and/or set a user-friendly message
    error_log("Error fetching data for report archives: " . $e->getMessage());
    $_SESSION['error_message'] = "A database error occurred while retrieving report archives.";
    $filteredBatches = []; // Ensure it's an empty array on error
    // Optionally redirect or display error more prominently
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report Archives - Maria Ow'embabazi P/S</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <style>
        body { background-color: #e0f7fa; }
        .container.main-content { background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 20px; margin-bottom: 30px; }
        .filter-form { margin-bottom: 2rem; padding: 1.5rem; background-color: #f8f9fa; border-radius: 0.5rem; }
        .action-buttons a { margin-right: 5px; margin-bottom: 5px; } /* For small screens */
    </style>
</head>
<body>
    <nav class="navbar navbar-light bg-light sticky-top shadow-sm">
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
            <h2>View Report Archives</h2>
            <p>Search and view previously processed report batches.</p>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
        <?php endif; ?>

        <form method="GET" action="view_report_archives.php" class="row g-3 filter-form align-items-end">
            <div class="col-md-3">
                <label for="filter_year_id" class="form-label">Year:</label>
                <select name="filter_year_id" id="filter_year_id" class="form-select">
                    <option value="">All Years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?php echo $year['id']; ?>" <?php if ($filter_year_id == $year['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($year['year_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filter_term_id" class="form-label">Term:</label>
                <select name="filter_term_id" id="filter_term_id" class="form-select">
                    <option value="">All Terms</option>
                     <?php foreach ($terms as $term): ?>
                        <option value="<?php echo $term['id']; ?>" <?php if ($filter_term_id == $term['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($term['term_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filter_class_id" class="form-label">Class:</label>
                <select name="filter_class_id" id="filter_class_id" class="form-select">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php if ($filter_class_id == $class['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Filter</button>
            </div>
             <div class="col-md-1 d-flex align-items-end">
                <a href="view_report_archives.php" class="btn btn-outline-secondary w-100" title="Clear Filters"><i class="fas fa-times"></i></a>
            </div>
        </form>

        <?php if (!empty($filteredBatches)): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Batch ID</th><th>Class</th><th>Year</th><th>Term</th><th>Imported On</th><th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredBatches as $batch): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($batch['batch_id']); ?></td>
                                <td><?php echo htmlspecialchars($batch['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($batch['year_name']); ?></td>
                                <td><?php echo htmlspecialchars($batch['term_name']); ?></td>
                                <td><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($batch['import_date']))); ?></td>
                                <td class="text-center action-buttons">
                                    <a href="view_processed_data.php?batch_id=<?php echo $batch['batch_id']; ?>" class="btn btn-sm btn-outline-info" title="View Raw Data & Run Calculations"><i class="fas fa-cogs"></i> Process/View Data</a>
                                    <a href="generate_pdf.php?batch_id=<?php echo $batch['batch_id']; ?>&output_mode=I" class="btn btn-sm btn-outline-warning" title="View PDF Report" target="_blank"><i class="fas fa-file-alt"></i> View PDF</a>
                                    <a href="generate_pdf.php?batch_id=<?php echo $batch['batch_id']; ?>&output_mode=D" class="btn btn-sm btn-outline-danger" title="Download PDF Report" target="_blank"><i class="fas fa-file-pdf"></i> Download PDF</a>
                                    <a href="summary_sheet.php?batch_id=<?php echo $batch['batch_id']; ?>" class="btn btn-sm btn-outline-success" title="View Summary Sheet" target="_blank"><i class="fas fa-chart-bar"></i> Summary</a>
                                    <form method="POST" action="delete_batch.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this entire batch (ID: <?php echo htmlspecialchars($batch['batch_id']); ?>) and all its associated data? This action cannot be undone.');">
                                        <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch['batch_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete Batch">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <?php if ($filter_year_id || $filter_term_id || $filter_class_id): ?>
                    No processed report batches found matching your filter criteria. <a href="view_report_archives.php">Clear filters</a> or <a href="data_entry.php">import new data</a>.
                <?php else: ?>
                    No processed report batches found in the system yet. You can <a href="data_entry.php">import new data</a>.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <footer class="text-center mt-5 mb-3 p-3 non-printable" style="background-color: #f8f9fa;">
        <p>&copy; <?php echo date('Y'); ?> Maria Ow'embabazi Primary School - <i>Good Christian, Good Citizen</i></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
