<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Manager bank remittance. remit_bdt is computed by the server (typed only for "Other"). */
class ManagerRemittance extends Model
{
    use BelongsToBusiness, HasPublicUuid, SoftDeletes;

    protected $fillable = ['uuid', 'user_id', 'method', 'currency', 'amount', 'rate', 'charge', 'charge_type', 'incentive', 'incentive_pct', 'remit_bdt', 'occurred_at', 'timezone', 'notes'];

    protected function casts(): array
    {
        return ['incentive' => 'boolean', 'occurred_at' => 'immutable_datetime'];
    }
}
