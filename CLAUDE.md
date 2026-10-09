# Dashboard Jaringan Intra Pemerintah Kabupaten Wonosobo

Aplikasi Diskominfo untuk inventaris jaringan (perangkat aktif dan infrastruktur
fiber optik) serta pemantauan koneksi OPD ke Diskominfo.

Dokumen pendamping: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md),
[docs/DATABASE.md](docs/DATABASE.md) (diagram relasi),
[docs/PANDUAN-UJI-SWITCH.md](docs/PANDUAN-UJI-SWITCH.md) (uji kolektor nyata),
[docs/LAPORAN.md](docs/LAPORAN.md) (definisi perhitungan laporan),
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), [docs/BACKUP-RESTORE.md](docs/BACKUP-RESTORE.md),
[docs/CHECKLIST-DEPLOYMENT.md](docs/CHECKLIST-DEPLOYMENT.md), dan
[docs/ROADMAP.md](docs/ROADMAP.md). Baca sebelum mengerjakan tahap baru, dan
perbarui DATABASE.md setiap kali skema berubah.

## Tumpukan teknologi

- PHP 8.4, Laravel 13, Livewire 4 (komponen satu berkas, awalan `⚡`), Flux UI, Tailwind 4
- Peta: Leaflet 1.9 dan Leaflet-Geoman (entri Vite terpisah, `resources/js/peta.js`); QR dibuat di server dengan `bacon/bacon-qr-code`
- Autentikasi: Laravel Fortify (login, reset kata sandi, 2FA, konfirmasi kata sandi)
- PostgreSQL 17; Vite 8 (butuh Node >= 22.12, dipakai Node 24 LTS)
- PHPUnit 12, Pint, Larastan

## Perintah

Di PowerShell, siapkan sesi dahulu (PATH PHP 8.4/Node 24 dan menyalakan PostgreSQL):

```powershell
. .\scripts\dev-env.ps1
```

| Keperluan | Perintah |
|---|---|
| Menjalankan aplikasi (server, antrean, log, Vite) | `composer dev` |
| Semua pemeriksaan (Pint, PHPStan, PHPUnit) | `composer test` |
| Satu pengujian | `php artisan test --filter=NamaTest` |
| Merapikan gaya kode | `composer lint` |
| Migrasi | `php artisan migrate` |
| Membuat akun pengguna | `php artisan pengguna:buat` |
| Mengubah peran dari server | `php artisan pengguna:peran {email} {peran}` |
| Build aset produksi | `npm run build` |
| Mengisi data contoh SIMULASI | `php artisan db:seed --class=SimulasiSeeder` |
| Menghapus data SIMULASI | `php artisan simulasi:hapus` |
| Menjalankan kolektor monitoring (proses terpisah) | `php artisan monitoring:kolektor` |
| Melihat status endpoint | `php artisan monitoring:status` |
| Menguji satu endpoint dari environment | `php -d extension=snmp artisan monitoring:uji-endpoint` |
| Membuat kunci penyegel kredensial SNMP | `php artisan monitoring:buat-kunci` |
| Retensi data monitoring mentah | `php artisan monitoring:pangkas [--uji-coba]` |
| Health check server | `php artisan kesehatan:periksa` |
| Melihat jadwal | `php artisan schedule:list` |

Pengujian memakai database PostgreSQL `dashboard_jaringan_test` (lihat `phpunit.xml`),
bukan SQLite, agar perilaku tipe data dan kueri sama dengan produksi.

## Aturan pengerjaan

- Kerjakan satu tahap ROADMAP setiap kali. Jangan membangun fitur tahap berikutnya
  lebih awal; perbarui `docs/ROADMAP.md` saat tahap selesai.
- Periksa isi berkas atau konfigurasi sebelum menimpanya.
- Jalankan `composer test` sebelum menyatakan pekerjaan selesai, dan tambahkan
  pengujian untuk setiap perilaku baru.
- Laporkan hasil dan cara menjalankan setelah setiap tahap.

## Aturan arsitektur

**Monitoring terpisah dari web.**
- Request HTTP dan komponen Livewire tidak boleh menjalankan ping, SNMP, atau
  koneksi apa pun ke perangkat jaringan. Halaman hanya membaca hasil dari database.
- Pemeriksaan dijalankan proses kolektor (`php artisan monitoring:kolektor`),
  yang dapat dipasang di host berbeda dari web.
