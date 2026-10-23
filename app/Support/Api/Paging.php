<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Standard list shape: { items: [...], meta: { page, per_page, total, last_page } }. */
final class Paging
{
    public const PER_PAGE_DEFAULT = 10;
    public const PER_PAGE_MAX     = 100;

    public static function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        // New clients use ?limit=10; older clients may continue to use ?per_page=.
        // If both are supplied, per_page wins for backward compatibility.
        $perPage = (int) $request->query('per_page', $request->query('limit', self::PER_PAGE_DEFAULT));
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));
        $page    = max(1, (int) $request->query('page', 1));

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /** @param callable(mixed): array $transform */
    public static function shape(LengthAwarePaginator $page, callable $transform): array
    {
        return [
            'items' => array_map($transform, $page->items()),
            'meta'  => [
                'page'      => $page->currentPage(),
                'per_page'  => $page->perPage(),
                'total'     => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /** Escapes a search text for LIKE. */
    public static function like(string $text): string
    {
        return '%' . addcslashes(trim($text), '%_\\') . '%';
    }
}
