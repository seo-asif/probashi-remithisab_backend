<?php

namespace App\Http\Resources\Sender;

use App\Support\Money\Dec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SavingsGoal  — pass the progress from SenderService::goalProgress() as additional data. */
class SavingsGoalResource extends JsonResource
{
    public function __construct($resource, private array $progress = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->uuid,
            'name'        => $this->name,
            'target_bdt'  => Dec::money($this->target_bdt),
            'target_date' => $this->target_date?->format('Y-m-d'),
            ...$this->progress,
        ];
    }
}