- Adapter (`App\Monitoring\Adapter`), kontraknya (`App\Monitoring\Kontrak`), dan
  `App\Monitoring\Kolektor` tidak boleh dipanggil dari `app/Livewire`,
  `app/Http`, `resources/views`, atau `routes`. Yang boleh dipakai web hanya
  model dan `App\Monitoring\LayananStatus` (murni database, mis. untuk memulai
  pemeliharaan). `EndpointDanArsitekturTest` menjaga batas ini.
- Akses ke perangkat selalu lewat kontrak `AdapterPemeriksa`; adapter dipilih
  dengan `MONITORING_ADAPTER` dan didaftarkan di `MonitoringServiceProvider`.
  Pengujian tidak boleh menyentuh jaringan.
- Hasil pemeriksaan, status terkini, insiden, dan riwayat status hanya diubah
  lewat `LayananStatus`. Aturan transisinya ada di `MesinStatus` (murni, tanpa
  database dan jam); ubah di sana dan sertakan pengujiannya.
- Kegagalan kolektor (adapter galat, melewati batas waktu) dicatat sebagai
  `galat_kolektor`, bukan `gagal_perangkat`, dan tidak pernah menjadi bukti
  bahwa endpoint tidak terjangkau.
- Pemeriksaan bertumpuk dicegah dengan klaim atomik di `Kolektor::klaim()`.
  Jangan memeriksa endpoint tanpa mengklaimnya.
- Semua cap waktu monitoring bertipe `timestamptz` dan ditulis dalam UTC.
  `diperiksa_pada` adalah jam kolektor, `diterima_pada` jam server.

**Kolektor nyata (adapter `jaringan`).**
- Tidak ada pemindaian subnet dan tidak ada discovery. Kolektor hanya
  menghubungi alamat endpoint yang terdaftar, dan hanya bila alamat itu ada di
  `MONITORING_SASARAN_DIIZINKAN` (`DaftarIzinSasaran`). Pemeriksaan daftar izin
  dilakukan sebelum paket apa pun dikirim; jangan dilewati.
- Sasaran selalu alamat IP harfiah. Nama host tidak diterima dan tidak diurai.
- Utilitas sistem (`ping`) dijalankan lewat `Process` dengan larik argumen dan
  batas waktu. Jangan pernah menyusun string perintah, memakai shell, atau
  memasukkan nilai dari pengguna atau database selain alamat IP yang sudah
  divalidasi.
- SNMP memakai ekstensi PHP `snmp`, dimuat hanya untuk proses kolektor
  (`php -d extension=snmp`, `MIBS=""`). Jangan mengaktifkannya di `php.ini` web
  dan jangan menjalankan `snmpget`/`snmpwalk` dengan kredensial di baris
  perintah. Hanya GET dan walk; tidak ada operasi SET.
- SNMPv3 diutamakan. SNMPv2c hanya pilihan untuk perangkat yang tidak
  mendukung v3, dengan community baca-saja.
- ICMP menentukan status keterjangkauan; SNMP dicatat terpisah
  (`keluaran_snmp`, `status_terkini.snmp_*`). Ping yang berhasil dengan SNMP
  yang gagal tetap "terjangkau".
- Metrik yang tidak dimiliki perangkat disimpan `NULL` dan ditampilkan lewat
  `App\Support\Tampilan` sebagai "Tidak tersedia". Jangan menggantinya dengan
  nol, `?? 0`, atau cast ke angka.
- Laju trafik hanya dihitung `PenghitungLaju` (murni): memperhitungkan selang
  waktu sebenarnya, putaran penghitung 32-bit, reset penghitung, restart
  perangkat, dan laju yang melebihi kapasitas port. Bila ragu hasilnya `NULL`
  beserta alasannya.
- Interface dikenali dari nama (ifName, atau ifDescr) dan dikuatkan alamat
  fisik. `if_index` hanya disimpan sebagai nomor terakhir; jangan dipakai
  sebagai kunci, relasi, atau pembanding antar-pemeriksaan.
- Informasi optik SFP hanya lewat adapter vendor (`App\Monitoring\Sfp`) yang
  mengenali `sysObjectID` perangkat. Tanpa adapter: "Tidak tersedia".
- Batas paket, batas waktu, dan percobaan ulang dipangkas `BatasPemeriksaan`.
  Jangan menambah perulangan percobaan di luar itu. Konkurensi diatur lewat
  jumlah proses kolektor, bukan thread atau proses anak.
