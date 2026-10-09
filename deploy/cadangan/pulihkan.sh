#!/usr/bin/env bash
# Pemulihan Dashboard Jaringan dari satu set cadangan.
#
#   sudo dashboard-pulihkan <cap waktu>                 # uji pemulihan (aman)
#   sudo dashboard-pulihkan <cap waktu> --ke-produksi   # menimpa data yang berjalan
#
# <cap waktu> adalah bagian nama berkas, mis. 20261009-011500.
#
# Tanpa --ke-produksi, database dipulihkan ke database baru bernama
# <database>_pulih dan unggahan diekstrak ke direktori sementara. Tidak ada
# data yang berjalan yang disentuh. Pakai cara ini untuk uji pemulihan berkala.
#
# Dengan --ke-produksi, database dan storage/app yang berjalan DIGANTI. Skrip
# meminta nama database diketik ulang, menghentikan layanan, dan menyimpan
# storage/app lama di samping yang baru. Jalankan cadangan baru dahulu.

set -euo pipefail
umask 077

KONFIGURASI="${DASHBOARD_CADANGAN_KONFIGURASI:-/etc/dashboard-jaringan/cadangan.conf}"

NAMA_DATABASE="dashboard_jaringan"
PEMILIK_DATABASE="dashboard_jaringan"
DIREKTORI_APLIKASI="/srv/dashboard-jaringan"
DIREKTORI_CADANGAN="/var/backups/dashboard-jaringan"
PENGGUNA_APLIKASI="dashboard"
LAYANAN="dashboard-antrean.service dashboard-penjadwal.timer dashboard-kesehatan.timer"

if [[ -r "$KONFIGURASI" ]]; then
    # shellcheck source=/dev/null
    source "$KONFIGURASI"
fi

gagal() {
    echo "GAGAL: $*" >&2
    exit 1
}

[[ $# -ge 1 ]] || gagal "pemakaian: dashboard-pulihkan <cap waktu> [--ke-produksi]"

CAP="$1"
MODE="${2:-uji}"

[[ "$CAP" =~ ^[0-9]{8}-[0-9]{6}$ ]] || gagal "cap waktu harus berbentuk TTTTBBHH-JJMMDD"
[[ "$MODE" == "uji" || "$MODE" == "--ke-produksi" ]] || gagal "pilihan tidak dikenal: $MODE"

DUMP="$DIREKTORI_CADANGAN/db-$CAP.dump"
ARSIP="$DIREKTORI_CADANGAN/unggahan-$CAP.tar.gz"
CEK="$DIREKTORI_CADANGAN/$CAP.sha256"

for berkas in "$DUMP" "$ARSIP" "$CEK"; do
    [[ -r "$berkas" ]] || gagal "tidak ada $berkas"
done

echo "Memeriksa checksum..."
( cd "$DIREKTORI_CADANGAN" && sha256sum --check --quiet "$(basename "$CEK")" ) || gagal "checksum tidak cocok; cadangan rusak atau berubah"

sql() {
    runuser -u postgres -- psql --quiet --no-psqlrc --set ON_ERROR_STOP=1 --dbname postgres --command "$1"
}

pulihkan_database() {
    local tujuan="$1"

    sql "DROP DATABASE IF EXISTS \"$tujuan\""
    sql "CREATE DATABASE \"$tujuan\" OWNER \"$PEMILIK_DATABASE\" ENCODING 'UTF8'"
    runuser -u postgres -- pg_restore --exit-on-error --no-owner --role="$PEMILIK_DATABASE" --dbname "$tujuan" "$DUMP"
}

if [[ "$MODE" == "uji" ]]; then
    TUJUAN="${NAMA_DATABASE}_pulih"
    SEMENTARA="$(mktemp -d /var/tmp/dashboard-pulih.XXXXXX)"

    echo "Uji pemulihan: database -> $TUJUAN, unggahan -> $SEMENTARA"
    pulihkan_database "$TUJUAN"
    tar --extract --gzip --file "$ARSIP" --directory "$SEMENTARA"

    echo
    echo "Isi database hasil pemulihan:"
    runuser -u postgres -- psql --no-psqlrc --dbname "$TUJUAN" --command \
        "SELECT (SELECT count(*) FROM users) AS pengguna, (SELECT count(*) FROM opd) AS opd, (SELECT count(*) FROM perangkat) AS perangkat, (SELECT count(*) FROM tiket) AS tiket, (SELECT max(waktu) FROM log_aktivitas) AS log_terakhir"
    echo "Berkas unggahan: $(find "$SEMENTARA/app" -type f | wc -l)"
    echo
    echo "Selesai. Tidak ada data yang berjalan yang diubah."
    echo "Setelah diperiksa, bersihkan:"
    echo "  sudo -u postgres dropdb $TUJUAN && sudo rm -rf $SEMENTARA"
    exit 0
fi

# ------------------------------------------------------------ ke produksi
echo "PERINGATAN: database \"$NAMA_DATABASE\" dan $DIREKTORI_APLIKASI/storage/app akan DIGANTI"
echo "dengan cadangan $CAP. Semua perubahan sejak cadangan itu hilang."
read -r -p "Ketik nama database untuk melanjutkan: " JAWABAN
[[ "$JAWABAN" == "$NAMA_DATABASE" ]] || gagal "dibatalkan"

echo "Menghentikan layanan..."
# Di server baru unit-unit ini mungkin belum terpasang.
# shellcheck disable=SC2086
systemctl stop $LAYANAN 2>/dev/null || echo "  (sebagian layanan belum terpasang; dilewati)"
( cd "$DIREKTORI_APLIKASI" && runuser -u "$PENGGUNA_APLIKASI" -- php artisan down ) || true

echo "Memulihkan database ke database sementara..."
pulihkan_database "${NAMA_DATABASE}_baru"

echo "Menukar database..."
LAMA="${NAMA_DATABASE}_sebelum_$(date +%Y%m%d%H%M%S)"
sql "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$NAMA_DATABASE' AND pid <> pg_backend_pid()" > /dev/null
sql "ALTER DATABASE \"$NAMA_DATABASE\" RENAME TO \"$LAMA\""
sql "ALTER DATABASE \"${NAMA_DATABASE}_baru\" RENAME TO \"$NAMA_DATABASE\""

echo "Memulihkan berkas unggahan..."
STORAGE="$DIREKTORI_APLIKASI/storage"
[[ -d "$STORAGE/app" ]] && mv "$STORAGE/app" "$STORAGE/app.sebelum-$CAP"
tar --extract --gzip --file "$ARSIP" --directory "$STORAGE"
chown -R "$PENGGUNA_APLIKASI:$PENGGUNA_APLIKASI" "$STORAGE/app"

echo "Menyalakan layanan..."
( cd "$DIREKTORI_APLIKASI" && runuser -u "$PENGGUNA_APLIKASI" -- php artisan migrate --force && runuser -u "$PENGGUNA_APLIKASI" -- php artisan up )
# shellcheck disable=SC2086
systemctl start $LAYANAN 2>/dev/null || echo "  (sebagian layanan belum terpasang; nyalakan setelah dipasang)"

echo
echo "Selesai. Data lama masih ada sebagai:"
echo "  database $LAMA"
echo "  $STORAGE/app.sebelum-$CAP"
echo "Hapus keduanya setelah pemulihan dipastikan benar. Kolektor (bila di host lain)"
echo "perlu dinyalakan ulang: sudo systemctl restart 'dashboard-kolektor@*'"
