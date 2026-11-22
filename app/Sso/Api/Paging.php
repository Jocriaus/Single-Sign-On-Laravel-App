<?php

namespace App\Sso\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** page (1-based) and size (default 20, at most 100) in, items/page/size/total out. */
final class Paging
{
    public const DEFAULT_SIZE = 20;
    public const MAX_SIZE = 100;

    /** @return array{items: list<mixed>, page: int, size: int, total: int} */
    public static function of(Builder $query, Request $request, callable $present): array
    {
        $page = self::number($request->query('page'), 1, PHP_INT_MAX >> 16);
        $size = self::number($request->query('size'), self::DEFAULT_SIZE, self::MAX_SIZE);

        $total = (clone $query)->toBase()->getCountForPagination();
        $items = $query->forPage($page, $size)->get()->map($present)->values()->all();

        return ['items' => $items, 'page' => $page, 'size' => $size, 'total' => $total];
    }

    private static function number(mixed $value, int $default, int $max): int
    {
        return is_string($value) && ctype_digit($value) && (int) $value >= 1 ? min((int) $value, $max) : $default;
    }
}
