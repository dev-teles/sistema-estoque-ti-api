<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class JwtService
{
    public const COOKIE_NAME = 'estoque_ti_jwt';
    private const TTL_SECONDS = 28800; // 8 horas

    public function issue(User $user): array
    {
        $now = time();
        $exp = $now + self::TTL_SECONDS;
        $jti = (string) Str::uuid();
        $header = $this->b64Encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $encryptedClaims = Crypt::encryptString(json_encode([
            'sub' => $user->id,
            'role' => $user->role->value,
            'ver' => (int) Cache::get("jwt_ver:{$user->id}", 1),
            'jti' => $jti,
        ]));
        $payload = $this->b64Encode(json_encode([
            'iss' => config('app.url'),
            'iat' => $now,
            'exp' => $exp,
            'enc' => $encryptedClaims,
        ]));

        $signature = $this->b64Encode(hash_hmac('sha256', "{$header}.{$payload}", $this->secret(), true));

        // Registo server-side da sessão JWT ativa
        Cache::put("jwt_session:{$jti}", $user->id, self::TTL_SECONDS);

        return [
            'token' => "{$header}.{$payload}.{$signature}",
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    public function extractToken(Request $request): ?string
    {
        $cookieToken = $request->cookie(self::COOKIE_NAME);
        if (! is_string($cookieToken) || $cookieToken === '') {
            $rawCookie = (string) $request->header('Cookie', '');
            if ($rawCookie !== '' && preg_match('/(?:^|;\s*)'.preg_quote(self::COOKIE_NAME, '/').'=([^;]+)/', $rawCookie, $m)) {
                $cookieToken = urldecode($m[1]);
            }
        }

        if (is_string($cookieToken) && $cookieToken !== '') {
            try {
                return Crypt::decryptString($cookieToken);
            } catch (DecryptException) {
                return $cookieToken;
            }
        }

        return $request->bearerToken();
    }

    public function makeCookie(string $token): SymfonyCookie
    {
        $encryptedCookieValue = Crypt::encryptString($token);

        return Cookie::make(
            name: self::COOKIE_NAME,
            value: $encryptedCookieValue,
            minutes: (int) (self::TTL_SECONDS / 60),
            path: '/',
            domain: null,
            secure: app()->isProduction() || (bool) request()?->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'strict',
        );
    }

    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::make(
            name: self::COOKIE_NAME,
            value: '',
            minutes: -2628000,
            path: '/',
            domain: null,
            secure: app()->isProduction() || (bool) request()?->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'strict',
        );
    }

    public function authenticate(Request $request): ?User
    {
        $claims = $this->decode($this->extractToken($request));
        if (! $claims) {
            return null;
        }

        $jti = $claims['jti'] ?? '';
        if (! $jti || Cache::has("jwt_revoked:{$jti}") || (int) Cache::get("jwt_session:{$jti}") !== (int) $claims['sub']) {
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
            Cache::forget("jwt_session:{$claims['jti']}");
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
        if (! is_array($claims) || empty($claims['enc']) || empty($claims['exp']) || $claims['exp'] < time()) {
            return null;
        }

        try {
            $inner = json_decode(Crypt::decryptString((string) $claims['enc']), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($inner) || empty($inner['sub'])) {
            return null;
        }

        return array_merge($claims, $inner);
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