- Rahasia tidak boleh muncul di log, keluaran perintah, pesan galat yang
  disimpan, atau argumen proses. Parameter kredensial diberi
  `#[SensitiveParameter]`; pesan dari pustaka dilewatkan `Penyamar` dahulu.
- Kolektor menulis hasil langsung ke database. Bila kelak hasil dikirim lewat
  API, API itu wajib memakai autentikasi per kolektor dan membatasi endpoint
  yang boleh dilaporkan tiap kolektor.

**Dashboard.**
- Judulnya "Status Koneksi OPD ke Diskominfo". Dashboard dan detail OPD hanya
  membaca hasil tersimpan lewat `App\Dashboard\RingkasanStatus` dan
  `KesehatanKolektor`; `wire:poll` dan peta status hanya mengulang pembacaan
  itu. Jangan menambah aksi yang memeriksa perangkat, menjalankan kolektor,
  atau mengubah jadwal pemeriksaan dari halaman web
  (`test_membuka_dashboard_tidak_memicu_ping_atau_snmp`).
- Status OPD adalah status endpoint switch utamanya. OPD tanpa switch utama
  yang dipantau berstatus "Belum diketahui" dengan keterangan sebabnya.
- Status selalu ditampilkan lewat `<x-status-koneksi>` (ikon dan teks) dan, di
  peta, huruf kode `StatusMonitoring::kode()`. Jangan menampilkan status hanya
  dengan warna.
- Jangan menulis "Internet aktif", "online", atau sejenisnya. Ping dan SNMP
  hanya membuktikan switch utama terjangkau dari Diskominfo.
- Dashboard terbuka untuk Viewer, yang tidak berhak melihat inventaris: IP
  manajemen dan tautan ke halaman aset hanya di dalam
  `@can('lihat-aset')`, dan data peta status tidak memuatnya.

**Tiket gangguan.**
- Status tiket (`StatusTiket`) adalah status pekerjaan, terpisah dari status
  monitoring. Monitoring hanya boleh membuat tiket dan menambah catatan sistem
  lewat event `InsidenDibuka`/`InsidenDitutup`; ia tidak pernah mengubah status
  pekerjaan, `waktu_pemulihan`, atau kolom penyebab. `monitoring_pulih_pada`
  hanya informasi.
- Semua perubahan tiket lewat `App\Tiket\LayananTiket`, supaya linimasa
  (`catatan_tiket`, hanya-tambah) selalu terisi.
- Paling banyak satu tiket terbuka hasil monitoring per endpoint: insiden baru
  dikaitkan ke tiket terbuka yang sudah ada (otomatis atau manual). Layanan
  memeriksanya, dan indeks unik `tiket_satu_otomatis_terbuka_per_endpoint`
  menolaknya di database.
- `dugaan_penyebab` dan `penyebab_terverifikasi` adalah dua kolom. Jangan
  menggabungkannya, dan jangan mengisi keduanya dari kode: tiket otomatis
  dibuat tanpa penyebab dan tanpa aset FO.
- Pekerjaan tiket di dalam transaksi monitoring dibungkus savepoint oleh
  `KaitkanTiketDenganInsiden`; kegagalannya dilaporkan dan tidak boleh
  membatalkan penilaian status.
- Hak: `kelola-tiket` untuk semua tiket; `tangani-tiket` hanya untuk tiket
  yang `teknisi_id`-nya pelaku dan masih terbuka (`TiketPolicy::tangani`).
  Halaman rincian memeriksa `view` lagi di `hydrate()`.

**Penelusuran jalur dan potensi terdampak.**
- `App\Fo\PenelusuranJalur` dan `App\Fo\AnalisisDampak` hanya membaca
  inventaris. Jangan memakai status monitoring, hasil pemeriksaan, atau
  insiden di dalamnya, dan jangan menghubungi perangkat.
