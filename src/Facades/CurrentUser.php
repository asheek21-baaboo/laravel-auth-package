<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Facades;

use Baaboo\InternalToolComposerAuthPackage\CurrentUserService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string id()
 * @method static string email()
 * @method static string globalRole()
 * @method static string projectId()
 * @method static string role()
 *
 * @see CurrentUserService
 */
class CurrentUser extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CurrentUserService::class;
    }
}
