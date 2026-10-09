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
        Schema::create('segmen_kabel', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 50)->unique();
            $table->string('nama')->nullable();
            $table->string('klasifikasi', 15)->nullable()->index();
            $table->unsignedSmallInteger('jumlah_core')->nullable();
            $table->foreignId('titik_awal_id')->nullable()->constrained('titik_jaringan')->restrictOnDelete();
            $table->foreignId('titik_akhir_id')->nullable()->constrained('titik_jaringan')->restrictOnDelete();
            // Dua panjang yang sengaja dipisah: hasil pengukuran lapangan (diisi
            // manusia) dan hasil perhitungan dari jalur peta (diisi aplikasi).
            $table->decimal('panjang_ukur_m', 9, 2)->nullable();
            $table->decimal('panjang_peta_m', 9, 2)->nullable();
            // Daftar titik [bujur, lintang] (urutan GeoJSON LineString).
            $table->jsonb('jalur_peta')->nullable();
            $table->text('catatan')->nullable();
            $table->string('status_data', 20)->default('perlu_dilengkapi')->index();
            $table->boolean('simulasi')->default(false)->index();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE segmen_kabel ADD CONSTRAINT segmen_kabel_kode_huruf_besar CHECK (kode = upper(kode))');
        DB::statement("ALTER TABLE segmen_kabel ADD CONSTRAINT segmen_kabel_klasifikasi_valid CHECK (klasifikasi IN ('backbone', 'distribusi'))");
        DB::statement('ALTER TABLE segmen_kabel ADD CONSTRAINT segmen_kabel_jumlah_core_valid CHECK (jumlah_core BETWEEN 1 AND 288)');
        DB::statement('ALTER TABLE segmen_kabel ADD CONSTRAINT segmen_kabel_ujung_berbeda CHECK (titik_awal_id <> titik_akhir_id)');
        DB::statement('ALTER TABLE segmen_kabel ADD CONSTRAINT segmen_kabel_panjang_positif CHECK (panjang_ukur_m > 0 AND panjang_peta_m >= 0)');
        DB::statement("ALTER TABLE segmen_kabel ADD CONSTRAINT segmen_kabel_status_data_valid CHECK (status_data IN ('lengkap', 'perlu_dilengkapi'))");
        DB::statement("ALTER TABLE segmen_kabel ADD CONSTRAINT segmen_kabel_simulasi_berlabel CHECK (NOT simulasi OR kode LIKE 'SIM-%')");

        // Banyak-ke-banyak antara segmen kabel dan tiang, dengan urutan.
        Schema::create('tiang_segmen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segmen_kabel_id')->constrained('segmen_kabel')->cascadeOnDelete();
            $table->foreignId('tiang_id')->constrained('tiang')->restrictOnDelete();
            $table->unsignedSmallInteger('urutan');

            $table->unique(['segmen_kabel_id', 'tiang_id']);
            $table->unique(['segmen_kabel_id', 'urutan']);
            $table->index('tiang_id');
        });

        DB::statement('ALTER TABLE tiang_segmen ADD CONSTRAINT tiang_segmen_urutan_positif CHECK (urutan >= 1)');

        Schema::create('core_kabel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segmen_kabel_id')->constrained('segmen_kabel')->cascadeOnDelete();
            $table->unsignedSmallInteger('nomor');
            $table->unsignedSmallInteger('tube');
            $table->string('warna', 20);
            $table->string('status', 10)->default('tersedia')->index();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['segmen_kabel_id', 'nomor']);
        });

        DB::statement("ALTER TABLE core_kabel ADD CONSTRAINT core_kabel_status_valid CHECK (status IN ('tersedia', 'terpakai', 'rusak'))");
        DB::statement('ALTER TABLE core_kabel ADD CONSTRAINT core_kabel_nomor_positif CHECK (nomor >= 1 AND tube >= 1)');

        Schema::create('port_terminasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titik_jaringan_id')->constrained('titik_jaringan')->cascadeOnDelete();
            $table->unsignedSmallInteger('nomor');
            $table->string('status', 10)->default('tersedia')->index();
            // Core yang diterminasi di sisi belakang port ini.
            $table->foreignId('core_id')->nullable()->constrained('core_kabel')->restrictOnDelete();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['titik_jaringan_id', 'nomor']);
        });

        DB::statement("ALTER TABLE port_terminasi ADD CONSTRAINT port_terminasi_status_valid CHECK (status IN ('tersedia', 'terpakai', 'rusak'))");
        DB::statement('ALTER TABLE port_terminasi ADD CONSTRAINT port_terminasi_nomor_positif CHECK (nomor >= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('port_terminasi');
        Schema::dropIfExists('core_kabel');
        Schema::dropIfExists('tiang_segmen');
        Schema::dropIfExists('segmen_kabel');
    }
};
