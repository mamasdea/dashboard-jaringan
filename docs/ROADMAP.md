# Peta Jalan

Satu tahap dikerjakan setiap kali. Tahap dinyatakan selesai bila `composer test`
lolos dan hasilnya sudah dilaporkan. Urutan dan isi tahap yang belum dimulai
dapat disesuaikan.

## Tahap 0 — Fondasi (selesai, 9 Oktober 2026)

- [x] Pemeriksaan lingkungan: PHP, Composer, Node.js, database, ping, SNMP
- [x] Pemilihan versi dari dokumentasi resmi: Laravel 13, Livewire 4, PHP 8.4
- [x] PHP 8.4, Node.js 24 LTS, dan PostgreSQL 17 dipasang berdampingan di Laragon
- [x] Proyek dari starter kit Livewire resmi dengan autentikasi Fortify
- [x] PostgreSQL untuk aplikasi dan pengujian
- [x] Pendaftaran mandiri dinonaktifkan; perintah `pengguna:buat`
- [x] `CLAUDE.md`, `docs/ARCHITECTURE.md`, `docs/ROADMAP.md`

## Tahap 1 — Hak akses, pengguna, dan log aktivitas (selesai, 9 Oktober 2026)

- [x] Empat peran: Administrator, Operator Diskominfo, Teknisi, Viewer
- [x] Izin sebagai Gate dan `UserPolicy`; otorisasi di rute, komponen, dan aksi
- [x] Halaman pengelolaan pengguna (tambah, ubah, peran, atur ulang kata sandi, hapus)
- [x] Log aktivitas dan halamannya
- [x] Pengujian bahwa Viewer tidak dapat mengubah data lewat URL atau request langsung
- [x] Perintah `pengguna:peran`; hapus akun sendiri ditiadakan

## Tahap 1b — Bahasa dan tampilan dasar

- [ ] Terjemahan Indonesia untuk halaman bawaan (login, pengaturan, validasi)
- [ ] Halaman beranda dan tata letak dengan identitas aplikasi
- [ ] Komponen lencana SIMULASI
- [ ] Menyesuaikan workflow CI

## Tahap 2 — OPD dan perangkat (selesai, 9 Oktober 2026)

- [x] OPD: kode, nama, alamat, koordinat, kontak teknis, status aktif
- [x] Perangkat: pemilik, penempatan, kode aset, identitas, IP manajemen, port uplink, penanda monitoring dan switch utama, foto, catatan
- [x] CRUD Livewire dengan pencarian, filter, validasi, dan pagination
- [x] Impor CSV dengan pratinjau, laporan kesalahan per baris, dan templat
- [x] Data contoh berlabel SIMULASI dan perintah penghapusnya
- [x] Pesan validasi berbahasa Indonesia untuk aturan yang dipakai

Belum termasuk: tabel port per perangkat (port uplink masih berupa teks).

## Tahap 3 — Inventaris fiber optik (selesai, 9 Oktober 2026)

- [x] Tiang, titik jaringan (ODC, ODP, closure, terminasi/OTB), segmen kabel
- [x] Core kabel dan port terminasi dibuat otomatis; port perangkat
- [x] Rute tiang berurutan (banyak-ke-banyak), jalur peta, panjang ukur dan panjang peta terpisah
- [x] Sambungan core, terminasi ke port, koneksi endpoint
- [x] Pencegahan pemakaian ganda di layanan dan di database
- [x] Riwayat perubahan hubungan jaringan
- [x] Pendataan bertahap dengan status "Perlu dilengkapi"
- [x] Diagram relasi di `docs/DATABASE.md`; data contoh FO berlabel SIMULASI

Belum termasuk: penelusuran jalur core ujung ke ujung, impor CSV untuk objek
FO, dan peta.

## Tahap 3b — Kelanjutan inventaris FO

