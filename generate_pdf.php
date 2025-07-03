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

// Autoloader and DB Connection
if (!file_exists('vendor/autoload.php')) { die('CRITICAL ERROR: Composer autoload file not found.'); }
require 'vendor/autoload.php';
if (!file_exists('db_connection.php')) { die('CRITICAL ERROR: Database connection file not found.'); }
require_once 'db_connection.php'; // Provides $pdo
if (!file_exists('dal.php')) { die('CRITICAL ERROR: Data Access Layer file not found.'); }
require_once 'dal.php'; // Provides DAL functions

// --- Input Validation ---
if (!isset($_GET['batch_id']) || !filter_var($_GET['batch_id'], FILTER_VALIDATE_INT) || $_GET['batch_id'] <= 0) {
    $_SESSION['error_message'] = 'Invalid or missing Batch ID for PDF generation.';
    header('Location: index.php'); // Redirect to dashboard or appropriate error page
    exit;
}
$batch_id = (int)$_GET['batch_id'];

// --- Determine Output Mode ---
$outputMode = 'D'; // Default to Download
if (isset($_GET['output_mode']) && strtoupper($_GET['output_mode']) === 'I') {
    $outputMode = 'I'; // Inline view
}

// --- Fetch Batch & Common Data ---
$batchSettingsData = getReportBatchSettings($pdo, $batch_id);
if (!$batchSettingsData) {
    $_SESSION['error_message'] = 'Could not find settings for Batch ID: ' . htmlspecialchars($batch_id);
    header('Location: view_processed_data.php?batch_id=' . $batch_id); // Or dashboard
    exit;
}

// Student IDs for the batch (fetch from student_report_summary as it implies calculations are done)
$stmtStudentIds = $pdo->prepare("SELECT student_id FROM student_report_summary WHERE report_batch_id = :batch_id ORDER BY student_id");
$stmtStudentIds->execute([':batch_id' => $batch_id]);
$studentIdsInBatch = $stmtStudentIds->fetchAll(PDO::FETCH_COLUMN);

if (empty($studentIdsInBatch)) {
    $_SESSION['error_message'] = 'No students found with calculated summaries for Batch ID: ' . htmlspecialchars($batch_id) . '. Please run calculations first.';
    header('Location: view_processed_data.php?batch_id=' . $batch_id);
    exit;
}

$teacherInitials = $_SESSION['current_teacher_initials'] ?? [];

$classNameForBatch = $batchSettingsData['class_name'];
$isP4_P7_batch = in_array($classNameForBatch, ['P4', 'P5', 'P6', 'P7']);
$isP1_P3_batch = in_array($classNameForBatch, ['P1', 'P2', 'P3']);
$expectedSubjectKeysForClass = [];
if ($isP4_P7_batch) {
    $expectedSubjectKeysForClass = ['english', 'mtc', 'science', 'sst', 'kiswahili'];
} elseif ($isP1_P3_batch) {
    $expectedSubjectKeysForClass = ['english', 'mtc', 're', 'lit1', 'lit2', 'local_lang'];
}

$subjectDisplayNames = [
    'english' => 'English', 'mtc' => 'Mathematics (MTC)', 'science' => 'Science',
    'sst' => 'Social Studies (SST)', 'kiswahili' => 'Kiswahili',
    're' => 'Religious Education (R.E)', 'lit1' => 'Literacy I',
    'lit2' => 'Literacy II', 'local_lang' => 'Local Language'
];
$gradingScaleForP4P7Display = [
    'D1' => '90-100', 'D2' => '80-89', 'C3' => '70-79', 'C4' => '60-69',
    'C5' => '55-59', 'C6' => '50-54', 'P7' => '45-49', 'P8' => '40-44', 'F9' => '0-39'
];

