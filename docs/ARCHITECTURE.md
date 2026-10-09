# Arsitektur

Status dokumen: fondasi (Tahap 0). Bagian bertanda *rencana* belum dibangun dan
dapat berubah saat tahapnya dikerjakan.

## Tujuan

1. Inventaris jaringan intra pemerintah: perangkat aktif di OPD dan infrastruktur
   fiber optik pasif (tiang, kabel, core, ODC, ODP, closure, terminasi, port).
2. Pemantauan koneksi setiap OPD ke Diskominfo melalui IP manajemen switch utama.

Bukan tujuan: mengukur atau menyimpulkan kualitas akses internet OPD.

## Gambaran komponen

```
                    jaringan manajemen (hanya dari Diskominfo)
                 ┌──────────────────────────────────────────────┐
                 │                                              ▼
┌──────────┐   ┌─┴───────────────┐                     ┌──────────────────┐
│ Browser  │   │ Kolektor        │  ICMP / SNMP        │ Switch utama OPD │
│ pengguna │   │ (scheduler +    ├────────────────────►│ (managed)        │
└────┬─────┘   │  worker antrean)│                     └──────────────────┘
     │ HTTPS   └─┬───────────────┘
     ▼           │ tulis hasil
┌──────────────┐ │
│ Aplikasi web │ │      ┌────────────┐
│ Laravel +    ├─┴─────►│ PostgreSQL │
│ Livewire     │ baca   └────────────┘
└──────────────┘
```

Dua proses dengan tanggung jawab berbeda dan satu basis kode:

| | Aplikasi web | Kolektor monitoring |
|---|---|---|
| Dijalankan sebagai | PHP-FPM / server web | `php artisan schedule:work` dan `queue:work --queue=monitoring` |
| Akses jaringan ke switch | Tidak ada | Ada (ICMP, SNMP UDP 161) |
| Kredensial SNMP | Tidak dimuat | Dimuat dari environment |
| Akses database | Baca/tulis inventaris, baca hasil monitoring | Baca target, tulis hasil monitoring |

Web tidak pernah menghubungi perangkat. Halaman dashboard hanya menampilkan baris
terakhir yang ditulis kolektor. Dengan demikian request pengguna tidak dapat
memicu trafik ke jaringan manajemen, waktu muat halaman tidak bergantung pada
perangkat yang lambat atau mati, dan host web dapat ditempatkan tanpa rute ke
jaringan manajemen.

## Pemisahan akses

- Di produksi kolektor berjalan di host atau kontainer tersendiri di jaringan
  Diskominfo, dengan pengguna OS sendiri dan berkas `.env` sendiri (*rencana,
  Tahap 8*).
- Firewall: hanya host kolektor yang boleh menjangkau IP manajemen switch.
- Dua role PostgreSQL: role web tanpa hak tulis ke tabel hasil monitoring, role
  kolektor tanpa hak tulis ke tabel pengguna (*rencana, Tahap 8*).
- **Kredensial SNMP** (sudah ada): disimpan di `profil_snmp` dan disegel dengan
  kriptografi kunci publik (sealed box libsodium). Host web hanya memegang
  `MONITORING_KUNCI_PUBLIK`: dapat menyimpan kredensial baru, tidak dapat
  membaca yang tersimpan. `MONITORING_KUNCI_PRIVAT` hanya dipasang di host
  kolektor. Bocornya host web beserta isi database tidak membuka kredensial.
  Mengganti pasangan kunci berarti mengisi ulang semua kredensial.
- SNMPv3 (authPriv) diutamakan; v2c hanya bila perangkat tidak mendukung v3,
  dengan community baca-saja.
- Di lingkungan pengembangan keduanya berjalan di satu mesin dan satu `.env`,
  tetapi batas di kode tetap berlaku.

## Monitoring

Dua adapter tersedia, dipilih dengan `MONITORING_ADAPTER`:

- `simulasi` (bawaan): tidak mengirim satu paket pun ke jaringan.
- `jaringan`: ICMP, ditambah SNMP untuk endpoint yang punya profil SNMP. Baru
  diuji terhadap loopback (ICMP) dan perangkat tiruan (SNMP); uji pertama
  terhadap switch sungguhan mengikuti [PANDUAN-UJI-SWITCH.md](PANDUAN-UJI-SWITCH.md).

### Alur

```
php artisan monitoring:kolektor            (proses terpisah dari web)
  └─ Kolektor::jalankanSekali()
       ├─ LayananStatus::sapu()            pemeliharaan habis masa, hasil kedaluwarsa
       ├─ klaim()                          endpoint jatuh tempo, UPDATE atomik
       └─ untuk tiap endpoint yang diklaim
            ├─ AdapterPemeriksa::periksa() simulasi sekarang; ICMP/SNMP nanti
            ├─ LayananStatus::terimaHasil()
            │    ├─ simpan hasil_pemeriksaan
            │    ├─ MesinStatus::terapkan() aturan transisi, murni
            │    └─ perbarui status_terkini, insiden, riwayat_status
            └─ lepas klaim, jadwalkan pemeriksaan berikutnya
```

`LayananStatus::terimaHasil()` tidak bergantung pada kolektor lokal. Kolektor
jarak jauh kelak cukup mengirim hasil ke titik masuk yang memanggilnya.

### Batas di dalam kode

