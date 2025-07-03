<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// No other PHP logic needed here for this static message version.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Report System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <style>
        body { background-color: #e0f7fa; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .forgot-password-container { background-color: #fff; padding: 30px 40px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.15); width: 100%; max-width: 500px; }
        .form-header { text-align: center; margin-bottom: 25px; } /* Keep this if title is part of it */
        .form-header h2 { color: #0056b3; font-weight: 600; }
    </style>
</head>
<body>
    <div class="forgot-password-container">
        <div class="text-center mb-4"> <!-- Re-adding a simple header for "Password Assistance" -->
            <h2>Password Assistance</h2>
        </div>
        <div class="alert alert-info" role="alert">
            <p>If you have forgotten your password, please contact the Super Administrator for assistance:</p>
            <p><strong>Email:</strong> nuwapeter2013@gmail.com<br>
               <strong>Phone:</strong> +256703985940</p>
        </div>
        <p class="mt-3 text-center">
            <a href="login.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
        </p>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
