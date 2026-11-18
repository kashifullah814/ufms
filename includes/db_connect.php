<?php
/**
 * Database connection (mysqli). Settings includes/config.php se aati hain.
 * Errors exceptions ki surat mein aate hain taake koi query chupke se fail na ho.
 */
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    // MySQL ka time zone PHP ke barabar rakhein (created_at / reports ke liye)
    $conn->query("SET time_zone = '" . date('P') . "'");
} catch (mysqli_sql_exception $e) {
    error_log('UFMS DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please check includes/config.php and make sure MySQL is running.');
}
