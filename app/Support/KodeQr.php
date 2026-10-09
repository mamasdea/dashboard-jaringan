<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class KodeQr
{
    /**
     * QR sebagai SVG siap disisipkan ke HTML. Dibuat di server, tanpa layanan
     * pihak ketiga, sehingga isi QR tidak keluar dari aplikasi.
     */
    public static function svg(string $isi, int $ukuran = 200): string
    {
        $penulis = new Writer(new ImageRenderer(new RendererStyle($ukuran, 2), new SvgImageBackEnd));

        // Deklarasi XML tidak boleh berada di tengah dokumen HTML.
        return trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $penulis->writeString($isi)));
    }
}
