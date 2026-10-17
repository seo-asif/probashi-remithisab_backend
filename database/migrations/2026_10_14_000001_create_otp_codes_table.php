<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30); // verify_email | password_reset
            $table->string('code_hash', 64); // HMAC of the code. The code itself is never stored.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at', 6);
            $table->timestamp('consumed_at')->nullable(); // used successfully
            $table->timestamp('invalidated_at')->nullable(); // replaced by a newer code
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'consumed_at', 'invalidated_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
