<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Archive is independent of soft deletion and does not release a lifetime plan slot. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->index(['business_id', 'archived']);
        });

        Schema::table('sender_recipients', function (Blueprint $table): void {
            $table->boolean('archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->index(['business_id', 'owner_user_id', 'archived'], 'sender_recipients_owner_archive_index');
        });
    }

    public function down(): void
    {
        Schema::table('sender_recipients', function (Blueprint $table): void {
            $table->dropIndex('sender_recipients_owner_archive_index');
            $table->dropColumn(['archived', 'archived_at']);
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'archived']);
            $table->dropColumn(['archived', 'archived_at']);
        });
    }
};
