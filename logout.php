<?php
// Start session to access session variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log activity before destroying session data
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    require_once 'db_connection.php'; // Provides $pdo - needed for logActivity
    require_once 'dal.php';         // Provides logActivity
    logActivity(
        $pdo,
        $_SESSION['user_id'],
        $_SESSION['username'],
        'USER_LOGOUT',
        "User '" . $_SESSION['username'] . "' logged out."
    );
}

// Unset all of the session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

// Set a success message for the login page (optional)
// To display this, login.php needs to be able to show 'logout_message' or a generic success message.
// For simplicity, we'll rely on login.php's existing $_SESSION['success_message'] if available,
// or just redirect. Let's add a specific logout message.
session_start(); // Need to start a new session to store the flash message
$_SESSION['logout_message'] = "You have been successfully logged out.";


// Redirect to login page
header("Location: login.php");
exit;
?>
