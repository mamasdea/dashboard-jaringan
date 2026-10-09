<?php

namespace App\Monitoring\Snmp;

use SensitiveParameter;

interface PabrikKlienSnmp
{
    /**
     * @param  array<string, mixed>  $kredensial  v2c: community. v3: pengguna, tingkat_keamanan, protokol_auth, sandi_auth, protokol_priv, sandi_priv.
     *
     * @throws GalatSnmp
     */
    public function buat(
        string $alamat,
        int $port,
        string $versi,
        #[SensitiveParameter] array $kredensial,
        int $batasWaktuMs,
        int $percobaanUlang,
    ): KlienSnmp;
}
