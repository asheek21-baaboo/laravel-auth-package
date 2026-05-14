<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Baaboo\InternalToolComposerAuthPackage\CurrentUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class MeController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUser,
    ) {}

    /**
     * Return the authenticated user context.
     *
     * Contract (must never change):
     * {
     *   "name":        string,
     *   "role":        string,
     *   "permissions": string[]   — ["*"] only when JWT project_role is admin; otherwise []
     * }
     */
    public function __invoke(): JsonResponse
    {
        $role = $this->currentUser->role();
        $permissions = $role === 'admin' ? ['*'] : [];

        return response()->json([
            'name' => $this->currentUser->email(),
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }
}
