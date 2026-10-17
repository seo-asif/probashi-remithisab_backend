<?php

namespace Tests\Support;

use App\Support\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/** Test-only business-owned model (table created by the testing-only migration). */
class TenancyProbe extends Model
{
    use BelongsToBusiness;

    protected $table = 'tenancy_probes';

    protected $fillable = ['business_id', 'label'];
}
