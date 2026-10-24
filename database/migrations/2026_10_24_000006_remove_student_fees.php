<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove obsolete Student Fee data from existing installations.
     * Fee-generated demo expenses must not remain as ordinary spending.
     */
    public function up(): void
    {
        if (Schema::hasTable('student_expenses') && Schema::hasColumn('student_expenses', 'student_fee_id')) {
            DB::table('student_expenses')->whereNotNull('student_fee_id')->delete();

            Schema::table('student_expenses', function (Blueprint $table): void {
                $table->dropForeign(['student_fee_id']);
                $table->dropUnique(['student_fee_id']);
                $table->dropColumn('student_fee_id');
            });
        }

        Schema::dropIfExists('student_fees');

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->where('target_type', 'StudentFee')->delete();
        }
    }

    /**
     * Intentionally irreversible: removed demo fee data cannot be recovered.
     */
    public function down(): void
    {
    }
};
