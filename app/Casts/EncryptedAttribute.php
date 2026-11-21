<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Criptografia transparente AES-256-CBC + HMAC-SHA256 em repouso (at-rest).
 */
class EncryptedAttribute implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return (string) $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $str = (string) $value;

        // Evita dupla criptografia caso o valor já seja um payload cifrado válido
        try {
            Crypt::decryptString($str);

            return $str;
        } catch (DecryptException) {
            return Crypt::encryptString($str);
        }
    }
}