- [x] Penelusuran jalur core dari port perangkat sampai ujung seberang (dikerjakan di Tahap 6c)
- [ ] Sambung lurus banyak core sekaligus (core 1–N ke core 1–N)
- [ ] Impor CSV tiang, titik jaringan, dan segmen kabel
- [ ] Halaman riwayat hubungan lintas titik dengan pencarian

## Tahap 3c — Peta jaringan, QR, dan label (selesai, 9 Oktober 2026)

- [x] Peta Leaflet: OPD, switch utama, tiang, titik jaringan, jalur backbone dan distribusi berbeda warna
- [x] Filter jenis aset dan OPD, pencarian kode aset, popup detail
- [x] Menggeser koordinat aset serta menggambar dan menyunting jalur kabel, sesuai hak akses
- [x] Halaman detail aset beralamat tetap, QR, dan label cetak
- [x] Penyedia tile dan atribusi dari konfigurasi

Belum termasuk: status koneksi di peta, pengelompokan penanda (clustering),
dan menambah aset baru langsung dari peta.

## Tahap 4a — Fondasi monitoring dengan adapter simulasi (selesai, 9 Oktober 2026)

- [x] Endpoint monitoring, profil SNMP tersegel, hasil pemeriksaan, status terkini, insiden, riwayat status
- [x] Lima status dan aturan transisi (3 gagal, 2 pulih, kedaluwarsa, pemeliharaan)
- [x] Kontrak adapter dan adapter simulasi; kolektor sebagai proses terpisah
- [x] Pencegahan pemeriksaan bertumpuk; galat kolektor dibedakan dari kegagalan perangkat
- [x] Cap waktu UTC; hasil tidak berurutan dan kiriman ganda ditangani
- [x] Pengujian arsitektur: web tidak memanggil adapter, kolektor, atau pembuka kredensial

## Tahap 4b — Kolektor ICMP dan SNMP (kode selesai 9 Oktober 2026; uji perangkat nyata belum)

- [x] Adapter `jaringan`: ICMP lewat `ping` sistem, SNMP lewat ekstensi PHP
- [x] Daftar izin sasaran (alamat/CIDR); tanpa pemindaian dan discovery
- [x] SNMPv3 dan SNMPv2c baca-saja: identitas, uptime, interface, penghitung trafik, galat, buang
- [x] Hasil ICMP dan SNMP dicatat terpisah; metrik tidak didukung tampil "Tidak tersedia"
- [x] Perhitungan laju: selang nyata, putaran 32-bit, reset penghitung, restart perangkat
- [x] Interface dikenali dari nama dan alamat fisik, bukan ifIndex
- [x] Kontrak adapter SFP per vendor (MikroTik, belum diuji)
- [x] Perintah `monitoring:uji-endpoint`, konfigurasi worker, panduan uji satu switch
- [ ] **Uji terhadap satu switch sungguhan** mengikuti `docs/PANDUAN-UJI-SWITCH.md`
- [ ] Menjalankan kolektor sebagai layanan di host tersendiri dengan role database sendiri
- [ ] Retensi atau peringkasan `hasil_pemeriksaan` dan `sampel_trafik`

## Tahap 5 — Kelanjutan SNMP

- [ ] Halaman pengelolaan profil SNMP: kredensial hanya dapat diganti, tidak ditampilkan
- [ ] Memilih profil SNMP per endpoint dari antarmuka
- [ ] Mencocokkan `interface_snmp` dengan `port_perangkat` dan `perangkat.port_uplink`
- [ ] Adapter SFP untuk vendor switch yang benar-benar dipakai di lapangan
- [ ] API penerima hasil dengan autentikasi per kolektor, bila kolektor tidak boleh mengakses database langsung

## Tahap 6 — Dashboard status (selesai, 9 Oktober 2026)

