<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string STATUS = "('normal', 'menurun', 'tidak_terjangkau', 'belum_diketahui', 'pemeliharaan')";

    /**
     * Run the migrations.
     *
     * Enam hal yang sengaja dipisah: profil akses SNMP, endpoint, hasil
     * pemeriksaan (deret waktu), status terkini (satu baris per endpoint),
     * insiden, dan riwayat perubahan status. Semua cap waktu bertipe
     * timestamptz dan ditulis dalam UTC.
     */
    public function up(): void
    {
        Schema::create('profil_snmp', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->string('versi', 5);
            $table->unsignedSmallInteger('port')->default(161);
            // Disegel dengan kunci publik; hanya host kolektor yang dapat membukanya.
            $table->text('kredensial_tersegel');
            $table->text('catatan')->nullable();
            $table->boolean('simulasi')->default(false);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE profil_snmp ADD CONSTRAINT profil_snmp_versi_valid CHECK (versi IN ('v2c', 'v3'))");
        DB::statement("ALTER TABLE profil_snmp ADD CONSTRAINT profil_snmp_simulasi_berlabel CHECK (NOT simulasi OR nama LIKE '[SIMULASI]%')");

        Schema::create('endpoint_monitoring', function (Blueprint $table) {
            $table->id();
            // Dibuat dari perangkat yang monitoringnya diaktifkan; tetap ada (nonaktif) bila perangkat dihapus.
            $table->foreignId('perangkat_id')->nullable()->unique()->constrained('perangkat')->nullOnDelete();
            $table->string('nama');
            $table->ipAddress('alamat');
            $table->string('metode', 10)->default('icmp');
            $table->foreignId('profil_snmp_id')->nullable()->constrained('profil_snmp')->restrictOnDelete();
            $table->unsignedInteger('interval_detik')->default(60);
            // Kosong berarti memakai ambang bawaan di config/monitoring.php.
            $table->decimal('ambang_latency_ms', 8, 2)->nullable();
            $table->decimal('ambang_packet_loss_persen', 5, 2)->nullable();
            $table->boolean('aktif')->default(true)->index();

            $table->boolean('pemeliharaan')->default(false);
            $table->timestampTz('pemeliharaan_sampai')->nullable();
            $table->string('pemeliharaan_catatan')->nullable();

            // Penjadwalan dan pencegah pemeriksaan bertumpuk.
            $table->timestampTz('berikutnya_pada')->useCurrent()->index();
            $table->timestampTz('sedang_diperiksa_sejak')->nullable();
            $table->uuid('klaim')->nullable();

            // Hanya dibaca adapter simulasi.
            $table->string('skenario_simulasi', 30)->nullable();
            $table->boolean('simulasi')->default(false)->index();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE endpoint_monitoring ADD CONSTRAINT endpoint_monitoring_metode_valid CHECK (metode IN ('icmp', 'snmp'))");
        DB::statement('ALTER TABLE endpoint_monitoring ADD CONSTRAINT endpoint_monitoring_interval_valid CHECK (interval_detik BETWEEN 10 AND 86400)');
        DB::statement('ALTER TABLE endpoint_monitoring ADD CONSTRAINT endpoint_monitoring_ambang_valid CHECK (ambang_latency_ms > 0 AND ambang_packet_loss_persen BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE endpoint_monitoring ADD CONSTRAINT endpoint_monitoring_snmp_butuh_profil CHECK (metode <> 'snmp' OR profil_snmp_id IS NOT NULL)");

        Schema::create('hasil_pemeriksaan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('endpoint_monitoring')->cascadeOnDelete();
            // Dibuat kolektor; hasil yang sama yang terkirim dua kali hanya tersimpan sekali.
            $table->uuid('id_pemeriksaan')->unique();
            // Kapan pemeriksaan dilakukan (jam kolektor) dan kapan hasilnya diterima (jam server).
            $table->timestampTz('diperiksa_pada');
            $table->timestampTz('diterima_pada');
            $table->string('keluaran', 20);
            $table->decimal('latency_ms', 9, 2)->nullable();
            $table->decimal('packet_loss_persen', 5, 2)->nullable();
            $table->string('kode_galat', 50)->nullable();
            $table->string('pesan', 500)->nullable();
            $table->string('sumber', 30);
            // Hasil yang tidak mengubah status: datang terlambat, saat pemeliharaan, atau bercap waktu di depan.
            $table->boolean('diterapkan')->default(true);
            $table->string('alasan_tidak_diterapkan', 20)->nullable();
            $table->boolean('simulasi')->default(false);

            $table->index(['endpoint_id', 'diperiksa_pada']);
        });

        DB::statement("ALTER TABLE hasil_pemeriksaan ADD CONSTRAINT hasil_pemeriksaan_keluaran_valid CHECK (keluaran IN ('berhasil', 'gagal_perangkat', 'galat_kolektor'))");
        DB::statement("ALTER TABLE hasil_pemeriksaan ADD CONSTRAINT hasil_pemeriksaan_alasan_valid CHECK (alasan_tidak_diterapkan IN ('terlambat', 'pemeliharaan', 'waktu_di_depan'))");
        DB::statement('ALTER TABLE hasil_pemeriksaan ADD CONSTRAINT hasil_pemeriksaan_alasan_konsisten CHECK (diterapkan = (alasan_tidak_diterapkan IS NULL))');
        DB::statement('ALTER TABLE hasil_pemeriksaan ADD CONSTRAINT hasil_pemeriksaan_ukuran_valid CHECK (latency_ms >= 0 AND packet_loss_persen BETWEEN 0 AND 100)');

        Schema::create('status_terkini', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->unique()->constrained('endpoint_monitoring')->cascadeOnDelete();
            $table->string('status', 20)->default('belum_diketahui')->index();
            $table->timestampTz('sejak')->useCurrent();
            $table->unsignedInteger('gagal_berturut')->default(0);
            $table->unsignedInteger('berhasil_berturut')->default(0);
            $table->unsignedInteger('galat_kolektor_berturut')->default(0);
            // Hasil terakhir yang diterapkan (apa pun keluarannya), untuk menolak hasil yang datang tidak berurutan.
            $table->timestampTz('diperiksa_terakhir_pada')->nullable();
            // Hasil terakhir yang benar-benar menguji endpoint; dasar kedaluwarsa.
            $table->timestampTz('hasil_valid_terakhir_pada')->nullable();
            $table->timestampTz('diterima_terakhir_pada')->nullable();
            $table->decimal('latency_ms', 9, 2)->nullable();
            $table->decimal('packet_loss_persen', 5, 2)->nullable();
            $table->boolean('simulasi')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE status_terkini ADD CONSTRAINT status_terkini_status_valid CHECK (status IN '.self::STATUS.')');

        Schema::create('insiden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('endpoint_monitoring')->cascadeOnDelete();
            $table->timestampTz('mulai_pada');
            $table->timestampTz('selesai_pada')->nullable();
            $table->string('ditutup_karena', 20)->nullable();
            $table->boolean('simulasi')->default(false);
            $table->timestamps();

            $table->index(['endpoint_id', 'mulai_pada']);
        });

        DB::statement("ALTER TABLE insiden ADD CONSTRAINT insiden_penutupan_valid CHECK (ditutup_karena IN ('pulih', 'pemeliharaan'))");
        DB::statement('ALTER TABLE insiden ADD CONSTRAINT insiden_penutupan_konsisten CHECK ((selesai_pada IS NULL) = (ditutup_karena IS NULL))');
        DB::statement('ALTER TABLE insiden ADD CONSTRAINT insiden_waktu_urut CHECK (selesai_pada IS NULL OR selesai_pada >= mulai_pada)');
        // Paling banyak satu insiden terbuka per endpoint.
        DB::statement('CREATE UNIQUE INDEX insiden_satu_terbuka_per_endpoint ON insiden (endpoint_id) WHERE selesai_pada IS NULL');

        // Hanya dapat ditambah.
        Schema::create('riwayat_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('endpoint_monitoring')->cascadeOnDelete();
            $table->string('dari', 20);
            $table->string('menjadi', 20);
            $table->string('alasan', 30);
            $table->timestampTz('pada');
            $table->foreignId('hasil_pemeriksaan_id')->nullable()->constrained('hasil_pemeriksaan')->nullOnDelete();
            $table->foreignId('insiden_id')->nullable()->constrained('insiden')->nullOnDelete();
            $table->boolean('simulasi')->default(false);

            $table->index(['endpoint_id', 'pada']);
        });

        DB::statement('ALTER TABLE riwayat_status ADD CONSTRAINT riwayat_status_status_valid CHECK (dari IN '.self::STATUS.' AND menjadi IN '.self::STATUS.' AND dari <> menjadi)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat_status');
        Schema::dropIfExists('insiden');
        Schema::dropIfExists('status_terkini');
        Schema::dropIfExists('hasil_pemeriksaan');
        Schema::dropIfExists('endpoint_monitoring');
        Schema::dropIfExists('profil_snmp');
    }
};