```
app/
  Models/                 EndpointMonitoring, ProfilSnmp, HasilPemeriksaan,
                          StatusTerkini, Insiden, RiwayatStatus
  Monitoring/
    Kontrak/              AdapterPemeriksa
    Adapter/              AdapterSimulasi (adapter nyata menyusul)
    SasaranPemeriksaan    masukan adapter
    HasilAdapter          keluaran adapter
    MesinStatus           aturan transisi, tanpa database dan jam
    LayananStatus         penyimpanan dan penilaian; hanya database
    Kolektor              klaim, jalankan adapter, serahkan hasil
  Support/BrankasKredensial  penyegel kredensial SNMP
```

Kode web tidak memanggil `Adapter`, `Kontrak`, maupun `Kolektor`; pengujian
arsitektur memeriksanya. Web hanya membaca model dan, untuk tindakan operator
seperti pemeliharaan, memanggil `LayananStatus`.

### Enam hal yang dipisah

| Tabel | Isi |
|---|---|
| `endpoint_monitoring` | Sasaran pemeriksaan: alamat, metode, interval, ambang, pemeliharaan, penjadwalan dan klaim. Dibuat dari perangkat yang monitoringnya diaktifkan |
| `profil_snmp` | Versi, port, dan kredensial tersegel |
| `hasil_pemeriksaan` | Deret waktu: satu baris per pemeriksaan, termasuk yang tidak diterapkan |
| `status_terkini` | Satu baris per endpoint: status hasil evaluasi dan penghitungnya |
| `insiden` | Rentang waktu endpoint tidak terjangkau; paling banyak satu terbuka per endpoint |
| `riwayat_status` | Setiap perubahan status beserta alasannya; hanya dapat ditambah |

### Aturan status

Bawaan: interval 60 detik, tidak terjangkau setelah 3 gagal berturut-turut,
pulih setelah 2 berhasil berturut-turut, kedaluwarsa setelah 3 × interval,
ambang latency 100 ms dan packet loss 5%. Semuanya dari `config/monitoring.php`
(`MONITORING_*`); interval dan ambang dapat ditimpa per endpoint.

| Kejadian | Akibat |
|---|---|
| Berhasil, di bawah ambang | Normal |
| Berhasil, latency atau packet loss di atas ambang | Koneksi menurun |
| Gagal ke-1 dan ke-2 berturut-turut | Koneksi menurun |
| Gagal ke-3 berturut-turut | Endpoint tidak terjangkau; insiden dibuka |
| Berhasil 1 kali saat tidak terjangkau | Tetap tidak terjangkau |
| Berhasil 2 kali berturut-turut saat tidak terjangkau | Normal atau menurun; insiden ditutup |
| Galat kolektor (adapter galat, melewati batas waktu) | Status dan hitungan tidak berubah |
| Tidak ada hasil valid selama 3 × interval | Belum diketahui; hitungan diulang dari nol; insiden tetap terbuka |
| Pemeliharaan dimulai | Pemeliharaan; insiden terbuka ditutup; hasil disimpan tetapi tidak dinilai |
| Pemeliharaan selesai atau habis masa | Belum diketahui sampai ada hasil baru |

Keputusan yang perlu diketahui:

- **Gagal ke-1 dan ke-2 langsung "Koneksi menurun".** Satu pemeriksaan yang
  gagal total sama dengan packet loss 100%, jadi tidak ditampilkan sebagai
  Normal sambil menunggu kegagalan ketiga.
- **Normal ↔ menurun berganti seketika**, tanpa hitungan berturut-turut.
- **Insiden hanya untuk "tidak terjangkau".** Penurunan kualitas tercatat di
  riwayat status, tidak membuka insiden.
- **Kedaluwarsa dihitung dua kali.** Saat dibaca (`statusTampil()`), supaya
  kolektor yang mati tidak membuat status lama tampak berlaku; dan oleh
  penyapu, supaya perubahannya tercatat di riwayat.
- **Hasil yang datang tidak berurutan** (cap waktu pemeriksaannya tidak lebih
  baru dari hasil terakhir yang diterapkan) disimpan dengan tanda `terlambat`
  dan tidak mengubah status. Hasil yang sama yang terkirim dua kali dikenali
  dari `id_pemeriksaan`. Hasil bercap waktu lebih dari 5 menit di depan jam
  server ditolak sebagai jam kolektor yang melenceng.
- **Galat kolektor bukan bukti.** Endpoint yang hanya menghasilkan galat
  kolektor berstatus "Belum diketahui", bukan "tidak terjangkau".

### Pencegahan pemeriksaan bertumpuk

Sebelum memeriksa, kolektor mengklaim endpoint dalam satu `UPDATE ... WHERE id
IN (SELECT ... FOR UPDATE SKIP LOCKED) RETURNING id`. Endpoint yang sedang
diklaim dilewati kolektor lain dan putaran berikutnya. Klaim yang lebih tua
dari 120 detik dianggap milik kolektor yang mati dan boleh diambil alih; klaim
hanya dilepas oleh pemiliknya. Karena itu beberapa kolektor dapat berjalan
bersamaan dengan aman.

### Kolektor nyata

```
AdapterJaringan::periksa(sasaran)
  ├─ DaftarIzinSasaran          di luar daftar: galat kolektor, tidak ada paket
  ├─ PemeriksaIcmp              `ping` sistem, larik argumen + batas waktu
  └─ PemeriksaSnmp              hanya bila endpoint ber-profil SNMP dan ICMP berhasil
       ├─ PabrikKlienSnmp       sesi v3/v2c lewat ekstensi PHP snmp (dalam proses)
       ├─ SNMPv2-MIB, IF-MIB    identitas, uptime, tabel interface (GET dan walk)
       └─ AdapterSfp            optik, hanya untuk vendor yang dikenali
```

