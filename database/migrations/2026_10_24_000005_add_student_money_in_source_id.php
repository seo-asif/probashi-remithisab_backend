<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve legacy "fromId" tokens (__fam__, __father__, __mother__, __sib__)
 * and the new __other__ choice without placing non-numeric values in the FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_money_in', function (Blueprint $table): void {
            $table->string('from_source_id', 64)->nullable()->after('from_recipient_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_money_in', function (Blueprint $table): void {
            $table->dropColumn('from_source_id');
        });
    }
};
