<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Worker tools (Money Rules 4). PRIVATE to each user, like all Sender records. Dates are local dates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('hourly_rate', 18, 2)->nullable();       // empty = only count hours
            $table->string('currency', 3)->nullable();
            $table->decimal('day_hours', 5, 2)->default(8);
            $table->decimal('ot_multiplier', 4, 2)->default(1.50);
            $table->timestamps();

            $table->unique(['business_id', 'owner_user_id']);
        });

        Schema::create('work_days', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('kind', 10);                              // work | holiday | sick | off
            $table->decimal('hours', 5, 2)->default(0);              // work days: all hours of the day
            $table->decimal('amount', 18, 2)->nullable();            // holiday / sick: the pay he types (0 allowed)
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'owner_user_id', 'work_date']);   // one entry per day
        });

        Schema::create('work_bonuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('bonus_date');
            $table->decimal('amount', 18, 2);
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'owner_user_id', 'bonus_date']);
        });

        Schema::create('salaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('amount_received', 18, 2)->nullable();
            $table->date('received_on')->nullable();
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'owner_user_id', 'from_date']);
        });

        Schema::create('loan_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 6);                               // take | cut | repay
            $table->decimal('amount', 18, 2);                        // always positive; the type gives the sign
            $table->date('entry_date');
            $table->foreignId('salary_id')->nullable()->constrained('salaries')->cascadeOnDelete();
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'owner_user_id', 'entry_date']);
        });

        Schema::create('spending_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('spent_on');
            $table->string('category', 10);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'owner_user_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        foreach (['spending_entries', 'loan_entries', 'salaries', 'work_bonuses', 'work_days', 'work_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
