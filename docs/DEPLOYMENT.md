# Panduan Instalasi di Server Ubuntu

Untuk Ubuntu Server 24.04 LTS. Berkas konfigurasi yang dirujuk ada di
`deploy/`. Dokumen pendamping: [BACKUP-RESTORE.md](BACKUP-RESTORE.md) dan
[CHECKLIST-DEPLOYMENT.md](CHECKLIST-DEPLOYMENT.md).

> **Belum diuji di server.** Konfigurasi dan skrip di `deploy/` ditulis dari
> lingkungan pengembangan Windows dan belum pernah dijalankan di Ubuntu.
> Skrip shell hanya diperiksa sintaksnya. Lakukan pemasangan pertama di
> server uji dan kerjakan checklist sampai habis sebelum dipakai.

> **Jangan membuka aplikasi sebelum akses ditinjau.** Konfigurasi nginx
> bawaan hanya menjawab dari server itu sendiri. Daftar jaringan yang boleh
> masuk diisi pada langkah 9, setelah tinjauan di checklist.

## 1. Gambaran

```
                       Jaringan intra Pemkab
  Pengguna ── HTTPS 443 ──► [ Host web ]
                              nginx ─ PHP-FPM (pengguna: dashboard)
                              worker antrean, penjadwal (pengguna: dashboard)
                              PostgreSQL 17
                                   ▲ 5432 (hanya dari host kolektor)
                                   │
                            [ Host kolektor ]  (pengguna: kolektor)
                              monitoring:kolektor
                                   │ ICMP, SNMP UDP 161 (keluar saja)
                                   ▼
                       IP manajemen switch OPD yang terdaftar
```

| Bagian | Pengguna sistem | Memegang | Boleh menjangkau |
|---|---|---|---|
| Web, antrean, penjadwal | `dashboard` | `APP_KEY`, kata sandi database web, kunci **publik** monitoring | Database |
| Kolektor | `kolektor` | kata sandi role `dashboard_kolektor`, kunci **privat** monitoring, daftar sasaran | Database dan IP manajemen switch |

Dianjurkan dua host. Bila hanya ada satu, kolektor tetap memakai checkout,
`.env`, pengguna sistem, dan role database tersendiri (langkah 10); web tidak
pernah memegang kunci privat monitoring.

Jalur yang dipakai panduan ini:

| Jalur | Isi |
|---|---|
| `/srv/dashboard-jaringan` | Aplikasi web |
| `/srv/dashboard-kolektor` | Checkout kolektor |
| `/etc/dashboard-jaringan/` | Konfigurasi cadangan |
| `/var/backups/dashboard-jaringan/` | Cadangan lokal |
| `/etc/ssl/dashboard-jaringan/` | Sertifikat TLS |

## 2. Paket sistem

Ubuntu 24.04 membawa PHP 8.3 dan PostgreSQL 16; aplikasi membutuhkan PHP 8.4
dan diuji dengan PostgreSQL 17. Keduanya diambil dari repositori resminya.

```bash
sudo apt update && sudo apt install -y software-properties-common ca-certificates curl gnupg unzip git rsync

# PHP 8.4 (PPA ondrej/php)
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath

# PostgreSQL 17 (repositori PGDG)
sudo install -d /usr/share/postgresql-common/pgdg
sudo curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc --fail https://www.postgresql.org/media/keys/ACCC4CF8.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
    | sudo tee /etc/apt/sources.list.d/pgdg.list
sudo apt update && sudo apt install -y postgresql-17

sudo apt install -y nginx
```

Composer dan Node 24 hanya untuk membangun aplikasi. Pasang Composer dari
getcomposer.org dan Node 24 LTS dari NodeSource, atau bangun di mesin lain dan
salin hasilnya (`vendor/` dan `public/build/`).

Periksa: `php -v` (8.4), `psql --version` (17), `php -m | grep -E 'pdo_pgsql|sodium|gd|mbstring'`.

## 3. Pengguna sistem

```bash
sudo adduser --system --group --home /srv/dashboard-jaringan --shell /usr/sbin/nologin dashboard
```