| Keputusan | Alasan |
|---|---|
| Tanpa pemindaian dan discovery | Kolektor hanya memeriksa endpoint yang terdaftar; daftar izin CIDR menjaga alamat yang salah masuk ke database tidak ikut dihubungi |
| `ping` sistem, bukan soket ICMP mentah | Soket mentah butuh hak root. Dijalankan tanpa shell, dengan larik argumen dan batas waktu; hanya alamat IP yang sudah divalidasi yang masuk ke argumen |
| Keluaran ping dibaca dari baris balasan (`TTL=`), bukan ringkasannya | Sama di Windows dan Linux, tidak bergantung bahasa sistem, dan "Destination host unreachable" tidak terhitung sebagai balasan |
| SNMP lewat ekstensi PHP, bukan `snmpget` | Kredensial tidak pernah menjadi argumen proses, jadi tidak terlihat di daftar proses. Ekstensi dimuat per proses kolektor, tidak di web |
| OID numerik, tanpa berkas MIB | Tidak bergantung pada MIB yang terpasang di host |
| SNMP hanya dicoba bila ICMP berhasil | Menghindari waktu tunggu ganda ke perangkat yang mati. Akibatnya perangkat yang memblokir ICMP tidak dibaca SNMP-nya |
| ICMP menentukan status, SNMP dicatat terpisah | "Ping sukses, SNMP gagal" adalah keadaan yang berbeda dari "tidak terjangkau" |

**Metrik SNMP.** Identitas (`sysDescr`, `sysObjectID`, `sysName`), uptime, dan
per interface: status admin/operasional, kecepatan, alamat fisik, penghitung
oktet (64-bit bila ada, 32-bit bila tidak), galat, dan buang. Semuanya boleh
`NULL`: perangkat yang tidak mendukung suatu metrik menampilkan "Tidak
tersedia", bukan nol.

**Identitas interface.** Interface dikenali dari `ifName` (atau `ifDescr` bila
perangkat tidak punya `ifName`). `ifIndex` boleh berubah setelah restart atau
penambahan modul, jadi hanya disimpan sebagai nomor terakhir beserta waktu
perubahannya. Alamat fisik yang berbeda di bawah nama yang sama berarti
perangkat kerasnya diganti: penghitung lama tidak dipakai. Interface tanpa nama
atau bernama ganda dilewati dan dihitung.

**Laju trafik** (`PenghitungLaju`). Dihitung dari selisih dua sampel dibagi
selang waktu sebenarnya, bukan interval yang dijadwalkan. Hasilnya `NULL`
beserta alasan bila: baru ada satu sampel, selang kurang dari satu detik atau
lebih dari batas kedaluwarsa, uptime perangkat mundur (restart), perangkat
menyatakan penghitung terputus (`ifCounterDiscontinuityTime`), penghitung
64-bit turun (reset), atau laju melebihi 1,2 × kecepatan port. Penghitung
32-bit yang berputar sekali tetap dihitung. Deret waktu (`sampel_trafik`) hanya
disimpan untuk interface uplink.

**Optik SFP.** Tidak ada MIB standar lintas vendor, jadi dibaca adapter per
vendor yang dipilih dari `sysObjectID`. Saat ini hanya MikroTik, dan belum
diuji pada perangkat nyata. Vendor lain: "Tidak tersedia".

**Konkurensi dan percobaan ulang.** Satu proses kolektor memeriksa satu
endpoint pada satu waktu; konkurensi adalah jumlah proses (lihat
`deploy/kolektor/`), dan klaim di database mencegah dua proses memeriksa
endpoint yang sama. Percobaan ulang hanya di dalam satu pemeriksaan (paket
ICMP, retry SNMP), dengan batas di `BatasPemeriksaan`.

**Hasil lewat API.** Belum ada: kolektor menulis langsung ke database dengan
role sendiri. Bila kelak diperlukan, API penerima hasil wajib memakai
autentikasi per kolektor dan membatasi endpoint yang boleh dilaporkan.

### Yang belum ada

Uji terhadap switch sungguhan, tampilan web (dashboard status, data SNMP,
pengelolaan endpoint, profil SNMP, dan pemeliharaan), adapter SFP vendor lain,
notifikasi, serta retensi `hasil_pemeriksaan` dan `sampel_trafik`.

## Dashboard status

"Status Koneksi OPD ke Diskominfo" (`/dashboard`) dan detail per OPD
(`/dashboard/opd/{id}`). Terbuka untuk semua peran, termasuk Viewer.

```
Browser                                   Server (hanya membaca database)
  wire:poll tiap 30 detik  ─────────────►  RingkasanStatus::opd()
                                             Opd aktif → switch utama → endpoint → status_terkini
                                             StatusTerkini::statusTampil()  (kedaluwarsa, pemeliharaan)
                                           KesehatanKolektor::periksa()
                                             detak_kolektor, endpoint terlambat, galat kolektor
  peta-status.js, fetch berkala ─────────►  GET /dashboard/peta  (status per OPD, tanpa IP)
```

Tidak ada jalur dari halaman ini ke adapter, kolektor, maupun utilitas jaringan.
Membuka, menyegarkan, atau menyaring dashboard hanya menjalankan kueri baca;
pemeriksaan tetap sepenuhnya milik proses kolektor.

