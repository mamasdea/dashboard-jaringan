@php
    use App\Support\KodeQr;
    use App\Support\TautanAset;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />
        <title>Label {{ TautanAset::labelJenis($jenis) }} - {{ config('app.name') }}</title>
        <style>
            /* Halaman mandiri untuk dicetak: tanpa aset Vite agar tetap utuh di printer mana pun. */
            * { box-sizing: border-box; }
            body { margin: 0; padding: 12mm; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color: #111; background: #f4f4f5; }
            .bilah { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 8mm; font-size: 14px; }
            .bilah button { padding: 8px 14px; font: inherit; font-weight: 600; color: #fff; background: #18181b; border: 0; border-radius: 6px; cursor: pointer; }
            .lembar { display: flex; flex-wrap: wrap; gap: 3mm; }
            .label { display: flex; align-items: center; gap: 3mm; width: 70mm; height: 36mm; padding: 3mm; background: #fff; border: 0.3mm solid #111; border-radius: 1.5mm; break-inside: avoid; page-break-inside: avoid; }
            .label svg { flex: none; width: 29mm; height: 29mm; }
            .teks { min-width: 0; }
            .jenis { font-size: 7pt; letter-spacing: 0.05em; text-transform: uppercase; }
            .kode { font-size: 12pt; font-weight: 700; line-height: 1.15; overflow-wrap: anywhere; }
            .instansi { margin-top: 1.5mm; font-size: 6.5pt; line-height: 1.25; }
            .simulasi { display: inline-block; margin-top: 1mm; padding: 0 1mm; font-size: 6.5pt; font-weight: 700; border: 0.3mm solid #111; }
            .catatan { margin-top: 8mm; font-size: 12px; color: #52525b; }
            @media print {
                body { padding: 0; background: #fff; }
                .bilah, .catatan { display: none; }
                @page { margin: 8mm; }
            }
        </style>
    </head>
    <body>
        <div class="bilah">
            <button type="button" onclick="window.print()">Cetak</button>
            <span>{{ $aset->count() }} label {{ mb_strtolower(TautanAset::labelJenis($jenis)) }}, ukuran 70 × 36 mm.</span>
            @if ($tidakDitemukan->isNotEmpty())
                <span>Tidak ditemukan: {{ $tidakDitemukan->implode(', ') }}.</span>
            @endif
        </div>

        @if ($aset->isEmpty())
            <p>Tidak ada aset untuk dicetak. Buka halaman ini dari tombol "Cetak label" pada daftar atau halaman detail aset.</p>
        @else
            <div class="lembar">
                @foreach ($aset as $butir)
                    @php($isi = TautanAset::url($butir))
                    <div class="label" data-test="label-aset" data-qr-isi="{{ $isi }}">
                        {!! KodeQr::svg($isi, 220) !!}
                        <div class="teks">
                            <div class="jenis">{{ TautanAset::labelJenis($jenis) }}</div>
                            <div class="kode">{{ TautanAset::kode($butir) }}</div>
                            @if ($butir->simulasi)
                                <div class="simulasi">SIMULASI</div>
                            @endif
                            <div class="instansi">Diskominfo Kab. Wonosobo<br>Pindai untuk melihat detail (wajib masuk)</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="catatan">
            Label hanya memuat kode aset dan QR alamat halaman detail. Tidak ada IP, kata sandi, atau token di dalamnya.
        </p>
    </body>
</html>
