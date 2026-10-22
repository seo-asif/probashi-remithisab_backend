<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable during initial insert; User's created hook assigns the number.
        // A unique database index prevents duplicate public support identifiers.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('member_no')->nullable()->unique();
        });

        // Backfill every pre-existing account without altering its primary key.
        DB::table('users')->select('id')->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->id)
                        ->whereNull('member_no')
                        ->update(['member_no' => 10000 + (int) $row->id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_member_no_unique');
            $table->dropColumn('member_no');
        });
    }
};
