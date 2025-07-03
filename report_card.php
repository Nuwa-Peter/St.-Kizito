<?php
// report_card.php - Template for generating student report cards
// EXPECTS variables: $pdo, $batch_id, $student_id, $currentStudentEnrichedData,
// $teacherInitials, $subjectDisplayNames,
// $gradingScaleForP4P7Display, $expectedSubjectKeysForClass,
// $totalStudentsInClassForP1P3 (passed directly or part of $studentSummaryData)

// Ensure critical DAL functions are available
if (!function_exists('getReportBatchSettings') || !function_exists('getStudentSummaryAndDetailsForReport')) {
    if (file_exists('dal.php')) {
        require_once 'dal.php';
    } else {
        die('FATAL ERROR: dal.php is missing.');
    }
}

// --- Primary Data Fetching (caller should ensure these IDs are valid) ---
if (!isset($pdo) || !($pdo instanceof PDO)) { die("Template Error: Valid PDO object (\$pdo) not provided."); }
if (!isset($batch_id) || !filter_var($batch_id, FILTER_VALIDATE_INT) || $batch_id <= 0) { die("Template Error: Valid batch_id not provided."); }
if (!isset($student_id) || !filter_var($student_id, FILTER_VALIDATE_INT) || $student_id <= 0) { die("Template Error: Valid student_id not provided."); }
if (!isset($currentStudentEnrichedData) || !is_array($currentStudentEnrichedData)) { die("Template Error: currentStudentEnrichedData not provided."); }
if (!isset($expectedSubjectKeysForClass) || !is_array($expectedSubjectKeysForClass)) { die("Template Error: expectedSubjectKeysForClass not provided."); }

$batchSettingsData = getReportBatchSettings($pdo, $batch_id);
$studentSummaryData = getStudentSummaryAndDetailsForReport($pdo, $student_id, $batch_id);

if (!$batchSettingsData || !$studentSummaryData) {
    die("Essential data missing for report card (Batch: $batch_id, Student: $student_id). Ensure calculations ran.");
}

// --- Prepare variables for the template ---
$studentName = strtoupper(htmlspecialchars($studentSummaryData['student_name'] ?? 'N/A'));
$linNo = htmlspecialchars($studentSummaryData['lin_no'] ?? '');
$className = htmlspecialchars($batchSettingsData['class_name'] ?? 'N/A');

// --- Student Photo Logic ---
$studentPhotoUrl = null;
$rawStudentNameForFile = $studentSummaryData['student_name'] ?? ''; // Use raw name for filename matching
$rawClassNameForPath = $batchSettingsData['class_name'] ?? ''; // Use raw class name for path

if ($rawStudentNameForFile && $rawClassNameForPath) {
    $photoBaseDir = 'vendor/photos/';
    $classPhotoDir = $photoBaseDir . $rawClassNameForPath . '/';
    $possibleExtensions = ['.png', '.jpg', '.jpeg'];

    // Prepare student name for filename - keep it as is, assuming filenames match exact student names.
    // Special characters in student names could be an issue if not handled by the OS/filesystem.
    // For URL, it will be rawurlencode'd later.
    $studentPhotoFilenameBase = $rawStudentNameForFile;

    foreach ($possibleExtensions as $ext) {
        $photoFilePath = $classPhotoDir . $studentPhotoFilenameBase . $ext;
        if (file_exists($photoFilePath)) {
            // URL encode parts of the path that come from variables
            $studentPhotoUrl = rawurlencode($photoBaseDir) . rawurlencode($rawClassNameForPath) . '/' . rawurlencode($studentPhotoFilenameBase . $ext);
            break;
        }
    }
}

if (!$studentPhotoUrl) {
    // IMPORTANT: User needs to create 'vendor/photos/' and place 'placeholder.png' in it.
    // And also 'vendor/photos/[CLASS_NAME]/' directories for student photos.
    $studentPhotoUrl = 'vendor/photos/placeholder.png';
    // Check if placeholder itself exists, to prevent broken image icon for placeholder
    if (!file_exists('vendor/photos/placeholder.png')) {
        // Fallback if even placeholder is missing, though this isn't ideal.
        // Alternatively, CSS could hide the img or show text.
        // For now, let it point to a non-existent placeholder if so.
    }
}
// --- End Student Photo Logic ---

