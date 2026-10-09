<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opd', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama');
            $table->text('alamat')->nullable();
            $table->decimal('lintang', 9, 6)->nullable();
            $table->decimal('bujur', 9, 6)->nullable();
            $table->string('kontak_teknis_nama')->nullable();
            $table->string('kontak_teknis_telepon', 30)->nullable();
            $table->string('kontak_teknis_email')->nullable();
            $table->boolean('aktif')->default(true)->index();
            $table->boolean('simulasi')->default(false)->index();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE opd ADD CONSTRAINT opd_kode_huruf_besar CHECK (kode = upper(kode))');
        DB::statement(
            'ALTER TABLE opd ADD CONSTRAINT opd_koordinat_valid CHECK (
                (lintang IS NULL AND bujur IS NULL)
                OR (
                    lintang IS NOT NULL AND bujur IS NOT NULL
                    AND lintang BETWEEN -90 AND 90 AND bujur BETWEEN -180 AND 180
                )
            )'
        );
        DB::statement("ALTER TABLE opd ADD CONSTRAINT opd_simulasi_berlabel CHECK (NOT simulasi OR nama LIKE '[SIMULASI]%')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opd');
    }
};
