<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string KOORDINAT_VALID = '(
        (lintang IS NULL AND bujur IS NULL)
        OR (lintang IS NOT NULL AND bujur IS NOT NULL AND lintang BETWEEN -90 AND 90 AND bujur BETWEEN -180 AND 180)
    )';

    /**
     * Run the migrations.
     *
     * Pendataan bertahap: selain kode (dan jenis titik), semua kolom boleh
     * kosong. Kelengkapannya tercermin di `status_data`.
     */
    public function up(): void
    {
        Schema::create('tiang', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 50)->unique();
            $table->decimal('lintang', 9, 6)->nullable();
            $table->decimal('bujur', 9, 6)->nullable();
            $table->string('pemilik', 20)->nullable()->index();
            $table->string('kondisi', 20)->nullable()->index();
            $table->string('foto_path')->nullable();
            $table->text('catatan')->nullable();
            $table->string('status_data', 20)->default('perlu_dilengkapi')->index();
            $table->boolean('simulasi')->default(false)->index();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE tiang ADD CONSTRAINT tiang_kode_huruf_besar CHECK (kode = upper(kode))');
        DB::statement('ALTER TABLE tiang ADD CONSTRAINT tiang_koordinat_valid CHECK '.self::KOORDINAT_VALID);
        DB::statement("ALTER TABLE tiang ADD CONSTRAINT tiang_pemilik_valid CHECK (pemilik IN ('diskominfo', 'pln', 'telkom', 'swasta', 'lainnya'))");
        DB::statement("ALTER TABLE tiang ADD CONSTRAINT tiang_kondisi_valid CHECK (kondisi IN ('baik', 'miring', 'rusak', 'hilang'))");
        DB::statement("ALTER TABLE tiang ADD CONSTRAINT tiang_status_data_valid CHECK (status_data IN ('lengkap', 'perlu_dilengkapi'))");
        DB::statement("ALTER TABLE tiang ADD CONSTRAINT tiang_simulasi_berlabel CHECK (NOT simulasi OR kode LIKE 'SIM-%')");

        Schema::create('titik_jaringan', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 50)->unique();
            $table->string('nama')->nullable();
            $table->string('jenis', 10)->index();
            $table->decimal('lintang', 9, 6)->nullable();
            $table->decimal('bujur', 9, 6)->nullable();
            // Titik yang terpasang di tiang (closure, ODP) atau di gedung OPD (OTB).
            $table->foreignId('tiang_id')->nullable()->constrained('tiang')->restrictOnDelete();
            $table->foreignId('opd_id')->nullable()->constrained('opd')->restrictOnDelete();
            $table->string('lokasi')->nullable();
            $table->unsignedSmallInteger('kapasitas_port')->nullable();
            $table->text('catatan')->nullable();
            $table->string('status_data', 20)->default('perlu_dilengkapi')->index();
            $table->boolean('simulasi')->default(false)->index();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE titik_jaringan ADD CONSTRAINT titik_jaringan_kode_huruf_besar CHECK (kode = upper(kode))');
        DB::statement("ALTER TABLE titik_jaringan ADD CONSTRAINT titik_jaringan_jenis_valid CHECK (jenis IN ('odc', 'odp', 'closure', 'otb'))");
        DB::statement('ALTER TABLE titik_jaringan ADD CONSTRAINT titik_jaringan_koordinat_valid CHECK '.self::KOORDINAT_VALID);
        DB::statement('ALTER TABLE titik_jaringan ADD CONSTRAINT titik_jaringan_kapasitas_valid CHECK (kapasitas_port BETWEEN 0 AND 576)');
        DB::statement("ALTER TABLE titik_jaringan ADD CONSTRAINT titik_jaringan_status_data_valid CHECK (status_data IN ('lengkap', 'perlu_dilengkapi'))");
        DB::statement("ALTER TABLE titik_jaringan ADD CONSTRAINT titik_jaringan_simulasi_berlabel CHECK (NOT simulasi OR kode LIKE 'SIM-%')");

        Schema::create('port_perangkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perangkat_id')->constrained('perangkat')->cascadeOnDelete();
            $table->string('nama', 100);
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['perangkat_id', 'nama']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('port_perangkat');
        Schema::dropIfExists('titik_jaringan');
        Schema::dropIfExists('tiang');
    }
};
