# Laporan

Halaman `/laporan`, untuk peran yang memiliki izin `lihat-laporan`
(Administrator, Operator Diskominfo, Viewer). Setiap laporan dapat diunduh
sebagai CSV dan PDF; definisi di bawah ini ikut tercantum di halaman dan di
kedua berkas ekspor.

Semua laporan hanya membaca data tersimpan. Membuka atau mengekspor laporan
tidak memicu pemeriksaan jaringan. Setiap ekspor dicatat di log aktivitas.

| Laporan | Sumber data | Berperiode |
|---|---|---|
| Ketersediaan koneksi per OPD | `riwayat_status`, `status_terkini` | ya |
| Durasi dan jumlah gangguan | `insiden` | ya |
| Riwayat tiket | `tiket` | ya |
| Inventaris aset dan kelengkapan pendataan | tabel inventaris | tidak (keadaan saat dibuat) |

## Periode

- Diisi sebagai tanggal awal dan tanggal akhir menurut WIB; keduanya ikut
  terhitung penuh (00.00 tanggal awal sampai 24.00 tanggal akhir).
- Paling panjang 366 hari. Bawaan: 30 hari terakhir.
- **Waktu yang belum terjadi tidak dihitung.** Bila tanggal akhir adalah hari
  ini, perhitungan berhenti pada saat laporan dibuat.

## Perhitungan ketersediaan

### Yang diukur

Keterjangkauan **switch utama OPD dari jaringan Diskominfo**, menurut
pemeriksaan kolektor. Bukan ketersediaan layanan internet di OPD, dan bukan
ketersediaan aplikasi apa pun.

### Lima golongan waktu

Seluruh periode dibagi habis ke lima golongan menurut status endpoint switch
utama pada tiap saat. Jumlah kelimanya selalu sama dengan lama periode yang
dihitung.

| Golongan | Status monitoring | Artinya |
|---|---|---|
| Normal | Normal | Endpoint menjawab, latency dan packet loss di bawah ambang |
| Menurun | Koneksi menurun | Endpoint menjawab, tetapi melewati ambang atau baru gagal 1–2 kali |
| Gangguan | Endpoint tidak terjangkau | Tiga pemeriksaan berturut-turut tidak dijawab, sampai dua pemeriksaan berturut-turut berhasil |
| Pemeliharaan | Pemeliharaan | Dinyatakan operator; hasil pemeriksaan tidak dinilai |
| Tanpa data | Belum diketahui | Tidak ada hasil yang berlaku (lihat di bawah) |

**Tanpa data** mencakup:

- sebelum endpoint pertama kali diperiksa;
- kolektor tidak berjalan atau tidak dapat memeriksa, sehingga hasil terakhir
  kedaluwarsa (lebih tua dari 3 × interval pemeriksaan);
- setelah pemeliharaan berakhir, sampai ada hasil baru;
- OPD yang tidak punya switch utama, atau switch utamanya tidak dipantau:
  seluruh periode.

Waktu tanpa data **tidak pernah dihitung sebagai normal** dan tidak pula
sebagai gangguan.

### Rumus

```
                      normal + menurun
ketersediaan = ─────────────────────────────── × 100%
                normal + menurun + gangguan

                 periode − tanpa data
cakupan data = ──────────────────────── × 100%
                       periode
```

- Pemeliharaan dan tanpa data **tidak masuk pembagi** ketersediaan: keduanya
  bukan waktu yang diamati sebagai terjangkau maupun tidak terjangkau.
- "Menurun" dihitung terjangkau, karena endpoint masih menjawab. Lamanya
  ditampilkan tersendiri supaya terlihat.
- Bila pembaginya nol (seluruh periode tanpa data atau pemeliharaan),
  ketersediaan ditulis **"Tidak tersedia"**, bukan 0% dan bukan 100%.
- Ketersediaan selalu dibaca bersama **cakupan data**. Ketersediaan 100%
  dengan cakupan 20% hanya berbicara tentang seperlima periode.
- Angka gabungan dihitung dari jumlah detik semua OPD, bukan rata-rata
  persentase tiap OPD.

### Contoh

Periode 24 jam: normal 20 jam, menurun 1 jam, gangguan 30 menit,
pemeliharaan 1 jam, tanpa data 1 jam 30 menit.

- Ketersediaan = (20 + 1) ÷ (20 + 1 + 0,5) = **97,67%**
- Cakupan data = (24 − 1,5) ÷ 24 = **93,75%**

### Cara dihitung

1. Status pada awal periode diambil dari perubahan status terakhir sebelum
   periode. Bila tidak ada, statusnya tanpa data.