| Bagian | Sumber |
|---|---|
| Jumlah OPD per status | Status endpoint switch utama tiap OPD aktif, lewat `statusTampil()` |
| Gangguan aktif | `insiden` yang belum ditutup; tetap tampil saat status belum diketahui, dengan catatan "belum ada bukti pulih" |
| Pemeriksaan terakhir | `status_terkini.hasil_valid_terakhir_pada` terbaru, dan per OPD |
| Kesehatan kolektor | `detak_kolektor` (tanda hidup tiap putaran), endpoint yang terlambat lebih dari dua interval, galat kolektor 15 menit terakhir |
| Peta status | Koordinat OPD dan statusnya; penanda memuat huruf N, M, T, ?, P dan berbeda bentuk |
| Detail OPD | Perangkat utama, port uplink, latency, packet loss, trafik dan uptime dari SNMP, riwayat insiden dan perubahan status |

Keputusan:

- **OPD tanpa switch utama yang dipantau** dihitung "Belum diketahui" dan
  diberi keterangan, supaya tidak dikira gangguan dan tidak hilang dari daftar.
  OPD nonaktif tidak dihitung.
- **Kesehatan kolektor** punya empat keadaan: berjalan, perlu diperiksa,
  berhenti, belum pernah berjalan. Kolektor yang masih memberi tanda hidup
  tetapi menghasilkan galat atau meninggalkan endpoint terlambat turun menjadi
  "perlu diperiksa".
- **Hasil kedaluwarsa** langsung tampil "Belum diketahui" saat dibaca, tanpa
  menunggu penyapu; karena itu kolektor yang mati tidak pernah membuat
  dashboard tetap hijau.
- **Metrik tanpa data** (uptime, trafik, latency saat tidak dipantau) tampil
  "Tidak tersedia", bukan nol.
- **Viewer** melihat status, nama OPD, dan kode aset switch utama, tetapi
  tidak IP manajemen maupun tautan ke inventaris.

Belum ada: grafik trafik dan latency, notifikasi, pengelolaan pemeliharaan
dari antarmuka, dan status di peta inventaris (`/peta`).

## Tiket gangguan

Tiket mencatat pekerjaan penanganan gangguan (`/tiket`, `/tiket/{id}`).
Monitoring dan pekerjaan sengaja dipisah:

| | Monitoring | Tiket |
|---|---|---|
| Pertanyaan | Apakah endpoint terjangkau dari Diskominfo? | Apakah pekerjaan sudah selesai? |
| Diubah oleh | Kolektor, lewat `LayananStatus` | Orang, lewat `LayananTiket` |
| Selesai bila | Dua pemeriksaan berhasil | Penangan menyatakan waktu pemulihan |

```
LayananStatus::pindah()
  ├─ insiden dibuka  → event InsidenDibuka  ─┐
  └─ insiden ditutup → event InsidenDitutup ─┤
                                             ▼
                          KaitkanTiketDenganInsiden (savepoint sendiri)
                                             ▼
                                       LayananTiket
        bukaDariInsiden: endpoint sudah punya tiket terbuka?
            ya    → insiden dikaitkan ke tiket itu, tambah catatan sistem
            tidak → tiket otomatis baru (tanpa penyebab, tanpa aset FO)
        catatPenutupanInsiden: isi monitoring_pulih_pada, tambah catatan;
                               status pekerjaan tidak disentuh
```

- **Tanpa duplikat.** Kegagalan lanjutan tidak membuka insiden baru, dan
  insiden berikutnya untuk endpoint yang tiketnya masih terbuka dikaitkan ke
  tiket itu. Indeks unik parsial di database menolak tiket otomatis terbuka
  kedua untuk endpoint yang sama.
- **Pemulihan tidak menutup pekerjaan.** Saat monitoring pulih, tiket hanya
  mendapat `monitoring_pulih_pada` dan catatan sistem. "Selesai" membutuhkan
  `waktu_pemulihan` yang diisi orang (dijaga check constraint).
- **Dugaan dan penyebab terverifikasi** adalah dua kolom. Tiket otomatis
  tidak mengisi keduanya: pemeriksaan yang gagal tidak menunjukkan apakah
  gangguan ada di kabel, perangkat, atau catu daya.
- **Linimasa** (`catatan_tiket`): tindakan, catatan, foto (disk privat,
  dilayani rute ber-policy), dan entri sistem. Hanya dapat ditambah.
- **Hak akses.** Operator dan Administrator mengelola semua tiket. Teknisi
  hanya melihat dan mengerjakan tiket yang ditugaskan kepadanya, selama masih
  terbuka; ia tidak dapat menugaskan, membatalkan, atau membuka kembali.
  Viewer tidak dapat membuka tiket; di dashboard ia hanya melihat nomornya.
- `TIKET_OTOMATIS=false` mematikan pembuatan tiket otomatis. Insiden tetap
  dikaitkan ke tiket terbuka yang sudah ada.

## Penelusuran jalur dan potensi terdampak

`/fo/penelusuran` dan rincian tiket. Keduanya hanya membaca inventaris FO;
tidak ada hasil monitoring yang ikut dihitung.

**Penelusuran jalur** (`App\Fo\PenelusuranJalur`) mengikuti hubungan yang
tercatat:

