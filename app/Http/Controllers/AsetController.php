<?php

namespace App\Http\Controllers;

use App\Models\SegmenKabel;
use App\Models\TitikJaringan;
use App\Support\TautanAset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Halaman detail aset (sasaran QR) dan label cetaknya. Keduanya di belakang
 * middleware `auth`, dan setiap aset diperiksa lewat policy `view`.
 */
class AsetController extends Controller
{
    public function rinci(string $jenis, string $kode): View|RedirectResponse
    {
        // Otorisasi dahulu, supaya yang tidak berhak tidak dapat menebak kode mana yang ada.
        Gate::authorize('viewAny', TautanAset::JENIS[$jenis]);

        $aset = TautanAset::cari($jenis, $kode);

        abort_if($aset === null, 404);
        Gate::authorize('view', $aset);

        // Titik jaringan dan segmen kabel sudah punya halaman rincian sendiri.
        if ($aset instanceof TitikJaringan) {
            return redirect()->route('fo.titik.rinci', $aset);
        }

        if ($aset instanceof SegmenKabel) {
            return redirect()->route('fo.kabel.rinci', $aset);
        }

        return view('aset.rinci', ['aset' => $aset, 'jenis' => $jenis]);
    }

    public function label(Request $request, string $jenis): View
    {
        $kelas = TautanAset::JENIS[$jenis];

        Gate::authorize('viewAny', $kelas);

        $kode = collect(explode(',', (string) $request->query('kode')))
            ->map(fn (string $k) => mb_strtoupper(trim($k)))
            ->filter(fn (string $k) => preg_match('/^'.TautanAset::POLA_KODE.'$/', $k) === 1)
            ->unique()
            ->take(100)
            ->values();

        $kolom = TautanAset::kolomKode($jenis);

        $aset = $kode->isEmpty()
            ? collect()
            : $kelas::query()->whereIn($kolom, $kode)->orderBy($kolom)->get()
                ->filter(fn ($a) => Gate::allows('view', $a))
                ->values();

        return view('aset.label', [
            'jenis' => $jenis,
            'aset' => $aset,
            'tidakDitemukan' => $kode->diff($aset->pluck($kolom))->values(),
        ]);
    }
}
