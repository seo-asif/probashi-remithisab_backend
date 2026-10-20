<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'phone_key']);
            $table->dropColumn('phone_key');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_key', 24)->nullable();
            $table->index(['business_id', 'phone_key']);
        });
    }
};
