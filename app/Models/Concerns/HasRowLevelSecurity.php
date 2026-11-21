<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait HasRowLevelSecurity
{
    public static function bindDbSession(?User $user): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if ($user) {
            DB::statement('SET @rls_user_id = ?, @rls_user_role = ?', [$user->id, $user->role->value]);
        } else {
            DB::statement('SET @rls_user_id = NULL, @rls_user_role = NULL');
        }
    }

    protected static function rlsUser(): ?User
    {
        return auth('jwt')->user() ?? auth()->user();
    }

    protected static function rlsRequireRole(string ...$roles): void
    {
        $user = static::rlsUser();
        if ($user && ! $user->hasRole(...$roles)) {
            throw new AuthorizationException('RLS: acesso negado ao nível da linha para o perfil '.$user->role->value.'.');
        }
    }

    protected static function rlsScopeOwnRowsUnlessRole(Builder $builder, string $column, string ...$roles): void
    {
        $user = static::rlsUser();
        if ($user && ! $user->hasRole(...$roles)) {
            $builder->where($builder->qualifyColumn($column), $user->id);
        }
    }
}
