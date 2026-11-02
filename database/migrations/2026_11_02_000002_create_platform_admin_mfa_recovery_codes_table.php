<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admin_mfa_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamps();
            $table->index('platform_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_mfa_recovery_codes');
    }
};
