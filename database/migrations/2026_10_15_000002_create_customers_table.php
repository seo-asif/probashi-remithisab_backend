<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Manager mode customers (business-owned). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                          // the record id, made by the phone
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();   // who created it

            $table->string('name', 60);
            $table->string('phone', 24)->nullable();
            $table->string('phone_key', 24)->nullable();             // digits only, leading 880 -> 0
            $table->string('address', 100)->nullable();
            $table->string('notes', 100)->nullable();
            $table->boolean('attention')->default(false);
            $table->json('bkash_numbers')->nullable();
            $table->json('nagad_numbers')->nullable();
            $table->json('rocket_numbers')->nullable();
            $table->json('banks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // only ACTIVE customers must have different numbers
            $table->string('active_phone_key', 24)->nullable()->storedAs('IF(`deleted_at` IS NULL, `phone_key`, NULL)');
            $table->unique(['business_id', 'active_phone_key']);
            $table->index(['business_id', 'phone_key']);
            $table->index(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
