# Menyiapkan sesi PowerShell untuk pengembangan lokal di Laragon.
# Pakai dengan dot-source agar PATH berlaku di sesi ini:
#
#     . .\scripts\dev-env.ps1
#
# Skrip ini tidak mengubah PATH sistem maupun versi default Laragon.
# Sesuaikan tiga jalur di bawah bila versi yang terpasang berbeda.

$php = 'C:\laragon\bin\php\php-8.4.26-nts-Win32-vs17-x64'
$node = 'C:\laragon\bin\nodejs\node-v24.21.0-win-x64'
$pgBin = 'C:\laragon\bin\postgresql\postgresql-17.11\bin'
$pgData = 'C:\laragon\data\postgresql-17'

foreach ($dir in @($php, $node, $pgBin, $pgData)) {
    if (-not (Test-Path $dir)) {
        Write-Warning "Tidak ditemukan: $dir"
    }
}

$env:Path = "$php;$node;$pgBin;$env:Path"

& "$pgBin\pg_isready.exe" -h 127.0.0.1 -p 5432 -q
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Menyalakan PostgreSQL...'
    Start-Process -FilePath "$pgBin\pg_ctl.exe" `
        -ArgumentList '-D', $pgData, '-l', "$pgData\server.log", '-w', 'start' `
        -WindowStyle Hidden -Wait
    & "$pgBin\pg_isready.exe" -h 127.0.0.1 -p 5432
}

Write-Host ("PHP {0} | Node {1} | {2}" -f (php -r 'echo PHP_VERSION;'), (node -v), (psql --version))
Write-Host "Hentikan PostgreSQL dengan: pg_ctl -D $pgData stop"
