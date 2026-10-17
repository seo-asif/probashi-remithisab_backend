<?php

namespace Tests\Support;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;

trait MakesTenants
{
    protected function makeBusiness(array $attributes = []): Business
    {
        return Business::factory()->create($attributes);
    }

    protected function makeMember(Business $business, BusinessRole $role = BusinessRole::Owner, array $membership = []): User
    {
        $user = User::factory()->create();

        BusinessMembership::create([
            'business_id' => $business->id,
            'user_id'     => $user->id,
            'role'        => $role,
            ...$membership,
        ]);

        return $user;
    }
}
