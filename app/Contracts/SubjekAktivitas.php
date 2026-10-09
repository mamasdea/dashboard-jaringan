<?php

namespace App\Contracts;

/**
 * Model yang dapat menjadi subjek log aktivitas.
 */
interface SubjekAktivitas
{
    /**
     * Label yang tetap terbaca di log setelah datanya dihapus.
     */
    public function labelAktivitas(): string;
}
