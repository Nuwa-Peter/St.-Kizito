<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('STKIZITO_SESSION');
    session_start();
}
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
    <title>Login - Report System - ST KIZITO PREPARATORY SEMINARY RWEBISHURI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="css/style.css" rel="stylesheet"> <!-- Shared stylesheet for body background -->
    <style>
        /* body background is now in css/style.css */
        body {
            /* background-color: #FFFACD; */ /* LemonChiffon - very light yellow - Moved to css/style.css */
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            background-color: #fff; /* White container */
            padding: 35px 45px; /* Increased padding */
            border-radius: 12px; /* Smoother radius */
            box-shadow: 0 8px 25px rgba(0,0,0,0.1); /* Enhanced shadow */
            width: 100%;
            max-width: 450px; /* Slightly wider */
            border-top: 5px solid #8B4513; /* Coffee brown top border accent */
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px; /* More space */
        }
        .login-header img {
            width: 85px;
            margin-bottom: 20px; /* More space below logo */
            border-radius: 50%; /* Circular logo if desired, ensure image is suitable */
            border: 3px solid #D2B48C; /* Tan border for logo */
        }
        .login-header h2 {
            color: #00008B; /* Dark Blue for heading */
            font-weight: 700; /* Bolder */
            font-size: 1.8rem; /* Larger heading */
        }
        .login-header p.text-muted {
            color: #4A3B31 !important; /* Darker coffee for subtitle */
            font-size: 0.95rem;
        }
        .form-floating label {
            padding-left: 0.5rem;
        }
        .form-control:focus { /* Highlight focus with theme color */
            border-color: #FFD700; /* Yellow border on focus */
            box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.3); /* Yellow glow */
        }
        .btn-login { /* Custom class for login button */
            background-color: #00008B; /* Dark Blue */
            border-color: #00008B;
            color: #fff;
            padding: 12px;
            font-size: 1.1rem;
            font-weight: 500;
            transition: background-color 0.2s ease-in-out;
        }
        .btn-login:hover {
            background-color: #00005A; /* Darker Blue for hover */
            border-color: #00005A;
        }
        .forgot-password-link {
            display: block;
            text-align: right;
            margin-top: 12px;
            font-size: 0.9em;
            color: #8B4513; /* Coffee brown link */
        }
        .forgot-password-link:hover {
            color: #A0522D; /* Lighter coffee brown on hover */
            text-decoration: underline;
        }
        .login-footer {
            margin-top: 25px;
            font-size: 0.85rem;
            color: #4A3B31; /* Darker coffee text */
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="images/logo.png" alt="School Logo" onerror="this.style.display='none';">
            <h2>School Report System</h2>
            <p class="text-muted">ST KIZITO PREPARATORY SEMINARY RWEBISHURI</p>
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
            <button class="w-100 btn btn-lg btn-login" type="submit"><i class="fas fa-sign-in-alt me-2"></i>Sign In</button>
            <a href="forgot_password.php" class="forgot-password-link">Forgot Password?</a>
        </form>
        <p class="mt-4 mb-3 text-muted text-center login-footer">&copy; <?php echo date('Y'); ?> ST KIZITO PREPARATORY SEMINARY RWEBISHURI - <i>MANE NOBISCUM DOMINE</i></p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
