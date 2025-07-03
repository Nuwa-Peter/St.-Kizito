<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Africa/Kampala');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Manual & System Information - Report System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f9; /* Lighter, cleaner background */
            color: #333;
            line-height: 1.6;
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .container.main-content {
            background-color: #ffffff;
            padding: 30px 40px; /* Increased padding */
            border-radius: 10px; /* Slightly more pronounced radius */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); /* Softer, more modern shadow */
            margin-top: 30px;
            margin-bottom: 30px;
        }
        .about-header {
            text-align: center;
            margin-bottom: 2.5rem; /* Increased spacing */
            padding-bottom: 1.5rem; /* Increased spacing */
            border-bottom: 1px solid #e9ecef; /* Lighter border */
        }
        .about-header img {
            width: 80px; /* Slightly larger logo */
            height: 80px;
            border-radius: 50%;
            margin-bottom: 15px;
            border: 3px solid #007bff; /* Standard Bootstrap primary blue */
            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
        }
        .about-header h1 { /* Changed h2 to h1 for main page title */
            font-weight: 600;
            color: #2c3e50; /* Darker, sophisticated blue/grey */
            margin-bottom: 0.75rem;
            font-size: 2.2rem; /* Larger main title */
        }
        .datetime-display {
            font-size: 0.9em;
            color: #555;
        }

        /* Section Styling */
        section {
            margin-bottom: 2.5rem;
            padding: 25px; /* Increased padding for sections */
            background-color: #fdfdfd;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); /* Subtle shadow for sections */
        }
        .section-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #0056b3;
            margin-top: 0;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 3px solid #007bff;
            display: block;
            text-align: left;
            letter-spacing: -0.5px;
        }
         section#school-contacts .section-title,
         section#new-features-docs .section-title,
         section#help-guide .section-title,
         section#troubleshooting .section-title {
            text-align: center;
        }


        section#help-guide h5 { /* Sub-headers in help guide */
            font-size: 1.25rem;
            color: #0067c2; /* Slightly lighter blue for sub-headers */
            margin-top: 1.5rem;
            margin-bottom: 0.75rem;
        }
        section#help-guide ol, section#help-guide ul,
        section#new-features-docs ol, section#new-features-docs ul {
            padding-left: 25px;
        }
        section#help-guide li, section#new-features-docs li {
            margin-bottom: 0.85rem; /* More space for list items */
            color: #454545;
            line-height: 1.7; /* Improved line height for readability */
        }
        section#help-guide strong, section#new-features-docs strong {
            color: #0056b3;
            font-weight: 600; /* Bolder strong tags */
        }
        section#help-guide ul ul li { /* Nested list items */
            margin-bottom: 0.5rem;
            font-size: 0.95em;
        }


        .btn-danger {
            box-shadow: 0 2px 5px rgba(220, 53, 69, 0.3);
        }
        .btn-danger:hover {
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.4);
        }

        .credits {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid #e0e0e0;
            font-size: 0.9em;
            color: #555;
            text-align: center; /* Center credits text */
        }
        .credits ul {
            list-style-type: none;
            padding-left: 0;
        }
        .credits li {
            margin-bottom: 0.5rem;
        }

        h2, h3, h4, h5 { /* Global for other headings if any */
             letter-spacing: -0.5px;
        }

        @media (max-width: 768px) {
            .container.main-content {
                padding: 20px;
            }
            .about-header h1 {
                font-size: 1.8rem;
            }
            .section-title {
                font-size: 1.5rem;
            }
            section#help-guide h5 {
                font-size: 1.15rem;
            }
        }
    </style>
