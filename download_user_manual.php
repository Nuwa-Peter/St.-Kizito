<?php
// download_user_manual.php

// Start session if not already started (in case about.php relies on session for anything, though unlikely for static content)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in - important for protecting access to any content generation script
if (!isset($_SESSION['user_id'])) {
    // Redirect to login or show an error, appropriate for your app's security model
    // For simplicity, let's just deny access if not logged in, as user manual might be considered internal.
    header('HTTP/1.1 403 Forbidden');
    echo "Access Denied. You must be logged in to download the user manual.";
    exit;
}

if (!file_exists('vendor/autoload.php')) {
    die('CRITICAL ERROR: Composer autoload file not found. Cannot generate PDF.');
}
require 'vendor/autoload.php';

// Capture the HTML content of about.php
ob_start();
// Define a variable to signal about.php that it's being included for PDF generation
// This can be used in about.php to exclude non-printable elements like navbars or footers if desired.
define('GENERATING_USER_MANUAL_PDF', true);
include 'about.php';
$htmlContent = ob_get_clean();

// Optional: Basic HTML cleanup for PDF (e.g., remove scripts, simplify some complex CSS if needed)
// This step can be quite involved depending on the complexity of about.php's HTML/CSS.
// For now, we'll try direct conversion.

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 16,
        'margin_bottom' => 16,
        'margin_header' => 9,
        'margin_footer' => 9,
        'default_font_size' => 10, // Standard font size for documents
        'default_font' => 'sans-serif' // Or 'helvetica', 'times' etc.
    ]);

    $mpdf->SetTitle("User Manual - Maria Ow'embabazi P/S Report System");
    $mpdf->SetAuthor("Maria Ow'embabazi Primary School");
    $mpdf->SetCreator('Report Card System');

    // Add a header and footer
    $mpdf->SetHeader('User Manual|Maria Ow\'embabazi P/S|{PAGENO}');
    $mpdf->SetFooter('Generated on: {DATE j-m-Y H:i:s}||Page {PAGENO} of {nbpg}');


    // Attempt to write the HTML. mPDF has some limitations with complex CSS.
    // The styling in about.php is relatively simple (Bootstrap based but also with custom styles).
    // We might need to adjust CSS in about.php or provide PDF-specific CSS here if issues arise.
    $mpdf->WriteHTML($htmlContent);

    $pdfFileName = 'Maria_Owembabazi_PS_Report_System_User_Manual.pdf';
    $mpdf->Output($pdfFileName, \Mpdf\Output\Destination::DOWNLOAD); // 'D' for download
    exit;

} catch (\Mpdf\MpdfException $e) {
    // Clean buffer if mPDF exception occurs
    if (ob_get_level() > 0) ob_end_clean();
    // Log error or display a user-friendly message
    error_log("mPDF Error generating User Manual PDF: " . $e->getMessage());
    die("Sorry, there was an error generating the User Manual PDF. Please try again later or contact support. mPDF error: " . $e->getMessage());
} catch (Exception $e) {
    if (ob_get_level() > 0) ob_end_clean();
    error_log("General error generating User Manual PDF: " . $e->getMessage());
    die("Sorry, an unexpected error occurred while generating the User Manual PDF. " . $e->getMessage());
}

?>