## 4. PostgreSQL

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE dashboard_jaringan LOGIN PASSWORD 'GANTI-KATA-SANDI-WEB';
CREATE DATABASE dashboard_jaringan OWNER dashboard_jaringan ENCODING 'UTF8';
SQL
```

- Buat kata sandi dengan `openssl rand -base64 24`; simpan hanya di `.env`.
- Bawaan Ubuntu, PostgreSQL hanya mendengar di `localhost`. Biarkan begitu
  bila kolektor berada di host yang sama.
- Kolektor di host lain: di `postgresql.conf` set `listen_addresses` ke
  alamat intra host web, dan di `pg_hba.conf` izinkan **hanya** role kolektor
  dari **hanya** IP host kolektor:

  ```
  hostssl dashboard_jaringan dashboard_kolektor 10.x.x.x/32 scram-sha-256
  ```

## 5. Kode aplikasi

```bash
sudo git clone <URL-REPOSITORI> /srv/dashboard-jaringan
cd /srv/dashboard-jaringan
sudo composer install --no-dev --optimize-autoloader --no-interaction
sudo npm ci && sudo npm run build
```

Hak berkas: kode milik root dan tidak dapat ditulis aplikasi; hanya `storage/`
dan `bootstrap/cache/` yang dapat ditulis pengguna `dashboard`.

```bash
sudo chown -R root:dashboard /srv/dashboard-jaringan
sudo chmod -R g-w,o-rwx /srv/dashboard-jaringan
sudo chown -R dashboard:dashboard /srv/dashboard-jaringan/storage /srv/dashboard-jaringan/bootstrap/cache
sudo chmod o+x /srv/dashboard-jaringan
sudo chmod -R o+rX /srv/dashboard-jaringan/public     # nginx membaca aset statis
```

## 6. Environment

```bash
sudo cp .env.example .env
sudo chown root:dashboard .env && sudo chmod 0640 .env
php artisan key:generate --show      # salin hasilnya ke APP_KEY
sudoedit .env
```

`.env.example` tidak memuat rahasia. Nilai yang wajib diubah di host web:

| Variabel | Nilai produksi |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<nama host intranet>` (dipakai QR label aset) |
| `DB_PASSWORD` | kata sandi role `dashboard_jaringan` |
| `LOG_STACK`, `LOG_LEVEL` | `single`, `warning` (dirotasi logrotate) |
| `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT` | `true`, `true` |
| `MONITORING_KUNCI_PUBLIK` | kunci publik dari langkah 10 |
| `MONITORING_KUNCI_PRIVAT` | **kosong** di host web |
| `MONITORING_ADAPTER` | biarkan `simulasi` di host web; web tidak menjalankan kolektor |
| `PETA_TILE_URL`, `PETA_TILE_ATRIBUSI` | penyedia tile yang boleh dipakai dinas; server tile publik OpenStreetMap tidak untuk pemakaian produksi berat |
| `KESEHATAN_TOKEN` | kosong, kecuali ada alat pemantau (langkah 13) |
| `MAIL_*` | server surel dinas, bila reset kata sandi lewat surel dipakai |

`APP_KEY` dan kunci monitoring dicadangkan terpisah; lihat BACKUP-RESTORE.md.

## 7. Database dan akun pertama

```bash
cd /srv/dashboard-jaringan
sudo -u dashboard php artisan migrate --force
sudo -u dashboard php artisan config:cache
sudo -u dashboard php artisan route:cache
sudo -u dashboard php artisan view:cache
sudo -u dashboard php artisan pengguna:buat        # akun Administrator pertama
```

Jangan menjalankan `SimulasiSeeder` di produksi (seeder itu menolak berjalan
di `APP_ENV=production`). Aktifkan 2FA untuk akun Administrator setelah masuk.

## 8. PHP-FPM

```bash
sudo cp deploy/php/dashboard-pool.conf /etc/php/8.4/fpm/pool.d/dashboard.conf
sudo phpdismod -v 8.4 -s fpm snmp 2>/dev/null || true    # snmp tidak untuk web
sudo systemctl restart php8.4-fpm
```

## 9. HTTPS dan nginx

Sertifikat diletakkan di `/etc/ssl/dashboard-jaringan/` (`fullchain.pem`,
`privkey.pem`; kunci `0600` milik root). Pilih satu:

