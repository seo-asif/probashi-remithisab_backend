<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);                          // owner | administrator | operator | read_only
            $table->string('status', 20)->default('active');     // active | suspended
            // Holds the business id only on the owner row, so a business can have at most one owner
            $table->unsignedBigInteger('owner_key')->nullable()->virtualAs("CASE WHEN role = 'owner' THEN business_id END");
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->unique('owner_key');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_memberships');
    }
};
