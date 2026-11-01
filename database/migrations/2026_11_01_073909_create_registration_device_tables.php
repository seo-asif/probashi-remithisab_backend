
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_devices', function (Blueprint $table) {
            $table->id();

            $table->char('device_hash', 64)->unique();

            $table->timestamps();
        });

        Schema::create('device_registrations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registration_device_id')
                ->constrained('registration_devices')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->unique('user_id');

            $table->index([
                'registration_device_id',
                'confirmed_at',
                'reserved_until',
            ], 'device_registration_limit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_registrations');
        Schema::dropIfExists('registration_devices');
    }
};
