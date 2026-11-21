<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class JwtService
{
    private const TTL_SECONDS = 28800; // 8 horas

    public function issue(User $user): array
    {
        $now = time();
        $exp = $now + self::TTL_SECONDS;
        $header = $this->b64Encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64Encode(json_encode([
            'iss' => config('app.url'),
            'sub' => $user->id,
            'role' => $user->role->value,
            'ver' => (int) Cache::get("jwt_ver:{$user->id}", 1),
            'iat' => $now,
            'exp' => $exp,
            'jti' => (string) Str::uuid(),
        ]));

        $signature = $this->b64Encode(hash_hmac('sha256', "{$header}.{$payload}", $this->secret(), true));

        return [
            'token' => "{$header}.{$payload}.{$signature}",
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    public function authenticate(Request $request): ?User
    {
        $claims = $this->decode($request->bearerToken());
        if (! $claims) {
            return null;
        }

        if (Cache::has("jwt_revoked:{$claims['jti']}")) {
            return null;
        }

        $currentVer = (int) Cache::get("jwt_ver:{$claims['sub']}", 1);
        if (($claims['ver'] ?? 1) < $currentVer) {
            return null;
        }

        $user = User::find($claims['sub']);

        return ($user && $user->ativo) ? $user : null;
    }

    public function revokeToken(?string $token): void
    {
        $claims = $this->decode($token);
        if ($claims && ! empty($claims['jti'])) {
            $ttl = max(60, ($claims['exp'] ?? time()) - time());
            Cache::put("jwt_revoked:{$claims['jti']}", true, $ttl);
        }
    }

    public function revokeAllForUser(User $user): void
    {
        $currentVer = (int) Cache::get("jwt_ver:{$user->id}", 1);
        Cache::forever("jwt_ver:{$user->id}", $currentVer + 1);
    }

    public function decode(?string $token): ?array
    {
        if (! $token || substr_count($token, '.') !== 2) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = explode('.', $token);
        $expectedSig = $this->b64Encode(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $this->secret(), true));

        if (! hash_equals($expectedSig, $sigB64)) {
            return null;
        }

        $claims = json_decode($this->b64Decode($payloadB64), true);
        if (! is_array($claims) || empty($claims['sub']) || empty($claims['exp']) || $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    private function secret(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }

    private function b64Encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64Decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
