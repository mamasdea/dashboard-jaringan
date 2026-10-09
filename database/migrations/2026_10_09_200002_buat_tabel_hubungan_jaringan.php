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
     * Model koneksi: setiap core punya dua ujung, yaitu di titik awal dan titik
     * akhir segmennya. Satu ujung core hanya boleh dipakai SEKALI, oleh salah
     * satu dari:
     *   - sambungan (core masuk <-> core keluar),
     *   - terminasi ke port (port_terminasi.core_id),
     *   - koneksi endpoint langsung ke port perangkat.
     *
     * Aturan lintas tabel ini dijaga tabel `pemakaian_ujung_core`: primary key
     * (core_id, titik_jaringan_id), diisi otomatis oleh trigger. Pemakaian
     * ganda ditolak database sebagai pelanggaran unik, dari mana pun asalnya.
     */
    public function up(): void
    {
        Schema::create('sambungan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titik_jaringan_id')->constrained('titik_jaringan')->restrictOnDelete();
            $table->foreignId('core_masuk_id')->constrained('core_kabel')->restrictOnDelete();
            $table->foreignId('core_keluar_id')->constrained('core_kabel')->restrictOnDelete();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('core_masuk_id');
            $table->index('core_keluar_id');
        });

        DB::statement('ALTER TABLE sambungan ADD CONSTRAINT sambungan_core_berbeda CHECK (core_masuk_id <> core_keluar_id)');

        Schema::create('koneksi_endpoint', function (Blueprint $table) {
            $table->id();
            // Satu port perangkat hanya punya satu koneksi.
            $table->foreignId('port_perangkat_id')->unique()->constrained('port_perangkat')->restrictOnDelete();
            // Lewat port terminasi (patch cord), atau ...
            $table->foreignId('port_terminasi_id')->nullable()->unique()->constrained('port_terminasi')->restrictOnDelete();
            // ... langsung ke ujung core di sebuah titik (tanpa patch panel).
            $table->foreignId('core_id')->nullable()->constrained('core_kabel')->restrictOnDelete();
            $table->foreignId('titik_jaringan_id')->nullable()->constrained('titik_jaringan')->restrictOnDelete();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE koneksi_endpoint ADD CONSTRAINT koneksi_endpoint_satu_sasaran
             CHECK ((port_terminasi_id IS NOT NULL) <> (core_id IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE koneksi_endpoint ADD CONSTRAINT koneksi_endpoint_core_butuh_titik
             CHECK ((core_id IS NULL) = (titik_jaringan_id IS NULL))'
        );

        DB::unprepared(<<<'SQL'
            CREATE TABLE pemakaian_ujung_core (
                core_id bigint NOT NULL REFERENCES core_kabel (id) ON DELETE CASCADE,
                titik_jaringan_id bigint NOT NULL REFERENCES titik_jaringan (id) ON DELETE CASCADE,
                sumber varchar(20) NOT NULL CHECK (sumber IN ('sambungan', 'terminasi', 'endpoint')),
                sumber_id bigint NOT NULL,
                PRIMARY KEY (core_id, titik_jaringan_id)
            );

            CREATE INDEX pemakaian_ujung_core_sumber ON pemakaian_ujung_core (sumber, sumber_id);

            -- OR REPLACE: `migrate:fresh` menghapus tabel tetapi tidak menghapus fungsi.
            CREATE OR REPLACE FUNCTION catat_pemakaian_ujung_core() RETURNS trigger AS $$
            DECLARE
                sumber_baris text := TG_ARGV[0];
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    DELETE FROM pemakaian_ujung_core WHERE sumber = sumber_baris AND sumber_id = OLD.id;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                IF sumber_baris = 'sambungan' THEN
                    INSERT INTO pemakaian_ujung_core (core_id, titik_jaringan_id, sumber, sumber_id)
                    VALUES (NEW.core_masuk_id, NEW.titik_jaringan_id, sumber_baris, NEW.id),
                           (NEW.core_keluar_id, NEW.titik_jaringan_id, sumber_baris, NEW.id);
                ELSIF NEW.core_id IS NOT NULL THEN
                    INSERT INTO pemakaian_ujung_core (core_id, titik_jaringan_id, sumber, sumber_id)
                    VALUES (NEW.core_id, NEW.titik_jaringan_id, sumber_baris, NEW.id);
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sambungan_pemakaian_ujung_core
                AFTER INSERT OR UPDATE OR DELETE ON sambungan
                FOR EACH ROW EXECUTE FUNCTION catat_pemakaian_ujung_core('sambungan');

            CREATE TRIGGER port_terminasi_pemakaian_ujung_core
                AFTER INSERT OR UPDATE OF core_id, titik_jaringan_id OR DELETE ON port_terminasi
                FOR EACH ROW EXECUTE FUNCTION catat_pemakaian_ujung_core('terminasi');

            CREATE TRIGGER koneksi_endpoint_pemakaian_ujung_core
                AFTER INSERT OR UPDATE OR DELETE ON koneksi_endpoint
                FOR EACH ROW EXECUTE FUNCTION catat_pemakaian_ujung_core('endpoint');
        SQL);

        // Histori perubahan hubungan jaringan; hanya dapat ditambah.
        Schema::create('riwayat_hubungan', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('waktu')->useCurrent()->index();
            $table->foreignId('pelaku_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pelaku_nama')->nullable();
            $table->string('jenis', 20)->index();
            $table->string('aksi', 10);
            $table->foreignId('titik_jaringan_id')->nullable()->constrained('titik_jaringan')->nullOnDelete();
            $table->foreignId('segmen_kabel_id')->nullable()->constrained('segmen_kabel')->nullOnDelete();
            // Teks yang tetap terbaca setelah objeknya dihapus.
            $table->text('ringkasan');
            $table->jsonb('sebelum')->nullable();
            $table->jsonb('sesudah')->nullable();
        });

        DB::statement("ALTER TABLE riwayat_hubungan ADD CONSTRAINT riwayat_hubungan_jenis_valid CHECK (jenis IN ('sambungan', 'terminasi', 'koneksi_endpoint', 'rute_tiang'))");
        DB::statement("ALTER TABLE riwayat_hubungan ADD CONSTRAINT riwayat_hubungan_aksi_valid CHECK (aksi IN ('dibuat', 'diubah', 'dihapus'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat_hubungan');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS koneksi_endpoint_pemakaian_ujung_core ON koneksi_endpoint;
            DROP TRIGGER IF EXISTS port_terminasi_pemakaian_ujung_core ON port_terminasi;
            DROP TRIGGER IF EXISTS sambungan_pemakaian_ujung_core ON sambungan;
            DROP FUNCTION IF EXISTS catat_pemakaian_ujung_core();
            DROP TABLE IF EXISTS pemakaian_ujung_core;
        SQL);

        Schema::dropIfExists('koneksi_endpoint');
        Schema::dropIfExists('sambungan');
    }
};
