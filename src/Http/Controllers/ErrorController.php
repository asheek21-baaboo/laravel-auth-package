<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class ErrorController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $stub = $request->query('stub');
        $stub = is_string($stub) && $stub !== '' ? $stub : null;

        /** @var array<string, array{message: string, description?: string|null}> $errors */
        $errors = config('company-auth.errors', []);

        $fallback = $errors['fallback'] ?? [
            'message' => 'Token Expired',
            'description' => 'The token has expired. Please log in again.',
        ];

        $copy = ($stub !== null && isset($errors[$stub])) ? $errors[$stub] : $fallback;

        $message = $copy['message'] ?? $fallback['message'];
        $description = $copy['description'] ?? $fallback['description'] ?? null;
        if (! is_string($description) || $description === '') {
            $description = null;
        }

        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'error.blade.php';

        return response(
            view()->file($path, [
                'message' => $message,
                'description' => $description,
            ])->render(),
        )->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
