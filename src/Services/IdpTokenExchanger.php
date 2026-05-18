<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Baaboo\InternalToolComposerAuthPackage\Data\IdpTokenResponse;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\CodeExchangeException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

final class IdpTokenExchanger
{
    public function __construct(
        private readonly ?Client $httpClient = null,
    ) {}

    /**
     * Exchange a one-time authorization code for an access JWT (server-side only).
     *
     * @throws CodeExchangeException
     */
    public function exchange(string $code, string $redirectUri): string
    {
        $projectId = config('company-auth.project_id');
        $clientSecret = config('company-auth.client_secret');

        if (! is_string($projectId) || $projectId === '') {
            throw CodeExchangeException::idpRejected('APP_PROJECT_ID is not configured.');
        }

        if (! is_string($clientSecret) || $clientSecret === '') {
            throw CodeExchangeException::idpRejected('COMPANY_AUTH_CLIENT_SECRET is not configured.');
        }

        $clientId = config('company-auth.client_id');
        if (! is_string($clientId) || $clientId === '') {
            $clientId = $projectId;
        }

        $url = CompanyAuth::idpUrl().CompanyAuth::TOKEN_EXCHANGE_PATH;

        $client = $this->httpClient ?? new Client;

        try {
            $response = $client->post($url, [
                'http_errors' => false,
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'project_id' => $projectId,
                ],
            ]);
        } catch (GuzzleException) {
            throw CodeExchangeException::transportFailed();
        }

        if ($response->getStatusCode() >= 400) {
            throw CodeExchangeException::idpRejected();
        }

        try {
            /** @var array<string, mixed> $body */
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $tokenResponse = IdpTokenResponse::fromArray($body);
        } catch (CodeExchangeException $e) {
            throw $e;
        } catch (Throwable) {
            throw CodeExchangeException::invalidResponse();
        }

        return $tokenResponse->accessToken;
    }
}
