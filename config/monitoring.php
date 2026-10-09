<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Adapter pemeriksa
    |--------------------------------------------------------------------------
    |
    | Implementasi App\Monitoring\Kontrak\AdapterPemeriksa yang dipakai kolektor.
    | Saat ini hanya "simulasi"; adapter nyata (ICMP, SNMP) didaftarkan di
    | App\Providers\MonitoringServiceProvider saat sudah dibuat.
    |
    */

    'adapter' => env('MONITORING_ADAPTER', 'simulasi'),

    /*
    |--------------------------------------------------------------------------
    | Retensi
    |--------------------------------------------------------------------------
    |
    | Umur (hari) data mentah sebelum dihapus `monitoring:pangkas`. Nol berarti
    | tidak pernah dihapus. Riwayat status dan insiden tidak dipangkas, sehingga
    | laporan ketersediaan dan gangguan tetap dapat dihitung untuk masa lalu.
    |
    */

    'retensi' => [
        'hasil_hari' => (int) env('MONITORING_RETENSI_HASIL_HARI', 90),
        'trafik_hari' => (int) env('MONITORING_RETENSI_TRAFIK_HARI', 90),
        'detak_hari' => (int) env('MONITORING_RETENSI_DETAK_HARI', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jadwal dan aturan transisi
    |--------------------------------------------------------------------------
    */

    // Jeda antar-pemeriksaan untuk endpoint baru (detik).
    'interval_detik' => (int) env('MONITORING_INTERVAL_DETIK', 60),

    // "Endpoint tidak terjangkau" setelah sekian pemeriksaan gagal berturut-turut.
    'gagal_berturut' => (int) env('MONITORING_GAGAL_BERTURUT', 3),

    // Keluar dari "tidak terjangkau" setelah sekian pemeriksaan berhasil berturut-turut.
    'pulih_berturut' => (int) env('MONITORING_PULIH_BERTURUT', 2),

    // Hasil dianggap kedaluwarsa (status "Belum diketahui") setelah sekian kali interval.
    'kedaluwarsa_kali_interval' => (int) env('MONITORING_KEDALUWARSA_KALI_INTERVAL', 3),

    /*
    |--------------------------------------------------------------------------
    | Ambang "Koneksi menurun"
    |--------------------------------------------------------------------------
    |
    | Bawaan untuk semua endpoint; tiap endpoint dapat menimpanya.
    |
    */

    'ambang' => [
        'latency_ms' => (float) env('MONITORING_AMBANG_LATENCY_MS', 100),
        'packet_loss_persen' => (float) env('MONITORING_AMBANG_PACKET_LOSS_PERSEN', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kolektor
    |--------------------------------------------------------------------------
    */

    // Pemeriksaan yang berjalan lebih lama dari ini dicatat sebagai galat kolektor.
    'batas_waktu_adapter_detik' => (float) env('MONITORING_BATAS_WAKTU_ADAPTER_DETIK', 10),

    // Klaim pemeriksaan yang lebih tua dari ini dianggap milik kolektor yang mati.
    'batas_klaim_detik' => (int) env('MONITORING_BATAS_KLAIM_DETIK', 120),

    // Endpoint yang diklaim per putaran kolektor.
    'ukuran_tumpak' => (int) env('MONITORING_UKURAN_TUMPAK', 50),

    // Hasil bercap waktu lebih dari ini di depan jam server ditolak (jam kolektor melenceng).
    'toleransi_waktu_depan_detik' => (int) env('MONITORING_TOLERANSI_WAKTU_DEPAN_DETIK', 300),

    /*
    |--------------------------------------------------------------------------
    | Sasaran yang diizinkan
    |--------------------------------------------------------------------------
    |
    | Alamat IP atau CIDR, dipisah koma, yang boleh dihubungi adapter nyata.
    | Kosong berarti tidak ada yang diizinkan. Endpoint di luar daftar ini
    | tidak pernah dikirimi paket; hasilnya dicatat sebagai galat kolektor.
    | Kolektor hanya memeriksa endpoint yang terdaftar: tidak ada pemindaian
    | subnet maupun discovery.
    |
    */

    'sasaran_diizinkan' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('MONITORING_SASARAN_DIIZINKAN', ''))))),

    /*
    |--------------------------------------------------------------------------
    | ICMP dan SNMP
    |--------------------------------------------------------------------------
    |
    | Nilai di luar batas wajar dipangkas di kode (lihat BatasPemeriksaan),
    | supaya salah konfigurasi tidak membanjiri perangkat.
    |
    */

    'icmp' => [
        // Paket per pemeriksaan (1-10) dan batas waktu tunggu tiap balasan (100-5000 ms).
        'jumlah_paket' => (int) env('MONITORING_ICMP_JUMLAH_PAKET', 4),
        'batas_waktu_ms' => (int) env('MONITORING_ICMP_BATAS_WAKTU_MS', 1000),
        // Jalur utilitas ping. Bukan masukan pengguna; hanya dari environment.
        'utilitas' => env('MONITORING_ICMP_UTILITAS', 'ping'),
    ],

    'snmp' => [
        // Batas waktu tiap permintaan (200-5000 ms) dan jumlah percobaan ulang (0-3).
        'batas_waktu_ms' => (int) env('MONITORING_SNMP_BATAS_WAKTU_MS', 1500),
        'percobaan_ulang' => (int) env('MONITORING_SNMP_PERCOBAAN_ULANG', 1),
        // Interface terbanyak yang dibaca dari satu perangkat.
        'maks_interface' => (int) env('MONITORING_SNMP_MAKS_INTERFACE', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint uji
    |--------------------------------------------------------------------------
    |
    | Satu switch untuk uji coba, diatur seluruhnya lewat environment dan
    | dipakai `monitoring:uji-endpoint`. Kredensialnya tidak pernah ditampilkan.
    | SNMPv3 diutamakan; v2c hanya bila perangkat tidak mendukung v3, dengan
    | community baca-saja.
    |
    */

    'uji' => [
        'alamat' => env('MONITORING_UJI_ALAMAT'),
        'port_uplink' => env('MONITORING_UJI_PORT_UPLINK'),
        'snmp' => [
            'versi' => env('MONITORING_UJI_SNMP_VERSI'),
            'port' => (int) env('MONITORING_UJI_SNMP_PORT', 161),
            'community' => env('MONITORING_UJI_SNMP_COMMUNITY'),
            'pengguna' => env('MONITORING_UJI_SNMP_PENGGUNA'),
            'tingkat_keamanan' => env('MONITORING_UJI_SNMP_TINGKAT_KEAMANAN', 'authPriv'),
            'protokol_auth' => env('MONITORING_UJI_SNMP_PROTOKOL_AUTH', 'SHA'),
            'sandi_auth' => env('MONITORING_UJI_SNMP_SANDI_AUTH'),
            'protokol_priv' => env('MONITORING_UJI_SNMP_PROTOKOL_PRIV', 'AES'),
            'sandi_priv' => env('MONITORING_UJI_SNMP_SANDI_PRIV'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Kunci kredensial SNMP
    |--------------------------------------------------------------------------
    |
    | Kredensial profil SNMP disegel dengan kunci publik dan hanya dapat dibuka
    | dengan kunci privat. Host web cukup memegang kunci publik (bisa menyimpan,
    | tidak bisa membaca); kunci privat hanya dipasang di host kolektor.
    | Buat pasangannya dengan `php artisan monitoring:buat-kunci`.
    |
    */

    'kunci_publik' => env('MONITORING_KUNCI_PUBLIK'),
    'kunci_privat' => env('MONITORING_KUNCI_PRIVAT'),

];
