<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string KELUARAN = "('berhasil', 'gagal_perangkat', 'galat_kolektor')";

    /**
     * Run the migrations.
     *
     * Hasil ICMP dan SNMP dicatat terpisah: ICMP menentukan status keterjangkauan,
     * SNMP punya keluarannya sendiri. Metrik yang tidak didukung perangkat
     * disimpan sebagai NULL ("Tidak tersedia"), tidak pernah sebagai nol.
     */
    public function up(): void
    {
        Schema::table('endpoint_monitoring', function (Blueprint $table) {
            // Untuk endpoint tanpa perangkat; bila ada perangkat, port uplink perangkat yang dipakai.
            $table->string('port_uplink', 100)->nullable();
        });

        Schema::table('hasil_pemeriksaan', function (Blueprint $table) {
            // NULL: SNMP tidak dicoba (endpoint tanpa profil, atau ICMP gagal).
            $table->string('keluaran_snmp', 20)->nullable();
            $table->string('kode_galat_snmp', 50)->nullable();
            $table->string('pesan_snmp', 500)->nullable();
        });

        DB::statement('ALTER TABLE hasil_pemeriksaan ADD CONSTRAINT hasil_pemeriksaan_keluaran_snmp_valid CHECK (keluaran_snmp IN '.self::KELUARAN.')');

        Schema::table('status_terkini', function (Blueprint $table) {
            $table->string('snmp_keluaran', 20)->nullable();
            $table->string('snmp_kode_galat', 50)->nullable();
            $table->unsignedInteger('snmp_gagal_berturut')->default(0);
            $table->timestampTz('snmp_berhasil_terakhir_pada')->nullable();
        });

        DB::statement('ALTER TABLE status_terkini ADD CONSTRAINT status_terkini_snmp_keluaran_valid CHECK (snmp_keluaran IN '.self::KELUARAN.')');

        // Identitas dan uptime perangkat menurut SNMP; satu baris per endpoint.
        Schema::create('snmp_perangkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->unique()->constrained('endpoint_monitoring')->cascadeOnDelete();
            $table->text('sys_descr')->nullable();
            $table->string('sys_object_id')->nullable();
            $table->string('sys_name')->nullable();
            // sysUpTime dalam seperseratus detik.
            $table->unsignedBigInteger('uptime_tiktik')->nullable();
            $table->timestampTz('restart_terdeteksi_pada')->nullable();
            $table->timestampTz('diperbarui_pada');
            $table->boolean('simulasi')->default(false);
        });

        Schema::create('interface_snmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('endpoint_monitoring')->cascadeOnDelete();
            // Identitas yang dapat diverifikasi: ifName (atau ifDescr), dikuatkan alamat fisik.
            // ifIndex hanya nomor urut saat ini dan boleh berubah setelah restart.
            $table->string('nama');
            $table->string('alamat_fisik', 40)->nullable();
            $table->unsignedBigInteger('if_index');
            $table->timestampTz('if_index_berubah_pada')->nullable();
            $table->string('deskripsi')->nullable();
            $table->string('alias')->nullable();
            $table->unsignedInteger('tipe')->nullable();
            $table->unsignedBigInteger('kecepatan_bps')->nullable();
            $table->unsignedSmallInteger('status_admin')->nullable();
            $table->unsignedSmallInteger('status_operasional')->nullable();
            $table->boolean('uplink')->default(false);

            // Sampel penghitung terakhir, dasar perhitungan laju berikutnya.
            $table->decimal('oktet_masuk', 20, 0)->nullable();
            $table->decimal('oktet_keluar', 20, 0)->nullable();
            $table->boolean('penghitung_64_bit')->nullable();
            $table->decimal('galat_masuk', 20, 0)->nullable();
            $table->decimal('galat_keluar', 20, 0)->nullable();
            $table->decimal('buang_masuk', 20, 0)->nullable();
            $table->decimal('buang_keluar', 20, 0)->nullable();
            $table->unsignedBigInteger('diskontinuitas_tiktik')->nullable();
            $table->unsignedBigInteger('uptime_tiktik_saat_sampel')->nullable();
            $table->timestampTz('sampel_pada')->nullable();

            // Laju hasil perhitungan; NULL beserta alasannya bila tidak dapat dihitung.
            $table->decimal('bps_masuk', 20, 2)->nullable();
            $table->decimal('bps_keluar', 20, 2)->nullable();
            $table->string('alasan_laju_kosong', 30)->nullable();

            // Optik SFP, hanya bila ada adapter vendor yang mendukung perangkatnya.
            $table->decimal('sfp_rx_dbm', 7, 3)->nullable();
            $table->decimal('sfp_tx_dbm', 7, 3)->nullable();
            $table->decimal('sfp_suhu_c', 6, 2)->nullable();
            $table->string('sfp_sumber', 30)->nullable();

            $table->timestampTz('terlihat_terakhir_pada');
            $table->boolean('simulasi')->default(false);

            $table->unique(['endpoint_id', 'nama']);
        });

        // Deret waktu trafik, hanya untuk interface uplink yang dipantau.
        Schema::create('sampel_trafik', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interface_snmp_id')->constrained('interface_snmp')->cascadeOnDelete();
            $table->timestampTz('diambil_pada');
            $table->decimal('selang_detik', 10, 2)->nullable();
            $table->decimal('bps_masuk', 20, 2)->nullable();
            $table->decimal('bps_keluar', 20, 2)->nullable();
            $table->decimal('galat_masuk', 20, 0)->nullable();
            $table->decimal('galat_keluar', 20, 0)->nullable();
            $table->decimal('buang_masuk', 20, 0)->nullable();
            $table->decimal('buang_keluar', 20, 0)->nullable();
            $table->string('alasan_laju_kosong', 30)->nullable();
            $table->unsignedSmallInteger('status_operasional')->nullable();
            $table->boolean('simulasi')->default(false);

            $table->index(['interface_snmp_id', 'diambil_pada']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sampel_trafik');
        Schema::dropIfExists('interface_snmp');
        Schema::dropIfExists('snmp_perangkat');

        DB::statement('ALTER TABLE status_terkini DROP CONSTRAINT status_terkini_snmp_keluaran_valid');
        Schema::table('status_terkini', function (Blueprint $table) {
            $table->dropColumn(['snmp_keluaran', 'snmp_kode_galat', 'snmp_gagal_berturut', 'snmp_berhasil_terakhir_pada']);
        });

        DB::statement('ALTER TABLE hasil_pemeriksaan DROP CONSTRAINT hasil_pemeriksaan_keluaran_snmp_valid');
        Schema::table('hasil_pemeriksaan', function (Blueprint $table) {
            $table->dropColumn(['keluaran_snmp', 'kode_galat_snmp', 'pesan_snmp']);
        });

        Schema::table('endpoint_monitoring', function (Blueprint $table) {
            $table->dropColumn('port_uplink');
        });
    }
};