```
port perangkat ─ koneksi_endpoint ─► port terminasi ─► core
                                  └► core (langsung, di sebuah titik)
core ─► ujung seberang kabelnya ─► sambungan ─► core berikutnya ─► ...
                                 ├► port terminasi ─ koneksi_endpoint ─► port perangkat (selesai)
                                 └► tidak ada catatan ─► berhenti + keterbatasan
```

Penelusuran berhenti dan mencatat keterbatasan bila: perangkat belum punya
port tercatat, port belum dihubungkan, port terminasi belum diterminasi ke
core, salah satu ujung kabel belum dicatat, ujung core tidak punya sambungan
atau terminasi, port terminasi belum dihubungkan ke perangkat, atau sambungan
berputar. Jalur disebut lengkap hanya bila sampai dari perangkat ke perangkat
tanpa keterbatasan.

**Potensi terdampak** (`App\Fo\AnalisisDampak`) menjawab "OPD mana yang
memakai aset ini":

| Aset dipilih | Kabel yang diperiksa |
|---|---|
| Segmen kabel | Kabel itu |
| Titik jaringan | Semua kabel yang berujung di titik itu, dan port yang terhubung ke perangkat tanpa core |
| Tiang | Kabel yang rutenya melewati tiang, dan kabel yang berujung di titik pada tiang itu |

Setiap core terpakai pada kabel-kabel itu ditelusuri ke dua arah. OPD pemilik
perangkat di ujung jalur masuk daftar, dengan tanda apakah jalurnya lengkap.
Hasil selalu berjudul "Potensi terdampak berdasarkan inventaris" dan disertai
keterbatasannya:

- core terpakai yang tidak sampai ke perangkat mana pun;
- jalur yang hanya tertelusur di satu sisi;
- kabel yang datanya belum lengkap;
- (tiang) kabel yang belum memiliki rute tiang;
- OPD yang switch utamanya belum dicatat terhubung ke FO, sehingga tidak
  mungkin muncul di daftar;
- hanya sambungan FO langsung yang ditelusuri: OPD di belakang perangkat lain
  tidak ikut terhitung.

Daftar ini bukan diagnosis. Ping yang gagal tidak menunjukkan kabel putus, dan
aplikasi tidak pernah menyimpulkannya.

## Laporan

`/laporan` (izin `lihat-laporan`). Definisi lengkap: [LAPORAN.md](LAPORAN.md).

```
Periode (tanggal WIB → rentang UTC, dipotong pada "sekarang")
        │
        ▼
LaporanKetersediaan / LaporanGangguan / LaporanTiket / LaporanInventaris
        │  hanya membaca tabel
        ▼
DokumenLaporan (ringkasan, tabel, catatan definisi)
        ├──► halaman Livewire
        ├──► Ekspor::csv()
        └──► Ekspor::pdf()   (dompdf, tanpa sumber luar)
```

- Ketersediaan dihitung `PenghitungKetersediaan` dari `riwayat_status`:
  waktu dibagi habis ke normal, menurun, gangguan, pemeliharaan, dan tanpa
  data. Ketersediaan = (normal + menurun) ÷ (normal + menurun + gangguan).
  Tanpa data tidak pernah menjadi normal.
- Ruas terakhir dipotong pada saat hasil terakhir kedaluwarsa, sehingga
  kolektor yang mati menghasilkan "tanpa data", bukan status lama yang
  diperpanjang.
- Laporan tidak membaca `hasil_pemeriksaan`, jadi tidak terpengaruh retensi.

## Operasi

Panduan: [DEPLOYMENT.md](DEPLOYMENT.md), [BACKUP-RESTORE.md](BACKUP-RESTORE.md),
[CHECKLIST-DEPLOYMENT.md](CHECKLIST-DEPLOYMENT.md). Berkasnya di `deploy/`.

| Proses | Pengguna sistem | Dijalankan oleh |
|---|---|---|
| Web (nginx → PHP-FPM pool `dashboard`) | `dashboard` | `php8.4-fpm` |
| Worker antrean | `dashboard` | `dashboard-antrean.service` |
| Penjadwal (`schedule:run` tiap menit) | `dashboard` | `dashboard-penjadwal.timer` |
| Health check tiap 5 menit | `dashboard` | `dashboard-kesehatan.timer` |
| Cadangan harian | root | `dashboard-cadangan.timer` |
| Kolektor | `kolektor`, checkout dan `.env` sendiri, role database terbatas | `dashboard-kolektor@N.service` |

- **Tanda hidup.** Penjadwal menulis tanda hidupnya tiap menit dan mengirim
  pekerjaan `CatatDetakAntrean`; bila worker hidup, pekerjaan itu menulis
  tanda hidup antrean. Kolektor menulis `detak_kolektor`.
  `PemeriksaKesehatan` membaca ketiganya, ditambah database dan penyimpanan.
- **Retensi.** `monitoring:pangkas` (tiap malam) menghapus `hasil_pemeriksaan`
  dan `sampel_trafik` yang lebih tua dari 90 hari. Riwayat status, insiden,
  tiket, dan log tidak dipangkas.
- **Tertutup bawaan.** Konfigurasi nginx hanya menjawab dari server sendiri
  sampai daftar jaringan diisi setelah tinjauan akses; `/kesehatan` mati
  sampai token diisi.

## Makna status

