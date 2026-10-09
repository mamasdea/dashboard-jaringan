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
     * Status tiket adalah status pekerjaan teknisi dan terpisah dari status
     * monitoring: insiden yang pulih tidak menutup tiket.
     */
    public function up(): void
    {
        Schema::create('tiket', function (Blueprint $table) {
            $table->id();
            // Dibentuk model dari tahun dan id.
            $table->string('nomor', 20)->unique();
            $table->string('judul');

            // Yang terkait. Tiket tetap ada bila objeknya dihapus.
            $table->foreignId('opd_id')->nullable()->constrained('opd')->nullOnDelete();
            $table->foreignId('perangkat_id')->nullable()->constrained('perangkat')->nullOnDelete();
            $table->foreignId('segmen_kabel_id')->nullable()->constrained('segmen_kabel')->nullOnDelete();
            $table->foreignId('titik_jaringan_id')->nullable()->constrained('titik_jaringan')->nullOnDelete();
            $table->foreignId('tiang_id')->nullable()->constrained('tiang')->nullOnDelete();

            // Kaitan dengan monitoring.
            $table->foreignId('endpoint_id')->nullable()->constrained('endpoint_monitoring')->nullOnDelete();
            $table->foreignId('insiden_id')->nullable()->unique()->constrained('insiden')->nullOnDelete();
            $table->string('sumber', 10)->default('manual');

            $table->timestampTz('waktu_mulai');
            // Kapan monitoring melihat endpoint terjangkau lagi. Hanya informasi.
            $table->timestampTz('monitoring_pulih_pada')->nullable();
            // Kapan layanan dinyatakan pulih oleh orang yang menangani.
            $table->timestampTz('waktu_pemulihan')->nullable();

            $table->string('prioritas', 10)->default('sedang')->index();
            $table->string('status', 15)->default('baru')->index();
            $table->foreignId('teknisi_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();

            // Dua kolom yang sengaja dipisah: perkiraan awal dan hasil pemeriksaan lapangan.
            $table->text('dugaan_penyebab')->nullable();
            $table->text('penyebab_terverifikasi')->nullable();

            $table->boolean('simulasi')->default(false)->index();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE tiket ADD CONSTRAINT tiket_sumber_valid CHECK (sumber IN ('otomatis', 'manual'))");
        DB::statement("ALTER TABLE tiket ADD CONSTRAINT tiket_prioritas_valid CHECK (prioritas IN ('rendah', 'sedang', 'tinggi', 'kritis'))");
        DB::statement("ALTER TABLE tiket ADD CONSTRAINT tiket_status_valid CHECK (status IN ('baru', 'ditugaskan', 'dikerjakan', 'menunggu', 'selesai', 'dibatalkan'))");
        DB::statement('ALTER TABLE tiket ADD CONSTRAINT tiket_pemulihan_setelah_mulai CHECK (waktu_pemulihan IS NULL OR waktu_pemulihan >= waktu_mulai)');
        DB::statement("ALTER TABLE tiket ADD CONSTRAINT tiket_selesai_butuh_pemulihan CHECK (status <> 'selesai' OR waktu_pemulihan IS NOT NULL)");
        DB::statement('ALTER TABLE tiket ADD CONSTRAINT tiket_satu_aset_fo CHECK (num_nonnulls(segmen_kabel_id, titik_jaringan_id, tiang_id) <= 1)');
        DB::statement("ALTER TABLE tiket ADD CONSTRAINT tiket_simulasi_berlabel CHECK (NOT simulasi OR judul LIKE '[SIMULASI]%')");

        // Tidak ada dua tiket otomatis yang masih terbuka untuk endpoint yang sama.
        DB::statement(
            "CREATE UNIQUE INDEX tiket_satu_otomatis_terbuka_per_endpoint ON tiket (endpoint_id)
             WHERE sumber = 'otomatis' AND status NOT IN ('selesai', 'dibatalkan') AND endpoint_id IS NOT NULL"
        );

        // Linimasa tiket: tindakan, catatan, foto, dan entri sistem. Hanya dapat ditambah.
        Schema::create('catatan_tiket', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiket_id')->constrained('tiket')->cascadeOnDelete();
            $table->foreignId('pengguna_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pengguna_nama')->nullable();
            $table->string('jenis', 10);
            $table->text('isi');
            $table->string('foto_path')->nullable();
            $table->timestampTz('dibuat_pada')->useCurrent();

            $table->index(['tiket_id', 'dibuat_pada']);
        });

        DB::statement("ALTER TABLE catatan_tiket ADD CONSTRAINT catatan_tiket_jenis_valid CHECK (jenis IN ('tindakan', 'catatan', 'sistem'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catatan_tiket');
        Schema::dropIfExists('tiket');
    }
};
