<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Models;

use Baaboo\InternalToolComposerAuthPackage\Services\SsoUserSynchronizer;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Local shadow profile for an IdP user (JWT {@see sub} = {@see $id}).
 *
 * Synced on login via {@see SsoUserSynchronizer};
 * loaded on each authenticated request — not re-synced unless missing (edge case).
 */
class SsoUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sso_users';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'email',
        'name',
    ];

    public function getAuthPasswordName(): string
    {
        return 'password';
    }
}
