# Kasera Pay SDKs

Official client libraries for [Kasera Pay](https://pay.kasera.id): accept QRIS and Virtual Account payments in Indonesia.

| Language | Folder | Package | Status |
|---|---|---|---|
| PHP 8.1+ | [`php/`](php) | [`kasera/kasera-pay`](https://packagist.org/packages/kasera/kasera-pay) | v0.1 |
| JavaScript / Node | `js/` | — | planned |
| Go | `go/` | — | planned |

Every SDK is written against [`openapi.json`](openapi.json), a copy of the live `/v1` spec. CI fails when it drifts from `https://pay.kasera.id/v1/openapi.json`.

## PHP

```sh
composer require kasera/kasera-pay
```

### Create a payment request

```php
use Kasera\Pay\Client;

$kasera = new Client(getenv('KASERA_API_KEY')); // kp_test_... or kp_live_...

$tx = $kasera->createTransaction([
    'amount'      => 25000,          // whole rupiah
    'external_id' => 'order-1001',
    'checkout'    => new stdClass(), // use the hosted Kasera Pay Checkout page
], 'order-1001');                    // Idempotency-Key: a retry returns the same payment

header('Location: ' . $tx['checkout_url']);
```

Always pass an idempotency key (your order id is fine). It is the only thing that stops a retried request, like a double click or a timeout, from creating a second payment.

### Read it back

```php
$tx   = $kasera->getTransaction('payreq_...');
$page = $kasera->listTransactions(['status' => 'succeeded', 'limit' => 50]);
$methods = $kasera->listPaymentMethods();
```

Responses are plain arrays shaped exactly like the API reference.

### Errors

```php
use Kasera\Pay\ApiException;

try {
    $kasera->createTransaction(['amount' => 0]);
} catch (ApiException $e) {
    $e->status;     // 422
    $e->errorCode;  // validation_failed
    $e->fields;     // ['amount' => '...']
    $e->requestId;  // quote this to support
}
```

### Verify webhooks

```php
use Kasera\Pay\SignatureException;
use Kasera\Pay\Webhook;

try {
    $event = Webhook::constructEvent(
        file_get_contents('php://input'),            // raw body
        $_SERVER['HTTP_KASERA_SIGNATURE_V1'] ?? '',
        getenv('KASERA_WEBHOOK_SECRET'),
    );
} catch (SignatureException) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'payment.paid') {
    // mark $event['data']['external_id'] paid; dedupe on $event['id']
}
```

The verifier checks the timestamp (five-minute tolerance) and accepts either signature during a secret rotation.

### Sandbox

A `kp_test_` key creates test payments that move no real money. [`php/examples/sandbox.php`](php/examples/sandbox.php) creates one, prints the checkout link and waits for it to be paid:

```sh
KASERA_API_KEY=kp_test_... php php/examples/sandbox.php
```

## Development

```sh
composer install && composer test
```

PHP releases are plain `vX.Y.Z` tags on this repo (Packagist reads it directly). Go releases will be `go/vX.Y.Z`.

## License

MIT
