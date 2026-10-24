<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_messages', function (Blueprint $table): void {
            $table->index(['status', 'sort_order', 'id'], 'daily_messages_published_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('daily_messages', function (Blueprint $table): void {
            $table->dropIndex('daily_messages_published_order_idx');
        });
    }
};
