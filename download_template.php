<?php
ob_start(); // Start output buffering at the very beginning
date_default_timezone_set('Africa/Kampala');

if (!file_exists('vendor/autoload.php')) {
    if (ob_get_length()) ob_end_clean();
    header("Content-Type: text/plain; charset=UTF-8");
    die('CRITICAL ERROR: Composer dependencies (vendor/autoload.php) not found. Please run "composer install". Template generation cannot proceed.');
}
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// Function to populate a single subject sheet
function populateSubjectSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet, string $subjectName) {
    $worksheet->setTitle($subjectName);

    // Headers
    $worksheet->getCell('A1')->setValue('LIN');
    $worksheet->getCell('B1')->setValue('Names/Name');
    $worksheet->getCell('C1')->setValue('BOT');
    $worksheet->getCell('D1')->setValue('MOT');
    $worksheet->getCell('E1')->setValue('EOT');
    $worksheet->getStyle('A1:E1')->getFont()->setBold(true);

    // Example student data
    $exampleStudentData = [
        ['LIN_EXAMPLE_1', 'STUDENT NAME 1 (ALL CAPS)'],
        ['', 'STUDENT NAME 2 (ALL CAPS)'],
        ['LIN_EXAMPLE_3', 'STUDENT NAME 3 (ALL CAPS)']
    ];
    $row = 2;
    foreach ($exampleStudentData as $student) {
        $worksheet->getCell('A' . $row)->setValue($student[0]); // LIN
        $worksheet->getCell('B' . $row)->setValue($student[1]); // Name
        // BOT, MOT, EOT will be empty for the template
        $row++;
    }

    // Column Dimensions
    $worksheet->getColumnDimension('A')->setWidth(20); // LIN
    $worksheet->getColumnDimension('B')->setWidth(35); // Names/Name
    $worksheet->getColumnDimension('C')->setWidth(12); // BOT
    $worksheet->getColumnDimension('D')->setWidth(12); // MOT
    $worksheet->getColumnDimension('E')->setWidth(12); // EOT

    // Note in F1 for instructions specific to the sheet
    $worksheet->getCell('F1')->setValue('NOTE: Enter student names in ALL CAPS. Ensure LIN is provided if available, otherwise leave blank. Data starts from Row 2. Do not change the sheet name.');
    $worksheet->getStyle('F1')->getFont()->setBold(true)->getColor()->setARGB('FF808080');
    $worksheet->getColumnDimension('F')->setWidth(80);


    // Page Setup
    $worksheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
    $worksheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
    $worksheet->getPageMargins()->setTop(0.75);
    $worksheet->getPageMargins()->setRight(0.7);
    $worksheet->getPageMargins()->setLeft(0.7);
    $worksheet->getPageMargins()->setBottom(0.75);
}

// Function to populate the Instructions sheet
function populateInstructionsSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet) {
    $worksheet->setTitle('Instructions');
    $worksheet->getCell('A1')->setValue('Instructions for Using This Template');
    $worksheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $instructions = [
        "Please enter student data in the respective subject sheets.",
        "Sheet names (e.g., 'English', 'Maths') should NOT be changed. They are used by the system to identify subjects.",
        "Each subject sheet requires the following headers in Row 1: LIN, Names/Name, BOT, MOT, EOT.",
        "Enter student names in ALL CAPS in the 'Names/Name' column.",
        "Data entry for students starts from Row 2 in each subject sheet.",
        "Leave the LIN column blank if a student does not have a LIN.",
        "Marks for BOT, MOT, EOT should be numerical (out of 100). Leave blank if not applicable.",
        "After filling in the data, save this Excel file.",
        "Upload the complete saved Excel file to the report card system."
    ];
    $row = 3;
    foreach ($instructions as $instruction) {
        $worksheet->getCell('A' . $row)->setValue("• " . $instruction);
        $worksheet->getRowDimension($row)->setRowHeight(-1); // Auto height
        $worksheet->getStyle('A' . $row)->getAlignment()->setWrapText(true);
        $row++;
    }
    $worksheet->getColumnDimension('A')->setWidth(100);
}


try {
    $templateType = $_GET['type'] ?? 'lower'; // Default to 'lower' if not specified
    $subjects = [];
    $filename = "";

    if ($templateType === 'lower') {
        $subjects = ['English', 'Maths', 'Literacy One', 'Literacy Two', 'Local Language', 'Religious Education'];
        $filename = "Lower_Primary_Marks_Template_" . date('Y-m-d') . ".xlsx";
    } elseif ($templateType === 'upper') {
        $subjects = ['English', 'Maths', 'Science', 'SST', 'Kiswahili'];
        $filename = "Upper_Primary_Marks_Template_" . date('Y-m-d') . ".xlsx";
    } else {
        if (ob_get_length()) ob_end_clean();
        header("Content-Type: text/plain; charset=UTF-8");
        die("Invalid template type specified. Use type=lower or type=upper.");
    }

    $spreadsheet = new Spreadsheet();
    if ($spreadsheet->getSheetCount() > 0) {
        $spreadsheet->removeSheetByIndex(0); // Remove default sheet
    }

    // Add Instructions Sheet first
    $instructionsSheet = $spreadsheet->createSheet();
    populateInstructionsSheet($instructionsSheet);
    $spreadsheet->setActiveSheetIndex(0); // Set Instructions as the first sheet visually

    // Add Subject Sheets
    $subjectSheetIndex = 1; // Start adding subject sheets after instructions
    foreach ($subjects as $subjectName) {
        $worksheet = $spreadsheet->createSheet($subjectSheetIndex); // Create sheet at specific index
        populateSubjectSheet($worksheet, $subjectName);
        $subjectSheetIndex++;
    }

    // Set the active sheet to be the first subject sheet after Instructions for user convenience
    if (count($subjects) > 0) {
        $spreadsheet->setActiveSheetIndex(1);
    }


    if (ob_get_length()) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    header("Content-Type: text/plain; charset=UTF-8");
    error_log("Error generating Excel template (type: " . htmlspecialchars($templateType ?? 'unknown') . "): " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\nStack Trace:\n" . $e->getTraceAsString());
    die("ERROR: Could not generate the Excel template due to an internal server error. Please contact the administrator. Details logged. Message: " . htmlspecialchars($e->getMessage()));
}
?>
