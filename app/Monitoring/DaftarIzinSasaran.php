<?php

namespace App\Monitoring;

/**
 * Daftar alamat dan CIDR yang boleh dihubungi adapter nyata
 * (MONITORING_SASARAN_DIIZINKAN). Kosong berarti tidak ada yang diizinkan.
 */
final class DaftarIzinSasaran
{
    /**
     * @param  list<string>  $daftar
     */
    public function __construct(private readonly array $daftar) {}

    public static function dariKonfigurasi(): self
    {
        return new self(array_values((array) config('monitoring.sasaran_diizinkan', [])));
    }

    /**
     * Hanya alamat IP harfiah yang diterima; nama host tidak pernah diurai,
     * sehingga sasaran tidak dapat dibelokkan lewat DNS.
     */
    public function mengizinkan(string $alamat): bool
    {
        $biner = @inet_pton($alamat);

        if ($biner === false || filter_var($alamat, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($this->daftar as $butir) {
            if ($this->cocok($biner, $butir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Butir konfigurasi yang tidak dapat dibaca, untuk dilaporkan ke operator.
     *
     * @return list<string>
     */
    public function butirTidakValid(): array
    {
        return array_values(array_filter($this->daftar, fn (string $butir) => $this->urai($butir) === null));
    }

    private function cocok(string $biner, string $butir): bool
    {
        $jaringan = $this->urai($butir);

        if ($jaringan === null || strlen($jaringan['alamat']) !== strlen($biner)) {
            return false;
        }

        $byteUtuh = intdiv($jaringan['prefiks'], 8);
        $sisaBit = $jaringan['prefiks'] % 8;

        if (substr($biner, 0, $byteUtuh) !== substr($jaringan['alamat'], 0, $byteUtuh)) {
            return false;
        }

        if ($sisaBit === 0) {
            return true;
        }

        $topeng = 0xFF << (8 - $sisaBit) & 0xFF;

        return (ord($biner[$byteUtuh]) & $topeng) === (ord($jaringan['alamat'][$byteUtuh]) & $topeng);
    }

    /**
     * @return array{alamat: string, prefiks: int}|null
     */
    private function urai(string $butir): ?array
    {
        [$alamat, $prefiks] = array_pad(explode('/', trim($butir), 2), 2, null);

        $biner = @inet_pton((string) $alamat);

        if ($biner === false) {
            return null;
        }

        $maks = strlen($biner) * 8;

        if ($prefiks === null) {
            return ['alamat' => $biner, 'prefiks' => $maks];
        }

        if (! ctype_digit($prefiks) || (int) $prefiks > $maks) {
            return null;
        }

        return ['alamat' => $biner, 'prefiks' => (int) $prefiks];
    }
}
