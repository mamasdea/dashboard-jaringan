<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $dokumen->judul }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
        h1 { font-size: 14pt; margin: 0 0 2pt; }
        h2 { font-size: 10.5pt; margin: 14pt 0 4pt; }
        p { margin: 0 0 2pt; }
        .keterangan { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.5pt solid #999; padding: 3pt 4pt; text-align: left; vertical-align: top; word-wrap: break-word; }
        th { background: #eee; }
        table.ringkasan { width: auto; }
        table.ringkasan td { border: 0; padding: 1pt 12pt 1pt 0; }
        ol { margin: 0; padding-left: 14pt; }
        li { margin-bottom: 2pt; }
    </style>
</head>
<body>
    <p class="keterangan">{{ config('app.name') }}</p>
    <h1>{{ $dokumen->judul }}</h1>
    <p>Periode: {{ $dokumen->periode ?? 'keadaan saat laporan dibuat' }}</p>
    <p class="keterangan">Dibuat {{ \App\Laporan\DokumenLaporan::waktu($dokumen->dibuatPada) }} WIB</p>

    @if ($dokumen->ringkasan !== [])
        <h2>Ringkasan</h2>
        <table class="ringkasan">
            @foreach ($dokumen->ringkasan as $nama => $nilai)
                <tr><td>{{ $nama }}</td><td><strong>{{ $nilai }}</strong></td></tr>
            @endforeach
        </table>
    @endif

    @foreach ($dokumen->bagian as $bagian)
        <h2>{{ $bagian->judul }}</h2>
        @if ($bagian->baris === [])
            <p>{{ $bagian->kosong }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach ($bagian->kolom as $kolom)
                            <th>{{ $kolom }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bagian->baris as $baris)
                        <tr>
                            @foreach ($baris as $sel)
                                <td>{{ $sel }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <h2>Definisi dan keterbatasan</h2>
    <ol>
        @foreach ($dokumen->catatan as $catatan)
            <li>{{ $catatan }}</li>
        @endforeach
    </ol>
</body>
</html>
