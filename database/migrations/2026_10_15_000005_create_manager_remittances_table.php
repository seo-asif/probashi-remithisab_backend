<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Manager bank remittances (Money Rules 3.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_remittances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('method', 12);                            // Bank | Account | Bkash | Nagad | Rocket | Other
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('rate', 10, 2)->default(0);
            $table->decimal('charge', 18, 2)->default(0);
            $table->string('charge_type', 10)->default('excluded');
            $table->boolean('incentive')->default(false);
            $table->decimal('incentive_pct', 5, 2)->default(0);
            $table->decimal('remit_bdt', 18, 2);                     // calculated (typed for Other)

            $table->timestamp('occurred_at');
            $table->string('timezone', 64);
            $table->string('notes', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_remittances');
    }
};
