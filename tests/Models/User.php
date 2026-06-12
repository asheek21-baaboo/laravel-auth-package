<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Test stand-in for the consuming app's User model. */
class User extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'users';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'email',
        'name',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->getIncrementing() === false && $user->getKey() === null) {
                $user->{$user->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