$yearName = htmlspecialchars($batchSettingsData['year_name'] ?? 'N/A');
$termName = htmlspecialchars($batchSettingsData['term_name'] ?? 'N/A');
$termEndDateFormatted = isset($batchSettingsData['term_end_date']) ? htmlspecialchars(date('d F Y', strtotime($batchSettingsData['term_end_date']))) : 'N/A';
$nextTermBeginDateFormatted = isset($batchSettingsData['next_term_begin_date']) ? htmlspecialchars(date('d F Y', strtotime($batchSettingsData['next_term_begin_date']))) : 'N/A';
$classTeacherRemark = nl2br(htmlspecialchars($studentSummaryData['class_teacher_remark'] ?? 'N/A')); // Assuming new schema field name
$headTeacherRemark = nl2br(htmlspecialchars($studentSummaryData['head_teacher_remark'] ?? 'N/A')); // Assuming new schema field name

// Class structure is now P5-P7, so P1-P3 specific logic is removed.
// We assume the P4-P7 structure is the standard for P5-P7.
// Variables previously prefixed with p4p7_ or p1p3_ will need to align with the new student_term_summaries table.

$aggregatePoints = htmlspecialchars($studentSummaryData['aggregate_points'] ?? 'N/A');
$division = htmlspecialchars($studentSummaryData['division'] ?? 'N/A');
$overallAverageScore = htmlspecialchars($studentSummaryData['average_score'] ?? 'N/A'); // New field from student_term_summaries
$positionInClass = htmlspecialchars($studentSummaryData['position_in_class'] ?? 'N/A');
$totalStudentsInClass = htmlspecialchars($studentSummaryData['total_students_in_class'] ?? 0);

// Fields for BOT/MOT aggregates/divisions if they exist in the new schema (student_term_summaries)
// For now, let's assume they might be added later or are not present in the immediate new schema for summaries.
// If they ARE in student_term_summaries, they should be fetched and displayed.
// Example:
// $aggregateBotScore = htmlspecialchars($studentSummaryData['aggregate_bot_score'] ?? 'N/A');
// $divisionBot = htmlspecialchars($studentSummaryData['division_bot'] ?? 'N/A');
// $aggregateMotScore = htmlspecialchars($studentSummaryData['aggregate_mot_score'] ?? 'N/A');
// $divisionMot = htmlspecialchars($studentSummaryData['division_mot'] ?? 'N/A');

$subjectsToDisplayInTable = $currentStudentEnrichedData['subjects'] ?? [];

