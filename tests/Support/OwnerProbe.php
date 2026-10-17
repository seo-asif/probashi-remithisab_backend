<?php

namespace Tests\Support;

use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** Test-only owner-private model (stands in for FamilyMember and SenderRemittance). */
class OwnerProbe extends Model
{
    use BelongsToOwner;

    protected $table = 'owner_probes';

    protected $fillable = ['business_id', 'owner_user_id', 'label'];
}
