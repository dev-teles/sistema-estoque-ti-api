<?php

namespace App\Models;

use App\Models\Concerns\HasRowLevelSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LogAcesso extends Model
{
    use HasRowLevelSecurity;

    public const UPDATED_AT = null;

    protected $table = 'logs_acesso';

    protected $fillable = ['user_id', 'evento', 'email', 'rota', 'ip', 'user_agent'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope('rls', fn (Builder $b) => static::rlsScopeOwnRowsUnlessRole($b, 'user_id', 'admin'));
        static::updating(fn () => throw new LogicException('RLS: logs de acesso são imutáveis.'));
        static::deleting(fn () => throw new LogicException('RLS: logs de acesso são imutáveis.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function registrar(string $evento, ?User $user = null, ?string $email = null): void
    {
        $request = request();

        static::create([
            'user_id' => $user?->id,
            'evento' => $evento,
            'email' => $email ?? $user?->email,
            'rota' => $request ? mb_substr($request->method().' '.$request->path(), 0, 255) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