// Updated subjectDisplayNames to use new subject codes (matching kizito_schema.sql and process_excel.php)
// and to ensure "ENGLISH" is used for 'ENG'.
$subjectDisplayNames = $subjectDisplayNames ?? [
    'ENG' => 'ENGLISH',
    'MTC' => 'MATHEMATICS',
    'SCI' => 'SCIENCE',
    'SST' => 'SOCIAL STUDIES',
    'RE'  => 'RELIGIOUS EDUCATION'
    // Obsolete subjects (kiswahili, lit1, lit2, local_lang) removed
];
$gradingScaleForP4P7Display = $gradingScaleForP4P7Display ?? [
    'D1' => '90-100', 'D2' => '80-89', 'C3' => '70-79', 'C4' => '60-69',
    'C5' => '55-59', 'C6' => '50-54', 'P7' => '45-49', 'P8' => '40-44', 'F9' => '0-39'
];
$teacherInitials = $teacherInitials ?? ($_SESSION['current_teacher_initials'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card - <?php echo $studentName; ?></title>
    <link rel="icon" type="image/png" href="images/logo.png">
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; margin: 0; padding: 0; background-color: #f0f0f0; font-size: 9.5pt; color: #000; }
        .report-card-container {
            width: 190mm;
            height: 261mm;
            margin: 10mm auto;
            padding: 10mm;
            background-color: white;
            position: relative;
            box-sizing: border-box;
            border: 1px solid #333;
            display: flex;
            flex-direction: column;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 3mm; /* Increased margin slightly */
            margin-top: 0;
            min-height: 40mm; /* Approx height of a passport photo */
            position: relative; /* For absolute positioning of central info if needed, or just rely on flex spacing */
        }

        .header-logo-container {
            width: 35mm; /* Width for school logo */
            height: 35mm; /* Height for school logo */
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            /* border: 1px dashed lightblue; */ /* For layout debugging */
        }
        .header-logo-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .header-info-container {
            text-align: center;
            flex-grow: 1;
            padding: 0 3mm; /* Padding between logo/photo and text */
            display: flex;
            flex-direction: column;
            justify-content: center; /* Center content vertically if header is taller */
            align-items: center;
            min-height: 40mm; /* Match student photo height */
            /* border: 1px dashed pink; */ /* For layout debugging */
        }
        .header .school-name { font-size: 18pt; font-weight: bold; margin: 0 0 1mm 0; color: #000; letter-spacing: 0.5px; }
        .header .school-details { font-size: 8pt; margin: 0.25mm 0; color: #000; }
        .header .report-title { font-size: 14pt; font-weight: bold; margin-top: 2mm; text-transform: uppercase; color: #000; letter-spacing: 1px; }

        .header-student-photo-container {
            width: 35mm; /* Passport photo width */
            height: 40mm; /* Passport photo height */
            border: 1px solid #ccc;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f0f0f0;
            /* border: 1px dashed lightgreen; */ /* For layout debugging */
        }
        .header-student-photo-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: cover;
        }

        .student-details-block { margin-bottom: 2.5mm; } /* Photo is now in header, so this block is just for text details */
        .student-info-grid { display: grid; grid-template-columns: auto 1fr auto 1fr; gap: 1mm 3mm; font-size: 10pt; margin-bottom:0.5mm;}
        .student-info-grid strong {font-weight: bold;}
        .lin-number-display {font-size: 9.5pt; text-align: left; margin-top: 0.5mm;}
        .lin-number-display strong {font-weight: bold;}
        .academic-summary-grid { display: grid; grid-template-columns: auto 1fr auto 1fr; gap: 1mm 3mm; margin-bottom: 2.5mm; font-size: 9pt; background-color: #f0f0f0; padding: 1.5mm; border: 1px solid #ddd;}
        .academic-summary-grid strong {font-weight: bold;}
        .results-table { width: 100%; border-collapse: collapse; margin-bottom: 2.5mm; font-size: 9pt; } /* Increased font size from 8pt to 9pt */
        /* Base style for all th/td in this table */
        .results-table th, .results-table td {
            border: 1px solid #000;
            padding: 1.5mm 1mm;
            text-align: center; /* Default, specific columns will override */
            vertical-align: middle;
            overflow-wrap: break-word;
            /* No generic width property here */
        }
        .results-table th { background-color: #e9e9e9; font-weight: bold; }
        /* td.subject-name rule is merged into the th:first-child, td.subject-name block below */

        /* Subject Column */
        .results-table th:first-child,
        .results-table td.subject-name {
            width: 25%; /* Target: 25% */
            text-align: left; /* Target: left */
            font-weight: normal; /* Ensure subject name in td is not bold if th is bolded by default */
        }
        .results-table th:first-child {
             font-weight: bold; /* Explicitly make header bold if td style overrides */
        }


        /* Remarks Column */
        .results-table th:nth-last-child(2),
        .results-table td:nth-last-child(2) {
            width: 30%; /* Target: 30% */
            text-align: left; /* Target: left */
        }

        /* Initials Column */
        .results-table th:last-child,
        .results-table td:last-child {
            width: 8%; /* Target: 8% */
            /* text-align: center; (default from .results-table th, .results-table td) */
        }

        .results-table .summary-row td { background-color: #f8f9fa; font-weight: bold; }
        .p1p3-performance-summary-after-table { margin-top: 2mm; margin-bottom: 2mm; font-size: 8.5pt; border: 1px solid #eaeaea; padding: 1mm; background-color: #f9f9f9; text-align:center; }
        .remarks-section { margin-top: 1.5mm; font-size: 9pt;} /* Reduced margin-top */
        .remarks-section .remark-block { margin-bottom: 2mm; padding: 1mm; border: 1px solid #ddd; min-height: 15mm; } /* Reduced padding */
        .remarks-section strong { display: block; margin-bottom: 0.5mm; font-weight: bold; font-size: 10pt; }
        .remarks-section p { margin: 0 0 1mm 0; line-height: 1.25; font-size: 10pt; }
        .remarks-section .signature-line { margin-top: 2mm; border-top: 1px solid #000; width: 45mm; padding-top:0.5mm; font-size:9pt; text-align: center; } /* Reduced margin-top */
        .term-dates { font-size: 11pt; margin-top: 2.5mm; margin-bottom: 2.5mm; text-align: center; border-top: 1px dashed #ccc; border-bottom: 1px dashed #ccc; padding: 1mm 0;} /* Reduced font size from 12pt */
        .term-dates strong {font-weight:bold;}
        .additional-note-p4p7 { font-size: 10pt; margin-top: 1.5mm; margin-bottom: 1.5mm; text-align: center; font-style: italic; } /* Reduced font-size and margins */
        .grading-scale-section-p4p7 {
            margin-top: 1.5mm; /* Reduced margin-top */
            font-size: 7.5pt;
            text-align:center;
        }
        .grading-scale-section-p4p7 strong { /* "GRADING SCALE" heading */
            display: block;
            margin-bottom: 1mm;
            font-weight: bold;
            font-size: 10pt;
        }
        .grading-scale-section-p4p7 .scale-container {
            display: inline-block;
            text-align: center;
            padding: 0;
        }
        .grading-scale-section-p4p7 .scale-item { /* e.g., "D1: 90-100" */
            display: inline-block;
            text-align: left;
            margin: 0.25mm 1mm; /* Reduced margin */
            white-space:nowrap;
            border: 1px solid #eee;
            padding: 0.25mm 0.5mm; /* Reduced padding */
            border-radius: 3px;
            font-size: 10pt; /* Adjusted font-size to 10pt */
        }
        .grading-scale-section-p4p7 .scale-item strong {font-weight:bold; display:inline; font-size: 10pt;} /* Ensure strong tag also has adjusted font-size */
        /* .results-table.p1p3-table td, (Removed as P1-P3 specific table styling is no longer needed) */
        /* .results-table.p1p3-table th { (Removed as P1-P3 specific table styling is no longer needed) */
            /* font-size: 9pt; */ /* Increased from base 8pt for P1-P3 table */
        /* } */
        .footer { text-align: center; font-size: 9.5pt; margin-top: 4mm; border-top: 1px solid #000; padding-top: 1.5mm; }
        .footer i { font-style: italic; font-size:13pt; } /* Remains 13pt */
        @media print {
            body { margin: 0; padding: 0; background-color: #fff; font-size:9pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .report-card-container { width: 100%; min-height: unset; margin: 0; border: none; box-shadow: none; padding: 7mm; /* page-break-after: always; */ }
            .watermark { opacity: 0.05; width: 140mm; }
            .non-printable { display: none; }
            .academic-summary-grid, .p1p3-performance-summary-after-table, .results-table th, .results-table .summary-row td { background-color: #e9e9e9 !important; }
            .grading-scale-section-p4p7 .scale-item { border: 1px solid #ccc !important; }
        }
    </style>
</head>
<body>
    <div class="report-card-container">
        <!-- Ensure no img tag for watermark is here -->
        <div class="header">
            <div class="header-logo-container">
                <img src="images/logo.png" alt="School Logo" onerror="this.style.display='none';this.parentElement.innerHTML='Logo not found';">
            </div>
            <div class="header-info-container">
                <div class="school-name"><?php echo htmlspecialchars("ST KIZITO PREPARATORY SEMINARY RWEBISHURI"); ?></div>
                <div class="school-details">P.O BOX 406, MBARARA</div>
                <div class="school-details">Tel. 0700172858 | Email: houseofnazareth.schools@gmail.com</div>
                <div class="report-title">TERMLY ACADEMIC REPORT</div>
            </div>
            <div class="header-student-photo-container">
                <img src="<?php echo $studentPhotoUrl; ?>" alt="Student Photo" onerror="this.style.display='none';this.parentElement.innerHTML='Photo not found';">
            </div>
        </div>
        <div class="report-body-content" style="flex-grow: 1;"> <!-- Wrapper for main content -->
        <div class="student-details-block">
            <!-- Student photo is now in the header, so this block only contains textual info -->
            <div class="student-info-grid">
                <strong>STUDENT'S NAME:</strong> <span><?php echo $studentName; ?></span>
                <strong>CLASS:</strong> <span><?php echo $className; ?></span>
                <strong>YEAR:</strong> <span><?php echo $yearName; ?></span>
                <strong>TERM:</strong> <span><?php echo $termName; ?></span>
            </div>
            <div class="lin-number-display"><strong>LIN:</strong> <?php echo $linNo; ?></div>
        </div>

        <!-- Standardized Academic Summary for P5-P7 -->
        <div class="academic-summary-grid">
            <strong>AGGREGATE:</strong> <span><?php echo $aggregatePoints; ?></span>
            <strong>DIVISION:</strong> <span><?php echo $division; ?></span>
            <strong>AVERAGE:</strong> <span><?php echo $overallAverageScore; ?></span>
            <strong>POSITION:</strong> <span><?php echo $positionInClass; ?> out of <?php echo $totalStudentsInClass; ?></span>
        </div>

        <table class="results-table">
            <thead>
                <tr>
                    <th>SUBJECT</th>
                    <th>B.O.T (100)</th>
                    <th>GRADE</th>
                    <th>M.O.T (100)</th>
                    <th>GRADE</th>
                    <th>END OF TERM (100)</th>
                    <th>GRADE</th>
                    <!-- No 'AVERAGE' column per subject, standard P4-P7 format had grades -->
                    <th>REMARKS</th>
                    <th>INITIALS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expectedSubjectKeysForClass as $subjectKey): ?>
                    <?php
                        $subjectPerformance = $subjectsToDisplayInTable[$subjectKey] ?? null;
                        $subjDisplayName = htmlspecialchars(
                            isset($subjectDisplayNames[$subjectKey]) ? $subjectDisplayNames[$subjectKey] : ($subjectPerformance['subject_name_full'] ?? ucfirst($subjectKey))
                        );
                        $initialsForSubj = htmlspecialchars($teacherInitials[$subjectKey] ?? 'N/A');

                        // Assuming grades are still part of subjectPerformance if calculated by backend
                        $bot_grade = htmlspecialchars($subjectPerformance['bot_grade'] ?? 'N/A');
                        $mot_grade = htmlspecialchars($subjectPerformance['mot_grade'] ?? 'N/A');
                        $eot_grade = htmlspecialchars($subjectPerformance['eot_grade'] ?? 'N/A');
                        // $subject_term_average = htmlspecialchars($subjectPerformance['subject_term_average'] ?? 'N/A'); // Removed as per P4-P7 structure
                        $eot_remark = htmlspecialchars($subjectPerformance['eot_remark'] ?? ($subjectPerformance['eot_remark_text'] ?? 'N/A')); // Check for eot_remark_text from new schema potential
                    ?>
                    <tr>
                        <td class="subject-name"><?php echo $subjDisplayName; ?></td>
                        <td><?php $bs = $subjectPerformance['bot_score'] ?? 'N/A'; echo htmlspecialchars(is_numeric($bs) ? round((float)$bs) : $bs); ?></td>
                        <td><?php echo $bot_grade; ?></td>
                        <td><?php $ms = $subjectPerformance['mot_score'] ?? 'N/A'; echo htmlspecialchars(is_numeric($ms) ? round((float)$ms) : $ms); ?></td>
                        <td><?php echo $mot_grade; ?></td>
                        <td><?php $es = $subjectPerformance['eot_score'] ?? 'N/A'; echo htmlspecialchars(is_numeric($es) ? round((float)$es) : $es); ?></td>
                        <td><?php echo $eot_grade; ?></td>
                        <td><?php echo $eot_remark; ?></td>
                        <td><?php echo $initialsForSubj; ?></td>
                    </tr>
                <?php endforeach; ?>

                <!-- Standardized Summary Rows for P5-P7 (based on former P4-P7 structure) -->
                <?php
                    // These would ideally come from student_term_summaries if calculated and stored there for BOT/MOT.
                    // For now, using placeholder logic or assuming they are not displayed if not in $studentSummaryData.
                    // The new schema for student_term_summaries focuses on EOT aggregates.
                    // If BOT/MOT aggregates are needed, the schema and DAL would need to support them.
                    $aggregate_bot_score = $studentSummaryData['aggregate_bot_score'] ?? 'N/A'; // Example if available
                    $division_bot = $studentSummaryData['division_bot'] ?? 'N/A'; // Example if available
                    $aggregate_mot_score = $studentSummaryData['aggregate_mot_score'] ?? 'N/A'; // Example if available
                    $division_mot = $studentSummaryData['division_mot'] ?? 'N/A'; // Example if available
                ?>
                <tr class="summary-row">
                    <td><strong>AGGREGATE</strong></td>
                    <td></td> <?php // Empty cell for BOT Score column ?>
                    <td><strong><?php echo htmlspecialchars($aggregate_bot_score); ?></strong></td>
                    <td></td> <?php // Empty cell for MOT Score column ?>
                    <td><strong><?php echo htmlspecialchars($aggregate_mot_score); ?></strong></td>
                    <td></td> <?php // Empty cell for EOT Score column ?>
                    <td><strong><?php echo $aggregatePoints; ?></strong></td>
                    <td colspan="2"></td> <?php // Colspan for Remarks and Initials ?>
                </tr>
                <tr class="summary-row">
                    <td><strong>DIVISION</strong></td>
                    <td></td>
                    <td><strong><?php echo htmlspecialchars($division_bot); ?></strong></td>
                    <td></td>
                    <td><strong><?php echo htmlspecialchars($division_mot); ?></strong></td>
                    <td></td>
                    <td><strong><?php echo $division; ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>

        <?php /* P1-P3 post-table summary div removed as requested - this comment can be removed too */ ?>

        <div class="remarks-section">
            <div class="remark-block"><strong>Class Teacher's Remarks:</strong><p><?php echo $classTeacherRemark; ?></p><div class="signature-line">Class Teacher's Signature</div></div>
            <div class="remark-block"><strong>Head Teacher's Remarks:</strong><p><?php echo $headTeacherRemark; ?></p><div class="signature-line">Head Teacher's Signature & Stamp</div></div>
        </div>
        <div class="term-dates">
            This Term Ended On: <strong><?php echo $termEndDateFormatted; ?></strong> &nbsp; | &nbsp;
            Next Term Begins On: <strong><?php echo $nextTermBeginDateFormatted; ?></strong>
        </div>
        <div class="grading-scale-section-p4p7">
            <strong>GRADING SCALE</strong>
            <div class="scale-container">
                <?php foreach ($gradingScaleForP4P7Display as $grade => $range): ?>
                    <span class="scale-item"><strong><?php echo htmlspecialchars($grade); ?>:</strong> <?php echo htmlspecialchars($range); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="additional-note-p4p7">Additional Note: Please ensure regular attendance and parental support for optimal performance.</div>
        </div> <!-- end .report-body-content -->
        <div class="footer"><i>MANE NOBISCUM DOMINE</i></div>
    </div><!-- report-card-container -->
</body>
</html>
