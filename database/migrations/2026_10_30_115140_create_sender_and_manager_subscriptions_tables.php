
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('tier', 20)->default('free');
            $table->string('access_source', 20)->nullable();

            $table->timestamp('access_starts_at')->nullable();
            $table->timestamp('access_ends_at')->nullable();

            $table->timestamps();

            $table->unique('user_id');
            $table->index(['tier', 'access_ends_at']);
        });

        Schema::create('manager_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('tier', 20)->default('free');
            $table->string('access_source', 20)->nullable();

            $table->timestamp('access_starts_at')->nullable();
            $table->timestamp('access_ends_at')->nullable();

            $table->timestamps();

            $table->unique('business_id');
            $table->index(['tier', 'access_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_subscriptions');
        Schema::dropIfExists('sender_subscriptions');
    }
};
