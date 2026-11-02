<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admin_mfa_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_admin_id')->unique()->constrained('platform_admins')->cascadeOnDelete();
            $table->text('secret');
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_mfa_credentials');
    }
};
