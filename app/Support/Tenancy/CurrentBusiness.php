<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\BusinessMembership;

/**
 * Holds "the business and the user this code is acting for". It is set from the logged-in user's
 * membership (never from request input), or explicitly by jobs and commands.
 *
 *  - business-owned models (customers, ...) are limited by business only
 *  - owner-private models (Sender family members and remittances) are limited by business AND user
 */
final class CurrentBusiness
{
    private ?Business $business = null;

    private ?BusinessMembership $membership = null;

    private ?int $userId = null;

    private bool $unscoped = false;

    public function set(Business $business, ?BusinessMembership $membership = null): void
    {
        $this->business   = $business;
        $this->membership = $membership;
        $this->userId     = $membership ? (int) $membership->user_id : null;
    }

    public function clear(): void
    {
        $this->business   = null;
        $this->membership = null;
        $this->userId     = null;
    }

    public function get(): ?Business
    {
        return $this->business;
    }

    public function id(): ?int
    {
        return $this->business?->getKey();
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function membership(): ?BusinessMembership
    {
        return $this->membership;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /**
     * Jobs and commands that work for a business as a whole.
     * Owner-private records stay invisible here, because no user is named.
     */
    public function runAs(Business $business, callable $callback): mixed
    {
        return $this->runWith($business, null, false, $callback);
    }

    /** Jobs and commands that work on behalf of ONE user (needed for private Sender records). */
    public function runAsUser(Business $business, int $userId, callable $callback): mixed
    {
        return $this->runWith($business, $userId, false, $callback);
    }

    /** Platform or system work that must see every business. Use sparingly. Bypasses both scopes. */
    public function runUnscoped(callable $callback): mixed
    {
        return $this->runWith($this->business, $this->userId, true, $callback);
    }

    private function runWith(?Business $business, ?int $userId, bool $unscoped, callable $callback): mixed
    {
        $previous = [$this->business, $this->membership, $this->userId, $this->unscoped];

        $this->business   = $business;
        $this->membership = null;
        $this->userId     = $userId;
        $this->unscoped   = $unscoped;

        try {
            return $callback();
        } finally {
            [$this->business, $this->membership, $this->userId, $this->unscoped] = $previous;
        }
    }
}
