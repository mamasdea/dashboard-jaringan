<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Penyedia tile
    |--------------------------------------------------------------------------
    |
    | URL templat tile Leaflet beserta atribusinya. Bawaan memakai server tile
    | OpenStreetMap, yang hanya cocok untuk pemakaian ringan; untuk produksi
    | arahkan ke penyedia berlangganan atau server tile internal.
    |
    | Atribusi wajib ditampilkan sesuai lisensi penyedia. Nilainya boleh berisi
    | tautan HTML dan selalu tampil di sudut peta.
    |
    */

    'tile' => [
        'url' => env('PETA_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'atribusi' => env('PETA_TILE_ATRIBUSI', '&copy; <a href="https://www.openstreetmap.org/copyright">Kontributor OpenStreetMap</a>'),
        'subdomain' => env('PETA_TILE_SUBDOMAIN', 'abc'),
        'zoom_maks' => (int) env('PETA_TILE_ZOOM_MAKS', 19),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tampilan awal
    |--------------------------------------------------------------------------
    |
    | Dipakai bila belum ada aset berkoordinat. Bawaan: pusat kota Wonosobo.
    |
    */

    'pusat' => [
        'lintang' => (float) env('PETA_PUSAT_LINTANG', -7.3589),
        'bujur' => (float) env('PETA_PUSAT_BUJUR', 109.9032),
    ],

    'zoom' => (int) env('PETA_ZOOM', 14),

];
