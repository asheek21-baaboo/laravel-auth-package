<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Tests\Support;

use Firebase\JWT\JWT;

final class TestJwt
{
    public const KID = 'baaboo-rsa-test';

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    public static function encode(array $payloadOverrides = [], ?int $iat = null): string
    {
        $iat ??= time();
        $payload = array_merge([
            'sub' => 'test-user-id',
            'email' => 'jane@company.test',
            'global_role' => 'staff',
            'aud' => 'hr-portal',
            'project_role' => 'manager',
            'iat' => $iat,
            'exp' => $iat + 900,
        ], $payloadOverrides);

        $pem = file_get_contents(self::privateKeyPath());
        if ($pem === false) {
            throw new \RuntimeException('Could not read test private key.');
        }

        $private = openssl_pkey_get_private($pem, '');
        if ($private === false) {
            throw new \RuntimeException('Invalid test private key.');
        }

        return JWT::encode($payload, $private, 'RS256', self::KID);
    }

    /**
     * @return array<string, mixed>
     */
    public static function jwks(): array
    {
        return self::jwksFromPublicPem(self::publicKeyPath(), self::KID);
    }

    /**
     * @return array<string, mixed>
     */
    public static function jwksFromPublicPem(string $pemPath, string $kid): array
    {
        $pem = file_get_contents($pemPath);
        if ($pem === false) {
            throw new \RuntimeException('Could not read test public key.');
        }

        $pub = openssl_pkey_get_public($pem);
        if ($pub === false) {
            throw new \RuntimeException('Invalid test public key.');
        }

        $details = openssl_pkey_get_details($pub);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new \RuntimeException('Expected RSA public key.');
        }

        /** @var array{n: string, e: string} $rsa */
        $rsa = $details['rsa'];

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => $kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => self::base64UrlEncode($rsa['n']),
                'e' => self::base64UrlEncode($rsa['e']),
            ]],
        ];
    }

    public static function privateKeyPath(): string
    {
        return dirname(__DIR__).'/fixtures/test_private.pem';
    }

    public static function publicKeyPath(): string
    {
        return dirname(__DIR__).'/fixtures/test_public.pem';
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