| Cara | Kapan |
|---|---|
| Sertifikat dari CA internal Pemkab atau CA komersial | Dianjurkan untuk nama host intranet |
| Let's Encrypt dengan tantangan DNS-01 (`certbot`) | Bila nama host berada di domain publik yang DNS-nya dikelola Diskominfo; server tidak perlu terbuka ke internet |
| Sertifikat tanda-tangan sendiri | Hanya untuk server uji; browser akan memperingatkan |

Catat tanggal kedaluwarsa sertifikat dan siapa yang memperbaruinya.

```bash
sudo cp deploy/nginx/dashboard-jaringan.conf /etc/nginx/sites-available/dashboard-jaringan
sudoedit /etc/nginx/sites-available/dashboard-jaringan     # ganti NAMA_HOST dan jalur sertifikat
sudo ln -s /etc/nginx/sites-available/dashboard-jaringan /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Pada titik ini situs hanya menjawab dari server sendiri:

```bash
curl -sk --resolve NAMA_HOST:443:127.0.0.1 https://NAMA_HOST/up -o /dev/null -w '%{http_code}\n'   # 200
```

**Membuka akses.** Kerjakan bagian "Tinjauan akses" di checklist. Setelah
disetujui, isi daftar `allow` di berkas situs dengan CIDR jaringan intra yang
perlu saja, lalu `sudo nginx -t && sudo systemctl reload nginx`. Firewall host:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow from <CIDR-ADMIN> to any port 22 proto tcp
sudo ufw allow from <CIDR-INTRA> to any port 443 proto tcp
sudo ufw allow from <CIDR-INTRA> to any port 80 proto tcp      # hanya pengalihan ke HTTPS
sudo ufw allow from <IP-HOST-KOLEKTOR> to any port 5432 proto tcp   # hanya bila kolektor di host lain
sudo ufw enable
```

Aplikasi ini untuk jaringan intra. Jangan memberi alamat publik, NAT, atau
reverse proxy dari internet tanpa keputusan tertulis dan tinjauan ulang.

## 10. Kolektor

Kolektor adalah satu-satunya bagian yang menghubungi perangkat jaringan.

**Di host web (sekali):** role database dengan hak terbatas.

```bash
sudo -u postgres psql dashboard_jaringan <<'SQL'
CREATE ROLE dashboard_kolektor LOGIN PASSWORD 'GANTI-KATA-SANDI-KOLEKTOR';
GRANT CONNECT ON DATABASE dashboard_jaringan TO dashboard_kolektor;
GRANT USAGE ON SCHEMA public TO dashboard_kolektor;

-- Dibaca: sasaran, kredensial tersegel, dan pemilik perangkat.
GRANT SELECT ON endpoint_monitoring, profil_snmp, perangkat, opd TO dashboard_kolektor;
-- Penjadwalan dan klaim.
GRANT UPDATE (berikutnya_pada, sedang_diperiksa_sejak, klaim, pemeliharaan, pemeliharaan_sampai, pemeliharaan_catatan, updated_at)
    ON endpoint_monitoring TO dashboard_kolektor;
-- Hasil dan tanda hidup.
GRANT SELECT, INSERT, UPDATE ON status_terkini, insiden, snmp_perangkat, interface_snmp, detak_kolektor TO dashboard_kolektor;
GRANT SELECT, INSERT ON hasil_pemeriksaan, riwayat_status, sampel_trafik TO dashboard_kolektor;
-- Tiket otomatis saat insiden dibuka, catatan sistemnya, dan log aktivitasnya.
GRANT SELECT, INSERT, UPDATE ON tiket TO dashboard_kolektor;
GRANT SELECT, INSERT ON catatan_tiket TO dashboard_kolektor;
GRANT INSERT ON log_aktivitas TO dashboard_kolektor;
-- Cache dipakai kerangka kerja (kunci, konfigurasi).
GRANT SELECT, INSERT, UPDATE, DELETE ON cache, cache_locks TO dashboard_kolektor;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO dashboard_kolektor;
SQL
```

Role ini tidak dapat membaca `users`, `sessions`, atau tabel inventaris FO.
Daftar hak di atas disusun dari kode dan **belum diuji pada pemasangan
nyata**: jalankan satu putaran (`--sekali`) dengan role ini, baca galat
"permission denied" di log, dan tambahkan hanya hak yang memang kurang. Tanpa
hak pada `tiket`, kolektor tetap menilai status tetapi tiket otomatis tidak
terbuat (galatnya masuk log kolektor).

**Di host kolektor:**

