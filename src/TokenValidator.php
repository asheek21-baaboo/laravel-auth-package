<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Baaboo\InternalToolComposerAuthPackage\Exceptions\InvalidTokenException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use stdClass;
use Throwable;

class TokenValidator
{
    private const CACHE_KEY = 'baaboo_auth_jwks_public_key';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $idpUrl = CompanyAuth::IDP_URL,
        private readonly int $cacheTtl = CompanyAuth::JWKS_CACHE_TTL,
        private readonly string $jwksPath = CompanyAuth::JWKS_PATH,
        private readonly ?Client $httpClient = null,
    ) {}

    /**
     * Validate a raw JWT string and return its decoded claims.
     *
     * @throws InvalidTokenException
     */
    public function validate(string $token): stdClass
    {
        $keys = $this->getPublicKeys();

        try {
            return JWT::decode($token, $keys);
        } catch (ExpiredException) {
            throw InvalidTokenException::expired();
        } catch (SignatureInvalidException) {
            throw InvalidTokenException::invalidSignature();
        } catch (Throwable $e) {
            throw InvalidTokenException::malformed($e->getMessage());
        }
    }

    /**
     * Fetch the JWKS public keys from the IdP, with caching.
     *
     * @throws InvalidTokenException
     */
    private function getPublicKeys(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $jwks = $this->fetchJwks();
        $keys = JWK::parseKeySet($jwks);

        $this->cache->put(self::CACHE_KEY, $keys, $this->cacheTtl);

        return $keys;
    }

    /**
     * Make the HTTP request to the JWKS endpoint.
     *
     * @throws InvalidTokenException
     */
    private function fetchJwks(): array
    {
        $client = $this->httpClient ?? new Client;
        $url = rtrim($this->idpUrl, '/').$this->jwksPath;

        try {
            $response = $client->get($url, ['timeout' => 5]);
            $body = (string) $response->getBody();

            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw InvalidTokenException::unresolvableKey();
        }
    }

    /**
     * Bust the cached public key.
     * Useful during key rotation or in tests.
     */
    public function forgetCachedKey(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
