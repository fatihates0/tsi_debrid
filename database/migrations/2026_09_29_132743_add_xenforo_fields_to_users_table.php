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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('xenforo_id')->nullable()->unique()->after('id');
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('avatar_url', 500)->nullable()->after('email');
            $table->integer('user_group_id')->nullable()->after('avatar_url');
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['xenforo_id', 'username', 'avatar_url', 'user_group_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
