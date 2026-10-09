# Panduan Backup dan Restore

Skripnya ada di `deploy/cadangan/`. Pemasangannya di
[DEPLOYMENT.md](DEPLOYMENT.md) langkah 14.

> Skrip ini belum pernah dijalankan di server; baru diperiksa sintaksnya.
> Cadangan baru boleh dianggap ada setelah **uji pemulihan** di bawah
> berhasil di server yang sebenarnya.

## Apa yang dicadangkan

| Data | Cara | Catatan |
|---|---|---|
| Database PostgreSQL | `pg_dump` format custom → `db-<cap>.dump` | Seluruh isi aplikasi: inventaris, monitoring, tiket, pengguna, log aktivitas |
| Berkas unggahan | `tar` `storage/app` → `unggahan-<cap>.tar.gz` | Foto perangkat, tiang, dan tiket (disk privat) |
| Checksum | `<cap>.sha256` | Diperiksa sebelum pemulihan |

**Tidak dicadangkan skrip ini, dan harus disimpan terpisah:**

| Rahasia | Di mana | Akibat bila hilang |
|---|---|---|
| `APP_KEY` | `.env` host web | Rahasia 2FA dan kode pemulihan pengguna tidak dapat dibuka; semua pengguna harus mengatur ulang 2FA |
| `MONITORING_KUNCI_PRIVAT` | `.env` host kolektor | Kredensial SNMP yang tersimpan tidak dapat dibuka; semua profil SNMP harus diisi ulang |
| Kata sandi role database | `.env` | Dapat diganti; tidak ada data yang hilang |
| Kunci privat TLS | `/etc/ssl/dashboard-jaringan/` | Sertifikat harus diterbitkan ulang |

Simpan salinan rahasia ini di pengelola kata sandi atau brankas dinas, bukan
di direktori cadangan dan bukan di repositori. Cadangan database tanpa
`APP_KEY` tetap dapat dipulihkan, tetapi dengan akibat di atas.

Berkas dump memuat hash kata sandi, rahasia 2FA terenkripsi, dan kredensial
SNMP tersegel. Direktori cadangan hanya terbaca root (`0700`), dan salinan di
luar server harus berada di tempat dengan pembatasan yang sama.

## Jadwal dan retensi

- `dashboard-cadangan.timer` menjalankan cadangan tiap hari pukul 01.15.
- Cadangan lokal disimpan `RETENSI_HARI` hari (bawaan 14) di
  `/var/backups/dashboard-jaringan/`.
- **Isi `SALIN_KE`** di `/etc/dashboard-jaringan/cadangan.conf` dengan tujuan
  rsync di mesin lain. Cadangan yang hanya ada di disk server yang sama ikut
  hilang bersama servernya; selama `SALIN_KE` kosong skrip menulis peringatan
  setiap kali berjalan. Retensi di tujuan salinan diatur di tujuan itu.
- Kehilangan data paling banyak sejak cadangan terakhir, yaitu sampai 24 jam.
  Bila itu terlalu lama, tambah frekuensi di timer.

## Mencadangkan sekarang

```bash
sudo dashboard-cadangkan
sudo ls -lh /var/backups/dashboard-jaringan/
sudo tail /var/log/dashboard-cadangan.log
```

Memeriksa cadangan harian berjalan:

```bash
systemctl list-timers dashboard-cadangan.timer
systemctl status dashboard-cadangan.service
```

## Uji pemulihan (aman, lakukan berkala)

Memulihkan ke database terpisah dan direktori sementara. Tidak ada data yang
berjalan yang disentuh.

```bash
sudo dashboard-pulihkan 20261009-011500
```

Skrip memeriksa checksum, memulihkan database ke `dashboard_jaringan_pulih`,
mengekstrak unggahan, lalu menampilkan jumlah pengguna, OPD, perangkat, tiket,
waktu log aktivitas terakhir, dan jumlah berkas. Bandingkan dengan yang
diharapkan, lalu bersihkan dengan perintah yang ditampilkan skrip.

