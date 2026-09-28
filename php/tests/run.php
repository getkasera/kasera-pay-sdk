<?php

// Run: php php/tests/run.php  (or composer test). Plain checks, no framework;
// check() instead of assert() so it still fails with zend.assertions off.

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Kasera\Pay\ApiException;
use Kasera\Pay\Client;
use Kasera\Pay\SignatureException;
use Kasera\Pay\Webhook;

$failed = 0;
function check(bool $ok, string $what): void
{
    global $failed;
    if (!$ok) {
        $failed++;
        fwrite(STDERR, "FAIL: $what\n");
    }
}

// --- Webhook signatures. The reference signature was computed outside PHP
// (python hmac), so this checks the implementation, not itself.
$secret = 'whsec_testsecret';
$body = '{"id":"evt_1","type":"payment.paid"}';
$t = 1757400000;
$sig = '5f9ae86b39e7d930ed2df6402275182ba092aa4385fea1dd29453b59f4f5e81a';
$ok = fn (string $header, ?int $now = null) => Webhook::verify($body, $header, $secret, $now ?? $t);

check($ok("t=$t,v1=$sig"), 'valid signature');
check($ok("t=$t,v1=" . strtoupper($sig)), 'hex is case-insensitive');
check($ok("t=$t, v1=$sig"), 'space after comma');
check($ok("t=$t,v1=deadbeef,v1=$sig"), 'rotation grace: any entry matches');
check($ok("t=$t,v1=$sig", $t + 300), 'exactly at tolerance');
check(!$ok("t=$t,v1=$sig", $t + 301), 'too old');
check(!$ok("t=$t,v1=$sig", $t - 301), 'from the future');
check(!$ok('t=' . ($t + 1) . ",v1=$sig"), 'timestamp not the signed one');
check(!$ok("t=$t,v1=deadbeef"), 'wrong signature');
check(!$ok("v1=$sig"), 'no timestamp');
check(!$ok("t=$t"), 'no signature');
check(!$ok(''), 'empty header');
check(!$ok("t=abc,v1=$sig"), 'non-numeric timestamp');
check(!Webhook::verify($body . 'x', "t=$t,v1=$sig", $secret, $t), 'body tampered');
check(!Webhook::verify($body, "t=$t,v1=$sig", 'whsec_other', $t), 'wrong secret');

check(Webhook::constructEvent($body, "t=$t,v1=$sig", $secret, $t)['id'] === 'evt_1', 'constructEvent decodes');
try {
    Webhook::constructEvent($body, "t=$t,v1=deadbeef", $secret, $t);
    check(false, 'constructEvent must throw on a bad signature');
} catch (SignatureException) {
}

// --- Client, against a recording fake transport.
$calls = [];
$fake = function (int $status, string $response) use (&$calls): callable {
    return function (string $method, string $url, array $headers, ?string $body) use (&$calls, $status, $response): array {
        $calls[] = compact('method', 'url', 'headers', 'body');
        return [$status, $response];
    };
};

$c = new Client('kp_test_abc', 'https://pay.example/', $fake(201, '{"id":"payreq_1","status":"pending"}'));
$tx = $c->createTransaction(['amount' => 50000, 'external_id' => 'order-9'], 'order-9');
$req = end($calls);
check($tx['id'] === 'payreq_1', 'create returns the decoded body');
check($req['method'] === 'POST' && $req['url'] === 'https://pay.example/v1/transactions', 'create hits POST /v1/transactions, trailing slash trimmed');
check($req['headers']['Authorization'] === 'Bearer kp_test_abc', 'bearer auth');
check($req['headers']['Idempotency-Key'] === 'order-9', 'idempotency key sent');
check($req['headers']['Content-Type'] === 'application/json', 'json content type');
check(json_decode($req['body'], true) === ['amount' => 50000, 'external_id' => 'order-9'], 'body is the params as JSON');

$c->createTransaction(['amount' => 1]);
check(!isset(end($calls)['headers']['Idempotency-Key']), 'no idempotency header when not given');

$c->getTransaction('payreq_a/b');
check(end($calls)['method'] === 'GET' && end($calls)['url'] === 'https://pay.example/v1/transactions/payreq_a%2Fb', 'get escapes the id');
check(end($calls)['body'] === null && !isset(end($calls)['headers']['Content-Type']), 'GET sends no body');

$c->listTransactions(['limit' => 5, 'status' => 'succeeded']);
check(end($calls)['url'] === 'https://pay.example/v1/transactions?limit=5&status=succeeded', 'list builds the query');
$c->listTransactions();
check(end($calls)['url'] === 'https://pay.example/v1/transactions', 'list without query');

$c->listPaymentMethods();
check(end($calls)['url'] === 'https://pay.example/v1/payment_methods', 'payment methods');
$c->createRefund(['transaction_id' => 'payreq_1'], 'rf-1');
check(end($calls)['url'] === 'https://pay.example/v1/refunds' && end($calls)['headers']['Idempotency-Key'] === 'rf-1', 'refund create');
$c->getRefund('rfd_1');
check(end($calls)['url'] === 'https://pay.example/v1/refunds/rfd_1', 'refund get');

$err = new Client('k', 'https://pay.example', $fake(422, '{"error":{"code":"validation_failed","message":"bad","fields":{"amount":"required"},"request_id":"req_1"}}'));
try {
    $err->createTransaction([]);
    check(false, '422 must throw');
} catch (ApiException $e) {
    check($e->status === 422 && $e->errorCode === 'validation_failed' && $e->getMessage() === 'bad', '422 maps code and message');
    check($e->fields === ['amount' => 'required'] && $e->requestId === 'req_1', '422 maps fields and request id');
}

$html = new Client('k', 'https://pay.example', $fake(502, '<html>Bad Gateway</html>'));
try {
    $html->getTransaction('payreq_1');
    check(false, '502 must throw');
} catch (ApiException $e) {
    check($e->status === 502 && $e->errorCode === 'unexpected_response', 'non-JSON error still throws with status');
}

if ($failed > 0) {
    fwrite(STDERR, "$failed check(s) failed\n");
    exit(1);
}
echo "all checks passed\n";