- [x] Halaman "Status Koneksi OPD ke Diskominfo": jumlah OPD per status, gangguan aktif, pemeriksaan terakhir
- [x] Status kesehatan kolektor dari tanda hidup, endpoint terlambat, dan galat kolektor
- [x] Peta status dengan huruf dan bentuk penanda, selain warna
- [x] Detail OPD: perangkat utama, port uplink, latency, packet loss, trafik, uptime, riwayat gangguan
- [x] Penyegaran berkala dari data tersimpan; tidak pernah memicu pemeriksaan
- [x] Terbuka untuk Viewer tanpa membuka IP manajemen

## Tahap 6b — Kelanjutan dashboard dan operasi

- [ ] Pengelolaan endpoint: interval, ambang, dan pemeliharaan oleh operator
- [ ] Grafik latency dan trafik uplink
- [ ] Status koneksi OPD di peta inventaris

## Tahap 6c — Tiket gangguan, penelusuran jalur, potensi terdampak (selesai, 9 Oktober 2026)

- [x] Tiket: nomor, OPD/perangkat/aset FO terkait, waktu mulai, prioritas, teknisi, status pekerjaan, waktu pemulihan
- [x] Dugaan penyebab dan penyebab terverifikasi sebagai dua kolom
- [x] Linimasa tindakan, catatan, dan foto (hanya-tambah)
- [x] Tiket otomatis dari insiden monitoring, tanpa duplikat selama gangguan atau tiketnya masih terbuka
- [x] Pemulihan monitoring hanya menambah keterangan; tidak menutup tiket
- [x] Hak akses lewat `TiketPolicy`: `kelola-tiket` semua tiket, `tangani-tiket` hanya tiket yang ditugaskan
- [x] Penelusuran jalur FO dari perangkat, dengan keterbatasan di tempat data berhenti
- [x] "Potensi terdampak berdasarkan inventaris" untuk kabel, titik jaringan, dan tiang
- [x] Nomor tiket pada daftar gangguan aktif di dashboard

## Tahap 6d — Kelanjutan tiket

- [ ] Pemberitahuan kepada teknisi saat ditugaskan (bergantung pada mail server, Tahap 8)
- [ ] Memilih aset FO terdampak langsung dari peta
- [ ] Dampak bertingkat: OPD yang terhubung di belakang perangkat lain
- [x] Laporan tiket untuk `lihat-laporan` (dikerjakan di Tahap 7)

## Tahap 7 — Laporan (selesai, 9 Oktober 2026)

- [x] Ketersediaan koneksi per OPD: normal, menurun, gangguan, pemeliharaan, dan tanpa data dipisah
- [x] Definisi perhitungan tertulis (`docs/LAPORAN.md`), tampil di halaman dan ikut diekspor
- [x] Durasi dan jumlah gangguan, dipotong pada batas periode
- [x] Riwayat tiket
- [x] Inventaris aset dan kelengkapan pendataan, tanpa data sensitif
- [x] Ekspor CSV dan PDF, dicatat di log aktivitas

## Tahap 7b — Kelanjutan laporan dan peta

- [ ] Status koneksi OPD di peta inventaris
- [ ] Notifikasi gangguan
- [ ] Grafik ketersediaan per hari
- [ ] Pilihan menyertakan atau mengecualikan data SIMULASI di laporan

## Tahap 8 — Persiapan produksi (berkas dan panduan selesai 9 Oktober 2026; belum diuji di server)

- [x] Konfigurasi nginx (HTTPS, tertutup bawaan) dan pool PHP-FPM tersendiri
- [x] Unit systemd: worker antrean, penjadwal, health check, cadangan; kolektor di checkout sendiri
- [x] Role database terbatas untuk kolektor, termasuk hak untuk tiket otomatis
- [x] Retensi data monitoring (`monitoring:pangkas`) dan rotasi log
- [x] Health check: `kesehatan:periksa` dan `/kesehatan` bertoken
- [x] Skrip cadangan dan pemulihan database serta berkas unggahan
- [x] Panduan instalasi, panduan backup/restore, checklist verifikasi
- [ ] **Pemasangan di server uji Ubuntu dan pengerjaan checklist sampai habis**
- [ ] Tinjauan akses dan keputusan jaringan yang boleh membuka aplikasi
- [ ] Pemberitahuan saat health check atau cadangan gagal