// --- mPDF Initialization ---
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 8, 'margin_bottom' => 8,
        'margin_header' => 4, 'margin_footer' => 4, 'default_font_size' => 9.5,
        'default_font' => 'helvetica'
    ]);

    // --- Watermark Settings ---
    $mpdf->SetWatermarkImage('images/logo.png', 0.06, 45, 'F'); // Opacity set to 0.06, Size set to 45mm width
    $mpdf->showWatermarkImage = true;
    // $mpdf->watermarkImageBehind = true; // This line was removed as it caused an error

    $pdfFileName = 'Report_Cards_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $batchSettingsData['class_name']) . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $batchSettingsData['term_name']) . '_' . $batchSettingsData['year_name'] . '.pdf';
    $mpdf->SetTitle('Report Cards - ' . $batchSettingsData['class_name'] . ' Term ' . $batchSettingsData['term_name'] . ' ' . $batchSettingsData['year_name']);
    $mpdf->SetAuthor("MARIA OW'EMBABAZI PRIMARY SCHOOL");
    $mpdf->SetCreator('Report Card System');

    $firstPage = true; // Initialize flag for first page handling

    // --- Loop Through Students & Generate HTML for Each Report ---
    foreach ($studentIdsInBatch as $student_id) { // $student_id is now correctly defined for use here

        if (!$firstPage) {
            $mpdf->AddPage();

            // Border drawing commands START (only if not first page)
            $outerBorderX = 7; $outerBorderY = 7; $pageWidth = 210; $pageHeight = 297;
            $outerBorderWidth = $pageWidth - (2 * $outerBorderX);
            $outerBorderHeight = $pageHeight - (2 * $outerBorderY);
            $gapBetweenBorders = 2;

            $mpdf->SetDrawColor(0, 0, 0); // Black
            $mpdf->SetLineWidth(0.8);    // Approx 2.25pt
            $mpdf->Rect($outerBorderX, $outerBorderY, $outerBorderWidth, $outerBorderHeight, 'D');

            $mpdf->SetLineWidth(0.2);    // Approx 0.57pt
            $innerBorderX = $outerBorderX + $gapBetweenBorders;
            $innerBorderY = $outerBorderY + $gapBetweenBorders;
            $innerBorderWidth = $outerBorderWidth - (2 * $gapBetweenBorders);
            $innerBorderHeight = $outerBorderHeight - (2 * $gapBetweenBorders);
            $mpdf->Rect($innerBorderX, $innerBorderY, $innerBorderWidth, $innerBorderHeight, 'D');
            $mpdf->SetLineWidth(0.2); // Reset
            // Border drawing commands END
        }
        $firstPage = false; // This must be outside the if, to correctly manage $firstPage state for next iteration

        $sessionKeyForEnrichedData = 'enriched_students_data_for_batch_' . $batch_id;
        if (!isset($_SESSION[$sessionKeyForEnrichedData][$student_id])) {
            throw new Exception("Enriched student data not found in session for student ID $student_id and batch ID $batch_id. Please run calculations first.");
        }
        $currentStudentEnrichedData = $_SESSION[$sessionKeyForEnrichedData][$student_id];

        // $pdo, $batch_id, $student_id are defined.
        // $currentStudentEnrichedData is fetched.
        // $teacherInitials, $subjectDisplayNames, $gradingScaleForP4P7Display, $expectedSubjectKeysForClass are defined.
        // These are all the variables report_card.php expects.

        ob_start();
        include 'report_card.php';
        $html = ob_get_clean();
        $mpdf->WriteHTML($html);
    }

    // Optional: Clear session data for this batch after PDF generation
    // unset($_SESSION['enriched_students_data_for_batch_' . $batch_id]);

    // Log successful PDF generation before outputting
    $logDescription = "Generated PDF report for batch '" . htmlspecialchars($batchSettingsData['class_name'] . " " . $batchSettingsData['term_name'] . " " . $batchSettingsData['year_name']) . "' (ID: " . $batch_id . ").";
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? null,
        $_SESSION['username'] ?? 'System',
        'REPORT_GENERATED',
        $logDescription,
        'batch',
        $batch_id
    );

    $mpdf->Output($pdfFileName, $outputMode);
    exit;

} catch (\Mpdf\MpdfException $e) {
    // Ensure buffer is cleaned if mPDF exception occurs before output
    if (ob_get_level() > 0) ob_end_clean();
    $_SESSION['error_message'] = "mPDF Error generating PDF for Batch ID " . htmlspecialchars($batch_id) . ": " . $e->getMessage();
} catch (Exception $e) {
    if (ob_get_level() > 0) ob_end_clean();
    $_SESSION['error_message'] = "General error generating PDF for Batch ID " . htmlspecialchars($batch_id) . ": " . $e->getMessage() . " (File: " . basename($e->getFile()) . ", Line: " . $e->getLine() . ")";
}

// If any error occurred and was caught, redirect back
if(isset($_SESSION['error_message'])){ // Check if error message was set
    header('Location: view_processed_data.php?batch_id=' . $batch_id);
    exit;
}
?>
