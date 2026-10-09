<?php

namespace App\Support;

use JsonException;
use RuntimeException;
use SensitiveParameter;

/**
 * Menyegel kredensial SNMP dengan kriptografi kunci publik (sealed box
 * libsodium).
 *
 * Menyegel hanya butuh kunci publik; membuka butuh kunci privat. Dengan
 * memasang kunci privat hanya di host kolektor, aplikasi web dapat menyimpan
 * kredensial baru tetapi tidak dapat membaca yang sudah tersimpan, bahkan
 * bila host web dan isi databasenya bocor.
 */
final class BrankasKredensial
{
    public function __construct(
        private readonly ?string $kunciPublik,
        #[SensitiveParameter] private readonly ?string $kunciPrivat,
    ) {}

    /**
     * Pasangan kunci baru, dalam base64.
     *
     * @return array{publik: string, privat: string}
     */
    public static function buatPasanganKunci(): array
    {
        $pasangan = sodium_crypto_box_keypair();

        return [
            'publik' => base64_encode(sodium_crypto_box_publickey($pasangan)),
            'privat' => base64_encode(sodium_crypto_box_secretkey($pasangan)),
        ];
    }

    /**
     * @param  array<string, mixed>  $kredensial
     */
    public function segel(#[SensitiveParameter] array $kredensial): string
    {
        return base64_encode(sodium_crypto_box_seal(
            json_encode($kredensial, JSON_THROW_ON_ERROR),
            $this->kunci($this->kunciPublik, SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, 'MONITORING_KUNCI_PUBLIK'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function buka(string $tersegel): array
    {
        $pasangan = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $this->kunci($this->kunciPrivat, SODIUM_CRYPTO_BOX_SECRETKEYBYTES, 'MONITORING_KUNCI_PRIVAT'),
            $this->kunci($this->kunciPublik, SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, 'MONITORING_KUNCI_PUBLIK'),
        );

        $polos = sodium_crypto_box_seal_open((string) base64_decode($tersegel, true), $pasangan);

        if ($polos === false) {
            throw new RuntimeException('Kredensial tidak dapat dibuka: kunci tidak cocok atau data rusak.');
        }

        try {
            return (array) json_decode($polos, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Kredensial tidak dapat dibuka: isinya tidak valid.');
        }
    }

    public function dapatMembuka(): bool
    {
        return filled($this->kunciPrivat) && filled($this->kunciPublik);
    }

    /**
     * @return non-empty-string
     */
    private function kunci(#[SensitiveParameter] ?string $base64, int $panjang, string $namaVariabel): string
    {
        $kunci = blank($base64) ? '' : (string) base64_decode((string) $base64, true);

        if ($kunci === '' || strlen($kunci) !== $panjang) {
            throw new RuntimeException("{$namaVariabel} belum diatur atau tidak valid. Buat dengan `php artisan monitoring:buat-kunci`.");
        }

        return $kunci;
    }
}