- Hasil analisis dampak selalu ditampilkan lewat `<x-potensi-terdampak>`,
  yang memuat label `AnalisisDampak::LABEL` ("Potensi terdampak berdasarkan
  inventaris") dan daftar keterbatasan. Jangan menampilkan daftar OPD-nya
  tanpa keduanya.
- Di tempat data inventaris berhenti, penelusuran berhenti dan menambah
  keterbatasan. Jangan menebak sambungan yang tidak tercatat.
- Jangan menulis atau menyiratkan "kabel putus" dari ping yang gagal, baik di
  antarmuka, judul tiket otomatis, maupun catatan sistem.
- Kesehatan kolektor dinilai dari `detak_kolektor`, endpoint yang terlambat
  diperiksa, dan galat kolektor. Kolektor yang hidup tetapi gagal memeriksa
  tidak boleh tampil sehat.

**Laporan.**
- Laporan (`App\Laporan`) hanya membaca data tersimpan dan terbuka untuk
  `lihat-laporan`, termasuk Viewer yang tidak berhak melihat inventaris:
  jangan memasukkan IP manajemen, nomor seri, kontak, atau kredensial ke
  laporan mana pun.
- Definisi ketersediaan ada di `docs/LAPORAN.md` dan diterapkan
  `PenghitungKetersediaan` (murni). Ubah keduanya bersama, dengan
  pengujiannya. Waktu "Belum diketahui" adalah **tanpa data**: tidak boleh
  dihitung sebagai normal ataupun gangguan, dan pembagi nol ditulis "Tidak
  tersedia", bukan 0% atau 100%.
- Laporan ketersediaan dan gangguan bersumber dari `riwayat_status` dan
  `insiden`, bukan `hasil_pemeriksaan`, supaya tidak terpengaruh retensi.
- Halaman, CSV, dan PDF dibuat dari satu `DokumenLaporan`; catatan definisi
  dan keterbatasannya selalu ikut diekspor. Sel CSV dilewatkan
  `Ekspor::amankan()` (pencegah rumus). PDF dibuat dompdf tanpa sumber luar.
- Setiap ekspor dicatat (`AksiAktivitas::LaporanDiekspor`).

**Operasi dan deployment.**
- Penjadwal (`routes/console.php`) tidak boleh menjalankan kolektor atau apa
  pun yang menghubungi perangkat. Isinya tanda hidup, retensi, pembersihan.
- `monitoring:pangkas` hanya menghapus data mentah (`hasil_pemeriksaan`,
  `sampel_trafik`, `detak_kolektor` lama). Jangan menambahkan
  `riwayat_status`, `insiden`, `log_aktivitas`, `riwayat_hubungan`, atau tiket.
- `/kesehatan` hanya menjawab pembawa `KESEHATAN_TOKEN` dan hanya dengan
  baik/bermasalah per komponen; rincian hanya di `kesehatan:periksa`.
- Berkas di `deploy/` belum diuji di Ubuntu. Bila mengubahnya, perbarui
  `docs/DEPLOYMENT.md` dan `docs/CHECKLIST-DEPLOYMENT.md`, dan jangan
  melonggarkan bawaan yang tertutup (nginx `deny all`, role kolektor terbatas).
- Kolektor memakai role database terbatas (`dashboard_kolektor`). Tabel baru
  yang ditulis dari jalur kolektor (mis. lewat event insiden) memerlukan hak
  baru; tambahkan ke daftar GRANT di `docs/DEPLOYMENT.md`.

**Makna status.**
- Yang dipantau adalah koneksi endpoint (IP manajemen switch OPD) ke Diskominfo.
  Jangan pernah menampilkan atau menyimpulkan "internet OPD berfungsi" dari
  ping atau SNMP, dan jangan memakai kata "online" atau "internet" untuk status.
- Lima status (`App\Enums\StatusMonitoring`): Normal, Koneksi menurun, Endpoint
  tidak terjangkau, Belum diketahui, Pemeliharaan.
- Yang ditampilkan kepada orang selalu `StatusTerkini::statusTampil()`, bukan
  kolom `status` mentah: hasil yang lebih tua dari batas kedaluwarsa tampil
  sebagai "Belum diketahui" meskipun kolektor dan penyapunya mati.
- Selalu tampilkan waktu pemeriksaan terakhir bersama statusnya.
**Rahasia.**
- Kredensial database dan kunci aplikasi hanya di `.env`. Jangan commit `.env`,
  jangan menulis rahasia di kode, seeder, log, atau dokumen.
- Setiap variabel baru ditambahkan ke `.env.example` dengan nilai kosong atau contoh.
- Kredensial SNMP disimpan di tabel `profil_snmp`, tersegel dengan kunci publik
  (`App\Support\BrankasKredensial`), dan tidak pernah di tabel `perangkat` atau
  `endpoint_monitoring`. Hanya kolektor, yang memegang
  `MONITORING_KUNCI_PRIVAT`, yang dapat membukanya; host web cukup memegang
  kunci publik.
- Kode web boleh memanggil `ProfilSnmp::aturKredensial()`, tetapi tidak pernah
  `kredensial()`. Kredensial tidak dikirim ke browser, tidak ditampilkan kembali
  di formulir (hanya dapat diganti), dan tidak masuk log.
**Data simulasi.**
- Data contoh wajib ditandai: kolom boolean `simulasi` bernilai benar dan nama
  berawalan `[SIMULASI]`. Objek tanpa nama (tiang, titik jaringan, segmen
  kabel) memakai kode berawalan `SIM-`. Antarmuka menampilkan lencana SIMULASI pada data tersebut.
- Seeder simulasi terpisah dari seeder data nyata dan menolak berjalan di
  lingkungan `production`. Sumbernya `database/simulasi/*.csv`.
- Model berkolom `simulasi` memakai trait `BerlabelSimulasi`; tabelnya punya
  check constraint yang mewajibkan awalan `[SIMULASI]`.
- Hasil pemeriksaan dari pemeriksa simulasi ditandai SIMULASI sampai ke tampilan.

**Inventaris.**
- Aturan validasi tiap entitas ada di satu kelas `App\Validasi\Aturan*` dan
  dipakai bersama oleh formulir Livewire dan impor CSV. Jangan menulis aturan
  kedua di komponen atau pengimpor.
- Impor CSV lewat turunan `App\Impor\PengimporCsv`: pratinjau menjalankan impor
  di dalam transaksi lalu membatalkannya, sehingga laporan per baris sama
  dengan hasil impor sebenarnya. Kunci (`kode`, `kode_aset`) yang sudah ada
  berarti memperbarui.
- Tabel `perangkat` tidak boleh memuat kredensial apa pun (SNMP community,
  USM, kata sandi); tempatnya `profil_snmp`. `PerangkatTest` dan
  `ProfilSnmpTest` menjaga nama kolomnya.
- `monitoring_aktif` pada perangkat ber-IP membuat dan mengaktifkan satu
  `endpoint_monitoring` (lewat `EndpointMonitoring::sinkronkanDari()`);
  mematikannya menonaktifkan endpoint tanpa menghapus riwayatnya. Halaman
  inventaris tidak menampilkan status koneksi.
- Foto perangkat disimpan di disk privat dan hanya dilayani lewat rute
  berotorisasi (`perangkat.foto`), bukan dari `public/`.
- IP contoh memakai rentang dokumentasi RFC 5737 (`192.0.2.0/24`,
  `198.51.100.0/24`), bukan alamat jaringan pemerintah.

**Inventaris FO.**
- Hubungan jaringan (sambungan core, terminasi ke port, koneksi endpoint, rute
  tiang) hanya diubah lewat `App\Fo\LayananHubungan`. Layanan itu menjalankan
  transaksi, memperbarui status core/port, dan menambah `riwayat_hubungan`.
  Jangan membuat atau menghapus baris tabel tersebut langsung dari komponen.
- Satu ujung core (core di satu titik) dipakai paling banyak sekali. Aturan ini
  dijaga database lewat `pemakaian_ujung_core` dan trigger; jangan menulis ke
  tabel itu dari aplikasi. Rinciannya di `docs/DATABASE.md`.
- `riwayat_hubungan` hanya dapat ditambah, sama seperti log aktivitas.
- Pendataan bertahap: untuk tiang, titik jaringan, dan segmen kabel hanya kode
  (dan jenis titik) yang wajib. Jangan menambah kolom wajib lain; tambahkan ke
  `kekurangan()` model supaya objeknya berstatus "Perlu dilengkapi".
- `panjang_ukur_m` (hasil pengukuran) dan `panjang_peta_m` (dihitung dari
  `jalur_peta`) tidak boleh saling menimpa. `panjang_peta_m` tidak fillable.
- Backbone/distribusi adalah kolom `klasifikasi` segmen kabel, bukan model lain.
- Perubahan yang memutus hubungan tercatat (mengurangi jumlah core atau
  kapasitas port, mengganti ujung segmen, menghapus objek) ditolak dengan pesan,
  lewat `AturanFo::galatPerubahan*`, sebelum database menolaknya.

**Peta, QR, dan label.**
- Penyedia tile, atribusi, dan tampilan awal peta hanya dari `config/peta.php`
  (variabel `PETA_*`). Jangan menulis URL tile di JavaScript atau view, dan
  jangan menyembunyikan kontrol atribusi Leaflet.
- Data peta (`/peta/data`) hanya memuat identitas dan posisi. IP manajemen,
  nomor seri, kontak, dan catatan tidak boleh ditambahkan ke sana; rinciannya
  ada di halaman detail.
- Tombol dan mode ubah di peta hanya kenyamanan. Koordinat dan jalur disimpan
  lewat aksi Livewire `simpanKoordinat` / `simpanJalur`, yang memanggil
  `authorize('update', ...)` sendiri.
- Isi QR selalu dari `App\Support\TautanAset::url()`: alamat aplikasi, jenis,
  dan kode aset. Tidak boleh ada token, kredensial, query string, atau IP
  perangkat. Halaman yang dituju tetap di belakang `auth` dan policy `view`.
- QR dibuat di server (`App\Support\KodeQr`); jangan memakai layanan pembuat QR
  pihak ketiga, karena isi QR akan terkirim keluar.
- Label cetak hanya memuat jenis, kode aset, QR, dan penanda SIMULASI.
- Teks dari database yang ditampilkan lewat JavaScript memakai `textContent`,
  bukan `innerHTML`.

**Bahasa dan penamaan.**
- Seluruh teks antarmuka berbahasa Indonesia (`APP_LOCALE=id`). Pesan validasi
  ada di `lang/id/validation.php`; tambahkan nama atribut baru di sana.
- Istilah domain memakai bahasa Indonesia di kode: model, tabel, kolom, perintah
  (mis. `Perangkat`, `tiang`, `pengguna:buat`). Karena pluralisasi Eloquent
  berbahasa Inggris, setiap model domain menetapkan nama tabel secara eksplisit.
- Kode bawaan kerangka kerja (`User`, `users`, Fortify) dibiarkan apa adanya.
- Komentar mengikuti bahasa berkas di sekitarnya.

**Data.**
- Waktu disimpan dalam UTC dan ditampilkan dalam `config('app.display_timezone')` (WIB).
  Koneksi PostgreSQL memakai zona waktu sesi UTC (`config/database.php`);
  jangan dihapus, karena kolom `timestamptz` akan bergeser.
- Integritas dijaga di database: foreign key, unique, dan check constraint,
  tidak hanya validasi aplikasi.
- Alamat IP memakai tipe `inet` PostgreSQL.

**Akses.**
- Pendaftaran mandiri dinonaktifkan; akun dibuat oleh Administrator.
- Semua halaman selain login berada di belakang middleware `auth`.
- Empat peran (`App\Enums\Peran`): Administrator, Operator Diskominfo, Teknisi,
  Viewer. Matriks izinnya hanya ada di `Peran::izin()`; jangan memeriksa nama
  peran di tempat lain (`if ($user->peran === ...)`), periksa izinnya.
- Setiap izin (`App\Enums\Izin`) adalah Gate. Fitur baru memakai izin yang sudah
  ada (`kelola-aset`, `kelola-endpoint`, `kelola-tiket`, `lihat-aset`,
  `tangani-tiket`, `lihat-laporan`, ...) lewat Policy model masing-masing.
- Otorisasi wajib di backend pada tiga lapis: middleware `can:` di rute,
  `authorize()` di `boot()` komponen Livewire (berlaku untuk setiap request ke
  komponen), dan `authorize()` di setiap aksi yang mengubah data. `@can` di
  view hanya untuk menyembunyikan tombol, bukan pengaman.
- ID data yang sedang disunting di komponen Livewire diberi `#[Locked]`.
- `peran` tidak fillable; hanya diatur lewat alur yang sudah diotorisasi.
- Setiap fitur yang mengubah data punya pengujian bahwa Viewer ditolak, baik
  lewat URL maupun pemanggilan aksi langsung (contoh: `PenolakanAksesTest`).

**Log aktivitas.**
- Aktivitas penting dicatat lewat `App\Support\PencatatAktivitas` ke tabel
  `log_aktivitas`: autentikasi, perubahan pengguna dan peran, akses ditolak.
  Perubahan data penting pada fitur baru (aset, endpoint, tiket, konfigurasi)
  ikut dicatat dengan menambah kasus di `App\Enums\AksiAktivitas`.
- Log hanya dapat ditambah. Jangan membuat rute, aksi, atau perintah yang
  mengubah atau menghapusnya.
- Kata sandi, token, dan rahasia lain tidak boleh masuk ke rincian log.
