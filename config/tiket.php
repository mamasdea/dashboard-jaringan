<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tiket otomatis
    |--------------------------------------------------------------------------
    |
    | Bila aktif, insiden monitoring (endpoint tidak terjangkau) membuat tiket
    | gangguan, kecuali endpoint itu sudah punya tiket yang masih terbuka.
    | Tiket otomatis tidak pernah diisi dugaan penyebab: ping yang gagal tidak
    | menunjukkan apa penyebabnya.
    |
    */

    'otomatis' => (bool) env('TIKET_OTOMATIS', true),

    'prioritas_otomatis' => env('TIKET_PRIORITAS_OTOMATIS', 'tinggi'),

];
