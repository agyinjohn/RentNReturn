<?php
// Fake Twilio Messages API for tests. Accepts account AC_test / token test-token; records each request.
header('Content-Type: application/json');
$ok = preg_match('#^/2010-04-01/Accounts/AC_test/Messages\.json$#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))
    && ($_SERVER['PHP_AUTH_USER'] ?? '') === 'AC_test' && ($_SERVER['PHP_AUTH_PW'] ?? '') === 'test-token';
file_put_contents(sys_get_temp_dir() . '/rnr-fake-twilio-' . ($_SERVER['SERVER_PORT'] ?? '0') . '.log', json_encode($_POST) . "\n", FILE_APPEND);
if (!$ok) {
    http_response_code(401);
    echo json_encode(['code' => 20003, 'message' => 'Authenticate']);
    return;
}
http_response_code(201);
echo json_encode(['sid' => 'SM' . bin2hex(random_bytes(16)), 'status' => 'queued']);
