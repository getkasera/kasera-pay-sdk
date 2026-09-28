<?php

declare(strict_types=1);

namespace Kasera\Pay;

/**
 * Kasera-Signature-V1 verification.
 *
 * Header format: "t=<unix>,v1=<hex>[,v1=<hex>]" — each v1 is lowercase hex
 * HMAC-SHA256 over "<unix>.<raw body>" keyed with the endpoint's signing
 * secret. Two v1 entries appear during the 24h after a secret rotation; any
 * match accepts. Deliveries more than five minutes off are rejected.
 */
final class Webhook
{
    public const TOLERANCE = 300;

    /**
     * Verify and decode a delivery. Pass the RAW request body
     * (file_get_contents('php://input')), not a re-encoded array.
     * Dedupe on the returned event's id: delivery is at-least-once.
     *
     * @throws SignatureException
     */
    public static function constructEvent(string $body, string $header, string $secret, ?int $now = null): array
    {
        if (!self::verify($body, $header, $secret, $now)) {
            throw new SignatureException('Kasera-Signature-V1 did not verify');
        }
        $event = json_decode($body, true);
        if (!is_array($event)) {
            throw new SignatureException('Webhook body is not a JSON object');
        }
        return $event;
    }

    public static function verify(string $body, string $header, string $secret, ?int $now = null): bool
    {
        $now ??= time();
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $t = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $sigs[] = strtolower($kv[1]);
            }
        }
        if ($t === null || !ctype_digit($t) || $sigs === []) {
            return false;
        }
        if (abs($now - (int) $t) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }
}
