<?php

namespace App\Enums;

/**
 * Klasifikasi segmen kabel.
 */
enum KlasifikasiKabel: string
{
    case Backbone = 'backbone';
    case Distribusi = 'distribusi';

    public function label(): string
    {
        return match ($this) {
            self::Backbone => 'Backbone',
            self::Distribusi => 'Distribusi',
        };
    }
}
