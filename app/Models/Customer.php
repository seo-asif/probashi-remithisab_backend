<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Manager customer. Business-owned: every user of the business sees the same customers. */
class Customer extends Model
{
    use BelongsToBusiness, HasPublicUuid, SoftDeletes;

    protected $fillable = ['uuid', 'user_id', 'name', 'phone', 'phone_key', 'address', 'notes', 'attention', 'bkash_numbers', 'nagad_numbers', 'rocket_numbers', 'banks'];

    protected function casts(): array
    {
        return ['attention' => 'boolean', 'bkash_numbers' => 'array', 'nagad_numbers' => 'array', 'rocket_numbers' => 'array', 'banks' => 'array'];
    }

    /** @return list<string> */
    public function walletNumbers(string $method): array
    {
        return array_values(match ($method) {
            'Bkash'  => $this->bkash_numbers ?? [],
            'Nagad'  => $this->nagad_numbers ?? [],
            'Rocket' => $this->rocket_numbers ?? [],
            default  => [],
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ManagerTransaction::class);
    }
}
