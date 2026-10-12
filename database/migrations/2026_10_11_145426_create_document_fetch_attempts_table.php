<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_fetch_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->index();
            $table->string('vin', 17)->index();
            $table->string('source_system', 32);
            $table->string('status', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('document_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_fetch_attempts');
    }
};
