<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['business_id', 'owner_user_id']);
        });

        Schema::create('student_opening_balances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'owner_user_id', 'currency'], 'student_opening_balances_owner_currency_unique');
        });

        Schema::create('student_money_in', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_recipient_id')->nullable()->constrained('sender_recipients')->nullOnDelete();
            $table->string('from_name', 60);
            $table->decimal('amount_bdt', 18, 2);
            $table->decimal('rate_bdt_per_currency', 12, 2)->nullable();
            $table->string('estimate_currency', 3)->nullable();
            $table->string('method', 12);
            $table->date('received_on');
            $table->string('note', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'owner_user_id', 'received_on'], 'student_money_in_owner_received_date_index');
        });

        Schema::create('student_expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 24);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->date('spent_on');
            $table->string('note', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'owner_user_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_expenses');
        Schema::dropIfExists('student_money_in');
        Schema::dropIfExists('student_opening_balances');
        Schema::dropIfExists('student_settings');
    }
};
