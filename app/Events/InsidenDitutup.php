<?php

namespace App\Events;

use App\Models\EndpointMonitoring;
use App\Models\Insiden;

/**
 * Monitoring menutup insiden, karena endpoint terjangkau lagi atau karena
 * pemeliharaan. Bukan tanda bahwa pekerjaan teknisi selesai.
 */
final readonly class InsidenDitutup
{
    public function __construct(public Insiden $insiden, public EndpointMonitoring $endpoint) {}
}
