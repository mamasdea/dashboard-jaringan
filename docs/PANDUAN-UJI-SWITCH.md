# Panduan Uji Satu Switch

Langkah menguji kolektor monitoring nyata terhadap **satu** switch, sebelum
kolektor dijalankan untuk perangkat lain. Kolektor hanya menghubungi alamat
yang Anda tulis; tidak ada pemindaian subnet dan tidak ada discovery.

> Status pengujian kode: jalur ICMP sudah dicoba terhadap loopback di Windows.
> Jalur SNMP baru diuji dengan perangkat tiruan; panduan inilah uji pertamanya
> terhadap perangkat sungguhan. Adapter optik SFP MikroTik belum pernah diuji
> pada perangkat nyata.

## 1. Yang perlu disiapkan

| Hal | Keterangan |
|---|---|
| Satu switch uji | Pilih yang tidak kritis. Catat IP manajemennya |
| Host kolektor | Mesin di jaringan Diskominfo yang dapat menjangkau IP manajemen itu |
| Akun SNMP baca-saja | SNMPv3 `authPriv` bila switch mendukung; v2c hanya bila tidak, dengan community **read-only** |
| Di switch | Batasi SNMP hanya dari IP host kolektor (ACL), dan jangan beri hak tulis |
| PHP di host kolektor | PHP 8.4 dengan `php_snmp` tersedia (cukup ada; dimuat per proses) dan utilitas `ping` |

Periksa ekstensi SNMP tanpa mengaktifkannya untuk web:

```powershell
$env:MIBS = ';'   # tidak memuat berkas MIB; di PowerShell nilai kosong justru menghapus variabelnya
php -d extension=snmp -r "echo extension_loaded('snmp') ? 'snmp siap' : 'snmp tidak ada';"
```

## 2. Isi `.env` di host kolektor

```ini
MONITORING_ADAPTER=jaringan

# Hanya alamat/CIDR ini yang boleh dihubungi. Untuk uji, tulis satu alamat saja.
MONITORING_SASARAN_DIIZINKAN=10.10.1.5/32

MONITORING_UJI_ALAMAT=10.10.1.5
# Nama port uplink persis seperti ifName, ifDescr, atau ifAlias di switch.
MONITORING_UJI_PORT_UPLINK=GigabitEthernet0/1

# SNMPv3 (diutamakan)
MONITORING_UJI_SNMP_VERSI=v3
MONITORING_UJI_SNMP_PENGGUNA=...
MONITORING_UJI_SNMP_TINGKAT_KEAMANAN=authPriv
MONITORING_UJI_SNMP_PROTOKOL_AUTH=SHA
MONITORING_UJI_SNMP_SANDI_AUTH=...
MONITORING_UJI_SNMP_PROTOKOL_PRIV=AES
MONITORING_UJI_SNMP_SANDI_PRIV=...

# ATAU SNMPv2c, hanya bila switch tidak mendukung v3:
# MONITORING_UJI_SNMP_VERSI=v2c
# MONITORING_UJI_SNMP_COMMUNITY=...
```

Alamat `10.10.1.5` hanyalah contoh. `.env` tidak ikut git; jangan menyalin
kredensial ke berkas lain, tiket, atau percakapan.

Protokol yang didukung: autentikasi MD5, SHA, SHA256, SHA512; privasi DES, AES
(AES-128). Kosongkan `MONITORING_UJI_SNMP_VERSI` untuk menguji ICMP saja.

## 3. Uji ICMP dan SNMP sekali

```powershell
$env:MIBS = ';'   # tidak memuat berkas MIB; di PowerShell nilai kosong justru menghapus variabelnya
php -d extension=snmp artisan monitoring:uji-endpoint
```

Di Linux: `MIBS= php -d extension=snmp artisan monitoring:uji-endpoint`.

Perintah ini tidak menulis apa pun ke database dan tidak pernah menampilkan
kredensial. Yang diperiksa:

