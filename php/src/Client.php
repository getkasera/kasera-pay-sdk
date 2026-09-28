<?php

declare(strict_types=1);

namespace Kasera\Pay;

/**
 * Kasera Pay API client. Responses come back as decoded JSON arrays, shaped
 * exactly as in openapi.json at the repo root.
 */
final class Client
{
    public const VERSION = '0.1.0';

    /** @var callable(string, string, array<string, string>, ?string): array{int, string} */
    private $transport;

    /**
     * @param string $apiKey kp_live_... or kp_test_... from the dashboard's Developer page
     * @param callable|null $transport fn(method, url, headers, body): [status, body]. Defaults to curl.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://pay.kasera.id',
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curl(...);
    }

    /**
     * Create a payment request. Pass an idempotency key (your order id works)
     * so a retried create returns the original instead of a second payment.
     */
    public function createTransaction(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/v1/transactions', $params, $idempotencyKey);
    }

    public function getTransaction(string $id): array
    {
        return $this->request('GET', '/v1/transactions/' . rawurlencode($id));
    }

    /** @param array $query limit, starting_after, status, external_id, merchant_ref, created_after, created_before */
    public function listTransactions(array $query = []): array
    {
        return $this->request('GET', '/v1/transactions' . ($query ? '?' . http_build_query($query) : ''));
    }

    public function listPaymentMethods(): array
    {
        return $this->request('GET', '/v1/payment_methods');
    }

    /** Refund a succeeded card payment, in full (omit amount) or in part. */
    public function createRefund(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/v1/refunds', $params, $idempotencyKey);
    }

    public function getRefund(string $id): array
    {
        return $this->request('GET', '/v1/refunds/' . rawurlencode($id));
    }

    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'kasera-pay-php/' . self::VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $json = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        [$status, $raw] = ($this->transport)($method, rtrim($this->baseUrl, '/') . $path, $headers, $json);

        $decoded = json_decode($raw, true);
        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            return $decoded;
        }
        $err = is_array($decoded) ? ($decoded['error'] ?? []) : [];
        throw new ApiException(
            $status,
            $err['code'] ?? 'unexpected_response',
            $err['message'] ?? "HTTP $status: " . substr($raw, 0, 200),
            $err['fields'] ?? [],
            $err['request_id'] ?? null,
        );
    }

    /** @return array{int, string} */
    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $msg = curl_error($ch);
            curl_close($ch);
            throw new ApiException(0, 'network_error', $msg);
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $raw];
    }
}
