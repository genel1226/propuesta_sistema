<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft')->index(); // draft | processing | completed | failed
            $table->string('category')->nullable();
            $table->string('cliente')->nullable();
            $table->string('source_name');
            $table->string('template_name');
            $table->string('source_path');
            $table->string('template_path');
            $table->json('template_schema')->nullable();
            $table->json('source_tables')->nullable();
            $table->json('mapping')->nullable();
            $table->json('settings')->nullable();
            $table->json('stats')->nullable();
            $table->longText('result_rows')->nullable();
            $table->string('result_path')->nullable();
            $table->string('clean_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
