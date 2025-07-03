<?php
// Database connection details - REPLACE WITH YOUR ACTUAL CREDENTIALS
define('DB_HOST', 'localhost');
define('DB_NAME', 'report_card_system'); // Choose your database name
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
    $findSql = "SELECT id FROM `$tableName` WHERE `$columnName` = :value_param";
    $stmt = $pdo->prepare($findSql);
    $stmt->execute([':value_param' => $value]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        $colsToInsert = [$columnName => $value] + $otherColumns;
        $colNamesString = implode(', ', array_map(function($col) { return "`$col`"; }, array_keys($colsToInsert)));
        $colValuesString = implode(', ', array_map(function($col) { return ":$col"; }, array_keys($colsToInsert)));

        $insertSql = "INSERT INTO `$tableName` ($colNamesString) VALUES ($colValuesString)";
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute($colsToInsert);
        $id = $pdo->lastInsertId();
    } elseif (!empty($otherColumns)) {
        // If record found and there are other columns to potentially update (e.g. subject_name_full)
        $updateParts = [];
        $updateValues = [':id_param' => $id, ':value_param' => $value]; // value_param is for WHERE clause
        foreach($otherColumns as $key => $val) {
            // Only update if the new value is different or if the current DB value is NULL
            // This simple version just updates. A more complex check could be added here.
            $updateParts[] = "`$key` = :$key";
            $updateValues[":$key"] = $val;
        }
        if (!empty($updateParts)) {
            $updateSql = "UPDATE `$tableName` SET " . implode(', ', $updateParts) . " WHERE `id` = :id_param AND `$columnName` = :value_param";
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($updateValues);
        }
    }
    return $id;
}
?>
