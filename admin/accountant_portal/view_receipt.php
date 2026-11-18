<?php
/** Expense receipt sirf logged-in (expenses permission) user dekh sakta hai. Uploads folder direct access se band hai. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('expenses');

$name = db_value("SELECT receipt FROM expenses WHERE id = ?", [(int)($_GET['id'] ?? 0)]);
$name = basename((string)$name);
$path = receipt_dir() . $name;
if ($name === '' || !is_file($path)) {
    render_not_found('Receipt not found', 'This expense has no receipt file, or the file has been removed.');
}
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    http_response_code(403);
    exit('This file type cannot be displayed.');
}
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
