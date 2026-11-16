<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Gestor = 'gestor';
    case Operador = 'operador';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin TI',
            self::Gestor => 'Gestor de TI',
            self::Operador => 'Operador de TI',
        };
    }
}
