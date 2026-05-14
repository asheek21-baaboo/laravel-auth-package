<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Http\Controllers;

use Illuminate\Routing\Controller;

class MeController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ]);
    }
}
