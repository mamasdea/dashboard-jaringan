<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('log_aktivitas', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('waktu')->useCurrent()->index();

            // Nama dan peran disalin agar log tetap terbaca setelah akun dihapus.
            $table->foreignId('pelaku_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pelaku_nama')->nullable();
            $table->string('pelaku_peran', 20)->nullable();

            $table->string('aksi', 60)->index();

            $table->string('subjek_tipe')->nullable();
            $table->unsignedBigInteger('subjek_id')->nullable();
            $table->string('subjek_label')->nullable();
            $table->index(['subjek_tipe', 'subjek_id']);

            $table->jsonb('rincian')->nullable();
            $table->ipAddress('alamat_ip')->nullable();
            $table->string('agen_pengguna', 500)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_aktivitas');
    }
};
