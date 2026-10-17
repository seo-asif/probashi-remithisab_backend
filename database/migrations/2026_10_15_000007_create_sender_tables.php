<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sender mode. Every table is PRIVATE to its user: business_id AND owner_user_id. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('relationship', 30)->nullable();
            $table->string('phone', 24)->nullable();
            $table->string('phone_normalized', 24)->nullable();
            $table->string('notes', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'owner_user_id', 'name']);
            $table->index(['business_id', 'owner_user_id', 'phone_normalized'], 'sender_recipients_owner_phone_index');
        });

        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 30);
            $table->decimal('target_bdt', 18, 2);
            $table->date('target_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'owner_user_id']);
        });

        Schema::create('sender_remittances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('recipient_type', 10); // family | self
            $table->foreignId('recipient_id')->nullable()->constrained('sender_recipients')->restrictOnDelete();
            $table->string('recipient_name', 60); // snapshot; "Myself" for self

            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('rate', 10, 2);
            $table->decimal('charge', 18, 2)->default(0);
            $table->string('charge_type', 10);
            $table->decimal('incentive_pct', 5, 2)->default(0);
            $table->string('method', 10); // bKash | Nagad | Rocket | Bank | Other

            $table->decimal('principal', 18, 2); // calculated by the server
            $table->decimal('incentive_bdt', 18, 2); // breakdown only
            $table->decimal('family_bdt', 18, 2); // the one rounded result
            $table->decimal('total_cost', 18, 2);

            $table->boolean('received')->default(false); // family confirmed they got it
            $table->timestamp('received_at')->nullable();
            $table->string('purpose', 12)->nullable();
            $table->foreignId('goal_id')->nullable()->constrained('savings_goals')->nullOnDelete();
            $table->string('notes', 100)->nullable();

            $table->dateTime('occurred_at');
            $table->string('timezone', 64);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'owner_user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_remittances');
        Schema::dropIfExists('savings_goals');
        Schema::dropIfExists('sender_recipients');
    }
};
