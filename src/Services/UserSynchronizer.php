<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use App\Models\User;
use Baaboo\InternalToolComposerAuthPackage\Exceptions\UserNotProvisionedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Syncs {@see User} from validated JWT claims (login / callback only).
 *
 * When `createUser` is true, creates or updates the local row; otherwise returns the existing row unchanged.
 *
 * @throws UserNotProvisionedException when createUser is false and no local row exists
 */
final class UserSynchronizer
{
    public function syncFromClaims(stdClass $claims): Authenticatable
    {
        $id = $claims->sub ?? null;
        if (! is_string($id) || $id === '') {
            throw new \InvalidArgumentException('JWT claim "sub" is required to sync a user.');
        }

        $email = $claims->email ?? null;
        if (! is_string($email) || $email === '') {
            throw new \InvalidArgumentException('JWT claim "email" is required to sync a user.');
        }

        $name = $claims->name ?? null;
        if (! is_string($name) || $name === '') {
            $name = $email;
        }

        $attributes = ['email' => $email];

        if ($this->tableHasColumn('name')) {
            $attributes['name'] = $name;
        }

        if ($claims->createUser === true) {
            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['id' => $id],
                $attributes,
            );

            return $user;
        }

        $user = User::query()->find($id);

        if (! $user instanceof Authenticatable) {
            throw UserNotProvisionedException::forSub($id);
        }

        return $user;
    }

    private function tableHasColumn(string $column): bool
    {
        $table = (new User)->getTable();

        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