## Catatan terbuka

Perlu diputuskan atau dikerjakan pada tahap yang disebut.

| Hal | Tahap |
|---|---|
| Seluruh isi `deploy/` (nginx, PHP-FPM, systemd, logrotate, skrip cadangan) ditulis di Windows dan belum pernah dijalankan di Ubuntu; skrip shell baru diperiksa sintaksnya. | 8 |
| Daftar GRANT role `dashboard_kolektor` disusun dari kode, belum diuji. Bila kurang, tiket otomatis tidak terbuat dan galatnya hanya ada di log kolektor. | 8 |
| Panduan ditulis untuk Ubuntu 24.04 LTS (PHP 8.4 dari PPA ondrej, PostgreSQL 17 dari PGDG); rilis Ubuntu lain belum diperiksa. | 8 |
| Health check dan cadangan yang gagal hanya terlihat di `systemctl --failed` dan log; belum ada pemberitahuan. | 8 |
| Cadangan tidak dienkripsi dan tidak ada pemulihan ke titik waktu; kehilangan data paling banyak 24 jam. | 8 |
| Lama penyimpanan log aktivitas, cadangan, dan hasil pemeriksaan memakai bawaan; kebijakan dinas belum ditetapkan. | 8 |
| Laporan ketersediaan memakai switch utama OPD yang sekarang; pergantian switch utama di tengah periode tidak dipisah. | 7b |
| Laporan ikut menghitung data SIMULASI (terlihat dari awalan namanya). | 7b |
| PDF laporan panjang (ribuan baris) dibuat dalam satu permintaan web; batas memori FPM 256 MB. Tinjau bila rentang panjang gagal diekspor. | 7b |
| Tampilan PDF dan CSV di Excel belum dilihat langsung; pengujian hanya memastikan berkas terbentuk dan isinya benar. | 7b |
| Halaman tiket dan penelusuran diuji lewat pengujian server (Livewire dan HTTP); modal, unggah foto, dan isian waktu belum dicoba di browser sungguhan. | 6d |
| Analisis dampak memuat seluruh hubungan FO ke memori tiap kali dihitung; cukup untuk ribuan core, tinjau bila inventaris jauh lebih besar. | 6d |
| Dampak hanya menelusuri sambungan FO langsung. OPD yang menumpang di belakang switch OPD lain tidak terhitung, dan aplikasi tidak tahu sisi mana yang mengarah ke Diskominfo. | 6d |
| Nomor tiket memakai id (`TKT-tahun-id`), jadi tidak berurutan rapat per tahun. | — |
| Tiket tidak dapat dihapus dari antarmuka, hanya dibatalkan. | — |
| Viewer melihat nomor dan status tiket di dashboard tetapi tidak dapat membukanya; tinjau bila laporan untuk Viewer dibuat. | 7 |
| Jalur SNMP nyata belum pernah dijalankan terhadap perangkat: bentuk nilai yang dikembalikan ekstensi (TimeTicks, alamat fisik, Counter64) baru diuji dengan tiruan. | 4b |
| Ekstensi PHP `snmp` dimuat per proses kolektor (`-d extension=snmp`, `MIBS=""`); tidak diaktifkan di `php.ini`. | — |
| Perangkat yang memblokir ICMP tidak dibaca SNMP-nya, karena SNMP hanya dicoba setelah ICMP berhasil. | 4b |
| Privasi SNMPv3 terbatas pada DES dan AES-128 (batas ekstensi PHP). | 5 |
| Data SNMP di web baru uptime dan trafik uplink pada detail OPD; daftar interface lengkap hanya di `monitoring:uji-endpoint`. | 6b |
| `sampel_trafik` hanya untuk interface uplink dan belum punya retensi. | 4b |
| Hak role database kolektor di panduan belum diuji pada pemasangan nyata. | 8 |
| Pengurai `ping` diuji dengan contoh keluaran Windows dan Linux dan dicoba nyata di Windows; belum dicoba nyata di Linux. | 4b |
| Interaksi peta status di dashboard baru diuji lewat DOM tiruan dan pengujian server, belum di browser sungguhan. | 6b |
| Dashboard memuat semua OPD sekaligus tiap penyegaran; cukup untuk puluhan OPD, tinjau bila jauh lebih banyak. | 6b |
| Status OPD hanya dari switch utama; perangkat lain yang dipantau belum tampil di dashboard. | 6b |
| Belum ada antarmuka untuk pemeliharaan, ambang per endpoint, dan profil SNMP; saat ini hanya lewat kode atau seeder. | 5, 6b |
| `hasil_pemeriksaan` tumbuh satu baris per endpoint per menit tanpa retensi (sekitar 1.440 baris per endpoint per hari). | 4b |
| Kolektor belum dijalankan otomatis; `composer dev` tidak menyalakannya. | 4b |
| Normal ↔ menurun berganti seketika, tanpa peredam; tinjau bila di lapangan sering berkedip. | 4b |
| OS dan lokasi host produksi (web dan kolektor) belum ditentukan. | 4, 8 |
| Versi SNMP dan kredensial baca-saja pada switch OPD belum diketahui. | 5 |
| `.github/workflows/tests.yml` bawaan starter kit masih memakai PHP 8.3, Node 22, dan tanpa layanan PostgreSQL; perlu disesuaikan sebelum CI dipakai. | 1b |
| PostgreSQL lokal tidak menyala otomatis; dinyalakan oleh `scripts/dev-env.ps1`. | — |
| Belum ada mail server; reset kata sandi lewat email hanya tercatat di log (`MAIL_MAILER=log`). Sementara itu Administrator mengatur ulang kata sandi dari halaman Pengguna. | 8 |
| Akun belum dapat dinonaktifkan sementara; pilihannya hanya menghapus. | 1b |
| Izin tiket dan aset sudah ada tetapi policy-nya baru dibuat bersama modelnya ("tiket yang ditugaskan" butuh kolom penerima tugas). | 2, 3 |
| Log aktivitas belum punya retensi atau pengarsipan. | 8 |
| `port_perangkat` baru berisi port yang dihubungkan ke FO, dan `perangkat.port_uplink` masih teks terpisah. Satukan saat port SNMP dicocokkan. | 5 |
| Warna core mengikuti urutan TIA-598 dan belum dapat diubah per kabel; sebagian pabrikan memakai urutan lain. | 3b |
| Penandaan core/port rusak tidak masuk riwayat hubungan maupun log aktivitas. | 3b |
| Tile bawaan memakai server OpenStreetMap: butuh akses internet dari browser dan hanya untuk pemakaian ringan. Tentukan server tile internal atau penyedia berlangganan. | 8 |
| `APP_URL` harus final sebelum label QR dicetak massal. | 8 |
| Semua aset dimuat sekaligus ke peta tanpa clustering; tinjau bila tiang mencapai ribuan. | 3b |
| Interaksi peta (geser, gambar jalur) baru diuji lewat DOM tiruan dan pengujian server, belum di browser sungguhan. | 3b |
| Ukuran label tetap 70 × 36 mm. | — |
| Teknisi hanya dapat melihat inventaris FO. Bila pendataan lapangan dikerjakan teknisi, perlu izin baru. | — |
| Impor CSV diproses di dalam request dengan batas 2000 baris; pindahkan ke antrean bila berkas lapangan lebih besar. | — |
| Batas unggah foto 5 MB membutuhkan `upload_max_filesize` dan `post_max_size` PHP di atas itu di server produksi. | 8 |
| Foto disimpan apa adanya, tanpa pengecilan ukuran atau penghapusan metadata lokasi (EXIF). | 8 |
| Koordinat OPD baru berupa angka; peta ada di Tahap 7. | 7 |
