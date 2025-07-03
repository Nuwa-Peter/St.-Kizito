<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Kampala');

// Strict Superadmin Access Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    $_SESSION['error_message'] = "Access Denied. You do not have permission to view this page.";
    header('Location: index.php'); // Redirect to dashboard or a general access denied page
    exit;
}

require_once 'db_connection.php';
require_once 'dal.php';

$pageTitle = "Global Activity Log";

// Pagination settings
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$itemsPerPage = 25; // Number of log entries per page
if ($currentPage < 1) {
    $currentPage = 1;
}
$offset = ($currentPage - 1) * $itemsPerPage;

// Fetch logs and total count
$totalLogs = 0;
$activityLogs = [];
$totalPages = 0;

try {
    $totalLogs = getGlobalActivityLogCount($pdo);
    if ($totalLogs > 0) {
        $activityLogs = getGlobalActivityLog($pdo, $itemsPerPage, $offset);
    }
    $totalPages = ceil($totalLogs / $itemsPerPage);
    if ($currentPage > $totalPages && $totalLogs > 0) { // If requested page is out of bounds
        $currentPage = $totalPages; // Go to last valid page
        $offset = ($currentPage - 1) * $itemsPerPage;
        $activityLogs = getGlobalActivityLog($pdo, $itemsPerPage, $offset); // Re-fetch for the last page
    } elseif ($currentPage < 1 && $totalLogs > 0) { // Should be caught by earlier check, but defensive
        $currentPage = 1;
        $offset = 0;
        $activityLogs = getGlobalActivityLog($pdo, $itemsPerPage, $offset);
    }


} catch (Exception $e) {
    error_log("Error fetching activity log: " . $e->getMessage());
    $_SESSION['error_message'] = "Could not retrieve activity logs due to a system error.";
    // Optionally redirect or display error on page
}

// Handle Log Deletion Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $deletedCount = -1;
        $logMessage = "";

        if ($action === 'delete_all_logs') {
            if (isset($_POST['confirm_delete_all'])) { // Check if confirmation was given
                $deletedCount = deleteActivityLogs($pdo, null);
                $logMessage = "Deleted all activity logs ($deletedCount entries).";
                $_SESSION['success_message'] = "All activity logs have been deleted ($deletedCount entries).";
            } else {
                $_SESSION['error_message'] = "Deletion not confirmed.";
            }
        } elseif ($action === 'delete_logs_older_than' && isset($_POST['older_than_date'])) {
            $olderThanDate = trim($_POST['older_than_date']);
            if (!empty($olderThanDate)) {
                // Validate date format if necessary, though SQL might handle some variations.
                // For simplicity, assuming YYYY-MM-DD format from date input. Append time for full day.
                $olderThanTimestamp = $olderThanDate . " 00:00:00";
                $deletedCount = deleteActivityLogs($pdo, $olderThanTimestamp);
                $logMessage = "Deleted activity logs older than $olderThanDate ($deletedCount entries).";
                $_SESSION['success_message'] = "Activity logs older than $olderThanDate have been deleted ($deletedCount entries).";
            } else {
                $_SESSION['error_message'] = "Date for 'older than' deletion not provided.";
            }
        }

        if ($deletedCount > -1) { // If a delete action was attempted (successfully or 0 rows)
            logActivity(
                $pdo,
                $_SESSION['user_id'],
                $_SESSION['username'],
                'LOGS_DELETED',
                $logMessage,
                null,
                null,
                null
            );
        }
        // Redirect to the same page to show messages and refresh log view (also clears POST)
        header("Location: view_activity_log.php" . ($currentPage > 1 ? "?page=$currentPage" : ""));
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Report System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <style>
        body { background-color: #e0f7fa; }
        .container.main-content {
            background-color: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 20px;
            margin-bottom: 30px;
        }
        .table th, .table td {
            font-size: 0.875rem; /* Smaller font for table content */
            vertical-align: middle;
        }
        .table td.description-cell {
            max-width: 400px; /* Limit width of description */
            white-space: normal; /* Allow text to wrap */
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-light bg-light sticky-top shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="images/logo.png" alt="Logo" width="30" height="30" class="d-inline-block align-text-top me-2" onerror="this.style.display='none';">
                Maria Ow'embabazi P/S - Report System
            </a>
             <div class="ms-auto d-flex align-items-center">
                <?php if (isset($_SESSION['username'])): ?>
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-dark" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-shield me-1"></i> <?php echo htmlspecialchars($_SESSION['username']); ?> (Superadmin)
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="index.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Sign Out</a></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0"><?php echo htmlspecialchars($pageTitle); ?></h2>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">Log Management</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <h5>Clear All Logs</h5>
                        <p><small class="text-muted">This will permanently delete all activity log entries.</small></p>
                        <form method="POST" action="view_activity_log.php" onsubmit="return confirm('DANGER! Are you absolutely sure you want to delete ALL activity logs? This action cannot be undone.');">
                            <input type="hidden" name="action" value="delete_all_logs">
                            <input type="hidden" name="confirm_delete_all" value="yes">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash-alt"></i> Clear All Logs
                            </button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <h5>Clear Logs Older Than</h5>
                        <form method="POST" action="view_activity_log.php" class="row g-2 align-items-end" onsubmit="return confirm('Are you sure you want to delete logs older than the selected date? This action cannot be undone.');">
                            <input type="hidden" name="action" value="delete_logs_older_than">
                            <div class="col-auto">
                                <label for="older_than_date" class="form-labelvisually-hidden">Date:</label>
                                <input type="date" class="form-control form-control-sm" id="older_than_date" name="older_than_date" required
                                       max="<?php echo date('Y-m-d'); // Prevent selecting future dates ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <i class="fas fa-calendar-times"></i> Clear Older Logs
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>


        <div class="table-responsive">
            <table class="table table-striped table-hover table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Timestamp</th>
                        <th>Username</th>
                        <th>Action Type</th>
                        <th style="width: 40%;">Description</th>
                        <th>Entity Type</th>
                        <th>Entity ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($activityLogs)): ?>
                        <?php foreach ($activityLogs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['id']); ?></td>
                                <td><?php echo htmlspecialchars(date('d M Y, H:i:s', strtotime($log['timestamp']))); ?></td>
                                <td><?php echo htmlspecialchars($log['username']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($log['action_type']); ?></span></td>
                                <td class="description-cell"><?php echo nl2br(htmlspecialchars($log['description'])); ?></td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($log['entity_id'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No activity logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Activity Log Pagination">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ($currentPage <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $currentPage - 1; ?>">Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php // Display a limited number of page links
                        if ($i == 1 || $i == $totalPages || ($i >= $currentPage - 2 && $i <= $currentPage + 2)): ?>
                            <li class="page-item <?php echo ($i == $currentPage) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php elseif ($i == $currentPage - 3 || $i == $currentPage + 3): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($currentPage >= $totalPages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $currentPage + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

    </div>

    <footer class="text-center mt-auto py-3 bg-light">
        <p class="mb-0">&copy; <?php echo date('Y'); ?> Maria Ow'embabazi Primary School - <i>Good Christian, Good Citizen</i></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