| Bagian | Yang benar |
|---|---|
| `ICMP : Berhasil` | Latency dan packet loss masuk akal untuk jaringan intra |
| `SNMP : Berhasil` | Dicatat terpisah dari ICMP |
| `sysName`, `sysDescr`, `sysObjectID` | Cocok dengan switch yang dimaksud |
| `uptime` | Cocok dengan `show version` / halaman status switch |
| Baris `UPLINK` | Muncul pada port yang benar; status `up/up` |
| Alamat fisik, kecepatan | Cocok dengan konfigurasi port |
| `Tidak tersedia` | Hanya pada metrik yang memang tidak dimiliki switch |

Arti hasil yang tidak berhasil:

| Keluaran | Artinya | Periksa |
|---|---|---|
| `Alamat ... tidak ada di MONITORING_SASARAN_DIIZINKAN` | Tidak ada paket yang dikirim | Daftar izin di `.env` |
| `ICMP : Endpoint tidak menjawab` | Ping tidak dibalas | Rute dan firewall dari host kolektor; SNMP tidak dicoba |
| `ICMP : Galat kolektor [ping_tidak_dapat_dijalankan]` | Utilitas ping tidak bisa dijalankan | `ping` terpasang dan boleh dipakai pengguna kolektor |
| `SNMP : Endpoint tidak menjawab [snmp_tanpa_jawaban]` | Tidak ada jawaban SNMP | SNMP aktif di switch, ACL, UDP 161, versi |
| `SNMP : Endpoint tidak menjawab [snmp_ditolak]` | Kredensial ditolak | Nama pengguna, sandi, protokol auth/priv |
| `SNMP : Galat kolektor [ekstensi_snmp_tidak_ada]` | PHP dijalankan tanpa `-d extension=snmp` | Cara menjalankan |
| `Port uplink "..." tidak ditemukan` | Nama tidak cocok | Jalankan dengan `--semua`, salin nama persisnya |

## 4. Uji laju trafik dengan dua sampel

```powershell
php -d extension=snmp artisan monitoring:uji-endpoint --sampel=3 --jeda=30
```

Pada sampel pertama kolom Masuk/Keluar berisi "butuh sampel ke-2"; itu benar,
karena laju dihitung dari selisih dua pembacaan penghitung. Bandingkan laju
uplink pada sampel kedua dan ketiga dengan grafik atau `show interface` di
switch pada saat yang sama. Selisih sampai beberapa persen wajar.

Untuk melihat semua interface: tambahkan `--semua`.

## 5. Uji yang sengaja dibuat gagal

Lakukan satu per satu dan kembalikan setelahnya:

| Percobaan | Yang harus terjadi |
|---|---|
| Ubah satu karakter sandi SNMP | ICMP tetap Berhasil; SNMP gagal `snmp_ditolak` atau `snmp_tanpa_jawaban` |
| Ganti `MONITORING_UJI_ALAMAT` ke alamat di luar daftar izin | Ditolak sebelum ada paket yang dikirim |
| Jalankan tanpa `-d extension=snmp` | ICMP Berhasil; SNMP `Galat kolektor` |
| Cabut kabel uplink switch uji (bila boleh) | ICMP `Endpoint tidak menjawab` |

Bandingkan juga dengan utilitas lain (mis. `snmpwalk` atau perangkat lunak
manajemen switch) untuk memastikan nilainya sama.

## 6. Jalankan sebagai kolektor

Setelah langkah 3–5 benar, daftarkan endpoint uji supaya ikut diperiksa
kolektor. Langkah ini menyimpan kredensial SNMP ke tabel `profil_snmp` dalam
keadaan tersegel, jadi kunci penyegel harus sudah ada:

```powershell
php artisan monitoring:buat-kunci          # sekali saja; salin hasilnya ke .env
php -d extension=snmp artisan monitoring:uji-endpoint --simpan
```

Lalu jalankan kolektor (proses terpisah dari web):

```powershell
.\deploy\kolektor\jalankan-kolektor.ps1     # Windows
```

Di Linux gunakan `deploy/kolektor/supervisor.conf` atau
`deploy/kolektor/dashboard-kolektor@.service`; keduanya menunjuk checkout
kolektor di `/srv/dashboard-kolektor` (lihat [DEPLOYMENT.md](DEPLOYMENT.md)).

