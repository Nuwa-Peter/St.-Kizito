<?php
// Database connection details - REPLACE WITH YOUR ACTUAL CREDENTIALS
define('DB_HOST', 'localhost');
define('DB_NAME', 'kizito'); // Updated database name
define('DB_USER', 'root'); // Your DB username
define('DB_PASS', '');    // Your DB password (leave empty for default XAMPP root with no password)

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Optional: useful default
} catch (PDOException $e) {
    // In a real app, log this error and show a user-friendly message
    die("Database connection failed: " . $e->getMessage() .
        "<br><br>Ensure the database '" . DB_NAME . "' exists and credentials are correct in db_connection.php. Also, ensure the MySQL server is running via XAMPP.");
}

// Helper function for simple "find or create" lookup table records
function findOrCreateLookup($pdo, $tableName, $columnName, $value, $otherColumns = []) {
    // 1. Trim the input value to remove accidental leading/trailing whitespace.
    $trimmedValue = trim($value);

    $findSql = "SELECT id FROM `$tableName` WHERE `$columnName` = :value_param";
    $stmt = $pdo->prepare($findSql);
    // 2. Use the trimmed value for the lookup.
    $stmt->execute([':value_param' => $trimmedValue]);
    $id = $stmt->fetchColumn();

    if (!$id) { // If no existing ID is found based on $trimmedValue
        // 3. Use the trimmed value for insertion.
        $colsToInsert = [$columnName => $trimmedValue] + $otherColumns;
        $colNamesString = implode(', ', array_map(function($col) { return "`$col`"; }, array_keys($colsToInsert)));
        $colValuesString = implode(', ', array_map(function($col) { return ":$col"; }, array_keys($colsToInsert)));

        $insertSql = "INSERT INTO `$tableName` ($colNamesString) VALUES ($colValuesString)";
        $stmtInsert = $pdo->prepare($insertSql); // Changed variable name to avoid conflict
        try {
            $stmtInsert->execute($colsToInsert);
            $id = $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Check for unique constraint violation (error code 1062 for MySQL)
            if ($e->errorInfo[1] == 1062) {
                // It's a duplicate key error. This means the value *does* exist,
                // possibly due to a race condition or a case-insensitivity mismatch
                // that the initial SELECT didn't catch but the INSERT did.
                // Try to re-fetch the ID.
                error_log("findOrCreateLookup: Insert failed due to duplicate for $tableName ($columnName=$trimmedValue). Re-fetching ID.");
                $stmtRetry = $pdo->prepare($findSql); // Use original $findSql
                $stmtRetry->execute([':value_param' => $trimmedValue]);
                $id = $stmtRetry->fetchColumn();
                if (!$id) {
                    // This is unexpected if 1062 occurred. Log and re-throw or return null.
                    error_log("findOrCreateLookup: CRITICAL - Re-fetch failed after 1062 for $tableName ($columnName=$trimmedValue).");
                    // throw $e; // Or handle more gracefully depending on desired behavior
                    return null;
                }
            } else {
                // Different error, re-throw it
                throw $e;
            }
        }
    } elseif (!empty($otherColumns)) {
        // Record found, and there are other columns to potentially update.
        // This part is usually for tables like 'subjects' where you might update 'subject_name_full' if 'subject_code' matches.
        // For 'terms', $otherColumns is usually empty, so this block might not be relevant for the 'terms' table call.
        $updateParts = [];
        $updateValues = [':id_param' => $id, ':value_param_where' => $trimmedValue]; // Use a different placeholder name for WHERE
        $needsUpdate = false;
        foreach($otherColumns as $key => $val) {
            // Check current value in DB to see if update is needed for this specific 'otherColumn'
            $checkOtherColSql = "SELECT `$key` FROM `$tableName` WHERE `id` = :id_param_check";
            $stmtCheckOther = $pdo->prepare($checkOtherColSql);
            $stmtCheckOther->execute([':id_param_check' => $id]);
            $currentOtherVal = $stmtCheckOther->fetchColumn();
            if ($currentOtherVal !== $val) {
                $updateParts[] = "`$key` = :$key";
                $updateValues[":$key"] = $val;
                $needsUpdate = true;
            }
        }
        if ($needsUpdate && !empty($updateParts)) {
            $updateSql = "UPDATE `$tableName` SET " . implode(', ', $updateParts) . " WHERE `id` = :id_param AND `$columnName` = :value_param_where";
            $stmtUpdate = $pdo->prepare($updateSql);
            $stmtUpdate->execute($updateValues);
        }
    }
    return $id;
}
?>
