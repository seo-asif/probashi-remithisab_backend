<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);                    // google
            $table->string('provider_user_id', 191);           // Google "sub"
            $table->string('email')->nullable();               // Google email when it was linked (information only)
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);  // one Google account belongs to one user
            $table->unique(['user_id', 'provider']);           // one Google account per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
