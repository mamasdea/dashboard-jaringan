<?php

namespace App\Events;

use App\Models\EndpointMonitoring;
use App\Models\Insiden;

/**
 * Monitoring membuka insiden: endpoint tidak terjangkau. Disiarkan di dalam
 * transaksi penilaian status.
 */
final readonly class InsidenDibuka
{
    public function __construct(public Insiden $insiden, public EndpointMonitoring $endpoint) {}
}
