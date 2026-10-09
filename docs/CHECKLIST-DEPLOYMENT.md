# Checklist Verifikasi Deployment

Dikerjakan setelah [DEPLOYMENT.md](DEPLOYMENT.md), sebelum aplikasi dibuka
untuk pengguna, dan diulang setelah perubahan besar. Setiap butir punya cara
memeriksa dan hasil yang diharapkan. Catat tanggal, pemeriksa, dan butir yang
tidak lolos.

Aplikasi **tidak dibuka** sebelum bagian 1 sampai 4 lolos seluruhnya.

Tanggal: ____________  Pemeriksa: ____________  Server: ____________

## 1. Tinjauan akses (wajib sebelum dibuka)

| # | Periksa | Cara | Diharapkan | ✔ |
|---|---|---|---|---|
| 1.1 | Daftar jaringan yang boleh membuka aplikasi sudah diputuskan dan tertulis | Dokumen/keputusan | Ada, disetujui penanggung jawab | ☐ |
| 1.2 | nginx hanya mengizinkan jaringan itu | `grep -nE '^\s*(allow|deny)' /etc/nginx/sites-enabled/dashboard-jaringan` | Hanya CIDR yang disetujui; ada `deny all`; tidak ada `allow all` | ☐ |
| 1.3 | Firewall host aktif dan sama ketatnya | `sudo ufw status verbose` | Masuk ditolak bawaan; 443/80 hanya dari CIDR intra; 22 hanya dari jaringan admin | ☐ |
| 1.4 | Tidak terjangkau dari luar jaringan yang diizinkan | `curl -m 5 https://NAMA_HOST/` dari jaringan lain | Ditolak atau habis waktu | ☐ |
| 1.5 | Tidak ada alamat publik, NAT, atau proxy dari internet ke server | Tanya pengelola jaringan; `ip -br a` | Hanya alamat intra | ☐ |
| 1.6 | PostgreSQL tidak terbuka | `sudo ss -ltnp \| grep 5432` | `127.0.0.1` saja, atau alamat intra dengan `pg_hba` hanya untuk IP kolektor | ☐ |
| 1.7 | Pendaftaran mandiri mati | Buka `/register` | 404 | ☐ |
| 1.8 | Halaman tanpa masuk dialihkan | `curl -sI https://NAMA_HOST/dashboard` | 302 ke `/login` | ☐ |
| 1.9 | Akun: hanya yang perlu, peran sesuai tugas | Halaman Pengguna | Tidak ada akun uji; Administrator sesedikit mungkin | ☐ |
| 1.10 | 2FA aktif untuk semua Administrator | Halaman Pengguna / tanya pemilik akun | Aktif | ☐ |
| 1.11 | Viewer dan Teknisi dibatasi | Masuk sebagai masing-masing; buka `/pengguna`, `/opd`, `/tiket` | Viewer: 403 untuk ketiganya. Teknisi: 403 untuk `/pengguna` dan `/laporan` | ☐ |
| 1.12 | `/kesehatan` tertutup | `curl -s -o /dev/null -w '%{http_code}' https://NAMA_HOST/kesehatan` dari klien biasa | 403 atau 404 | ☐ |

## 2. Konfigurasi dan rahasia

| # | Periksa | Cara | Diharapkan | ✔ |
|---|---|---|---|---|
| 2.1 | Mode produksi | `sudo -u dashboard php artisan about` | Environment `production`, Debug Mode `OFF` | ☐ |
| 2.2 | Galat tidak menampilkan rincian | Buka alamat yang tidak ada | Halaman 404 polos, tanpa jejak tumpukan | ☐ |
| 2.3 | `.env` terlindung | `stat -c '%U:%G %a' /srv/dashboard-jaringan/.env` | `root:dashboard 640` | ☐ |
| 2.4 | `.env` dan `.git` tidak dilayani | `curl -s -o /dev/null -w '%{http_code}' https://NAMA_HOST/.env` (juga `/.git/config`) | 403 atau 404 | ☐ |
| 2.5 | Kode tidak dapat ditulis aplikasi | `sudo -u dashboard test -w /srv/dashboard-jaringan/app && echo DAPAT` | Tidak ada keluaran | ☐ |
| 2.6 | Kunci privat monitoring tidak ada di host web | `sudo grep MONITORING_KUNCI_PRIVAT /srv/dashboard-jaringan/.env` | Kosong | ☐ |
| 2.7 | `APP_KEY`, kunci privat monitoring, dan kunci TLS disimpan terpisah | Tanya penanggung jawab | Ada di brankas/pengelola kata sandi dinas | ☐ |
| 2.8 | Tidak ada data SIMULASI | `sudo -u dashboard php artisan simulasi:hapus` | "Tidak ada data SIMULASI." | ☐ |
| 2.9 | `APP_URL` benar | Cetak satu label aset, pindai QR | Membuka halaman aset di `https://NAMA_HOST/…` | ☐ |
| 2.10 | Penyedia tile peta sudah ditentukan dan atribusinya tampil | Buka Peta jaringan | Peta tampil; atribusi terlihat | ☐ |

