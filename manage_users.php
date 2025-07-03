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

// Role check: Ensure only 'superadmin' can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    $_SESSION['error_message'] = "You do not have permission to access this page.";
    header('Location: index.php'); // Redirect to dashboard or an error page
    exit;
}

require_once 'db_connection.php'; // For database interaction

// Handle Admin Password Reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_admin_password') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword) || empty($confirmPassword)) {
        $_SESSION['error_message'] = "Both password fields are required.";
    } elseif (strlen($newPassword) < 8) {
        $_SESSION['error_message'] = "Password must be at least 8 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $_SESSION['error_message'] = "Passwords do not match.";
    } else {
        // All checks passed, proceed to update password
        $adminEmail = 'houseofnazareth.schools@gmail.com'; // Target admin user
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE email = :email AND role = 'admin'");
            $stmt->execute([':password_hash' => $newPasswordHash, ':email' => $adminEmail]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['success_message'] = "Admin password has been successfully reset.";
            } else {
                // This could mean the admin account with that email/role wasn't found, or password is the same (less likely due to hash)
                $_SESSION['error_message'] = "Could not reset admin password. User not found or no changes made.";
            }
        } catch (PDOException $e) {
            error_log("Admin Password Reset Error: " . $e->getMessage());
            $_SESSION['error_message'] = "A database error occurred while resetting the password. Please try again.";
        }
    }
    // Redirect to the same page to show messages and clear POST data
    header("Location: manage_users.php");
    exit;
}

$pageTitle = "Manage Users - Report System";
$pageUsername = $_SESSION['username'] ?? 'User'; // For navbar display
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Maria Ow'embabazi P/S</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="css/style.css" rel="stylesheet"> <!-- Assuming a general style.css -->
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
    </style>
</head>
<body>
    <nav class="navbar navbar-light bg-light sticky-top shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="images/logo.png" alt="Logo" width="30" height="30" class="d-inline-block align-text-top me-2" onerror="this.style.display='none';">
                Maria Ow'embabazi P/S - Report System
            </a>
            <!-- User Dropdown (similar to index.php but might show different options or just logout) -->
            <div class="ms-auto d-flex align-items-center">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-dark" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-shield me-1"></i> <?php echo htmlspecialchars($pageUsername); ?> (Superadmin)
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="index.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Sign Out</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">User Management</h2>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                Reset Admin User Password
            </div>
            <div class="card-body">
                <p>Here you can reset the password for the main Admin account (associated with email: <code>houseofnazareth.schools@gmail.com</code>).</p>
                <form action="manage_users.php" method="POST" class="row g-3 needs-validation" novalidate>
                    <input type="hidden" name="action" value="reset_admin_password">
                    <div class="col-md-6">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                        <div class="invalid-feedback">
                            Please enter a new password (minimum 8 characters).
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                        <div class="invalid-feedback">
                            Please confirm the new password.
                        </div>
                    </div>
                    <div class="col-12 mt-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-key me-2"></i>Reset Admin Password</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Placeholder for other user management features if any -->

    </div>

    <footer class="text-center mt-5 mb-3 p-3" style="background-color: #f8f9fa;">
        <p>&copy; <?php echo date('Y'); ?> Maria Ow'embabazi Primary School - <i>Good Christian, Good Citizen</i></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Example starter JavaScript for disabling form submissions if there are invalid fields
        (function () {
            'use strict'

            // Fetch all the forms we want to apply custom Bootstrap validation styles to
            var forms = document.querySelectorAll('.needs-validation')

            // Loop over them and prevent submission
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }

                        form.classList.add('was-validated')
                    }, false)
                })
        })()
    </script>
</body>
</html>
