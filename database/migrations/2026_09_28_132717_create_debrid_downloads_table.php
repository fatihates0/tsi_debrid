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
        Schema::create('debrid_downloads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->text('original_link');
            $table->string('link_hash', 64)->index();
            $table->string('debrid_id')->nullable();
            $table->text('debrid_link')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('filesize')->default(0);
            $table->unsignedBigInteger('downloaded_bytes')->default(0);
            $table->string('status')->default('pending')->index(); // pending, unrestricting, downloading, completed, failed
            $table->string('mime_type')->nullable();
            $table->string('storage_path')->nullable();
            $table->text('error_message')->nullable();
            $table->string('user_ip')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debrid_downloads');
    }
};
