<?php
session_start();
require_once 'db_connection.php';

$token = $_GET['token'] ?? $_POST['token'] ?? null;
$error_message = $_SESSION['reset_password_error'] ?? null;
$success_message = $_SESSION['reset_password_success'] ?? null; // Not typically used here, redirects to login
unset($_SESSION['reset_password_error']);
unset($_SESSION['reset_password_success']);

$user_id = null;
$valid_token = false;

if ($token) {
    try {
        $stmt = $pdo->prepare("SELECT user_id, expires_at FROM password_reset_tokens WHERE token = :token AND expires_at > NOW()");
        $stmt->execute([':token' => $token]);
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($token_data) {
            $valid_token = true;
            $user_id = $token_data['user_id'];
            // Store user_id in session to persist across POST if form validation fails on server & page reloads
            $_SESSION['password_reset_target_user_id'] = $user_id;
        } else {
            $_SESSION['login_error_message'] = 'Invalid or expired password reset token. Please try the forgot password process again.';
            header('Location: login.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Reset Password Token Check DB Error: " . $e->getMessage());
        $_SESSION['login_error_message'] = 'Database error verifying reset token. Please try again later.';
        header('Location: login.php');
        exit;
    }
} else {
    // No token provided in GET request (initial load without token)
     $_SESSION['login_error_message'] = 'No password reset token provided. Please use the link sent to you or start the forgot password process.';
     header('Location: login.php');
     exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$valid_token && isset($_SESSION['password_reset_target_user_id'])) {
        // Re-validate token on POST if initial GET validation might be stale, or rely on session user_id
        // For simplicity, we'll trust the user_id stored in session if POST matches session token
        // but a full token re-check from POST is more robust.
        // The current $valid_token is from GET. A hidden field should resubmit the token.
        $posted_token = $_POST['token'] ?? '';
        if ($posted_token !== $token) { // $token here is from GET
             $_SESSION['reset_password_error'] = 'Token mismatch. Please try again.';
             header('Location: reset_password.php?token=' . htmlspecialchars($token)); // Keep original token in URL for retry
             exit;
        }
        $user_id = $_SESSION['password_reset_target_user_id']; // Use user_id from session validated on GET
        $valid_token = true; // Assume it's still valid as it was on GET and token matches
    }

    if ($valid_token && $user_id) {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($new_password) || empty($confirm_password)) {
            $error_message = "Both password fields are required.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "Password must be at least 8 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Passwords do not match.";
        } else {
            // All checks passed, update password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            try {
                $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :user_id");
                $updateStmt->execute([':password_hash' => $new_password_hash, ':user_id' => $user_id]);

                if ($updateStmt->rowCount() > 0) {
                    // Password updated, now invalidate the token
                    $deleteTokenStmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE token = :token OR user_id = :user_id"); // Delete all for user for safety
                    $deleteTokenStmt->execute([':token' => $token, ':user_id' => $user_id]);

                    unset($_SESSION['password_reset_target_user_id']);
                    unset($_SESSION['forgot_password_username']); // Clean up other related session vars
                    unset($_SESSION['forgot_password_attempts']);
                    unset($_SESSION['forgot_password_show_questions_for_user']);


                    $_SESSION['success_message'] = 'Your password has been successfully updated. Please log in with your new password.';
                    header('Location: login.php');
                    exit;
                } else {
                    $error_message = "Could not update password. Please try again or contact support.";
                }
            } catch (PDOException $e) {
                error_log("Reset Password Update DB Error: " . $e->getMessage());
                $error_message = "A database error occurred. Please try again.";
            }
        }
    } else {
         $error_message = "Password reset session invalid or token issue. Please start over.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Report System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <style>
        body { background-color: #e0f7fa; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .reset-password-container { background-color: #fff; padding: 30px 40px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.15); width: 100%; max-width: 500px; }
        .form-header { text-align: center; margin-bottom: 25px; }
        .form-header h2 { color: #0056b3; font-weight: 600; }
    </style>
</head>
<body>
    <div class="reset-password-container">
        <div class="form-header">
            <h2>Set New Password</h2>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if ($valid_token): // Only show form if token was valid on GET ?>
        <form action="reset_password.php" method="post" class="needs-validation" novalidate>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password" required minlength="8">
                <label for="new_password"><i class="fas fa-key me-2"></i>New Password</label>
                <div class="invalid-feedback">Password must be at least 8 characters.</div>
            </div>

            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required minlength="8">
                <label for="confirm_password"><i class="fas fa-key me-2"></i>Confirm New Password</label>
                <div class="invalid-feedback">Please confirm your new password.</div>
            </div>

            <button class="w-100 btn btn-lg btn-primary" type="submit">Reset Password</button>
        </form>
        <?php elseif(!$error_message): // If not valid token and no other error_message is set from POST handling (e.g. initial load with bad token) ?>
             <div class="alert alert-warning">If you have a valid token, please ensure it's correctly included in the URL. Otherwise, <a href="forgot_password.php">start the password reset process</a>.</div>
        <?php endif; ?>

        <p class="mt-3 text-center">
            <a href="login.php"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
        </p>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Example starter JavaScript for disabling form submissions if there are invalid fields
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
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