</head>
<body>
    <?php if (!defined('GENERATING_USER_MANUAL_PDF') || !GENERATING_USER_MANUAL_PDF): ?>
    <nav class="navbar navbar-light bg-light sticky-top shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="images/logo.png" alt="Logo" width="30" height="30" class="d-inline-block align-text-top me-2" onerror="this.style.display='none';">
                Maria Ow'embabazi P/S - Report System
            </a>
            <div>
                <a href="index.php" class="btn btn-outline-secondary me-2"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <a href="logout.php" class="btn btn-outline-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <div class="container main-content">
        <div class="about-header">
            <img src="images/logo.png" alt="School Logo" onerror="this.style.display='none';">
            <h1>User Manual & System Information</h1>
            <p class="datetime-display">Current Date & Time: <?php echo date("D, d M Y H:i:s"); ?></p>
        </div>

        <div class="text-center mb-5">
            <a href="download_user_manual.php" class="btn btn-danger btn-lg">
                <i class="fas fa-file-pdf me-2"></i> Download User Manual (PDF)
            </a>
        </div>

        <section id="about-system">
            <h3 class="section-title">About the Report Card System (v8.0)</h3>
            <p>This system is designed to streamline the generation and management of student academic reports for Maria Ow'embabazi Primary School. It allows for the importation of student scores from Excel files, calculation of termly performance metrics, and generation of printable PDF report cards and summary sheets.</p>
            <p>Key features include:</p>
            <ul>
                <li><strong>Unified Excel Templates (New in v8.0):</strong> Streamlined data import using a single Excel workbook per class level (Lower Primary P1-P3, Upper Primary P4-P7). Each subject's data is entered into a separate, pre-named sheet within the workbook.</li>
                <li>Batch processing of student marks.</li>
                <li>Automatic calculation of aggregates, divisions (for P4-P7), averages, and class positions (for P1-P3).</li>
                <li>Automated generation of teacher and headteacher remarks based on performance.</li>
                <li>Persistent storage of processed data in a database for historical access.</li>
                <li>A user-friendly dashboard for navigation and accessing various functionalities.</li>
                <li>Generation of individual and class-wide PDF report cards.</li>
                <li>Class performance summary sheets with visual charts.</li>
                <li>Student Analytics: Historical Performance Tracking and Comparative Analysis.</li>
            </ul>
        </section>

        <section id="school-contacts" style="text-align: center;">
            <h3 class="section-title">School Contacts</h3>
            <div style="margin-top: 1rem;">
                <p style="font-size: 1.2em; font-weight: bold; margin-bottom: 0.5rem;">Maria Ow'embabazi Primary School</p>
                <address style="font-style: normal; line-height: 1.6;">
                    P.O BOX 406, MBARARA<br>
                    Tel. 0700172858<br>
                    Email: houseofnazareth.schools@gmail.com
                </address>
            </div>
        </section>

        <section id="help-guide">
            <h3 class="section-title">System Usage Guide (v8.0 Workflow)</h3>
            <p><strong>Dashboard:</strong> The main entry point. Use the sidebar to navigate to different sections of the system.</p>

            <h5><i class="fas fa-file-download me-2"></i>Step 1: Download Marks Entry Template</h5>
            <ol>
                <li>Navigate to "Data Entry" from the sidebar on the main dashboard.</li>
                <li>On the Data Entry page, click the "Select Template to Download" button.</li>
                <li>Choose the appropriate template for the class level you are working with:
                    <ul>
                        <li><strong>Lower Primary Template (P1-P3):</strong> Includes sheets for English, Maths, Literacy One, Literacy Two, Local Language, and Religious Education.</li>
                        <li><strong>Upper Primary Template (P4-P7):</strong> Includes sheets for English, Maths, Science, SST, and Kiswahili.</li>
                    </ul>
                </li>
                <li>An "Instructions" sheet is included in each template. Please read it carefully. Save the downloaded Excel file to your computer.</li>
            </ol>

            <h5><i class="fas fa-edit me-2"></i>Step 2: Enter Data into Excel</h5>
            <ol>
                <li>Open the downloaded template file using Microsoft Excel or a compatible spreadsheet program.</li>
                <li>Navigate to each subject's sheet (e.g., "English", "Maths"). <strong>Important: Do NOT change the sheet names.</strong></li>
                <li>Enter student data starting from Row 2 in each subject sheet:
                    <ul>
                        <li><strong>LIN:</strong> Student's Learner Identification Number (if available, otherwise leave blank).</li>
                        <li><strong>Names/Name:</strong> Student's full name in ALL CAPS.</li>
                        <li><strong>BOT:</strong> Beginning of Term score (out of 100).</li>
                        <li><strong>MOT:</strong> Mid of Term score (out of 100).</li>
                        <li><strong>EOT:</strong> End of Term score (out of 100).</li>
                    </ul>
                </li>
                <li>Save the Excel file once all data for all subjects for the class has been entered.</li>
            </ol>

            <h5><i class="fas fa-upload me-2"></i>Step 3: Upload Excel File & Enter Details</h5>
            <ol>
                <li>Return to the "Data Entry" page in the system.</li>
                <li>Fill in the "School & Term Information" section:
                    <ul>
                        <li>Select the Class, Year, and Term.</li>
                        <li>Enter the "This Term Ended On" and "Next Term Begins On" dates.</li>
                    </ul>
                </li>
                <li>In the "Upload Marks File & Teacher Initials" section:
                    <ul>
                        <li>Under "1. Upload Marks Excel File", click "Choose File" (or similar, depending on your browser) and select your completed and saved Excel workbook. The label will update to show the selected class (e.g., "P1 Marks Excel File (.xlsx)").</li>
                        <li>Under "2. Enter Teacher Initials", enter the initials for each subject teacher for the selected class. These initials will appear on the report cards. The relevant initial fields will appear based on the class you selected.</li>
                    </ul>
                </li>
                <li>Click the "Process & Save Data" button at the bottom.</li>
                <li>The system will validate the file and data. If successful, you will see a success message and a link to "View Details for Processed Batch ID: X". Click this link. If there are errors, they will be displayed on the Data Entry page; correct them in your Excel file and re-upload.</li>
            </ol>

            <h5><i class="fas fa-calculator me-2"></i>Step 4: Run Calculations & Generate Remarks</h5>
             <ol>
                <li>After successful upload and clicking the "View Details for Processed Batch ID: X" link (or navigating via "View Report Archives" -> "Process/View Data" for an existing batch), you will be on the specific batch processing page.</li>
                <li>On this page, review the imported scores if needed.</li>
                <li><strong>Crucially, click the "<i class="fas fa-calculator"></i> Run Calculations & Auto-Remarks" button.</strong> This performs all necessary calculations (averages, positions, aggregates, divisions) and generates automated teacher/headteacher remarks for each student. This step must be completed before generating final reports or summaries.</li>
                <li>You should see a success message once calculations are done.</li>
            </ol>

            <h5><i class="fas fa-file-pdf me-2"></i>Step 5: View/Download Reports & Summaries</h5>
            <ol>
                <li>Once calculations are complete for a batch, you can use the action buttons available on the batch processing page or from the "View Report Archives" page:
                    <ul>
                        <li>"<i class="fas fa-file-alt"></i> View PDF": Opens the combined PDF report for all students in the batch in a new browser tab.</li>
                        <li>"<i class="fas fa-download"></i> Download PDF": Downloads the combined PDF report. (Note: Icon might vary based on actual implementation, `fa-file-pdf` is also common).</li>
                        <li>"<i class="fas fa-chart-bar"></i> Summary Sheet": Takes you to the `summary_sheet.php` for that batch, displaying class performance analytics.</li>
                    </ul>
                </li>
            </ol>
        </section>

        <section id="new-features-docs">
            <h3 class="section-title">Student Analytics Features</h3>
            <div style="text-align: center; margin-bottom: 1.5rem;">
                 The following features provide deeper insights into student performance.
            </div>

            <h4 style="text-align: center; font-weight: bold; margin-top: 1.5rem;">1. Historical Performance Tracking</h4>
            <p><strong>Purpose:</strong> This feature allows users to view a comprehensive summary of a student's academic performance across multiple terms and academic years. It helps in identifying trends and overall progress of the student over time.</p>
            <p><strong>Access:</strong> Navigate to "Student Analytics" from the main dashboard sidebar, then click on "Historical Performance".</p>
            <p><strong>Usage:</strong></p>
            <ol>
                <li>Upon accessing the page, select a student from the provided dropdown list.</li>
                <li>Once a student is selected, the system will display a table detailing their key performance indicators for each recorded term (e.g., Average Score, Total Score, Class Position for P1-P3; Aggregate Points, Division for P4-P7; general remarks).</li>
                <li>Line charts may visualize performance trends if sufficient data is available.</li>
            </ol>
            <p><em>This tool is invaluable for tracking long-term academic development.</em></p>

            <h4 style="text-align: center; font-weight: bold; margin-top: 2rem;">2. Comparative Analysis</h4>
            <p><strong>Purpose:</strong> This feature offers tools to conduct comparative studies of a student's performance, either across different subjects within a single term or their performance in a specific subject across several terms.</p>
            <p><strong>Access:</strong> Navigate to "Student Analytics" from the main dashboard sidebar, then click on "Comparative Analysis".</p>
            <p><strong>Usage:</strong></p>
            <ol>
                <li>First, select a student from the main dropdown list.</li>
                <li>Choose an analysis option via tabs:
                    <ul>
                        <li><strong>Compare Subjects (Single Term):</strong> Select a term/batch. A table and bar chart will show subject scores for that term.</li>
                        <li><strong>Track Subject (Across Terms):</strong> Select a subject. A table and line chart will show that subject's scores across all recorded terms.</li>
                    </ul>
                </li>
            </ol>
            <p><em>This analytical tool helps pinpoint specific academic patterns.</em></p>
        </section>

        <section id="troubleshooting">
            <h3 class="section-title">Troubleshooting / Common Issues</h3>
            <ul>
                <li><strong>Error during Excel Upload/Processing:</strong>
                    <ul>
                        <li>Ensure your Excel file strictly follows the downloaded template format (especially sheet names and headers in Row 1).</li>
                        <li>Verify student names are in ALL CAPS.</li>
                        <li>Check for any non-numeric values in score columns.</li>
                        <li>Ensure the file is in `.xlsx` format.</li>
                    </ul>
                </li>
                <li><strong>Reports/Summaries are empty or show old data:</strong>
                    <ul>
                        <li>Always ensure you have clicked "Run Calculations & Auto-Remarks" for the specific batch after importing marks.</li>
                    </ul>
                </li>
                <li><strong>PDF Not Generating / Error in PDF:</strong>
                    <ul>
                        <li>Confirm calculations were run. Note any error message and contact support if issues persist.</li>
                    </ul>
                </li>
            </ul>
        </section>

        <section id="credits" class="credits">
            <h3 class="section-title" style="text-align:center;">Credits</h3>
            <p>This system was designed and developed by:</p>
            <ul>
                <li><strong>Peter Nuwahereza</strong> (nuwapeter2013@gmail.com)</li>
                <li>with the assistance of an AI Agent.</li>
            </ul>
            <p>We hope this system serves Maria Ow'embabazi Primary School effectively!</p>
        </section>

    </div>

    <?php if (!defined('GENERATING_USER_MANUAL_PDF') || !GENERATING_USER_MANUAL_PDF): ?>
    <footer class="text-center mt-5 mb-3 p-3 non-printable" style="background-color: #f8f9fa;">
        <p>&copy; <?php echo date('Y'); ?> Maria Ow'embabazi Primary School - <i>Good Christian, Good Citizen</i></p>
    </footer>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
