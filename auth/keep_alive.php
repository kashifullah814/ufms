<?php
/**
 * "Stay logged in" (session timeout popup): POST + CSRF. Session zinda ho to last_activity naya, JSON mein baqi waqt.
 * Session khatam ho chuki ho to 401 (page phir login par jata hai).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
no_cache_headers();
header('Content-Type: application/json');

$user = current_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false]));
}
if (!$user || $user['status'] !== 'Active' || account_locked_minutes($user)) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'login' => login_url(session_expired_portal())]));
}
touch_session_activity();
$left = session_time_left($user);
echo json_encode(['ok' => true, 'seconds' => $left['seconds'], 'reason' => $left['reason']]);
