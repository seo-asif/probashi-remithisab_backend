<?php

use App\Models\WorkSetting;
use App\Support\Money\Dec;
use App\Support\Work\WorkCalculator as W;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('hourly_rate', 18, 2)->nullable();
            $table->string('currency', 3);
            $table->date('effective_from')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'owner_user_id', 'effective_from']);
        });
        Schema::table('salaries', function (Blueprint $table) {
            $table->string('currency', 3)->nullable();
            $table->decimal('period_pay', 18, 2)->nullable();
            $table->boolean('rate_configured')->nullable();
        });
        Schema::table('work_bonuses', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
        Schema::table('work_days', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
        Schema::table('loan_entries', fn (Blueprint $table) => $table->string('currency', 3)->nullable());

        $settings = [];
        foreach (DB::table('work_settings')->get() as $s) {
            $settings[$s->business_id . ':' . $s->owner_user_id] = $s;
            $currency = $s->currency ?: DB::table('businesses')->where('id', $s->business_id)->value('default_currency') ?: 'FJD';
            if ($s->hourly_rate !== null && bccomp(Dec::of($s->hourly_rate), '0', 2) > 0) {
                DB::table('worker_rates')->insert([
                    'business_id' => $s->business_id, 'owner_user_id' => $s->owner_user_id,
                    'hourly_rate' => $s->hourly_rate, 'currency' => $currency,
                    'effective_from' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (['work_days', 'work_bonuses', 'loan_entries'] as $table) {
            foreach (DB::table($table)->get(['id', 'business_id', 'owner_user_id']) as $row) {
                $s = $settings[$row->business_id . ':' . $row->owner_user_id] ?? null;
                $currency = $s?->currency ?: DB::table('businesses')->where('id', $row->business_id)->value('default_currency') ?: 'FJD';
                DB::table($table)->where('id', $row->id)->update(['currency' => $currency]);
            }
        }

        // Legacy records had no rate history; freeze them using the only known settings.
        foreach (DB::table('salaries')->get() as $salary) {
            $s = $settings[$salary->business_id . ':' . $salary->owner_user_id] ?? null;
            $currency = $s?->currency ?: DB::table('businesses')->where('id', $salary->business_id)->value('default_currency') ?: 'FJD';
            $setting = $s ? new WorkSetting((array) $s) : null;
            $total = '0.00';
            foreach (DB::table('work_days')->where('business_id', $salary->business_id)
                ->where('owner_user_id', $salary->owner_user_id)
                ->whereBetween('work_date', [$salary->from_date, $salary->to_date])->get() as $day) {
                $calc = W::day($day->kind, $day->hours, $day->amount, $setting);
                $total = bcadd($total, $calc['day_pay'], 2);
            }
            foreach (DB::table('work_bonuses')->where('business_id', $salary->business_id)
                ->where('owner_user_id', $salary->owner_user_id)
                ->whereBetween('bonus_date', [$salary->from_date, $salary->to_date])->get() as $bonus) {
                $total = bcadd($total, Dec::of($bonus->amount), 2);
            }
            DB::table('salaries')->where('id', $salary->id)->update([
                'currency' => $currency, 'period_pay' => $total, 'rate_configured' => W::hasRate($setting),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('worker_rates')->whereNotNull('effective_from')->exists()) {
            throw new RuntimeException('Cannot remove dated pay history without data loss.');
        }
        Schema::table('salaries', fn (Blueprint $table) => $table->dropColumn(['currency', 'period_pay', 'rate_configured']));
        Schema::table('work_days', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('loan_entries', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('work_bonuses', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::dropIfExists('worker_rates');
    }
};
