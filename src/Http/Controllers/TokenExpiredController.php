<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class TokenExpiredController extends Controller
{
    public function __invoke(): Response
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'token-expired.blade.php';

        return response(
            view()->file($path, [
                'idpUrl' => CompanyAuth::idpUrl(),
            ])->render(),
        )->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
