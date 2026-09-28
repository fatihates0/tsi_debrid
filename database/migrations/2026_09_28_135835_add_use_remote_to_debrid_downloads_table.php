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
        Schema::table('debrid_downloads', function (Blueprint $table) {
            $table->boolean('use_remote')->default(true)->after('user_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debrid_downloads', function (Blueprint $table) {
            $table->dropColumn('use_remote');
        });
    }
};
