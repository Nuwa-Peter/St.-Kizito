<?php
session_start();
require_once 'db_connection.php'; // For DB access

// Basic security: Redirect if not a POST request or if username not from form/session
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['username'])) {
    $_SESSION['forgot_password_error'] = "Invalid access to password reset handler.";
    header('Location: forgot_password.php');
    exit;
}

$username_from_post = trim($_POST['username']); // Username submitted with answers

// Retrieve stored username from session to ensure continuity, but prefer POSTed one if consistent
$username_from_session = $_SESSION['forgot_password_username'] ?? null;

if ($username_from_session !== $username_from_post) {
    // This could indicate tampering or session issue.
    $_SESSION['forgot_password_error'] = "User context mismatch. Please start over.";
    unset($_SESSION['forgot_password_username']); // Clear to force restart
    unset($_SESSION['forgot_password_attempts']);
    header('Location: forgot_password.php');
    exit;
}
$username = $username_from_session; // Use the validated session username

// Check attempts
if (!isset($_SESSION['forgot_password_attempts'])) {
    $_SESSION['forgot_password_attempts'] = 0;
}

// This check is important here as well, in case user bypasses forgot_password.php's display logic
if ($_SESSION['forgot_password_attempts'] >= 3) {
    $_SESSION['forgot_password_error'] = "You have exceeded the maximum number of attempts. Please contact the Super Admin for assistance: nuwapeter2013@gmail.com, Phone: +256703985940";
    // We might want to also unset forgot_password_username here to force a full restart.
    // unset($_SESSION['forgot_password_username']);
    header('Location: forgot_password.php');
    exit;
}

$answer1_provided = trim($_POST['answer1'] ?? '');
$answer2_provided = trim($_POST['answer2'] ?? '');

if (empty($answer1_provided) || empty($answer2_provided)) {
    $_SESSION['forgot_password_error'] = "Both security answers are required.";
    $_SESSION['forgot_password_attempts'] = ($_SESSION['forgot_password_attempts'] ?? 0) + 1;
    $_SESSION['forgot_password_show_questions_for_user'] = $username; // To re-show questions
    header('Location: forgot_password.php');
    exit;
}

$admin_username_check = 'houseofnazareth.schools@gmail.com';
if (strtolower($username) !== strtolower($admin_username_check)) {
    $_SESSION['forgot_password_error'] = "Password reset via security questions is only available for the designated admin account.";
    header('Location: forgot_password.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id as user_id, security_answer_1_hash, security_answer_2_hash FROM users WHERE username = :username AND role = 'admin'");
    $stmt->execute([':username' => $username]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_data) { // Check if user_data was fetched
        // --- BEGIN REWRITTEN ANSWER VERIFICATION ---
        $ans1_correct = false;
        $ans2_correct = false;

        if (isset($_POST['answer1'], $user_data['security_answer_1_hash'])) {
            $submitted_answer1_raw = $_POST['answer1'];
            $processed_answer1 = strtolower(trim($submitted_answer1_raw));
            $ans1_correct = password_verify($processed_answer1, $user_data['security_answer_1_hash']);
        }

        if (isset($_POST['answer2'], $user_data['security_answer_2_hash'])) {
            $submitted_answer2_raw = $_POST['answer2'];
            $processed_answer2 = strtolower(trim($submitted_answer2_raw));
            $ans2_correct = password_verify($processed_answer2, $user_data['security_answer_2_hash']);
        }

        // --- END REWRITTEN ANSWER VERIFICATION ---

        if ($ans1_correct && $ans2_correct) {
            // Answers are correct - Generate a token
            unset($_SESSION['forgot_password_attempts']);
            unset($_SESSION['forgot_password_username']);
            unset($_SESSION['forgot_password_show_questions_for_user']);

            $token = bin2hex(random_bytes(32));
            $userId = $user_data['user_id'];

            $insertTokenStmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
            $insertTokenStmt->execute([':user_id' => $userId, ':token' => $token]);

            $_SESSION['password_reset_user_id'] = $userId; // Store user_id for reset_password.php (though token lookup is primary)

            header('Location: reset_password.php?token=' . $token);
            exit;

        } else {
            // Answers incorrect
            $_SESSION['forgot_password_attempts'] = ($_SESSION['forgot_password_attempts'] ?? 0) + 1;
            $_SESSION['forgot_password_error'] = "One or both security answers are incorrect.";
            $_SESSION['forgot_password_show_questions_for_user'] = $username; // Signal to re-show questions
            header('Location: forgot_password.php');
            exit;
        }
    } else {
        $_SESSION['forgot_password_error'] = "Security questions/answers not properly configured for this user. Contact administrator.";
        $_SESSION['forgot_password_show_questions_for_user'] = $username; // Re-show questions to avoid user confusion
        header('Location: forgot_password.php');
        exit;
    }

} catch (PDOException $e) {
    error_log("Forgot Password Handler DB Error: " . $e->getMessage());
    $_SESSION['forgot_password_error'] = "Database error during password reset process. Please try again later.";
    $_SESSION['forgot_password_show_questions_for_user'] = $username; // Re-show questions
    header('Location: forgot_password.php');
    exit;
}
?>