| Status | Arti |
|---|---|
| Normal | Endpoint menjawab dari Diskominfo; latency dan packet loss di bawah ambang |
| Koneksi menurun | Menjawab tetapi melewati ambang, atau satu–dua pemeriksaan terakhir gagal |
| Endpoint tidak terjangkau | Tiga pemeriksaan berturut-turut tidak dijawab |
| Belum diketahui | Belum pernah diperiksa, hasil terakhir kedaluwarsa, endpoint nonaktif, atau hanya ada galat kolektor |
| Pemeliharaan | Ditandai operator; hasil pemeriksaan tidak dinilai |

- Status ini tentang koneksi endpoint (IP manajemen switch OPD) ke Diskominfo.
  Bukan bukti internet OPD berfungsi; antarmuka tidak memakai kata "online"
  atau "internet" untuknya.
- SNMP kelak menambah detail (status port uplink, uptime, trafik) tetapi tetap
  tentang perangkat dan tautan ke Diskominfo.
- Kolektor yang mati tidak boleh membuat dashboard tampak hijau: status
  dihitung dari umur hasil terakhir, dan waktu pemeriksaan terakhir selalu
  ditampilkan.

## Model data

Diagram relasi lengkap, model koneksi FO, dan daftar constraint ada di
[DATABASE.md](DATABASE.md). Bagian ini merangkum isinya.

### Sudah ada

**`opd`** — organisasi yang terhubung ke jaringan intra; Diskominfo sendiri
juga satu baris di sini.

| Kolom | Catatan |
|---|---|
| `kode` | Unik, huruf besar; kunci impor CSV |
| `nama`, `alamat` | |
| `lintang`, `bujur` | Derajat desimal; keduanya terisi atau keduanya kosong |
| `kontak_teknis_nama`, `_telepon`, `_email` | |
| `aktif` | OPD nonaktif tetap tersimpan beserta perangkatnya |
| `simulasi` | Data contoh |

**`perangkat`** — perangkat jaringan aktif.

| Kolom | Catatan |
|---|---|
| `opd_id` | OPD pemilik; OPD tidak dapat dihapus selama masih punya perangkat |
| `penempatan`, `lokasi` | `opd` atau `diskominfo`, ditambah keterangan ruang/rak |
| `kode_aset` | Unik, huruf besar; kunci impor CSV |
| `nama`, `jenis`, `merek`, `model`, `nomor_seri` | Jenis: switch, router, firewall, access_point, olt, media_converter, lainnya |
| `ip_manajemen` | Tipe `inet`, unik |
| `port_uplink` | Nama port uplink yang dipantau (teks) |
| `monitoring_aktif` | Penanda untuk kolektor; mensyaratkan `ip_manajemen` |
| `switch_utama` | Paling banyak satu per OPD (indeks unik parsial); hanya untuk jenis switch |
| `foto_path` | Jalur di disk privat |
| `catatan`, `simulasi` | |

Pemilik dan penempatan dipisah: perangkat sisi Diskominfo untuk tautan sebuah
OPD dicatat dengan `opd_id` OPD tersebut dan `penempatan = diskominfo`.
Perangkat inti milik Diskominfo memakai `opd_id` Diskominfo.

Semua aturan di atas dijaga constraint database, bukan hanya validasi.
Kredensial SNMP tidak disimpan di tabel ini; tempatnya ditetapkan di Tahap 5
dan hanya terbaca oleh kolektor.

**`users`** (dengan `peran`) dan **`log_aktivitas`** — lihat bagian akses.

### Impor CSV

`App\Impor\PengimporCsv` membaca berkas (pemisah koma atau titik koma, BOM,
desimal koma), memvalidasi tiap baris dengan aturan yang sama dengan formulir,
dan melaporkan kesalahan per nomor baris. Pratinjau menjalankan seluruh impor
dalam transaksi lalu rollback; impor sungguhan menyimpan baris valid dan
melewati yang bermasalah. Batas 1 MB dan 2000 baris per berkas, diproses dalam
request (belum lewat antrean).

### Inventaris FO

| Tabel | Isi |
|---|---|
| `tiang` | Kode, koordinat, pemilik, kondisi, foto |
| `titik_jaringan` | ODC, ODP, closure, terminasi/OTB; lokasinya koordinat, tiang, atau OPD |
| `segmen_kabel` | Bentang kabel antara dua titik; klasifikasi backbone/distribusi, jumlah core, dua macam panjang, jalur peta |
| `tiang_segmen` | Tiang yang dilalui tiap segmen beserta urutannya (banyak-ke-banyak) |
| `core_kabel` | Dibuat otomatis dari jumlah core; nomor, tube, warna TIA-598, status |
| `port_terminasi` | Dibuat otomatis dari kapasitas titik; status dan core di sisi belakangnya |
| `port_perangkat` | Port pada perangkat aktif, didaftarkan saat pertama kali dihubungkan |
| `sambungan` | Core masuk ↔ core keluar di sebuah titik |
| `koneksi_endpoint` | Port perangkat ↔ port terminasi, atau langsung ke ujung core |
| `pemakaian_ujung_core` | Buku besar yang diisi trigger; mencegah satu ujung core dipakai dua kali |
| `riwayat_hubungan` | Histori sambungan, terminasi, koneksi endpoint, dan rute tiang; hanya dapat ditambah |

Semua perubahan hubungan melewati `App\Fo\LayananHubungan`. Pemakaian ganda
ditolak dua kali: oleh layanan dengan pesan yang dapat dibaca, lalu oleh
database bila dua perubahan terjadi bersamaan atau ada penulisan di luar
layanan.

`jalur_peta` disimpan sebagai daftar koordinat di kolom `jsonb` dan digambar
di peta jaringan. Bila PostGIS dipasang kelak, kolom ini dapat dipindah ke
tipe `geography` tanpa mengubah model lainnya.