## 3. HTTPS

| # | Periksa | Cara | Diharapkan | ✔ |
|---|---|---|---|---|
| 3.1 | HTTP dialihkan | `curl -sI http://NAMA_HOST/` | 301 ke `https://` | ☐ |
| 3.2 | Sertifikat sah untuk nama host | `openssl s_client -connect NAMA_HOST:443 -servername NAMA_HOST </dev/null 2>/dev/null \| openssl x509 -noout -subject -dates` | Nama cocok; belum kedaluwarsa | ☐ |
| 3.3 | Tanggal kedaluwarsa dan penanggung jawab pembaruan dicatat | — | Tercatat: ____________ | ☐ |
| 3.4 | Hanya TLS 1.2 ke atas | `openssl s_client -tls1_1 -connect NAMA_HOST:443 </dev/null` | Gagal | ☐ |
| 3.5 | Cookie sesi hanya lewat HTTPS | Alat pengembang browser setelah masuk | Cookie sesi bertanda `Secure` dan `HttpOnly` | ☐ |

## 4. Aplikasi

| # | Periksa | Cara | Diharapkan | ✔ |
|---|---|---|---|---|
| 4.1 | Migrasi lengkap | `sudo -u dashboard php artisan migrate:status` | Semua `Ran` | ☐ |
| 4.2 | Masuk dan keluar | Browser | Berhasil; tercatat di Log aktivitas | ☐ |
| 4.3 | Unggah foto | Tambah foto pada satu perangkat | Tersimpan dan tampil | ☐ |
| 4.4 | Foto tidak dapat dibuka tanpa masuk | Salin alamat foto, buka di jendela privat | Dialihkan ke `/login` | ☐ |
| 4.5 | Ekspor laporan | Laporan → CSV dan PDF | Keduanya terunduh dan terbuka | ☐ |
| 4.6 | Aset statis | Buka dashboard, lihat tab Jaringan browser | Tidak ada 404 untuk `/build/…` | ☐ |

## 5. Worker, penjadwal, kolektor

| # | Periksa | Cara | Diharapkan | ✔ |
|---|---|---|---|---|
| 5.1 | Layanan hidup dan menyala saat boot | `systemctl is-active dashboard-antrean dashboard-penjadwal.timer dashboard-kesehatan.timer dashboard-cadangan.timer` dan `is-enabled` | `active` / `enabled` | ☐ |
| 5.2 | Health check lolos | `sudo -u dashboard php artisan kesehatan:periksa; echo $?` | Semua BAIK; `0` | ☐ |
| 5.3 | Health check mendeteksi worker mati | `sudo systemctl stop dashboard-antrean`, tunggu 6 menit, ulangi 5.2, lalu nyalakan lagi | `antrean` BERMASALAH; kode `1`; pulih setelah dinyalakan | ☐ |
| 5.4 | Kolektor berjalan sebagai pengguna sendiri | `systemctl status dashboard-kolektor@1`; `ps -o user= -C php` | Aktif; pengguna `kolektor` | ☐ |
| 5.5 | Kolektor memakai role database terbatas | `sudo grep DB_USERNAME /srv/dashboard-kolektor/.env` | `dashboard_kolektor` | ☐ |
| 5.6 | Tidak ada galat hak di log kolektor | `sudo grep -i 'permission denied' /srv/dashboard-kolektor/storage/logs/laravel.log` | Kosong | ☐ |
| 5.7 | Daftar sasaran sesempit mungkin | `sudo grep MONITORING_SASARAN_DIIZINKAN /srv/dashboard-kolektor/.env` | Hanya IP/CIDR manajemen switch | ☐ |
| 5.8 | Satu switch nyata terbaca | PANDUAN-UJI-SWITCH.md; lalu `php artisan monitoring:status` | Status dan waktu pemeriksaan terisi | ☐ |
| 5.9 | Dashboard menandai kolektor mati | `sudo systemctl stop 'dashboard-kolektor@*'`, tunggu 5 menit | Dashboard: kolektor berhenti, status "Belum diketahui". Nyalakan lagi | ☐ |
| 5.10 | Insiden membuat tiket | Cabut uplink switch uji (atau blok ICMP) selama 4 menit | Satu tiket otomatis; tidak bertambah saat gangguan berlanjut | ☐ |
| 5.11 | Web tidak menghubungi perangkat | `sudo -u dashboard php -m \| grep -i snmp` dan `php-fpm8.4 -m \| grep -i snmp` | Kosong | ☐ |
| 5.12 | SNMP di switch dibatasi ke host kolektor, baca saja | Konfigurasi switch | ACL ada; tidak ada hak tulis | ☐ |

