// Runs against the built dist/: npm test. The reference signature was
// computed outside JS (python hmac), the same vector the PHP SDK uses.
import { test } from "node:test";
import assert from "node:assert/strict";
import { KaseraPay, KaseraPayError, SignatureError, constructWebhookEvent, verifyWebhook } from "../dist/index.js";

const secret = "whsec_testsecret";
const body = '{"id":"evt_1","type":"payment.paid"}';
const t = 1757400000;
const sig = "5f9ae86b39e7d930ed2df6402275182ba092aa4385fea1dd29453b59f4f5e81a";
const ok = (header, now = t) => verifyWebhook(body, header, secret, now);

test("webhook signatures", async () => {
  assert.equal(await ok(`t=${t},v1=${sig}`), true, "valid");
  assert.equal(await ok(`t=${t},v1=${sig.toUpperCase()}`), true, "hex is case-insensitive");
  assert.equal(await ok(`t=${t}, v1=${sig}`), true, "space after comma");
  assert.equal(await ok(`t=${t},v1=deadbeef,v1=${sig}`), true, "rotation grace: any entry matches");
  assert.equal(await ok(`t=${t},v1=${sig}`, t + 300), true, "exactly at tolerance");
  assert.equal(await ok(`t=${t},v1=${sig}`, t + 301), false, "too old");
  assert.equal(await ok(`t=${t},v1=${sig}`, t - 301), false, "from the future");
  assert.equal(await ok(`t=${t + 1},v1=${sig}`), false, "timestamp not the signed one");
  assert.equal(await ok(`t=${t},v1=deadbeef`), false, "wrong signature");
  assert.equal(await ok(`v1=${sig}`), false, "no timestamp");
  assert.equal(await ok(`t=${t}`), false, "no signature");
  assert.equal(await ok(""), false, "empty header");
  assert.equal(await ok(`t=abc,v1=${sig}`), false, "non-numeric timestamp");
  assert.equal(await verifyWebhook(body + "x", `t=${t},v1=${sig}`, secret, t), false, "body tampered");
  assert.equal(await verifyWebhook(body, `t=${t},v1=${sig}`, "whsec_other", t), false, "wrong secret");

  assert.equal((await constructWebhookEvent(body, `t=${t},v1=${sig}`, secret, t)).id, "evt_1");
  await assert.rejects(constructWebhookEvent(body, `t=${t},v1=deadbeef`, secret, t), SignatureError);
});

function fake(status, response) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, ...init });
    return new Response(response, { status });
  };
  return { calls, fetch };
}

test("client requests", async () => {
  const f = fake(201, '{"id":"payreq_1","status":"pending"}');
  const c = new KaseraPay("kp_test_abc", { baseUrl: "https://pay.example/", fetch: f.fetch });
  const last = () => f.calls.at(-1);

  const tx = await c.createTransaction({ amount: 50000, external_id: "order-9" }, { idempotencyKey: "order-9" });
  assert.equal(tx.id, "payreq_1");
  assert.equal(last().method, "POST");
  assert.equal(last().url, "https://pay.example/v1/transactions", "trailing slash trimmed");
  assert.equal(last().headers.Authorization, "Bearer kp_test_abc");
  assert.equal(last().headers["Idempotency-Key"], "order-9");
  assert.equal(last().headers["Content-Type"], "application/json");
  assert.deepEqual(JSON.parse(last().body), { amount: 50000, external_id: "order-9" });

  await c.createTransaction({ amount: 1 });
  assert.equal(last().headers["Idempotency-Key"], undefined, "no key when not given");

  await c.getTransaction("payreq_a/b");
  assert.equal(last().url, "https://pay.example/v1/transactions/payreq_a%2Fb", "id escaped");
  assert.equal(last().body, undefined);
  assert.equal(last().headers["Content-Type"], undefined, "GET has no body");

  await c.listTransactions({ limit: 5, status: "succeeded", merchant_ref: undefined });
  assert.equal(last().url, "https://pay.example/v1/transactions?limit=5&status=succeeded", "undefined dropped");
  await c.listTransactions();
  assert.equal(last().url, "https://pay.example/v1/transactions");

  await c.listPaymentMethods();
  assert.equal(last().url, "https://pay.example/v1/payment_methods");
  await c.createRefund({ transaction_id: "payreq_1" }, { idempotencyKey: "rf-1" });
  assert.equal(last().url, "https://pay.example/v1/refunds");
  assert.equal(last().headers["Idempotency-Key"], "rf-1");
  await c.getRefund("rfd_1");
  assert.equal(last().url, "https://pay.example/v1/refunds/rfd_1");
});

test("errors", async () => {
  const e422 = new KaseraPay("k", {
    fetch: fake(422, '{"error":{"code":"validation_failed","message":"bad","fields":{"amount":"required"},"request_id":"req_1"}}').fetch,
  });
  await assert.rejects(e422.createTransaction({ amount: 0 }), (e) => {
    assert.ok(e instanceof KaseraPayError);
    assert.equal(e.status, 422);
    assert.equal(e.code, "validation_failed");
    assert.equal(e.message, "bad");
    assert.deepEqual(e.fields, { amount: "required" });
    assert.equal(e.requestId, "req_1");
    return true;
  });

  const html = new KaseraPay("k", { fetch: fake(502, "<html>Bad Gateway</html>").fetch });
  await assert.rejects(html.getTransaction("payreq_1"), { status: 502, code: "unexpected_response" });

  const down = new KaseraPay("k", { fetch: async () => { throw new TypeError("fetch failed"); } });
  await assert.rejects(down.listPaymentMethods(), { status: 0, code: "network_error", message: "fetch failed" });
});

// Cloudflare Workers and browsers throw "Illegal invocation" when fetch is
// called with a receiver other than the global; Node does not, so the stub
// enforces it.
test("fetch is never called with the client as its receiver", async () => {
  const calls = [];
  async function strictFetch(url, init) {
    if (this !== undefined && this !== globalThis) throw new TypeError("Illegal invocation");
    calls.push(url);
    return new Response('{"data":[]}', { status: 200 });
  }

  await new KaseraPay("k", { baseUrl: "https://pay.example", fetch: strictFetch }).listPaymentMethods();

  const original = globalThis.fetch;
  globalThis.fetch = strictFetch;
  try {
    await new KaseraPay("k", { baseUrl: "https://pay.example" }).listPaymentMethods();
  } finally {
    globalThis.fetch = original;
  }
  assert.deepEqual(calls, ["https://pay.example/v1/payment_methods", "https://pay.example/v1/payment_methods"]);
});
