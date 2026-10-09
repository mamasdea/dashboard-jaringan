<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Penyegaran dashboard
    |--------------------------------------------------------------------------
    |
    | Selang (detik) dashboard membaca ulang hasil monitoring yang tersimpan.
    | Penyegaran hanya membaca database; tidak pernah memicu pemeriksaan.
    | Dipangkas ke 10-300 detik.
    |
    */

    'segarkan_detik' => max(10, min(300, (int) env('DASHBOARD_SEGARKAN_DETIK', 30))),

    /*
    |--------------------------------------------------------------------------
    | Kesehatan kolektor
    |--------------------------------------------------------------------------
    |
    | Kolektor mencatat detak tiap putaran. Tanpa detak selama batas pertama
    | kolektor dianggap terlambat; selama batas kedua dianggap berhenti.
    |
    */

    'kolektor' => [
        'terlambat_detik' => (int) env('DASHBOARD_KOLEKTOR_TERLAMBAT_DETIK', 90),
        'berhenti_detik' => (int) env('DASHBOARD_KOLEKTOR_BERHENTI_DETIK', 300),
    ],

];
