<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_app_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('language', 2)->nullable();
            $table->string('mode', 12)->nullable();
            $table->string('default_currency', 3)->nullable();
            $table->boolean('is_student')->nullable();
            $table->boolean('intro_seen')->default(false);
            $table->json('features')->nullable();
            $table->json('reminders')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_app_settings');
    }
};
