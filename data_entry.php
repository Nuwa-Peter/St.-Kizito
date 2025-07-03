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

// Note: db_connection.php is not strictly needed here yet, but good to include if any DB interaction is planned for index.
// require_once 'db_connection.php';

$last_processed_batch_id = $_SESSION['last_processed_batch_id'] ?? null;
$current_teacher_initials_for_session = $_SESSION['current_teacher_initials'] ?? []; // For repopulating form if needed

// Clear report-specific session data that might conflict with DB-driven approach
// unset($_SESSION['report_data']); // process_excel.php no longer sets this with student details
// Let's be more targeted:
if(isset($_SESSION['report_data']) && !isset($_SESSION['last_processed_batch_id'])) {
    // If report_data exists from a very old session structure and no new batch processed, clear it.
    unset($_SESSION['report_data']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card Generator - Maria Ow'embabazi Primary School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logo.png">
    <link href="css/style.css" rel="stylesheet">
    <style>
        body { background-color: #e0f7fa; /* Matching dashboard theme */ }
        .container.main-content {
            background-color: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 20px;
            margin-bottom: 30px; /* Added margin at bottom */
        }
        .card-header-custom {
            background-color: #f8f9fa; /* Light grey background, Bootstrap's default for table headers */
            padding: 0.75rem 1.25rem;
            margin-bottom: 0;
            border-bottom: 1px solid rgba(0,0,0,.125);
            border-top-left-radius: 0.25rem;
            border-top-right-radius: 0.25rem;
        }
    </style>
</head>
<body>
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

    <div class="container main-content">
        <?php
        if (isset($_SESSION['error_message']) && !empty($_SESSION['error_message'])) {
            echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
            unset($_SESSION['error_message']);
        }
        if (isset($_SESSION['success_message']) && !empty($_SESSION['success_message'])) {
            echo '<div class="alert alert-success" role="alert">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
            if ($last_processed_batch_id) {
                echo '<div class="mt-3">';
                // Link to a new page (view_processed_data.php) that will handle fetching details for this batch
                echo '<a href="view_processed_data.php?batch_id=' . htmlspecialchars($last_processed_batch_id) . '" class="btn btn-primary me-2"><i class="fas fa-eye"></i> View Details for Processed Batch ID: ' . htmlspecialchars($last_processed_batch_id) . '</a>';
                // The actual "Generate PDF" and "View Summary" for this batch will be on view_processed_data.php
                echo '</div>';
            }
            unset($_SESSION['success_message']);
            // Don't unset last_processed_batch_id here, might be needed if user navigates away and comes back.
            // Or, more robustly, view_processed_data.php should be the main interaction point for a batch.
        }
        ?>
        <div class="text-center mb-4">
            <!-- Logo removed from here as it's in navbar -->
            <h2>Report Card Data Entry</h2>
            <h4><?php echo htmlspecialchars($selectedClassValue ?? 'New Batch'); // Example, might not be set on initial load ?></h4>
        </div>

        <!-- New Card for Template Downloads -->
        <div class="row justify-content-center"><div class="col-lg-9 mx-auto">
            <div class="card mb-4">
                <h5 class="card-header card-header-custom text-center">Download Marks Entry Template</h5>
                <div class="card-body text-center">
                    <p class="text-muted mb-3">Download the appropriate Excel template for the class level. Each template contains multiple sheets, one for each subject.</p>
                    <div class="dropdown d-inline-block">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="downloadTemplateDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-file-excel"></i> Select Template to Download
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="downloadTemplateDropdown">
                            <li><a class="dropdown-item" href="download_template.php?type=lower"><i class="fas fa-child"></i> Lower Primary (P1-P3)</a></li>
                            <li><a class="dropdown-item" href="download_template.php?type=upper"><i class="fas fa-user-graduate"></i> Upper Primary (P4-P7)</a></li>
                        </ul>
                    </div>
                    <p class="text-muted mt-3"><small>Ensure you have Microsoft Excel or a compatible spreadsheet program to open and edit these files.</small></p>
                </div>
            </div>
        </div></div>
        <!-- End New Card for Template Downloads -->

        <form action="process_excel.php" method="post" enctype="multipart/form-data">
            <div class="row"><div class="col-lg-9 mx-auto"> <!-- Overall Form Width Wrapper -->

            <div class="card mb-4">
                <h5 class="card-header card-header-custom">School & Term Information</h5>
                <div class="card-body">
                    <div class="row mb-3 justify-content-center mt-3">
                        <div class="col-md-3">
                            <label for="class_selection" class="form-label">Class:</label>
                    <select class="form-select" id="class_selection" name="class_selection" required>
                        <option value="" disabled selected>Select Class</option>
                        <optgroup label="Lower Primary">
                            <option value="P1">P1</option>
                            <option value="P2">P2</option>
                            <option value="P3">P3</option>
                        </optgroup>
                        <optgroup label="Upper Primary">
                            <option value="P4">P4</option>
                            <option value="P5">P5</option>
                            <option value="P6">P6</option>
                            <option value="P7">P7</option>
                        </optgroup>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="year" class="form-label">Year:</label>
                    <select class="form-select" id="year" name="year" required>
                        <option value="" disabled selected>Select Year</option>
                        <!-- JS will populate this -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="term" class="form-label">Term:</label>
                    <select class="form-select" id="term" name="term" required>
                        <option value="" disabled selected>Select Term</option>
                        <option value="I">Term I</option>
                        <option value="II">Term II</option>
                        <option value="III">Term III</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3 justify-content-center">
                 <div class="col-md-4">
                    <label for="term_end_date" class="form-label">This Term Ended On:</label>
                    <input type="date" class="form-control" id="term_end_date" name="term_end_date" required>
                </div>
                <div class="col-md-4">
                    <label for="next_term_begin_date" class="form-label">Next Term Begins On:</label>
                    <input type="date" class="form-control" id="next_term_begin_date" name="next_term_begin_date" required>
                </div>
            </div>
            </div></div> <!-- Close School & Term Info Card's card-body -->
            </div> <!-- Close School & Term Info Card -->

            <div class="card mb-4">
                <h5 class="card-header card-header-custom text-center">Upload Marks File & Teacher Initials</h5>
                <div class="card-body">
                    <h6 class="mt-3 text-center">1. Upload Marks Excel File</h6>
                    <p class="text-muted text-center">Upload a single .xlsx file containing all subject marks in their respective sheets. Download the appropriate template above if you haven't already.</p>
                    <div class="row justify-content-center mb-4">
                        <div class="col-md-8">
                             <label for="marks_excel_file" class="form-label" id="marks_excel_file_label">Marks Excel File (.xlsx):</label>
                             <input type="file" class="form-control" id="marks_excel_file" name="marks_excel_file" required accept=".xlsx">
                        </div>
                    </div>

                    <hr>

                    <h6 class="mt-4 text-center">2. Enter Teacher Initials</h6>
                    <p class="text-muted text-center">Enter teacher initials for each subject taught in the selected class. These will appear on the report cards.</p>

                    <div class="row mb-2 subject-initials-row common-subject-initials justify-content-center" id="english-initials-block">
                        <div class="col-md-4 text-end"><label for="english_initials" class="form-label">English Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="english_initials" name="teacher_initials[english]" placeholder="e.g., J.D." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['english'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2 subject-initials-row common-subject-initials justify-content-center" id="mtc-initials-block">
                        <div class="col-md-4 text-end"><label for="mtc_initials" class="form-label">MTC (Math) Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="mtc_initials" name="teacher_initials[mtc]" placeholder="e.g., A.B." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['mtc'] ?? ''); ?>"></div>
                    </div>

                    <!-- P1-P3 Specific Subject Initials -->
                    <div class="row mb-2 subject-initials-row p1p3-subject-initials justify-content-center" id="re-initials-block" style="display:none;">
                        <div class="col-md-4 text-end"><label for="re_initials" class="form-label">R.E Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="re_initials" name="teacher_initials[re]" placeholder="e.g., S.P." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['re'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2 subject-initials-row p1p3-subject-initials justify-content-center" id="lit1-initials-block" style="display:none;">
                        <div class="col-md-4 text-end"><label for="lit1_initials" class="form-label">Literacy I Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="lit1_initials" name="teacher_initials[lit1]" placeholder="e.g., K.L." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['lit1'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2 subject-initials-row p1p3-subject-initials justify-content-center" id="lit2-initials-block" style="display:none;">
                        <div class="col-md-4 text-end"><label for="lit2_initials" class="form-label">Literacy II Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="lit2_initials" name="teacher_initials[lit2]" placeholder="e.g., M.N." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['lit2'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2 subject-initials-row p1p3-subject-initials justify-content-center" id="local_lang-initials-block" style="display:none;">
                        <div class="col-md-4 text-end"><label for="local_lang_initials" class="form-label">Local Language Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="local_lang_initials" name="teacher_initials[local_lang]" placeholder="e.g., O.P." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['local_lang'] ?? ''); ?>"></div>
                    </div>

                    <!-- P4-P7 Specific Subject Initials -->
                    <div class="row mb-2 subject-initials-row p4p7-subject-initials justify-content-center" id="science-initials-block" style="display:none;">
                        <div class="col-md-4 text-end"><label for="science_initials" class="form-label">Science Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="science_initials" name="teacher_initials[science]" placeholder="e.g., C.E." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['science'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-2 subject-initials-row p4p7-subject-initials justify-content-center" id="sst-initials-block" style="display:none;">
                        <div class="col-md-4 text-end"><label for="sst_initials" class="form-label">SST Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="sst_initials" name="teacher_initials[sst]" placeholder="e.g., F.G." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['sst'] ?? ''); ?>"></div>
                    </div>
                    <div class="row mb-3 subject-initials-row p4p7-subject-initials justify-content-center" id="kiswahili-initials-block" style="display:none;">
                        <div class="col-md-4 text-end"><label for="kiswahili_initials" class="form-label">Kiswahili Teacher Initials:</label></div>
                        <div class="col-md-4"><input type="text" class="form-control" id="kiswahili_initials" name="teacher_initials[kiswahili]" placeholder="e.g., H.I." value="<?php echo htmlspecialchars($current_teacher_initials_for_session['kiswahili'] ?? ''); ?>"></div>
                    </div>
                </div>
            </div> <!-- Close Unified File Upload & Initials Card's card-body -->
            </div> <!-- Close Unified File Upload & Initials Card -->

            <!-- General Remarks Card Removed -->

            <div class="d-grid gap-2 col-md-6 mx-auto mt-4 mb-5">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-cogs"></i> Process & Save Data</button>
            </div>
            </div></div> <!-- Close Overall Form Width Wrapper -->
        </form>
    </div>
    <footer class="text-center mt-5 mb-3"><p>&copy; <span id="currentYear"></span> Maria Ow'embabazi Primary School - <i>Good Christian, Good Citizen</i></p></footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/script.js"></script> <!-- js/script.js is assumed to be the same as before -->
</body>
</html>
