<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The operator's own wallet numbers used to pay customers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sending_numbers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('label', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->string('active_number', 20)->nullable()->storedAs('IF(`deleted_at` IS NULL, `number`, NULL)');
            $table->unique(['business_id', 'active_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sending_numbers');
    }
};
