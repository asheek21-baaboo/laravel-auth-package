<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Ends the user's IdP OAuth session using their access JWT (Bearer token).
 */
final class IdpSessionEndClient
{
    public function __construct(
        private readonly ?Client $httpClient = null,
    ) {}

    /**
     * Best-effort IdP session end — local logout must succeed even if the IdP call fails.
     */
    public function endSession(string $accessToken): void
    {
        $token = trim($accessToken);
        if ($token === '') {
            return;
        }

        $url = CompanyAuth::idpUrl().CompanyAuth::OAUTH_SESSION_END_PATH;
        $client = $this->httpClient ?? new Client;

        try {
            $client->post($url, [
                'http_errors' => false,
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ],
            ]);
        } catch (GuzzleException) {
            // Local cookie/session cleanup still proceeds.
        }
    }
}