### Tiket

| Tabel | Isi |
|---|---|
| `tiket` | Pekerjaan penanganan gangguan: nomor, yang terkait (OPD, perangkat, satu aset FO), insiden monitoring, prioritas, teknisi, status pekerjaan, dua kolom penyebab, waktu mulai dan pemulihan |
| `catatan_tiket` | Linimasa tiket: tindakan, catatan, foto, dan entri sistem; hanya dapat ditambah |

Rinciannya di bagian "Tiket gangguan" dan di `docs/DATABASE.md`.

### Rencana

- **Port perangkat lengkap** dari SNMP, dicocokkan dengan `port_perangkat`.

## Peta jaringan, QR, dan label

| Langkah | Arah | Isi |
|---|---|---|
| `GET /peta` | server → browser | Halaman Livewire dan konfigurasi tile dari `config/peta.php` |
| `GET /peta/data` | server → browser | JSON seluruh aset (`PetaDataController`) |
| Filter, pencarian, popup | di browser | `resources/js/peta.js` (Leaflet + Geoman) |
| `simpanKoordinat`, `simpanJalur` | browser → server | Aksi Livewire dengan `authorize('update', ...)` |
| Tile | penyedia tile → browser | Alamat dari `PETA_TILE_URL` |

- **Yang digambar:** OPD, switch utama (di lokasi OPD-nya, karena perangkat
  tidak punya koordinat sendiri), tiang, titik jaringan (ODC, ODP, closure,
  terminasi/OTB), dan jalur segmen kabel. Backbone merah tebal, distribusi
  biru, yang belum diklasifikasi abu-abu putus-putus. Penanda dibedakan bentuk
  selain warna.
- **Posisi titik jaringan** diambil dari koordinatnya sendiri; bila kosong,
  dari tiang atau OPD tempatnya terpasang. Menggesernya di peta memberi titik
  itu koordinat sendiri.
- **Filter dan pencarian** berjalan di browser atas data yang dimuat sekali.
  Filter OPD mencakup titik di gedung OPD itu, kabel yang berujung di sana, dan
  tiang yang dilalui kabel tersebut. Aset tanpa koordinat tidak digambar tetapi
  tetap ditemukan pencarian.
- **Mengubah:** hanya pemegang `kelola-aset`. Menggeser penanda atau titik jalur
  langsung menyimpan; jalur baru digambar untuk segmen yang belum punya jalur.
  `panjang_peta_m` dihitung ulang di server.
- **Tile:** bawaan memakai server OpenStreetMap, yang kebijakannya hanya untuk
  pemakaian ringan dan membutuhkan akses internet dari browser pengguna. Untuk
  jaringan intra, arahkan `PETA_TILE_URL` ke server tile internal atau
  penyedia berlangganan dan sesuaikan `PETA_TILE_ATRIBUSI`. Bila tile tidak
  terjangkau, aset tetap tergambar di atas latar kosong.
- **Peta tidak menampilkan status koneksi.** Itu bagian dashboard (Tahap 6).

### QR dan halaman detail

Setiap aset punya alamat tetap `/aset/{jenis}/{kode}` (jenis: `opd`,
`perangkat`, `tiang`, `titik`, `kabel`). QR memuat alamat itu saja, dibentuk
dari `APP_URL` supaya label yang dicetak dari komputer mana pun sama. Orang
yang memindai diarahkan ke halaman masuk, lalu ke detail bila berhak
(`lihat-aset`); pengguna tanpa hak mendapat 403 baik kodenya ada maupun tidak.

`APP_URL` harus berisi alamat yang akan dipakai di lapangan sebelum label
dicetak. Mengganti alamat aplikasi setelah itu membuat QR lama tidak berlaku.
Pakai nama DNS, bukan alamat IP server.

Label (`/label/{jenis}?kode=A,B,...`, paling banyak 100) adalah halaman cetak
mandiri berukuran 70 × 36 mm per label: jenis, kode aset, QR, dan penanda
SIMULASI.

## Data simulasi

Sebelum data lapangan tersedia, aplikasi diisi data contoh. Supaya tidak pernah
tertukar dengan data nyata:

- kolom `simulasi = true` dan nama berawalan `[SIMULASI]`;
- lencana SIMULASI di setiap tampilan yang memuat data tersebut;
- seeder simulasi menolak berjalan di lingkungan `production`;
- IP manajemen contoh berasal dari rentang dokumentasi RFC 5737, sehingga
  kolektor yang salah konfigurasi pun tidak akan menghubungi perangkat nyata;
- `php artisan simulasi:hapus` menghapus semuanya sebelum data lapangan masuk;
- hasil dari pemeriksa simulasi ditandai sama.

## Autentikasi dan akses

- Laravel Fortify: login, reset kata sandi, autentikasi dua faktor (TOTP),
  konfirmasi kata sandi.
- Pendaftaran mandiri, verifikasi email, passkey, dan hapus akun sendiri
  dinonaktifkan. Akun pertama dibuat dengan `php artisan pengguna:buat`,
  selanjutnya oleh Administrator di halaman Pengguna.

### Peran dan izin

Peran disimpan di kolom `users.peran` (dengan check constraint). Matriksnya
didefinisikan sekali di `App\Enums\Peran::izin()`:

