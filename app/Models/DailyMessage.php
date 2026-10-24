<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class DailyMessage extends Model
{
    protected $fillable = ['code', 'message_bn', 'message_en', 'status', 'sort_order'];

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
