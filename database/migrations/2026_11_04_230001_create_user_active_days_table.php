<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_active_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('activity_date'); // UTC day recorded by the server
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'activity_date']);
            $table->index('activity_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_active_days');
    }
};
