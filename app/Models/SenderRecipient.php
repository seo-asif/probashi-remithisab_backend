<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Sender family member / recipient. Private to the user who created it (no role exception). */
class SenderRecipient extends Model
{
    use BelongsToOwner, HasPublicUuid, SoftDeletes;

    protected $fillable = ['uuid', 'name', 'relationship', 'phone', 'phone_normalized', 'notes', 'archived', 'archived_at'];

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'archived_at' => 'datetime'];
    }
}
