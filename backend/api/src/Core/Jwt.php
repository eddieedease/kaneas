<?php

declare(strict_types=1);

namespace Kaneas\Core;

/**
 * Minimal HS256 JSON Web Token implementation.
 * Only HS256 is accepted on decode, which rules out "alg: none" and algorithm confusion attacks.
 */
final class Jwt
{
    private const LEEWAY = 30;

    public static function encode(array $payload, string $secret): string
    {
        $segments = [
            self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $segments[] = self::base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $secret, true));
        return implode('.', $segments);
    }

    /** @throws HttpException 401 token_invalid | token_expired */
    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'token_invalid');
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(self::base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new HttpException(401, 'token_invalid');
        }

        $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        if (!hash_equals($expected, self::base64UrlDecode($signatureB64))) {
            throw new HttpException(401, 'token_invalid');
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new HttpException(401, 'token_invalid');
        }

        $now = time();
        if (!is_int($payload['exp'] ?? null) || $payload['exp'] + self::LEEWAY < $now) {
            throw new HttpException(401, 'token_expired');
        }
        if (is_int($payload['nbf'] ?? null) && $payload['nbf'] - self::LEEWAY > $now) {
            throw new HttpException(401, 'token_invalid');
        }
        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
