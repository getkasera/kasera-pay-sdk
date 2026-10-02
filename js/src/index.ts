import type { components, operations } from "./schema.js";

type Schemas = components["schemas"];
export type Transaction = Schemas["Transaction"];
export type TransactionList = Schemas["TransactionList"];
export type CreateTransactionRequest = Schemas["CreateTransactionRequest"];
export type ListTransactionsQuery = NonNullable<operations["listTransactions"]["parameters"]["query"]>;
export type PaymentMethodList = Schemas["PaymentMethodList"];
export type Refund = Schemas["Refund"];
export type CreateRefundRequest = Schemas["CreateRefundRequest"];
export type WebhookEvent = Schemas["WebhookEvent"] | Schemas["WebhookTestPing"];
export type { components, operations };

export const VERSION = "0.1.1";

export interface ClientOptions {
  /** Defaults to https://pay.kasera.id */
  baseUrl?: string;
  /** Defaults to the global fetch. */
  fetch?: typeof fetch;
}

export interface WriteOptions {
  /**
   * Deduplicates the create: a retry with the same key and body returns the
   * original object instead of a second one. Your order id works.
   */
  idempotencyKey?: string;
}

/**
 * A non-2xx answer (or no answer: status 0, code network_error). `code` is the
 * stable machine-readable code, e.g. validation_failed; `fields` maps the
 * offending dotted JSON paths to what was wrong; quote `requestId` to support.
 */
export class KaseraPayError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly fields: Record<string, string> = {},
    readonly requestId?: string,
  ) {
    super(message);
    this.name = "KaseraPayError";
  }
}

export class KaseraPay {
  private readonly baseUrl: string;
  private readonly fetch: typeof fetch;

  /** @param apiKey kp_live_... or kp_test_... from the dashboard's Developer page */
  constructor(private readonly apiKey: string, options: ClientOptions = {}) {
    this.baseUrl = (options.baseUrl ?? "https://pay.kasera.id").replace(/\/+$/, "");
    // Wrapped, not stored bare: called as this.fetch(...), the platform fetch
    // would get the client as its receiver, which Cloudflare Workers and
    // browsers reject with "Illegal invocation".
    const f = options.fetch ?? globalThis.fetch;
    this.fetch = (input, init) => f(input, init);
  }

  createTransaction(params: CreateTransactionRequest, options: WriteOptions = {}): Promise<Transaction> {
    return this.request("POST", "/v1/transactions", params, options.idempotencyKey);
  }

  getTransaction(id: string): Promise<Transaction> {
    return this.request("GET", `/v1/transactions/${encodeURIComponent(id)}`);
  }

  listTransactions(query: ListTransactionsQuery = {}): Promise<TransactionList> {
    const qs = new URLSearchParams(
      Object.entries(query).filter(([, v]) => v !== undefined).map(([k, v]) => [k, String(v)]),
    ).toString();
    return this.request("GET", `/v1/transactions${qs ? `?${qs}` : ""}`);
  }

  listPaymentMethods(): Promise<PaymentMethodList> {
    return this.request("GET", "/v1/payment_methods");
  }

  /** Refund a succeeded card payment, in full (omit amount) or in part. */
  createRefund(params: CreateRefundRequest, options: WriteOptions = {}): Promise<Refund> {
    return this.request("POST", "/v1/refunds", params, options.idempotencyKey);
  }

  getRefund(id: string): Promise<Refund> {
    return this.request("GET", `/v1/refunds/${encodeURIComponent(id)}`);
  }

  private async request<T>(method: string, path: string, body?: unknown, idempotencyKey?: string): Promise<T> {
    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.apiKey}`,
      Accept: "application/json",
    };
    // Browsers refuse to set User-Agent; the SDK runs server-side, where it sticks.
    headers["User-Agent"] = `kasera-pay-js/${VERSION}`;
    if (body !== undefined) headers["Content-Type"] = "application/json";
    if (idempotencyKey !== undefined) headers["Idempotency-Key"] = idempotencyKey;

    let res: Response;
    try {
      res = await this.fetch(this.baseUrl + path, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(30_000),
      });
    } catch (e) {
      throw new KaseraPayError(0, "network_error", e instanceof Error ? e.message : String(e));
    }

    const raw = await res.text();
    let json: any;
    try {
      json = JSON.parse(raw);
    } catch {
      json = undefined;
    }
    if (res.ok && json && typeof json === "object") return json as T;

    const err = json?.error ?? {};
    throw new KaseraPayError(
      res.status,
      err.code ?? "unexpected_response",
      err.message ?? `HTTP ${res.status}: ${raw.slice(0, 200)}`,
      err.fields ?? {},
      err.request_id,
    );
  }
}

/** A webhook delivery that failed verification. Answer it 400, never process it. */
export class SignatureError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "SignatureError";
  }
}

export const WEBHOOK_TOLERANCE_SECONDS = 300;

/**
 * Verify a Kasera-Signature-V1 header: "t=<unix>,v1=<hex>[,v1=<hex>]", each v1
 * lowercase hex HMAC-SHA256 over "<unix>.<raw body>" keyed with the endpoint's
 * signing secret. Two v1 entries appear during the 24h after a secret
 * rotation; any match accepts. Deliveries more than five minutes off fail.
 *
 * Uses Web Crypto, so it runs on Node 20+, Deno, Bun and edge runtimes.
 */
export async function verifyWebhook(
  body: string,
  header: string,
  secret: string,
  now: number = Math.floor(Date.now() / 1000),
): Promise<boolean> {
  let t: string | undefined;
  const sigs: string[] = [];
  for (const part of header.split(",")) {
    const i = part.indexOf("=");
    if (i < 0) continue;
    const k = part.slice(0, i).trim();
    const v = part.slice(i + 1).trim();
    if (k === "t") t = v;
    else if (k === "v1") sigs.push(v.toLowerCase());
  }
  if (t === undefined || !/^\d+$/.test(t) || sigs.length === 0) return false;
  if (Math.abs(now - Number(t)) > WEBHOOK_TOLERANCE_SECONDS) return false;

  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey("raw", enc.encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const mac = new Uint8Array(await crypto.subtle.sign("HMAC", key, enc.encode(`${t}.${body}`)));
  const expected = Array.from(mac, (b) => b.toString(16).padStart(2, "0")).join("");
  return sigs.some((sig) => timingSafeEqual(expected, sig));
}

/**
 * Verify and decode a delivery. Pass the RAW request body (await req.text()),
 * never a re-serialized object. Dedupe on the returned event's id: delivery
 * is at-least-once.
 */
export async function constructWebhookEvent(
  body: string,
  header: string,
  secret: string,
  now?: number,
): Promise<WebhookEvent> {
  if (!(await verifyWebhook(body, header, secret, now))) {
    throw new SignatureError("Kasera-Signature-V1 did not verify");
  }
  const event = JSON.parse(body);
  if (!event || typeof event !== "object") throw new SignatureError("Webhook body is not a JSON object");
  return event as WebhookEvent;
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}
