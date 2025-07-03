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
$yearName = htmlspecialchars($batchSettingsData['year_name'] ?? 'N/A');
$termName = htmlspecialchars($batchSettingsData['term_name'] ?? 'N/A');
$termEndDateFormatted = isset($batchSettingsData['term_end_date']) ? htmlspecialchars(date('d F Y', strtotime($batchSettingsData['term_end_date']))) : 'N/A';
$nextTermBeginDateFormatted = isset($batchSettingsData['next_term_begin_date']) ? htmlspecialchars(date('d F Y', strtotime($batchSettingsData['next_term_begin_date']))) : 'N/A';
$classTeacherRemark = nl2br(htmlspecialchars($studentSummaryData['auto_classteachers_remark_text'] ?? 'N/A'));
$headTeacherRemark = nl2br(htmlspecialchars($studentSummaryData['auto_headteachers_remark_text'] ?? 'N/A'));

$isP1_P3 = in_array($className, ['P1', 'P2', 'P3']);
$isP4_P7 = in_array($className, ['P4', 'P5', 'P6', 'P7']);

$p4p7Aggregate = htmlspecialchars($studentSummaryData['p4p7_aggregate_points'] ?? 'N/A');
$p4p7Division = htmlspecialchars($studentSummaryData['p4p7_division'] ?? 'N/A'); // Roman numeral

$p1p3TotalEOT = htmlspecialchars($studentSummaryData['p1p3_total_eot_score'] ?? 'N/A');
$p1p3AverageEOT = htmlspecialchars($studentSummaryData['p1p3_average_eot_score'] ?? 'N/A'); // This is Overall Average EOT
$p1p3PositionBasedOnAvgEOT = htmlspecialchars($studentSummaryData['p1p3_position_in_class'] ?? 'N/A');
$totalStudentsInClassForP1P3 = htmlspecialchars($studentSummaryData['p1p3_total_students_in_class'] ?? 0);

// New P1-P3 fields for the new summary rows in the table
$p1p3OverallAverageBot = htmlspecialchars($studentSummaryData['p1p3_average_bot_score'] ?? 'N/A');
$p1p3OverallAverageMot = htmlspecialchars($studentSummaryData['p1p3_average_mot_score'] ?? 'N/A');
// $p1p3AverageEOT is already defined above for the overall EOT average.

$p1p3PositionTotalBot = htmlspecialchars($studentSummaryData['p1p3_position_total_bot'] ?? 'N/A');
$p1p3PositionTotalMot = htmlspecialchars($studentSummaryData['p1p3_position_total_mot'] ?? 'N/A');
$p1p3PositionTotalEot = htmlspecialchars($studentSummaryData['p1p3_position_total_eot'] ?? 'N/A');


$subjectsToDisplayInTable = $currentStudentEnrichedData['subjects'] ?? [];

