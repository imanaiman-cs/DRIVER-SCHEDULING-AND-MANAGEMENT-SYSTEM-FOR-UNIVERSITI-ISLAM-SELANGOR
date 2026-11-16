<?php
// A stand-in for the OpenWA server used by t_whatsapp.py: records each send-text call and answers like OpenWA (HTTP 201 + JSON).
// Run: php -S 127.0.0.1:2786 tests/http/fake_openwa.php     (never used outside the tests)
$log = sys_get_temp_dir() . '/fake_openwa.jsonl';
if (preg_match('#^/api/sessions/([^/]+)/messages/send-text$#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($key !== 'test-key') { http_response_code(401); echo '{"error":"bad key"}'; return; }
    file_put_contents($log, json_encode(['session' => $m[1], 'body' => json_decode(file_get_contents('php://input'), true)]) . "\n", FILE_APPEND);
    http_response_code(201);
    header('Content-Type: application/json');
    echo '{"id":"msg' . mt_rand() . '","status":"sent"}';
    return;
}
http_response_code(404);
echo '{"error":"not found"}';
