#!/usr/bin/env bash
# Cadangan Dashboard Jaringan: database PostgreSQL dan berkas unggahan.
#
#   sudo install -m 0750 deploy/cadangan/cadangkan.sh /usr/local/sbin/dashboard-cadangkan
#   sudo install -m 0750 deploy/cadangan/pulihkan.sh  /usr/local/sbin/dashboard-pulihkan
#   sudo install -m 0640 deploy/cadangan/cadangan.conf.example /etc/dashboard-jaringan/cadangan.conf
#
# Dijalankan root (timer dashboard-cadangan). Menghasilkan, per hari:
#   db-<cap waktu>.dump          pg_dump format custom
#   unggahan-<cap waktu>.tar.gz  storage/app (foto perangkat, tiang, tiket)
#   <cap waktu>.sha256           checksum keduanya
#
# Yang TIDAK dicadangkan di sini: berkas .env (APP_KEY, kunci monitoring).
# Tanpa APP_KEY, rahasia 2FA tidak dapat dibuka; tanpa MONITORING_KUNCI_PRIVAT,
# kredensial SNMP tersimpan tidak dapat dibuka. Simpan keduanya terpisah dan
# terlindung (docs/BACKUP-RESTORE.md).

set -euo pipefail
umask 077

KONFIGURASI="${DASHBOARD_CADANGAN_KONFIGURASI:-/etc/dashboard-jaringan/cadangan.conf}"

# Bawaan; ditimpa berkas konfigurasi.
NAMA_DATABASE="dashboard_jaringan"
DIREKTORI_APLIKASI="/srv/dashboard-jaringan"
DIREKTORI_CADANGAN="/var/backups/dashboard-jaringan"
RETENSI_HARI=14
SALIN_KE=""
BERKAS_LOG="/var/log/dashboard-cadangan.log"

if [[ -r "$KONFIGURASI" ]]; then
    # shellcheck source=/dev/null
    source "$KONFIGURASI"
fi

catat() {
    printf '%s %s\n' "$(date --iso-8601=seconds)" "$*" | tee -a "$BERKAS_LOG"
}

gagal() {
    catat "GAGAL: $*"
    exit 1
}

trap 'gagal "berhenti di baris $LINENO"' ERR

[[ "$RETENSI_HARI" =~ ^[0-9]+$ && "$RETENSI_HARI" -ge 1 ]] || gagal "RETENSI_HARI harus bilangan bulat >= 1"
[[ -d "$DIREKTORI_APLIKASI/storage/app" ]] || gagal "tidak ada $DIREKTORI_APLIKASI/storage/app"

install -d -m 0700 "$DIREKTORI_CADANGAN"

CAP="$(date +%Y%m%d-%H%M%S)"
DUMP="$DIREKTORI_CADANGAN/db-$CAP.dump"
ARSIP="$DIREKTORI_CADANGAN/unggahan-$CAP.tar.gz"
CEK="$DIREKTORI_CADANGAN/$CAP.sha256"

catat "mulai cadangan $CAP"

# Database. Ditulis ke berkas sementara dahulu supaya dump yang terpotong
# tidak pernah tampak seperti cadangan utuh.
runuser -u postgres -- pg_dump --format=custom --no-owner --no-privileges "$NAMA_DATABASE" > "$DUMP.sebagian"
runuser -u postgres -- pg_restore --list "$DUMP.sebagian" > /dev/null || gagal "dump tidak dapat dibaca kembali"
mv "$DUMP.sebagian" "$DUMP"

# Berkas unggahan. Berkas sementara Livewire dan cache kerangka tidak ikut.
tar --create --gzip --file "$ARSIP.sebagian" \
    --directory "$DIREKTORI_APLIKASI/storage" \
    --exclude='app/private/livewire-tmp' \
    --exclude='app/livewire-tmp' \
    app
tar --list --gzip --file "$ARSIP.sebagian" > /dev/null || gagal "arsip unggahan tidak dapat dibaca kembali"
mv "$ARSIP.sebagian" "$ARSIP"

( cd "$DIREKTORI_CADANGAN" && sha256sum "$(basename "$DUMP")" "$(basename "$ARSIP")" > "$CEK" )

catat "selesai: $(du -h "$DUMP" | cut -f1) database, $(du -h "$ARSIP" | cut -f1) unggahan"

# Salinan ke luar server. Cadangan di disk yang sama bukan cadangan.
if [[ -n "$SALIN_KE" ]]; then
    rsync --archive --chmod=F600,D700 "$DUMP" "$ARSIP" "$CEK" "$SALIN_KE/" || gagal "salinan ke $SALIN_KE gagal"
    catat "disalin ke $SALIN_KE"
else
    catat "PERINGATAN: SALIN_KE kosong; cadangan hanya ada di server ini"
fi

# Retensi lokal. Hanya berkas berpola cadangan ini yang dihapus.
find "$DIREKTORI_CADANGAN" -maxdepth 1 -type f \
    \( -name 'db-*.dump' -o -name 'unggahan-*.tar.gz' -o -name '*.sha256' -o -name '*.sebagian' \) \
    -mtime "+$RETENSI_HARI" -print -delete | while read -r berkas; do catat "dihapus (retensi): $berkas"; done

catat "cadangan $CAP beres"