$subjectDisplayNames = $subjectDisplayNames ?? [
    'english' => 'ENGLISH',
    'mtc' => 'MATHEMATICS',
    'science' => 'SCIENCE',
    'sst' => 'SOCIAL STUDIES',
    'kiswahili' => 'KISWAHILI',
    're' => 'RELIGIOUS EDUCATION',
    'lit1' => 'LITERACY ONE',
    'lit2' => 'LITERACY TWO',
    'local_lang' => 'LOCAL LANGUAGE'
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
            /* min-height: 275mm; */ /* Removed */
            height: 261mm; /* Added fixed height */
            margin: 10mm auto;
            padding: 10mm; /* Adjusted padding back to 10mm */
            background-color: white;
            position: relative;
            box-sizing: border-box;
            border: 1px solid #333; /* Added a solid border */
            display: flex;
            flex-direction: column;
        }
        /* .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); opacity: 0.06; z-index: 0; pointer-events: none; width: 150mm; height: auto; } */
        .header { text-align: center; margin-bottom: 2.5mm; margin-top: 0; } /* Reduced margin-bottom */
        .header .school-name { font-size: 20pt; font-weight: bold; margin: 0; color: #000; letter-spacing: 0.5px; }
        .header .logo-container { margin-top: 1mm; margin-bottom: 1mm; } /* Reduced margins */
        .header .logo-container img { width: 40px; height: 40px; object-fit: contain; } /* Further reduced size */
        .header .school-details { font-size: 8pt; margin: 0.25mm 0; color: #000; }
        .header .report-title { font-size: 16pt; font-weight: bold; margin-top: 2mm; text-transform: uppercase; color: #000; letter-spacing: 1px; }
        .student-details-block { margin-bottom: 2.5mm; }
        .student-info-grid { display: grid; grid-template-columns: auto 1fr auto 1fr; gap: 1mm 3mm; font-size: 10pt; margin-bottom:0.5mm;} /* Changed to 10pt */
        .student-info-grid strong {font-weight: bold;}
        .lin-number-display {font-size: 9.5pt; text-align: left; margin-top: 0.5mm;} /* Changed to 9.5pt */
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
        .results-table.p1p3-table td,
        .results-table.p1p3-table th {
            font-size: 9pt; /* Increased from base 8pt for P1-P3 table */
        }
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
            <div class="school-name"><?php echo htmlspecialchars("MARIA OW'EMBABAZI PRIMARY SCHOOL"); ?></div>
            <div class="logo-container"><img src="images/logo.png" alt="School Logo" onerror="this.style.display='none';"></div>
            <div class="school-details">P.O BOX 406, MBARARA</div>
            <div class="school-details">Tel. 0700172858 | Email: houseofnazareth.schools@gmail.com</div>
            <div class="report-title">TERMLY ACADEMIC REPORT</div>
        </div>
        <div class="report-body-content" style="flex-grow: 1;"> <!-- Wrapper for main content -->
        <div class="student-details-block">
            <div class="student-info-grid">
                <strong>STUDENT'S NAME:</strong> <span><?php echo $studentName; ?></span>
                <strong>CLASS:</strong> <span><?php echo $className; ?></span>
                <strong>YEAR:</strong> <span><?php echo $yearName; ?></span>
                <strong>TERM:</strong> <span><?php echo $termName; ?></span>
            </div>
            <div class="lin-number-display"><strong>LIN:</strong> <?php echo $linNo; ?></div>
        </div>

        <?php if ($isP4_P7): ?>
        <div class="academic-summary-grid">
            <strong>AGGREGATE:</strong> <span><?php echo $p4p7Aggregate; ?></span>
            <strong>DIVISION:</strong> <span><?php echo $p4p7Division; ?></span>
        </div>
        <?php elseif ($isP1_P3): ?>
        <div class="academic-summary-grid">
            <strong>POSITION (EOT):</strong> <span><?php echo $p1p3PositionTotalEot; ?> out of <?php echo $totalStudentsInClassForP1P3; ?></span>
            <span></span>
        </div>
        <?php endif; ?>

        <table class="results-table <?php if ($isP1_P3) echo 'p1p3-table'; ?>">
            <thead>
                <tr>
                    <th>SUBJECT</th>
                    <th>B.O.T (100)</th>
                    <?php if (!$isP1_P3): ?><th>GRADE</th><?php endif; ?>
                    <th>M.O.T (100)</th>
                    <?php if (!$isP1_P3): ?><th>GRADE</th><?php endif; ?>
                    <th>END OF TERM (100)</th>
                    <?php if (!$isP1_P3): ?><th>GRADE</th><?php endif; ?>
                    <?php if ($isP1_P3): ?><th>AVERAGE</th><?php endif; ?>
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

                        $bot_grade = htmlspecialchars($subjectPerformance['bot_grade'] ?? 'N/A');
                        $mot_grade = htmlspecialchars($subjectPerformance['mot_grade'] ?? 'N/A');
                        $eot_grade = htmlspecialchars($subjectPerformance['eot_grade'] ?? 'N/A');
                        $subject_term_average = htmlspecialchars($subjectPerformance['subject_term_average'] ?? 'N/A');
                        $eot_remark = htmlspecialchars($subjectPerformance['eot_remark'] ?? 'N/A');
                    ?>
                    <tr>
                        <td class="subject-name"><?php echo $subjDisplayName; ?></td>
                        <td><?php $bs = $subjectPerformance['bot_score'] ?? 'N/A'; echo htmlspecialchars(is_numeric($bs) ? round((float)$bs) : $bs); ?></td>
                        <?php if (!$isP1_P3): ?><td><?php echo $bot_grade; ?></td><?php endif; ?>
                        <td><?php $ms = $subjectPerformance['mot_score'] ?? 'N/A'; echo htmlspecialchars(is_numeric($ms) ? round((float)$ms) : $ms); ?></td>
                        <?php if (!$isP1_P3): ?><td><?php echo $mot_grade; ?></td><?php endif; ?>
                        <td><?php $es = $subjectPerformance['eot_score'] ?? 'N/A'; echo htmlspecialchars(is_numeric($es) ? round((float)$es) : $es); ?></td>
                        <?php if (!$isP1_P3): ?><td><?php echo $eot_grade; ?></td><?php endif; ?>
                        <?php if ($isP1_P3): ?><td><?php echo $subject_term_average; ?></td><?php endif; ?>
                        <td><?php echo $eot_remark; ?></td>
                        <td><?php echo $initialsForSubj; ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($isP1_P3): ?>
                    <tr class="summary-row">
                        <td><strong>AVERAGE</strong></td>
                        <td><strong><?php echo $p1p3OverallAverageBot; ?></strong></td>
                        <?php // No Grade column for P1-P3 ?>
                        <td><strong><?php echo $p1p3OverallAverageMot; ?></strong></td>
                        <?php // No Grade column for P1-P3 ?>
                        <td><strong><?php echo $p1p3AverageEOT; // This is overall EOT Average ?></strong></td>
                        <?php // No Grade column for P1-P3 ?>
                        <td></td> <?php // Empty cell for Subject Term Average column ?>
                        <td colspan="2"></td> <?php // Colspan for Remarks and Initials ?>
                    </tr>
                    <tr class="summary-row">
                        <td><strong>POSITION</strong></td>
                        <td><strong><?php echo $p1p3PositionTotalBot; ?></strong></td>
                        <?php // No Grade column for P1-P3 ?>
                        <td><strong><?php echo $p1p3PositionTotalMot; ?></strong></td>
                        <?php // No Grade column for P1-P3 ?>
                        <td><strong><?php echo $p1p3PositionTotalEot; ?></strong></td>
                        <?php // No Grade column for P1-P3 ?>
                        <td></td> <?php // Empty cell for Subject Term Average column ?>
                        <td colspan="2"></td>
                    </tr>
                <?php endif; ?>

                <?php if ($isP4_P7): ?>
                    <?php
                        // Placeholder variables for BOT and MOT aggregates and divisions.
                        // These might need to be fetched or made available from the backend.
                        // For EOT, we use existing variables.
                        $p4p7_aggregate_bot = $studentSummaryData['p4p7_aggregate_bot_score'] ?? 'N/A';
                        $p4p7_aggregate_mot = $studentSummaryData['p4p7_aggregate_mot_score'] ?? 'N/A';
                        // $p4p7Aggregate is already defined for EOT aggregate

                        $p4p7_division_bot = $studentSummaryData['p4p7_division_bot'] ?? 'N/A';
                        $p4p7_division_mot = $studentSummaryData['p4p7_division_mot'] ?? 'N/A';
                        // $p4p7Division is already defined for EOT division
                    ?>
                    <tr class="summary-row">
                        <td><strong>AGGREGATE</strong></td>
                        <td></td> <?php // Empty cell for BOT Score ?>
                        <td><strong><?php echo htmlspecialchars($p4p7_aggregate_bot); ?></strong></td>
                        <td></td> <?php // Empty cell for MOT Score ?>
                        <td><strong><?php echo htmlspecialchars($p4p7_aggregate_mot); ?></strong></td>
                        <td></td> <?php // Empty cell for EOT Score ?>
                        <td><strong><?php echo $p4p7Aggregate; // Already htmlspecialchar'd ?></strong></td>
                        <td colspan="2"></td> <?php // Colspan for Remarks and Initials ?>
                    </tr>
                    <tr class="summary-row">
                        <td><strong>DIVISION</strong></td>
                        <td></td> <?php // Empty cell for BOT Score ?>
                        <td><strong><?php echo htmlspecialchars($p4p7_division_bot); ?></strong></td>
                        <td></td> <?php // Empty cell for MOT Score ?>
                        <td><strong><?php echo htmlspecialchars($p4p7_division_mot); ?></strong></td>
                        <td></td> <?php // Empty cell for EOT Score ?>
                        <td><strong><?php echo $p4p7Division; // Already htmlspecialchar'd ?></strong></td>
                        <td colspan="2"></td> <?php // Colspan for Remarks and Initials ?>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php /* P1-P3 post-table summary div removed as requested */ ?>

        <div class="remarks-section">
            <div class="remark-block"><strong>Class Teacher's Remarks:</strong><p><?php echo $classTeacherRemark; ?></p><div class="signature-line">Class Teacher's Signature</div></div>
            <div class="remark-block"><strong>Head Teacher's Remarks:</strong><p><?php echo $headTeacherRemark; ?></p><div class="signature-line">Head Teacher's Signature & Stamp</div></div>
        </div>
        <div class="term-dates">
            This Term Ended On: <strong><?php echo $termEndDateFormatted; ?></strong> &nbsp; | &nbsp;
            Next Term Begins On: <strong><?php echo $nextTermBeginDateFormatted; ?></strong>
        </div>
        <?php if ($isP4_P7): ?>
        <div class="grading-scale-section-p4p7">
            <strong>GRADING SCALE</strong>
            <div class="scale-container">
                <?php foreach ($gradingScaleForP4P7Display as $grade => $range): ?>
                    <span class="scale-item"><strong><?php echo htmlspecialchars($grade); ?>:</strong> <?php echo htmlspecialchars($range); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="additional-note-p4p7">Additional Note: Please ensure regular attendance and parental support for optimal performance.</div>
        <?php endif; ?>
        </div> <!-- end .report-body-content -->
        <div class="footer"><i>Good Christian, Good Citizen</i></div>
    </div><!-- report-card-container -->
</body>
</html>
