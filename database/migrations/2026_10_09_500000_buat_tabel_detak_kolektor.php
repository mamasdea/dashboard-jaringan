<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Satu baris per proses kolektor, diperbarui tiap putaran. Dengan ini
     * dashboard dapat membedakan "kolektor berhenti" dari "tidak ada endpoint
     * yang jatuh tempo".
     */
    public function up(): void
    {
        Schema::create('detak_kolektor', function (Blueprint $table) {
            $table->id();
            // Nama host dan nomor proses.
            $table->string('nama')->unique();
            $table->string('adapter', 30);
            $table->boolean('simulasi')->default(false);
            $table->timestampTz('mulai_pada');
            $table->timestampTz('detak_pada')->index();
            $table->unsignedBigInteger('putaran')->default(0);
            $table->unsignedInteger('diperiksa_putaran_terakhir')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detak_kolektor');
    }
};
