<?php
/** Budget meeting minutes (PDF) sirf logged-in user (accounts permission) dekh sakta hai. Uploads folder direct access se band hai. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('accounts');

$name = basename((string)db_value("SELECT minutes_file FROM budget_approvals WHERE id = ?", [(int)($_GET['id'] ?? 0)]));
$path = budget_minutes_dir() . $name;
if ($name === '' || !is_file($path) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
    render_not_found('Minutes not found', 'No minutes file is attached to this decision, or the file has been removed.');
}
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
