<?php

// Webhook endpoint. Point your dashboard's webhook URL here and set
// KASERA_WEBHOOK_SECRET to the endpoint's signing secret.

require __DIR__ . '/../../vendor/autoload.php';

use Kasera\Pay\SignatureException;
use Kasera\Pay\Webhook;

try {
    $event = Webhook::constructEvent(
        file_get_contents('php://input'),           // the RAW body, never a re-encoded array
        $_SERVER['HTTP_KASERA_SIGNATURE_V1'] ?? '',
        getenv('KASERA_WEBHOOK_SECRET'),
    );
} catch (SignatureException) {
    http_response_code(400);
    exit;
}

// Delivery is at-least-once: skip events you have already handled, keyed on
// $event['id'].
if ($event['type'] === 'payment.paid') {
    $paid = $event['data'];
    // Mark your order $paid['external_id'] as paid.
}

http_response_code(200);
