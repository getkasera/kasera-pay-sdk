# Kasera Pay SDKs

Official client libraries for [Kasera Pay](https://pay.kasera.id): accept QRIS and Virtual Account payments in Indonesia.

| Language | Folder | Package | Status |
|---|---|---|---|
| PHP 8.1+ | [`php/`](php) | [`kasera/kasera-pay`](https://packagist.org/packages/kasera/kasera-pay) | v0.1 |
| JavaScript / TypeScript | [`js/`](js) | [`kasera-pay`](https://www.npmjs.com/package/kasera-pay) | v0.1 |
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

## JavaScript / TypeScript

```sh
npm install kasera-pay
```

Node 20.19+, Deno, Bun and edge runtimes. No dependencies; types are generated from `openapi.json`.

```ts
import { KaseraPay, KaseraPayError } from "kasera-pay";

const kasera = new KaseraPay(process.env.KASERA_API_KEY!); // kp_test_... or kp_live_...

const tx = await kasera.createTransaction(
  { amount: 25000, external_id: "order-1001", checkout: {} },
  { idempotencyKey: "order-1001" }, // a retry returns the same payment
);
// redirect the buyer to tx.checkout_url

await kasera.getTransaction(tx.id);
await kasera.listTransactions({ status: "succeeded", limit: 50 });
await kasera.listPaymentMethods();
```

A failed call throws `KaseraPayError` with `status`, `code` (e.g. `validation_failed`), `fields` and `requestId`.

### Verify webhooks

```ts
import { constructWebhookEvent, SignatureError } from "kasera-pay";

// e.g. a Next.js route handler
export async function POST(req: Request) {
  try {
    const event = await constructWebhookEvent(
      await req.text(), // raw body
      req.headers.get("kasera-signature-v1") ?? "",
      process.env.KASERA_WEBHOOK_SECRET!,
    );
    if (event.type === "payment.paid") {
      // mark event.data.external_id paid; dedupe on event.id
    }
    return new Response("ok");
  } catch (e) {
    if (e instanceof SignatureError) return new Response("bad signature", { status: 400 });
    throw e;
  }
}
```

## Development

```sh
composer install && composer test   # PHP
cd js && npm ci && npm test          # JS
```

PHP releases are plain `vX.Y.Z` tags on this repo (Packagist reads it directly). JS releases are `npm publish` from `js/` (version in `js/package.json`). Go releases will be `go/vX.Y.Z`.

## License

MIT