2. Setiap perubahan status di dalam periode (`riwayat_status`) memotong waktu
   menjadi ruas; tiap ruas masuk golongan statusnya.
3. Ruas terakhir tidak dianggap berlaku terus. Normal, menurun, dan gangguan
   hanya dihitung sampai hasil terakhir kedaluwarsa (`hasil_valid_terakhir_pada`
   + 3 × interval); sisanya tanpa data. Ini menjaga laporan tetap benar ketika
   kolektor mati dan tidak ada yang menulis perubahan status.

4. Perubahan ke "Belum diketahui" karena hasil kedaluwarsa dicatat pada saat
   hasil terakhir berhenti berlaku, bukan saat penyapu atau kolektor baru
   menyadarinya (`LayananStatus::sapu()` dan `nilai()`). Tanpa itu, selang
   kolektor mati akan terhitung sebagai status sebelumnya.

Perhitungan ada di `App\Laporan\PenghitungKetersediaan` (murni, tanpa database
dan jam) dan diuji di `tests/Feature/Laporan/LaporanTest.php`. Ubah dokumen
ini bersama kodenya.

### Keterbatasan

- Ketelitian mengikuti interval pemeriksaan (bawaan 60 detik). Gangguan yang
  lebih pendek dari tiga pemeriksaan tidak pernah menjadi "gangguan".
- Kegagalan kolektor (galat kolektor) bukan gangguan perangkat. Bila
  berlangsung lama, waktunya menjadi tanpa data.
- Status OPD adalah status switch utamanya saat ini. Bila switch utama OPD
  diganti di tengah periode, laporan memakai riwayat switch utama yang
  sekarang.
- Data SIMULASI ikut dihitung dan dapat dikenali dari nama berawalan
  `[SIMULASI]`. Hapus data simulasi sebelum laporan dipakai secara resmi.

## Durasi dan jumlah gangguan

- **Gangguan** di laporan ini adalah **insiden**: dibuka saat endpoint
  menjadi tidak terjangkau, ditutup saat terjangkau kembali atau saat masuk
  pemeliharaan.
- Insiden yang mulai sebelum periode atau berakhir sesudahnya tetap
  dihitung; **lamanya dipotong pada batas periode**. Insiden yang masih
  berlangsung dihitung sampai laporan dibuat.
- Semua perangkat yang dipantau ikut, bukan hanya switch utama.
- Lama insiden dapat lebih panjang daripada "gangguan" di laporan
  ketersediaan: insiden tetap terbuka selama status belum diketahui, karena
  belum ada bukti endpoint pulih, sedangkan laporan ketersediaan menghitung
  waktu itu sebagai tanpa data.
- Insiden hanya menyatakan endpoint tidak terjangkau dari Diskominfo.
  Penyebabnya, bila sudah diperiksa, ada di tiket.

## Riwayat tiket

- Memuat tiket yang **waktu mulainya** di dalam periode.
- Status adalah status pekerjaan. Waktu pemulihan diisi orang yang menangani;
  waktu pulih menurut monitoring tidak dipakai.
- Lama penanganan = waktu pemulihan − waktu mulai, hanya untuk tiket selesai.
- Dugaan penyebab dan penyebab terverifikasi adalah dua kolom terpisah.
  Ringkasan menyebut berapa tiket selesai yang belum punya penyebab
  terverifikasi.

## Inventaris dan kelengkapan

Keadaan saat laporan dibuat.

| Jenis | Dianggap lengkap bila |
|---|---|
| Tiang, titik jaringan, segmen kabel | Tidak ada butir "perlu dilengkapi" (aturan pendataan bertahap di inventaris) |
| OPD | Koordinat dan nama kontak teknis terisi |
| Perangkat | Merek, model, nomor seri terisi; switch utama juga IP manajemen dan port uplink |

Laporan hanya menyebut **apa yang belum diisi**. IP manajemen, nomor seri, dan
kontak tidak dicantumkan, karena laporan dapat dibuka Viewer yang tidak berhak
melihat rincian inventaris.

## Ekspor

- **CSV:** UTF-8 dengan BOM, pemisah titik koma, angka memakai koma desimal,
  supaya terbuka langsung di Excel berbahasa Indonesia. Sel yang berawalan
  `=`, `+`, `-`, atau `@` diberi apostrof agar tidak dijalankan sebagai rumus.
- **PDF:** A4 mendatar, dibuat di server (dompdf) tanpa memuat sumber luar.
- Paling banyak 20 ekspor per menit per pengguna.
