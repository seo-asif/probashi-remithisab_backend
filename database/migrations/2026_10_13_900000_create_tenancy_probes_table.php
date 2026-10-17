<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEST ONLY. Lets the tenancy and privacy tests run before the real tables exist.
 * Does nothing unless APP_ENV=testing. Delete this file in Phase 3 and test with Customer
 * (business-owned) and FamilyMember (owner-private) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!app()->environment('testing')) {
            return;
        }

        Schema::create('tenancy_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('owner_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_probes');
        Schema::dropIfExists('tenancy_probes');
    }
};
