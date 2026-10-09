# Diagram Relasi Database

Keadaan setelah Tahap 6c (tiket gangguan). Diagram ditulis dalam Mermaid; tampil
langsung di GitHub, GitLab, dan pratinjau Markdown VS Code.

Tabel bawaan kerangka kerja (`sessions`, `cache`, `jobs`, dan sejenisnya)
tidak digambar.

## Inventaris dan hubungan jaringan

```mermaid
erDiagram
    opd ||--o{ perangkat : "memiliki"
    opd ||--o{ titik_jaringan : "lokasi (opsional)"
    perangkat ||--o{ port_perangkat : "memiliki"

    tiang ||--o{ titik_jaringan : "tempat terpasang (opsional)"
    tiang ||--o{ tiang_segmen : "dilalui"
    segmen_kabel ||--o{ tiang_segmen : "melewati, berurutan"

    titik_jaringan ||--o{ segmen_kabel : "titik awal"
    titik_jaringan ||--o{ segmen_kabel : "titik akhir"
    titik_jaringan ||--o{ port_terminasi : "memiliki"
    segmen_kabel ||--o{ core_kabel : "terdiri atas"

    titik_jaringan ||--o{ sambungan : "tempat"
    core_kabel ||--o{ sambungan : "core masuk"
    core_kabel ||--o{ sambungan : "core keluar"

    core_kabel |o--o{ port_terminasi : "diterminasi di"

    port_perangkat ||--o| koneksi_endpoint : "satu koneksi"
    port_terminasi |o--o| koneksi_endpoint : "lewat port"
    core_kabel |o--o{ koneksi_endpoint : "atau langsung ke core"
    titik_jaringan |o--o{ koneksi_endpoint : "titik ujung core"

    core_kabel ||--o{ pemakaian_ujung_core : "ujung dipakai"
    titik_jaringan ||--o{ pemakaian_ujung_core : "di titik"

    titik_jaringan |o--o{ riwayat_hubungan : "histori"
    segmen_kabel |o--o{ riwayat_hubungan : "histori rute"

    opd {
        bigint id PK
        string kode UK
        string nama
        decimal lintang
        decimal bujur
        boolean aktif
        boolean simulasi
    }
    perangkat {
        bigint id PK
        bigint opd_id FK
        string kode_aset UK
        string penempatan "opd | diskominfo"
        string jenis
        inet ip_manajemen UK
        string port_uplink
        boolean monitoring_aktif
        boolean switch_utama "maks. satu per OPD"
        boolean simulasi
    }
    port_perangkat {
        bigint id PK
        bigint perangkat_id FK
        string nama "unik per perangkat"
    }
    tiang {
        bigint id PK
        string kode UK
        decimal lintang
        decimal bujur
        string pemilik
        string kondisi
        string foto_path
        string status_data "lengkap | perlu_dilengkapi"
        boolean simulasi
    }
    titik_jaringan {
        bigint id PK
        string kode UK
        string jenis "odc | odp | closure | otb"
        decimal lintang
        decimal bujur
        bigint tiang_id FK
        bigint opd_id FK
        smallint kapasitas_port
        string status_data
        boolean simulasi
    }
    segmen_kabel {
        bigint id PK
        string kode UK
        string klasifikasi "backbone | distribusi"
        smallint jumlah_core
        bigint titik_awal_id FK
        bigint titik_akhir_id FK
        decimal panjang_ukur_m "hasil pengukuran"
        decimal panjang_peta_m "hasil perhitungan jalur"
        jsonb jalur_peta "daftar [bujur, lintang]"
        string status_data
        boolean simulasi
    }
    tiang_segmen {
        bigint id PK
        bigint segmen_kabel_id FK
        bigint tiang_id FK
        smallint urutan "unik per segmen"
    }
    core_kabel {
        bigint id PK
        bigint segmen_kabel_id FK
        smallint nomor "unik per segmen"
        smallint tube
        string warna
        string status "tersedia | terpakai | rusak"
    }
    port_terminasi {
        bigint id PK
        bigint titik_jaringan_id FK
        smallint nomor "unik per titik"
        string status "tersedia | terpakai | rusak"
        bigint core_id FK "core di sisi belakang"
    }
    sambungan {
        bigint id PK
        bigint titik_jaringan_id FK
        bigint core_masuk_id FK
        bigint core_keluar_id FK
        text catatan
    }
    koneksi_endpoint {
        bigint id PK
        bigint port_perangkat_id FK, UK
        bigint port_terminasi_id FK, UK "salah satu ..."
        bigint core_id FK "... dari keduanya"
        bigint titik_jaringan_id FK "wajib bila core_id"
    }
    pemakaian_ujung_core {
        bigint core_id PK, FK
        bigint titik_jaringan_id PK, FK
        string sumber "sambungan | terminasi | endpoint"
        bigint sumber_id
    }
    riwayat_hubungan {
        bigint id PK
        timestamptz waktu
        bigint pelaku_id FK
        string jenis "sambungan | terminasi | koneksi_endpoint | rute_tiang"
        string aksi "dibuat | diubah | dihapus"
        bigint titik_jaringan_id FK
        bigint segmen_kabel_id FK
        text ringkasan
        jsonb sebelum
        jsonb sesudah
    }
```

