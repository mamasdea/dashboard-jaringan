# Menjalankan kolektor monitoring di Windows (pengembangan, atau sebagai tugas terjadwal).
#
#     .\deploy\kolektor\jalankan-kolektor.ps1            # terus berjalan
#     .\deploy\kolektor\jalankan-kolektor.ps1 -Sekali    # satu putaran
#
# Untuk berjalan otomatis, daftarkan skrip ini di Task Scheduler dengan pemicu
# "At startup" dan satu instans saja. Sesuaikan jalur PHP bila berbeda.

param(
    [switch] $Sekali,
    [int] $Jeda = 5,
    [string] $Php = 'C:\laragon\bin\php\php-8.4.26-nts-Win32-vs17-x64\php.exe'
)

$akar = Resolve-Path (Join-Path $PSScriptRoot '..\..')

# OID numerik dipakai, jadi berkas MIB tidak dimuat. PowerShell tidak dapat menyetel variabel
# kosong (itu justru menghapusnya); pemisah daftar saja berarti "tidak ada MIB" di Windows.
$env:MIBS = ';'
$env:SNMP_PERSISTENT_DIR = Join-Path $env:LOCALAPPDATA 'dashboard-kolektor\snmp'
$env:SNMPCONFPATH = $env:SNMP_PERSISTENT_DIR
New-Item -ItemType Directory -Force $env:SNMP_PERSISTENT_DIR | Out-Null

$argumen = @('-d', 'extension=snmp', (Join-Path $akar 'artisan'), 'monitoring:kolektor', "--jeda=$Jeda")
if ($Sekali) { $argumen += '--sekali' }

# -d extension=snmp : ekstensi SNMP dimuat hanya untuk proses ini, tidak untuk web.
& $Php @argumen
