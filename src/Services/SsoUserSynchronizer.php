<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage\Services;

use Baaboo\InternalToolComposerAuthPackage\Models\SsoUser;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Upserts {@see SsoUser} from validated JWT claims (login / callback only).
 */
final class SsoUserSynchronizer
{
    public function syncFromClaims(stdClass $claims): SsoUser
    {
        $id = $claims->sub ?? null;
        if (! is_string($id) || $id === '') {
            throw new \InvalidArgumentException('JWT claim "sub" is required to sync an SsoUser.');
        }

        $email = $claims->email ?? null;
        if (! is_string($email) || $email === '') {
            throw new \InvalidArgumentException('JWT claim "email" is required to sync an SsoUser.');
        }

        $name = $claims->name ?? null;
        if (! is_string($name) || $name === '') {
            $name = $email;
        }

        $attributes = ['email' => $email];

        if ($this->tableHasColumn('name')) {
            $attributes['name'] = $name;
        }

        /** @var SsoUser $user */
        $user = SsoUser::query()->updateOrCreate(
            ['id' => $id],
            $attributes,
        );

        return $user;
    }

    private function tableHasColumn(string $column): bool
    {
        $table = (new SsoUser)->getTable();

        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