| Izin (nama Gate) | Administrator | Operator Diskominfo | Teknisi | Viewer |
|---|:-:|:-:|:-:|:-:|
| `kelola-pengguna` | ya | | | |
| `kelola-konfigurasi` | ya | | | |
| `lihat-log-aktivitas` | ya | | | |
| `kelola-aset` | ya | ya | | |
| `kelola-endpoint` | ya | ya | | |
| `kelola-tiket` | ya | ya | | |
| `lihat-aset` | ya | ya | ya | |
| `tangani-tiket` (hanya yang ditugaskan) | ya | | ya | |
| `lihat-dashboard` | ya | ya | ya | ya |
| `lihat-laporan` | ya | ya | | ya |

Laporan, pengelolaan endpoint, dan konfigurasi belum ada; izinnya sudah
didaftarkan supaya policy fitur tersebut tinggal memakainya. `tangani-tiket`
dipersempit `TiketPolicy` menjadi tiket yang ditugaskan kepada pelaku.

### Lapisan otorisasi

1. Middleware `can:` pada rute menolak URL yang dibuka langsung.
2. `boot()` komponen Livewire memanggil `authorize()` pada setiap request ke
   komponen, termasuk request `/livewire/update` yang dikirim tanpa membuka
   halamannya.
3. Setiap aksi pengubah data memanggil `authorize()` terhadap policy model.
4. `@can` di view hanya menyembunyikan menu dan tombol.

Aturan tambahan di `UserPolicy`: Administrator tidak dapat menghapus akunnya
sendiri atau mengubah perannya sendiri, sehingga selalu ada minimal satu
Administrator. Bila tetap terkunci, `php artisan pengguna:peran` dijalankan
dari server.

### Log aktivitas

Tabel `log_aktivitas` hanya dapat ditambah (model menolak ubah dan hapus, tidak
ada rute untuk itu). Nama dan peran pelaku disalin ke baris log sehingga tetap
terbaca setelah akunnya dihapus.

| Sumber | Yang dicatat |
|---|---|
| Event autentikasi | masuk, gagal masuk (email saja), terkunci sementara, keluar, 2FA diaktifkan/dinonaktifkan |
| `UserObserver` | pengguna dibuat, diubah (nilai lama dan baru), peran diubah, kata sandi diubah, dihapus; berlaku juga untuk perubahan dari konsol dan reset kata sandi |
| Penanganan respons 403 | akses ditolak, beserta metode dan jalur |

Belum ada retensi atau pengarsipan log; lihat ROADMAP Tahap 8.

## Keputusan teknis

| Keputusan | Alasan |
|---|---|
| Laravel 13, PHP 8.4, Livewire 4 | Versi yang masih menerima perbaikan bug per Oktober 2026 (Laravel 13 sampai Q3 2027, keamanan Q1 2028; PHP 8.4 keamanan sampai akhir 2028) |
| Starter kit Livewire resmi | Autentikasi Fortify, Flux UI, dan pengujian sudah tersedia dan dipelihara |
| PostgreSQL 17 | Tipe `inet`/`cidr`, constraint yang kuat, dan PostGIS bila peta dibutuhkan |
| Pengujian di PostgreSQL, bukan SQLite | Tipe dan constraint yang dipakai tidak ada di SQLite |
| Waktu disimpan UTC | Deret waktu monitoring tidak ambigu; tampilan dikonversi ke WIB |
| Kolasi ICU `id-ID` | Urutan nama sesuai bahasa Indonesia |
| Leaflet + Leaflet-Geoman, tile dapat dikonfigurasi | Tanpa kunci API dan dapat diarahkan ke server tile internal; Geoman untuk menggambar dan menyunting jalur |
| QR dibuat di server, isinya hanya alamat detail | Tidak ada data yang keluar ke layanan pihak ketiga, dan label yang hilang atau difoto tidak membocorkan apa pun |
| Antrean dan cache di database | Cukup untuk skala kabupaten; tanpa layanan tambahan. Ditinjau lagi di Tahap 4 |

## Lingkungan pengembangan

Windows 11 dengan Laragon. Semua komponen dipasang berdampingan tanpa mengubah
default Laragon atau PATH sistem; `scripts/dev-env.ps1` mengaktifkannya per sesi.

| Komponen | Lokasi |
|---|---|
| PHP 8.4.26 NTS (+ Composer) | `C:\laragon\bin\php\php-8.4.26-nts-Win32-vs17-x64` |
| Node.js 24.21.0 LTS | `C:\laragon\bin\nodejs\node-v24.21.0-win-x64` |
| PostgreSQL 17.11 | `C:\laragon\bin\postgresql\postgresql-17.11` |
| Data PostgreSQL | `C:\laragon\data\postgresql-17` |
| Kata sandi role PostgreSQL | `%APPDATA%\postgresql\pgpass.conf` |

PostgreSQL hanya mendengarkan di `localhost` dan tidak dipasang sebagai layanan
Windows. Sejak 9 Oktober 2026 Laragon sendiri yang menjalankannya (dan PHP 8.4
menjadi versi PHP Laragon); `scripts/dev-env.ps1` hanya menyalakannya bila
belum berjalan.

Laragon menulis ulang `php.ini` saat versi PHP diganti dan membawa daftar
ekstensi dari versi sebelumnya. Bila muncul galat "could not find driver",
aktifkan kembali `pdo_pgsql`, `pgsql`, `sodium`, `sockets`, `zip`, dan
`opcache` di `php.ini` PHP 8.4 (menu Laragon: PHP > Extensions).
