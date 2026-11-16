<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogAcesso extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'logs_acesso';

    protected $fillable = ['user_id', 'evento', 'email', 'rota', 'ip', 'user_agent'];

    protected $casts = ['created_at' => 'datetime'];

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
