<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;

/**
 * Builds the IdP OAuth2 authorization URL for browser login.
 */
final class SsoAuthorizationUrlBuilder
{
    public function authorizeUrl(): string
    {
        $projectId = config('company-auth.project_id');
        if (! is_string($projectId) || $projectId === '') {
            throw new \RuntimeException('SSO_PROJECT_ID is not configured.');
        }

        $clientId = config('company-auth.client_id');
        if (! is_string($clientId) || $clientId === '') {
            $clientId = $projectId;
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('company-auth.callback'),
            'response_type' => 'code',
            'project_id' => $projectId,
        ]);

        return CompanyAuth::idpUrl().CompanyAuth::OAUTH_AUTHORIZE_PATH.'?'.$query;
    }

    public function logoutUrl(): string
    {
        return CompanyAuth::idpUrl().CompanyAuth::IDP_LOGOUT_PATH;
    }
}
