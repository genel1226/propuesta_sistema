<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mapeos aprendidos: "este encabezado del cliente corresponde a esta columna de la plantilla".
     */
    public function up(): void
    {
        Schema::create('header_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('source_header', 191);
            $table->string('template_key', 191);
            $table->unsignedInteger('hits')->default(1);
            $table->timestamps();

            $table->unique(['source_header', 'template_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('header_aliases');
    }
};
