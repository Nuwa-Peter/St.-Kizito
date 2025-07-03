<?php
session_start();
$error_message = $_SESSION['login_error_message'] ?? null;
unset($_SESSION['login_error_message']); // Clear error after displaying

$success_message = $_SESSION['success_message'] ?? null; // For messages like "Password updated successfully"
unset($_SESSION['success_message']);

// If user is already logged in, redirect them to index.php
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Report System - Maria Ow'embabazi P/S</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <style>
        body {
            background-color: #e0f7fa; /* Light blue background */
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            background-color: #fff;
            padding: 30px 40px; /* Increased padding */
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 420px; /* Slightly wider */
        }
        .login-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .login-header img {
            width: 80px; /* Larger logo */
            margin-bottom: 15px;
        }
        .login-header h2 {
            color: #0056b3; /* Darker blue for heading */
            font-weight: 600;
        }
        .form-floating label {
            padding-left: 0.5rem; /* Align floating label better */
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            padding: 10px; /* Larger button padding */
            font-size: 1.1rem;
        }
        .btn-primary:hover {
            background-color: #0069d9;
            border-color: #0062cc;
        }
        .forgot-password-link {
            display: block;
            text-align: right;
            margin-top: 10px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="images/logo.png" alt="School Logo" onerror="this.style.display='none';">
            <h2>School Report System</h2>
            <p class="text-muted">Please sign in to continue</p>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <form action="handle_login.php" method="post">
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="username" name="username" placeholder="Username or Email" required autofocus>
                <label for="username"><i class="fas fa-user me-2"></i>Username or Email</label>
            </div>
            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                <label for="password"><i class="fas fa-lock me-2"></i>Password</label>
            </div>
            <button class="w-100 btn btn-lg btn-primary" type="submit"><i class="fas fa-sign-in-alt me-2"></i>Sign In</button>
            <a href="forgot_password.php" class="forgot-password-link">Forgot Password?</a>
        </form>
        <p class="mt-4 mb-3 text-muted text-center">&copy; <?php echo date('Y'); ?> Maria Ow'embabazi P/S</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