Lakukan sekurangnya setelah pemasangan pertama, setelah setiap perubahan pada
skrip atau konfigurasi cadangan, dan berkala (mis. tiap tiga bulan). Catat
tanggal dan hasilnya.

## Pemulihan ke produksi

Menimpa database dan berkas unggahan yang berjalan. Semua perubahan sejak
cadangan yang dipilih hilang.

1. Bila server masih hidup, buat cadangan keadaan sekarang dahulu:
   `sudo dashboard-cadangkan`.
2. Hentikan kolektor supaya tidak menulis selama pemulihan:
   `sudo systemctl stop 'dashboard-kolektor@*'` (di host kolektor).
3. Pulihkan:

   ```bash
   sudo dashboard-pulihkan 20261009-011500 --ke-produksi
   ```

   Skrip meminta nama database diketik ulang, lalu: menghentikan worker dan
   penjadwal, memasang mode pemeliharaan, memulihkan ke database sementara,
   menukar nama database, mengganti `storage/app`, menjalankan migrasi, dan
   menyalakan kembali layanan. Database dan `storage/app` lama **tidak
   dihapus**; namanya ditampilkan di akhir.
4. Periksa:

   ```bash
   sudo -u dashboard php artisan kesehatan:periksa
   ```

   Masuk ke aplikasi, buka satu perangkat berfoto dan satu tiket.
5. Nyalakan kolektor: `sudo systemctl start dashboard-kolektor@1`.
6. Setelah yakin, hapus database `..._sebelum_...` dan direktori
   `storage/app.sebelum-...`.

Setelah pemulihan, status monitoring menunjukkan "Belum diketahui" sampai
kolektor memeriksa lagi, dan laporan ketersediaan mencatat selang itu sebagai
waktu tanpa data. Itu benar: selama itu memang tidak ada pemeriksaan.

## Pemulihan ke server baru

1. Pasang server menurut DEPLOYMENT.md langkah 2–6, **tanpa** menjalankan
   `migrate` dan tanpa membuat akun.
2. Isi `.env` dengan `APP_KEY` yang lama (dan `MONITORING_KUNCI_PRIVAT` yang
   lama di host kolektor).
3. Salin berkas `db-…`, `unggahan-…`, dan `.sha256` ke
   `/var/backups/dashboard-jaringan/`.
4. Buat role database (langkah 4 DEPLOYMENT.md), lalu
   `sudo dashboard-pulihkan <cap> --ke-produksi`.
5. Lanjutkan DEPLOYMENT.md dari langkah 7 (lewati `migrate` dan
   `pengguna:buat`), lalu kerjakan checklist.

## Bila pemulihan gagal di tengah

- Gagal sebelum "Menukar database": data yang berjalan belum diubah. Nyalakan
  layanan (`sudo systemctl start dashboard-antrean dashboard-penjadwal.timer
  dashboard-kesehatan.timer`, `sudo -u dashboard php artisan up`) dan hapus
  database `dashboard_jaringan_baru`.
- Gagal sesudahnya: database lama masih ada dengan nama
  `dashboard_jaringan_sebelum_<waktu>` dan berkas lama di
  `storage/app.sebelum-<cap>`. Kembalikan dengan menukar namanya lagi
  (`ALTER DATABASE … RENAME TO …`, `mv`).

## Yang belum ada

- Enkripsi berkas cadangan. Lindungi dengan hak akses dan tempat penyimpanan;
  tambahkan enkripsi bila salinan disimpan di luar kendali Diskominfo.
- Pemulihan ke titik waktu tertentu (arsip WAL). Yang tersedia hanya cadangan
  harian.
- Pemberitahuan bila cadangan gagal; saat ini hanya terlihat di
  `systemctl --failed` dan `/var/log/dashboard-cadangan.log`.
