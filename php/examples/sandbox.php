<?php

// Sandbox walk-through: create a payment request with a test key, open the
// checkout link, pay it with the sandbox simulator, then read it back.
//
//   KASERA_API_KEY=kp_test_... php php/examples/sandbox.php
//
// A test key moves no real money.

require __DIR__ . '/../../vendor/autoload.php';

use Kasera\Pay\ApiException;
use Kasera\Pay\Client;

$key = getenv('KASERA_API_KEY') ?: exit("Set KASERA_API_KEY to a kp_test_... key\n");
$client = new Client($key, getenv('KASERA_BASE_URL') ?: 'https://pay.kasera.id');

$orderId = 'sdk-demo-' . bin2hex(random_bytes(4));

try {
    // The order id doubles as the idempotency key: retrying this exact call
    // returns the same payment request instead of creating a second one.
    $tx = $client->createTransaction([
        'amount' => 25000,
        'description' => 'SDK sandbox demo',
        'external_id' => $orderId,
        'checkout' => new stdClass(),
    ], $orderId);
} catch (ApiException $e) {
    exit("Create failed: {$e->errorCode}: {$e->getMessage()}\n");
}

echo "Created {$tx['id']} ({$tx['status']}, Rp{$tx['amount']})\n";
echo "Pay it here: {$tx['checkout_url']}\n";
echo "Waiting for payment (Ctrl-C to stop)...\n";

for ($i = 0; $i < 60; $i++) {
    sleep(5);
    $tx = $client->getTransaction($tx['id']);
    if ($tx['status'] !== 'pending') {
        echo "Status: {$tx['status']}, paid_at {$tx['paid_at']}, fee Rp{$tx['fee']}, net Rp{$tx['net']}\n";
        exit($tx['status'] === 'succeeded' ? 0 : 1);
    }
}
echo "Still pending after 5 minutes.\n";