## Monitoring

```mermaid
erDiagram
    perangkat |o--o| endpoint_monitoring : "dipantau sebagai"
    profil_snmp |o--o{ endpoint_monitoring : "dipakai oleh"
    endpoint_monitoring ||--o{ hasil_pemeriksaan : "menghasilkan"
    endpoint_monitoring ||--|| status_terkini : "berstatus"
    endpoint_monitoring ||--o{ insiden : "mengalami"
    endpoint_monitoring ||--o{ riwayat_status : "berubah status"
    hasil_pemeriksaan |o--o{ riwayat_status : "pemicu"
    insiden |o--o{ riwayat_status : "terkait"
    endpoint_monitoring ||--o| snmp_perangkat : "identitas SNMP"
    endpoint_monitoring ||--o{ interface_snmp : "memiliki"
    interface_snmp ||--o{ sampel_trafik : "deret waktu (uplink)"

    profil_snmp {
        bigint id PK
        string nama UK
        string versi "v2c | v3"
        smallint port
        text kredensial_tersegel "sealed box; hanya kolektor yang dapat membuka"
        boolean simulasi
    }
    endpoint_monitoring {
        bigint id PK
        bigint perangkat_id FK, UK "kosong bila perangkat dihapus"
        string nama
        inet alamat
        string metode "icmp | snmp"
        bigint profil_snmp_id FK "wajib bila snmp"
        int interval_detik "bawaan 60"
        decimal ambang_latency_ms "kosong = bawaan"
        decimal ambang_packet_loss_persen "kosong = bawaan"
        boolean aktif
        boolean pemeliharaan
        timestamptz pemeliharaan_sampai
        timestamptz berikutnya_pada "jadwal"
        timestamptz sedang_diperiksa_sejak "klaim"
        uuid klaim
        string skenario_simulasi
        boolean simulasi
    }
    hasil_pemeriksaan {
        bigint id PK
        bigint endpoint_id FK
        uuid id_pemeriksaan UK "penangkal kiriman ganda"
        timestamptz diperiksa_pada "jam kolektor, UTC"
        timestamptz diterima_pada "jam server, UTC"
        string keluaran "berhasil | gagal_perangkat | galat_kolektor"
        decimal latency_ms
        decimal packet_loss_persen
        string kode_galat
        string sumber "nama adapter"
        boolean diterapkan
        string alasan_tidak_diterapkan "terlambat | pemeliharaan | waktu_di_depan"
        boolean simulasi
    }
    status_terkini {
        bigint id PK
        bigint endpoint_id FK, UK
        string status "normal | menurun | tidak_terjangkau | belum_diketahui | pemeliharaan"
        timestamptz sejak
        int gagal_berturut
        int berhasil_berturut
        int galat_kolektor_berturut
        timestamptz diperiksa_terakhir_pada "untuk menolak hasil tidak berurutan"
        timestamptz hasil_valid_terakhir_pada "dasar kedaluwarsa"
        timestamptz diterima_terakhir_pada
        decimal latency_ms
        decimal packet_loss_persen
        boolean simulasi
    }
    insiden {
        bigint id PK
        bigint endpoint_id FK
        timestamptz mulai_pada
        timestamptz selesai_pada "kosong = masih terbuka"
        string ditutup_karena "pulih | pemeliharaan"
        boolean simulasi
    }
    riwayat_status {
        bigint id PK
        bigint endpoint_id FK
        string dari
        string menjadi
        string alasan
        timestamptz pada
        bigint hasil_pemeriksaan_id FK
        bigint insiden_id FK
        boolean simulasi
    }
```

