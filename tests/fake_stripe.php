<?php
// Fake Stripe API for tests, run with PHP's built-in server. Implements just the endpoints RentNReturn uses,
// honours Idempotency-Key like Stripe (same key → same response), and logs every request for assertions.
// Test-only controls: POST /__test/config (e.g. fail_next_refund=1) and POST /__test/accounts/{id} (set fields).

header('Content-Type: application/json');
$port = $_SERVER['SERVER_PORT'] ?? '0';
$stateFile = sys_get_temp_dir() . "/rnr-fake-stripe-{$port}.json";
$logFile = sys_get_temp_dir() . "/rnr-fake-stripe-{$port}.log";
$fp = fopen($stateFile . '.lock', 'c');
flock($fp, LOCK_EX); // serialise requests (the server may run several workers)
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
$state += ['objects' => [], 'idempotency' => [], 'config' => []];

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$params = $method === 'GET' ? $_GET : $_POST;
$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
file_put_contents($logFile, json_encode(['method' => $method, 'path' => $path, 'params' => $params, 'idempotency_key' => $key]) . "\n", FILE_APPEND);

$respond = static function (int $status, array $body) use (&$state, $stateFile, $fp, $key, $method): void {
    if ($key !== null && $method === 'POST') {
        $state['idempotency'][$key] = [$status, $body];
    }
    file_put_contents($stateFile, json_encode($state));
    flock($fp, LOCK_UN);
    http_response_code($status);
    echo json_encode($body);
    exit;
};
$error = static fn (int $status, string $message) => $respond($status, ['error' => ['type' => 'invalid_request_error', 'message' => $message]]);
$newId = static fn (string $prefix) => $prefix . bin2hex(random_bytes(8));

if (str_starts_with($path, '/__test/')) {
    if ($path === '/__test/config') {
        $state['config'] = array_merge($state['config'], $params);
    } elseif (preg_match('#^/__test/accounts/([\w]+)$#', $path, $m) && isset($state['objects'][$m[1]])) {
        foreach ($params as $k => $v) {
            $state['objects'][$m[1]][$k] = in_array($v, ['true', 'false'], true) ? $v === 'true' : $v;
        }
    }
    $key = null;
    $respond(200, ['ok' => true]);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk_test_fake') {
    $error(401, 'Invalid API Key provided');
}
if ($key !== null && $method === 'POST' && isset($state['idempotency'][$key])) {
    [$status, $body] = $state['idempotency'][$key];
    $key = null;
    $respond($status, $body);
}

switch (true) {
    case $method === 'POST' && $path === '/v1/customers':
        $obj = ['id' => $newId('cus_'), 'object' => 'customer', 'email' => $params['email'] ?? null, 'metadata' => $params['metadata'] ?? []];
        break;
    case $method === 'POST' && $path === '/v1/accounts':
        $obj = ['id' => $newId('acct_'), 'object' => 'account', 'type' => 'express', 'country' => $params['country'] ?? null, 'email' => $params['email'] ?? null,
            'details_submitted' => false, 'charges_enabled' => false, 'payouts_enabled' => false,
            'requirements' => ['currently_due' => ['individual.dob.day', 'external_account']], 'metadata' => $params['metadata'] ?? []];
        break;
    case $method === 'GET' && preg_match('#^/v1/accounts/([\w]+)$#', $path, $m):
        $obj = $state['objects'][$m[1]] ?? $error(404, 'No such account');
        $respond(200, $obj);
    case $method === 'POST' && preg_match('#^/v1/accounts/([\w]+)/login_links$#', $path, $m):
        $respond(200, ['object' => 'login_link', 'url' => 'https://connect.stripe.test/express/' . $m[1]]);
    case $method === 'POST' && $path === '/v1/account_links':
        $respond(200, ['object' => 'account_link', 'url' => 'https://connect.stripe.test/setup/' . $params['account'], 'expires_at' => time() + 300]);
    case $method === 'POST' && $path === '/v1/checkout/sessions':
        $total = 0;
        foreach ($params['line_items'] ?? [] as $li) {
            $total += (int) $li['price_data']['unit_amount'] * (int) $li['quantity'];
        }
        $id = $newId('cs_test_');
        $obj = ['id' => $id, 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/c/pay/' . $id, 'status' => 'open', 'payment_status' => 'unpaid',
            'amount_total' => $total, 'currency' => 'gbp', 'expires_at' => (int) ($params['expires_at'] ?? time() + 86400), 'customer' => $params['customer'] ?? null,
            'metadata' => $params['metadata'] ?? [], 'payment_intent' => null, 'mode' => 'payment'];
        break;
    case $method === 'GET' && preg_match('#^/v1/payment_intents/([\w]+)$#', $path, $m):
        $respond(200, ['id' => $m[1], 'object' => 'payment_intent', 'status' => 'succeeded', 'latest_charge' => 'ch_' . substr($m[1], 3)]);
    case $method === 'POST' && $path === '/v1/refunds':
        if (!empty($state['config']['fail_next_refund'])) {
            $state['config']['fail_next_refund'] = '';
            $error(400, 'Charge has already been refunded.');
        }
        $obj = ['id' => $newId('re_'), 'object' => 'refund', 'amount' => (int) $params['amount'], 'payment_intent' => $params['payment_intent'],
            'status' => $state['config']['refund_status'] ?? 'succeeded', 'metadata' => $params['metadata'] ?? []];
        break;
    case $method === 'POST' && $path === '/v1/transfers':
        if (!empty($state['config']['fail_next_transfer'])) {
            $state['config']['fail_next_transfer'] = '';
            $error(400, 'Insufficient funds in Stripe account.');
        }
        $obj = ['id' => $newId('tr_'), 'object' => 'transfer', 'amount' => (int) $params['amount'], 'currency' => $params['currency'], 'destination' => $params['destination'],
            'transfer_group' => $params['transfer_group'] ?? null, 'source_transaction' => $params['source_transaction'] ?? null];
        break;
    default:
        $error(404, "Unrecognized request URL ({$method}: {$path})");
}
$state['objects'][$obj['id']] = $obj;
$respond(200, $obj);
