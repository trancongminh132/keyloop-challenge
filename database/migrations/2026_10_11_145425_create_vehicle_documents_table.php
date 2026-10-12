<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('vin', 17)->index();
            $table->string('source_system', 32);
            $table->string('external_id');
            $table->string('document_type')->nullable();
            $table->string('title');
            $table->timestamp('issued_at')->nullable();
            $table->string('document_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
