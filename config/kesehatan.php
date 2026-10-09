<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health check
    |--------------------------------------------------------------------------
    |
    | `php artisan kesehatan:periksa` selalu tersedia di server. Titik HTTP
    | `/kesehatan` hanya aktif bila token diisi, dan hanya menjawab permintaan
    | yang membawa token itu (Authorization: Bearer ...). Tanpa token, titik
    | itu menjawab 404.
    |
    */

    'token' => env('KESEHATAN_TOKEN'),

    // Umur tanda hidup (detik) sebelum penjadwal atau worker dianggap berhenti.
    'penjadwal_detik' => (int) env('KESEHATAN_PENJADWAL_DETIK', 180),
    'antrean_detik' => (int) env('KESEHATAN_ANTREAN_DETIK', 300),

    // Sisa ruang disk penyimpanan (MB) di bawah ini dianggap bermasalah.
    'disk_minimum_mb' => (int) env('KESEHATAN_DISK_MINIMUM_MB', 1024),

];