Tabel data SNMP (semua metrik boleh `NULL`, artinya "Tidak tersedia"):

```mermaid
erDiagram
    snmp_perangkat {
        bigint id PK
        bigint endpoint_id FK, UK
        text sys_descr
        string sys_object_id
        string sys_name
        bigint uptime_tiktik "seperseratus detik"
        timestamptz restart_terdeteksi_pada
        timestamptz diperbarui_pada
    }
    interface_snmp {
        bigint id PK
        bigint endpoint_id FK
        string nama "ifName atau ifDescr; unik per endpoint"
        string alamat_fisik "penguat identitas"
        bigint if_index "nomor terakhir, boleh berubah"
        timestamptz if_index_berubah_pada
        string deskripsi
        string alias
        bigint kecepatan_bps
        smallint status_admin
        smallint status_operasional
        boolean uplink
        decimal oktet_masuk "sampel terakhir"
        decimal oktet_keluar
        boolean penghitung_64_bit
        decimal galat_masuk
        decimal galat_keluar
        decimal buang_masuk
        decimal buang_keluar
        bigint diskontinuitas_tiktik
        bigint uptime_tiktik_saat_sampel
        timestamptz sampel_pada
        decimal bps_masuk "hasil perhitungan"
        decimal bps_keluar
        string alasan_laju_kosong
        decimal sfp_rx_dbm "hanya lewat adapter vendor"
        decimal sfp_tx_dbm
        decimal sfp_suhu_c
        string sfp_sumber
        timestamptz terlihat_terakhir_pada
    }
    sampel_trafik {
        bigint id PK
        bigint interface_snmp_id FK
        timestamptz diambil_pada
        decimal selang_detik
        decimal bps_masuk
        decimal bps_keluar
        decimal galat_masuk "selisih sejak sampel sebelumnya"
        decimal galat_keluar
        decimal buang_masuk
        decimal buang_keluar
        string alasan_laju_kosong
        smallint status_operasional
    }
```

`hasil_pemeriksaan` juga memuat `keluaran_snmp`, `kode_galat_snmp`, dan
`pesan_snmp`; `status_terkini` memuat `snmp_keluaran`, `snmp_kode_galat`,
`snmp_gagal_berturut`, dan `snmp_berhasil_terakhir_pada`. Keluaran SNMP `NULL`
berarti SNMP tidak dicoba.

`detak_kolektor` berdiri sendiri (tanpa relasi): satu baris per proses kolektor
(`nama` = host dan nomor proses, unik), berisi `adapter`, `simulasi`,
`mulai_pada`, `detak_pada`, `putaran`, dan `diperiksa_putaran_terakhir`.
Diperbarui tiap putaran dan dibaca dashboard untuk menilai kesehatan kolektor;
baris yang lebih tua dari sehari dihapus.

Constraint monitoring yang dijaga database:
| Aturan | Dijaga oleh |
|---|---|
| Paling banyak satu insiden terbuka per endpoint | Indeks unik parsial `insiden_satu_terbuka_per_endpoint` |
| Hasil yang sama tidak tersimpan dua kali | Unique `hasil_pemeriksaan.id_pemeriksaan` |
| Satu baris status per endpoint; satu endpoint per perangkat | Unique `status_terkini.endpoint_id`, `endpoint_monitoring.perangkat_id` |
| Endpoint SNMP wajib punya profil; profil yang dipakai tidak dapat dihapus | Check `endpoint_monitoring_snmp_butuh_profil`, foreign key `RESTRICT` |
| Hasil yang tidak diterapkan selalu punya alasan, dan sebaliknya | Check `hasil_pemeriksaan_alasan_konsisten` |
| Riwayat selalu mencatat perubahan (dari ≠ menjadi) | Check `riwayat_status_status_valid` |
| Nilai status, keluaran, metode, dan versi SNMP hanya yang dikenal | Check per kolom |

