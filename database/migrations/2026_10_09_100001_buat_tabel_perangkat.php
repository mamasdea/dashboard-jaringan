<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Kredensial SNMP sengaja tidak disimpan di tabel ini (lihat CLAUDE.md).
     */
    public function up(): void
    {
        Schema::create('perangkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opd_id')->constrained('opd')->restrictOnDelete();
            $table->string('penempatan', 20)->default('opd');
            $table->string('lokasi')->nullable();
            $table->string('kode_aset', 50)->unique();
            $table->string('nama');
            $table->string('jenis', 30)->index();
            $table->string('merek', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('nomor_seri', 100)->nullable()->index();
            $table->ipAddress('ip_manajemen')->nullable()->unique();
            $table->string('port_uplink', 100)->nullable();
            $table->boolean('monitoring_aktif')->default(false)->index();
            $table->boolean('switch_utama')->default(false);
            $table->string('foto_path')->nullable();
            $table->text('catatan')->nullable();
            $table->boolean('simulasi')->default(false)->index();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE perangkat ADD CONSTRAINT perangkat_kode_aset_huruf_besar CHECK (kode_aset = upper(kode_aset))');
        DB::statement("ALTER TABLE perangkat ADD CONSTRAINT perangkat_penempatan_valid CHECK (penempatan IN ('opd', 'diskominfo'))");
        DB::statement(
            "ALTER TABLE perangkat ADD CONSTRAINT perangkat_jenis_valid
             CHECK (jenis IN ('switch', 'router', 'firewall', 'access_point', 'olt', 'media_converter', 'lainnya'))"
        );
        DB::statement("ALTER TABLE perangkat ADD CONSTRAINT perangkat_switch_utama_adalah_switch CHECK (NOT switch_utama OR jenis = 'switch')");
        DB::statement('ALTER TABLE perangkat ADD CONSTRAINT perangkat_monitoring_butuh_ip CHECK (NOT monitoring_aktif OR ip_manajemen IS NOT NULL)');
        DB::statement("ALTER TABLE perangkat ADD CONSTRAINT perangkat_simulasi_berlabel CHECK (NOT simulasi OR nama LIKE '[SIMULASI]%')");

        // Paling banyak satu switch utama per OPD.
        DB::statement('CREATE UNIQUE INDEX perangkat_satu_switch_utama_per_opd ON perangkat (opd_id) WHERE switch_utama');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perangkat');
    }
};