```bash
sudo apt install -y php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-snmp iputils-ping
sudo phpdismod -v 8.4 snmp          # dimuat per proses dengan -d extension=snmp, bukan global
sudo adduser --system --group --home /var/lib/dashboard-kolektor --shell /usr/sbin/nologin kolektor

sudo git clone <URL-REPOSITORI> /srv/dashboard-kolektor
cd /srv/dashboard-kolektor
sudo composer install --no-dev --optimize-autoloader --no-interaction
sudo chown -R root:kolektor . && sudo chmod -R g-w,o-rwx .
sudo chown -R kolektor:kolektor storage bootstrap/cache
sudo cp .env.example .env && sudo chown root:kolektor .env && sudo chmod 0640 .env
```

Kunci penyegel kredensial SNMP dibuat sekali, di host kolektor:

```bash
sudo -u kolektor php artisan monitoring:buat-kunci
```

`.env` kolektor:

| Variabel | Nilai |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_KEY` | kunci sendiri (`php artisan key:generate --show`); tidak perlu sama dengan host web |
| `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` | host web, `dashboard_kolektor`, kata sandinya |
| `MONITORING_ADAPTER` | `jaringan` |
| `MONITORING_KUNCI_PUBLIK` | kunci publik (salin juga ke `.env` host web) |
| `MONITORING_KUNCI_PRIVAT` | kunci privat; **hanya di sini** |
| `MONITORING_SASARAN_DIIZINKAN` | IP atau CIDR manajemen switch yang boleh dihubungi. Kosong = tidak ada yang diizinkan |
| `CACHE_STORE`, `SESSION_DRIVER` | `database`, `array` |

Akses jaringan yang dibutuhkan host kolektor, dan tidak lebih:

| Arah | Tujuan | Protokol |
|---|---|---|
| Keluar | IP manajemen switch yang terdaftar | ICMP echo; UDP 161 (SNMP) |
| Keluar | Host web | TCP 5432 |
| Masuk | Hanya SSH dari jaringan admin | TCP 22 |

Di switch, batasi SNMP ke IP host kolektor (ACL) dengan hak baca saja.
Kolektor tidak memindai subnet: ia hanya menghubungi endpoint yang terdaftar
di aplikasi **dan** tercantum di `MONITORING_SASARAN_DIIZINKAN`.

Uji satu switch dahulu menurut [PANDUAN-UJI-SWITCH.md](PANDUAN-UJI-SWITCH.md),
lalu pasang layanan:

```bash
sudo cp deploy/kolektor/dashboard-kolektor@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now dashboard-kolektor@1
```

## 11. Worker antrean dan penjadwal (host web)

```bash
sudo cp deploy/systemd/dashboard-*.service deploy/systemd/dashboard-*.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now dashboard-antrean.service dashboard-penjadwal.timer dashboard-kesehatan.timer
```

Yang dijalankan penjadwal (`php artisan schedule:list`):

| Jadwal | Pekerjaan |
|---|---|
| Tiap menit | Tanda hidup penjadwal dan worker antrean |
| 02.30 WIB | `monitoring:pangkas` (retensi) |
| 03.00, 03.10 | Pembersihan pekerjaan gagal dan token reset kedaluwarsa |

Penjadwal tidak menjalankan kolektor dan tidak menghubungi perangkat.

## 12. Log dan retensi

```bash
sudo cp deploy/logrotate/dashboard-jaringan /etc/logrotate.d/dashboard-jaringan
sudo logrotate --debug /etc/logrotate.d/dashboard-jaringan
```

| Data | Disimpan | Diatur di |
|---|---|---|
| Log aplikasi dan kolektor (`storage/logs`) | 30 hari, dikompres | logrotate |
| Log nginx | bawaan paket (14 hari) | `/etc/logrotate.d/nginx` |
| Log unit systemd | sesuai journald | `SystemMaxUse=` di `/etc/systemd/journald.conf` (mis. `500M`) |
| `hasil_pemeriksaan` | 90 hari | `MONITORING_RETENSI_HASIL_HARI` |
| `sampel_trafik` | 90 hari | `MONITORING_RETENSI_TRAFIK_HARI` |
| `detak_kolektor` | 30 hari | `MONITORING_RETENSI_DETAK_HARI` |
| `riwayat_status`, `insiden` | selamanya | dasar laporan; tidak dipangkas |
| `log_aktivitas`, `riwayat_hubungan`, tiket | selamanya | catatan audit; tidak dipangkas |
| Cadangan lokal | 14 hari | `RETENSI_HARI` di `cadangan.conf` |

Hasil pemeriksaan tumbuh sekitar 1.440 baris per endpoint per hari. Laporan
ketersediaan dan gangguan tidak bergantung padanya, jadi retensi dapat
dipendekkan tanpa mengubah laporan. Lihat dahulu apa yang akan dihapus:

```bash
sudo -u dashboard php artisan monitoring:pangkas --uji-coba
```

Lama penyimpanan log aktivitas dan cadangan adalah keputusan kebijakan dinas;
angka di atas adalah bawaan, bukan ketentuan.

## 13. Health check

| Pemeriksaan | Cara | Menjawab |
|---|---|---|
| Aplikasi hidup | `GET /up` (dibatasi nginx ke server sendiri) | 200 bila kerangka kerja dapat berjalan |
| Semua komponen | `sudo -u dashboard php artisan kesehatan:periksa` | Tabel; kode keluar 1 bila ada yang bermasalah |
| Berkala | `dashboard-kesehatan.timer` tiap 5 menit | Unit gagal terlihat di `systemctl --failed` |
| Alat pemantau | `GET /kesehatan` dengan `Authorization: Bearer <KESEHATAN_TOKEN>` | 200 atau 503, hanya baik/bermasalah per komponen |

Komponen yang diperiksa: database, penyimpanan (dapat ditulis, sisa disk),
penjadwal, worker antrean, dan kolektor (tanda hidup, endpoint yang terlambat
diperiksa, galat kolektor).

`/kesehatan` mati (404) selama `KESEHATAN_TOKEN` kosong. Bila dipakai: buat
token dengan `openssl rand -hex 32`, jalankan `php artisan config:cache`, dan
tambahkan IP host pemantau ke `location = /kesehatan` di nginx.

`dashboard-kesehatan` hanya mencatat ke journal. Sambungkan ke pemberitahuan
yang dipakai Diskominfo (mis. `OnFailure=` ke unit pengirim pesan); tanpa itu
tidak ada yang tahu saat kolektor berhenti kecuali membuka dashboard.

## 14. Cadangan

Lihat [BACKUP-RESTORE.md](BACKUP-RESTORE.md). Ringkasnya:

```bash
sudo install -d -m 0750 /etc/dashboard-jaringan
sudo install -m 0640 deploy/cadangan/cadangan.conf.example /etc/dashboard-jaringan/cadangan.conf
sudo install -m 0750 deploy/cadangan/cadangkan.sh /usr/local/sbin/dashboard-cadangkan
sudo install -m 0750 deploy/cadangan/pulihkan.sh  /usr/local/sbin/dashboard-pulihkan
sudo systemctl enable --now dashboard-cadangan.timer
sudo dashboard-cadangkan            # cadangan pertama, lalu uji pemulihannya
```

## 15. Memasang versi baru

```bash
sudo dashboard-cadangkan
cd /srv/dashboard-jaringan
sudo -u dashboard php artisan down
sudo git pull --ff-only
sudo composer install --no-dev --optimize-autoloader --no-interaction
sudo npm ci && sudo npm run build
sudo -u dashboard php artisan migrate --force
sudo -u dashboard php artisan config:cache && sudo -u dashboard php artisan route:cache && sudo -u dashboard php artisan view:cache
sudo systemctl reload php8.4-fpm
sudo systemctl restart dashboard-antrean
sudo -u dashboard php artisan up
sudo -u dashboard php artisan kesehatan:periksa
```

Checkout kolektor diperbarui dengan cara yang sama (tanpa `npm` dan tanpa
`migrate`), lalu `sudo systemctl restart 'dashboard-kolektor@*'`. Migrasi yang
menambah tabel dapat memerlukan hak baru untuk role `dashboard_kolektor`.

## 16. Yang belum tercakup

- Pemberitahuan (surel, pesan) saat health check gagal.
- Ketersediaan tinggi: satu host web dan satu database; pemulihan
  mengandalkan cadangan.
- Pemantauan sertifikat yang akan kedaluwarsa.
- Kebijakan tertulis lama penyimpanan log aktivitas dan cadangan.