Pantau hasilnya:

```powershell
php artisan monitoring:status
```

Biarkan berjalan sekurangnya 15 menit, lalu periksa:

- endpoint "[UJI] Endpoint uji dari environment" berstatus Normal dan kolom
  SNMP "Berhasil";
- endpoint SIMULASI berstatus "Belum diketahui": alamatnya di luar daftar izin,
  jadi kolektor nyata tidak mengirim apa pun kepadanya;
- mematikan kolektor membuat status menjadi "Belum diketahui" setelah tiga
  menit, bukan tetap Normal.

Endpoint dan profil uji dihapus dengan menghapus baris bernama `[UJI] ...` di
`endpoint_monitoring` dan `profil_snmp` (belum ada halaman pengelolaannya).

## 7. Batas yang berlaku

| Hal | Bawaan | Batas di kode |
|---|---|---|
| Paket ICMP per pemeriksaan | 4 | 1–10 |
| Batas waktu tiap balasan ICMP | 1000 ms | 100–5000 ms |
| Batas waktu permintaan SNMP | 1500 ms | 200–5000 ms |
| Percobaan ulang SNMP | 1 | 0–3 |
| Interface dibaca per perangkat | 512 | 1–2048 |
| Pemeriksaan bersamaan | 1 per proses kolektor | jumlah proses (`numprocs`) |
| Interval pemeriksaan | 60 detik | 10 detik – 1 hari |

Nilai di luar batas dipangkas otomatis. Tidak ada percobaan ulang selain yang
tercantum: pemeriksaan yang gagal ditunggu sampai interval berikutnya.

## 8. Host dan akun database terpisah (produksi)

Kolektor menulis hasil langsung ke database; tidak ada API penerima hasil pada
tahap ini. Bila kolektor kelak mengirim hasil lewat API, API itu wajib memakai
autentikasi per kolektor dan membatasi endpoint yang boleh dilaporkan tiap
kolektor.

Yang berlaku sekarang untuk memisahkan kolektor dari web:

- Kolektor di host sendiri, dengan `.env` sendiri. Hanya `.env` kolektor yang
  berisi `MONITORING_KUNCI_PRIVAT` dan `MONITORING_SASARAN_DIIZINKAN` yang
  sesungguhnya; `.env` web cukup `MONITORING_KUNCI_PUBLIK`.
- Firewall: hanya host kolektor yang boleh menjangkau IP manajemen switch.
- Role PostgreSQL tersendiri untuk kolektor, tanpa akses ke tabel pengguna:

```sql
CREATE ROLE dashboard_kolektor LOGIN PASSWORD '...';
GRANT CONNECT ON DATABASE dashboard_jaringan TO dashboard_kolektor;
GRANT USAGE ON SCHEMA public TO dashboard_kolektor;

-- Dibaca: sasaran dan kredensial tersegel.
GRANT SELECT ON endpoint_monitoring, profil_snmp, perangkat TO dashboard_kolektor;
-- Penjadwalan dan klaim.
GRANT UPDATE (berikutnya_pada, sedang_diperiksa_sejak, klaim, pemeliharaan, pemeliharaan_sampai, pemeliharaan_catatan, updated_at)
    ON endpoint_monitoring TO dashboard_kolektor;
-- Hasil.
GRANT SELECT, INSERT, UPDATE ON status_terkini, insiden, snmp_perangkat, interface_snmp TO dashboard_kolektor;
GRANT SELECT, INSERT ON hasil_pemeriksaan, riwayat_status, sampel_trafik TO dashboard_kolektor;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO dashboard_kolektor;
```

Daftar di atas adalah hak untuk pemeriksaan saja. Daftar lengkap yang dipakai
saat pemasangan, termasuk hak untuk tiket otomatis, tanda hidup, dan cache,
ada di [DEPLOYMENT.md](DEPLOYMENT.md) langkah 10. Keduanya belum diuji pada
pemasangan nyata; jalankan satu putaran kolektor dengan role ini dan tambahkan
hak yang kurang sebelum dipakai.