Semua kolom waktu monitoring bertipe `timestamptz`, dan sesi database memakai
zona waktu UTC.

## Tiket gangguan

```mermaid
erDiagram
    insiden |o--o| tiket : "dikaitkan"
    endpoint_monitoring |o--o{ tiket : "dipantau"
    opd |o--o{ tiket : "terkait"
    perangkat |o--o{ tiket : "terkait"
    segmen_kabel |o--o{ tiket : "aset FO"
    titik_jaringan |o--o{ tiket : "aset FO"
    tiang |o--o{ tiket : "aset FO"
    users |o--o{ tiket : "teknisi"
    users |o--o{ tiket : "pembuat"
    tiket ||--o{ catatan_tiket : "linimasa"
    users |o--o{ catatan_tiket : "penulis"

    tiket {
        bigint id PK
        string nomor UK "TKT-tahun-id"
        string judul
        bigint opd_id FK "nullable"
        bigint perangkat_id FK "nullable"
        bigint segmen_kabel_id FK "nullable"
        bigint titik_jaringan_id FK "nullable"
        bigint tiang_id FK "nullable"
        bigint endpoint_id FK "nullable"
        bigint insiden_id FK "nullable, unik"
        string sumber "otomatis | manual"
        timestamptz waktu_mulai
        timestamptz monitoring_pulih_pada "informasi dari monitoring"
        timestamptz waktu_pemulihan "dinyatakan orang"
        string prioritas "rendah | sedang | tinggi | kritis"
        string status "status pekerjaan"
        bigint teknisi_id FK "nullable"
        bigint dibuat_oleh FK "nullable"
        text dugaan_penyebab "nullable"
        text penyebab_terverifikasi "nullable"
        boolean simulasi
    }

    catatan_tiket {
        bigint id PK
        bigint tiket_id FK
        bigint pengguna_id FK "nullable"
        string pengguna_nama "salinan saat ditulis"
        string jenis "tindakan | catatan | sistem"
        text isi
        string foto_path "nullable, disk privat"
        timestamptz dibuat_pada
    }
```

| Aturan | Dijaga oleh |
|---|---|
| Paling banyak satu tiket otomatis terbuka per endpoint | Indeks unik parsial `tiket_satu_otomatis_terbuka_per_endpoint` (`sumber = 'otomatis'`, status bukan selesai/dibatalkan) |
| Satu insiden paling banyak pada satu tiket | `insiden_id` unik |
| Tiket selesai harus punya waktu pemulihan | Check `tiket_selesai_butuh_pemulihan` |
| Waktu pemulihan tidak sebelum waktu mulai | Check `tiket_pemulihan_setelah_mulai` |
| Paling banyak satu aset FO per tiket | Check `tiket_satu_aset_fo` |
| Sumber, prioritas, status, dan jenis catatan hanya yang dikenal | Check per kolom |
| Tiket simulasi berjudul `[SIMULASI]` | Check `tiket_simulasi_berlabel` |
| Catatan tiket tidak diubah | Model menolak pembaruan |

Semua kunci asing tiket ke objek lain `ON DELETE SET NULL`: tiket adalah
catatan pekerjaan dan tetap ada walaupun perangkat, kabel, atau pengguna yang
dirujuknya dihapus. Status tiket tidak punya hubungan apa pun dengan
`status_terkini`; pemulihan monitoring hanya mengisi `monitoring_pulih_pada`.

## Pengguna dan log

```mermaid
erDiagram
    users ||--o{ log_aktivitas : "pelaku"
    users ||--o{ riwayat_hubungan : "pelaku"

    users {
        bigint id PK
        string name
        string email UK
        string peran "administrator | operator | teknisi | viewer"
    }
    log_aktivitas {
        bigint id PK
        timestamptz waktu
        bigint pelaku_id FK
        string pelaku_nama
        string aksi
        string subjek_tipe
        bigint subjek_id
        jsonb rincian
        inet alamat_ip
    }
```

