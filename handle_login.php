<?php
session_start();
require_once 'db_connection.php'; // Provides $pdo

// Redirect to login if accessed directly without POST or if already logged in
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}
if (isset($_SESSION['user_id'])) {
    header('Location: index.php'); // Already logged in
    exit;
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    $_SESSION['login_error_message'] = 'Username and password are required.';
    header('Location: login.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = :username OR email = :username_as_email LIMIT 1");
    $stmt->execute([':username' => $username, ':username_as_email' => $username]); // Allow login with username or email
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Password is correct, start session
        session_regenerate_id(true); // Regenerate session ID for security

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role']; // Store role if you have role-based access control

        // Log activity
        require_once 'dal.php'; // Ensure DAL is available
        logActivity(
            $pdo,
            $user['id'],
            $user['username'],
            'USER_LOGIN',
            "User '" . $user['username'] . "' logged in successfully."
        );

        // Redirect to dashboard or intended page
        header('Location: index.php');
        exit;
    } else {
        // Invalid credentials
        $_SESSION['login_error_message'] = 'Invalid username or password.';
        header('Location: login.php');
        exit;
    }

} catch (PDOException $e) {
    // Log error and set a generic error message for the user
    error_log("Login Error: " . $e->getMessage());
    $_SESSION['login_error_message'] = 'An error occurred during login. Please try again later.';
    header('Location: login.php');
    exit;
}
?>
