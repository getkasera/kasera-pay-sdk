# kasera-pay

Official JavaScript/TypeScript SDK for [Kasera Pay](https://pay.kasera.id): accept QRIS and Virtual Account payments in Indonesia.

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

Source, the PHP SDK and the API spec: [github.com/getkasera/kasera-pay-sdk](https://github.com/getkasera/kasera-pay-sdk). MIT license.