## 6. Cadangan

| # | Periksa | Cara | Diharapkan | ✔ |
|---|---|---|---|---|
| 6.1 | Cadangan berjalan | `sudo dashboard-cadangkan`; `sudo ls -lh /var/backups/dashboard-jaringan/` | Tiga berkas baru; log berakhir "beres" | ☐ |
| 6.2 | Direktori cadangan tertutup | `sudo stat -c '%a %U' /var/backups/dashboard-jaringan` | `700 root` | ☐ |
| 6.3 | **Uji pemulihan berhasil** | `sudo dashboard-pulihkan <cap>` | Jumlah pengguna, OPD, perangkat, tiket sesuai; berkas unggahan ada | ☐ |
| 6.4 | Salinan di luar server | `SALIN_KE` terisi; lihat berkas di tujuan | Ada dan terbaru | ☐ |
| 6.5 | Timer cadangan terjadwal | `systemctl list-timers dashboard-cadangan.timer` | Ada waktu berikutnya | ☐ |
| 6.6 | Penanggung jawab dan jadwal uji pemulihan berkala ditetapkan | — | Tercatat: ____________ | ☐ |

## 7. Log dan retensi

| # | Periksa | Cara | Diharapkan | ✔ |
|---|---|---|---|---|
| 7.1 | Rotasi log terpasang | `sudo logrotate --debug /etc/logrotate.d/dashboard-jaringan` | Tanpa galat | ☐ |
| 7.2 | Log aplikasi ditulis pengguna yang benar | `ls -l /srv/dashboard-jaringan/storage/logs/` | Milik `dashboard` | ☐ |
| 7.3 | Retensi monitoring terjadwal | `sudo -u dashboard php artisan schedule:list` | `monitoring:pangkas` tiap hari | ☐ |
| 7.4 | Retensi sesuai kebijakan | `sudo -u dashboard php artisan monitoring:pangkas --uji-coba` | Batas hari sesuai yang diputuskan | ☐ |
| 7.5 | Journal dibatasi | `grep SystemMaxUse /etc/systemd/journald.conf` | Terisi | ☐ |
| 7.6 | Sisa disk cukup | `df -h /srv /var/lib/postgresql /var/backups` | Lapang; ada perkiraan pertumbuhan | ☐ |
| 7.7 | Jam server benar | `timedatectl` | Tersinkron NTP | ☐ |

## 8. Setelah dibuka

| # | Periksa | Kapan | ✔ |
|---|---|---|---|
| 8.1 | `systemctl --failed` kosong | Hari pertama, lalu mingguan | ☐ |
| 8.2 | Log aktivitas: tidak ada lonjakan "Gagal masuk" atau "Akses ditolak" | Minggu pertama | ☐ |
| 8.3 | Laporan ketersediaan: cakupan data mendekati 100% untuk OPD yang dipantau | Setelah satu minggu | ☐ |
| 8.4 | Cadangan harian ada dan tersalin keluar | Mingguan | ☐ |
| 8.5 | Pembaruan keamanan sistem terpasang (`unattended-upgrades` atau jadwal manual) | Bulanan | ☐ |

Butir yang tidak lolos dan tindak lanjutnya:

| Butir | Temuan | Penanggung jawab | Tenggat |
|---|---|---|---|
| | | | |