## Cara membaca model koneksi

```
perangkat ── port_perangkat ══ koneksi_endpoint ══ port_terminasi ── core ─┐
                                                                           │ segmen kabel
              core ── sambungan ── core ─────────── core ── sambungan ── core
                       (di titik)                            (di titik)
```

- **Segmen kabel** membentang di antara dua titik jaringan. Backbone atau
  distribusi adalah kolom `klasifikasi`, bukan tabel terpisah.
- **Tiang** dan segmen berhubungan banyak-ke-banyak lewat `tiang_segmen`, yang
  menyimpan `urutan` tiang dari titik awal ke titik akhir.
- Setiap **core** punya dua ujung: di titik awal dan di titik akhir segmennya.
  Di tiap ujung, core dapat:
  - disambung ke core lain (`sambungan`),
  - diterminasi ke port (`port_terminasi.core_id`), atau
  - dihubungkan langsung ke port perangkat (`koneksi_endpoint.core_id`).
- **Port terminasi** punya sisi belakang (satu core) dan sisi depan (satu
  koneksi endpoint ke port perangkat).

## Pencegahan pemakaian ganda

| Aturan | Dijaga oleh |
|---|---|
| Satu ujung core dipakai paling banyak sekali, lintas sambungan, terminasi, dan koneksi endpoint | Primary key `pemakaian_ujung_core (core_id, titik_jaringan_id)`; barisnya diisi trigger `catat_pemakaian_ujung_core` pada ketiga tabel |
| Core tidak disambung ke dirinya sendiri | Check `sambungan_core_berbeda` |
| Satu port perangkat hanya punya satu koneksi | Unique `koneksi_endpoint.port_perangkat_id` |
| Satu port terminasi hanya terhubung ke satu port perangkat | Unique `koneksi_endpoint.port_terminasi_id` |
| Satu port terminasi hanya menerima satu core | Kolom tunggal `port_terminasi.core_id` |
| Koneksi endpoint menuju tepat satu sasaran (port atau core) | Check `koneksi_endpoint_satu_sasaran` |
| Titik awal dan akhir segmen berbeda | Check `segmen_kabel_ujung_berbeda` |
| Satu tiang tidak muncul dua kali di rute satu segmen; urutan tidak kembar | Unique `(segmen_kabel_id, tiang_id)` dan `(segmen_kabel_id, urutan)` |
| Objek yang masih terhubung tidak dapat dihapus | Foreign key `RESTRICT` dari sambungan, port, dan koneksi |

Aturan berikut dijaga di aplikasi (`App\Fo\LayananHubungan` dan
`App\Validasi\AturanFo`), belum di database:

- core hanya boleh dipakai di titik yang menjadi ujung segmennya;
- core atau port berstatus rusak tidak dapat dihubungkan;
- titik awal/akhir segmen, jumlah core, dan kapasitas port tidak dapat diubah
  bila perubahan itu memutus hubungan yang sudah tercatat;
- kolom `status` core dan port (`tersedia`/`terpakai`) diturunkan dari hubungan
  yang tercatat setiap kali hubungan berubah.

## Dua panjang kabel

| Kolom | Sumber | Diisi oleh |
|---|---|---|
| `panjang_ukur_m` | Pengukuran lapangan (OTDR, meteran) | Pengguna |
| `panjang_peta_m` | Jumlah jarak antar-titik `jalur_peta` (rumus haversine) | Aplikasi, setiap kali jalur berubah; tidak dapat diisi manual |

Selisih keduanya wajar: jalur peta tidak memuat lendutan kabel, cadangan
(slack), dan naik-turun tiang.

## Kelengkapan data

`status_data` dihitung ulang setiap kali objek disimpan:

| Objek | Wajib untuk disimpan | Wajib untuk "Lengkap" |
|---|---|---|
| Tiang | kode | koordinat, pemilik, kondisi |
| Titik jaringan | kode, jenis | lokasi (koordinat, tiang, atau OPD); kapasitas port kecuali closure |
| Segmen kabel | kode | klasifikasi, jumlah core, titik awal, titik akhir, panjang hasil pengukuran, jalur peta |
