<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
use Throwable;

class CursorPage
{
    public static function respond(Request $request, Builder $query, string $key, bool $reverse = false): JsonResponse
    {
        $data = validator($request->query(), [
            'perPage' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ])->validate();
        $cursor = null;
        if (isset($data['cursor']) && $data['cursor'] !== '') {
            try {
                $cursor = Cursor::fromEncoded($data['cursor']);
                if ($cursor === null) {
                    throw new \InvalidArgumentException;
                }
                foreach ($query->orders as $order) {
                    if (! is_string($cursor->parameter($order['column'])) && ! is_int($cursor->parameter($order['column']))) {
                        throw new \InvalidArgumentException;
                    }
                }
            } catch (Throwable) {
                throw ValidationException::withMessages(['cursor' => 'Invalid pagination cursor.']);
            }
        }

        $page = $query->cursorPaginate((int) ($data['perPage'] ?? 50), ['*'], 'cursor', $cursor);
        $items = $page->items();

        return response()->json([
            $key => $reverse ? array_reverse($items) : $items,
            'pagination' => [
                'perPage' => $page->perPage(),
                'hasMore' => $page->nextCursor() !== null,
                'nextCursor' => $page->nextCursor()?->encode(),
                'previousCursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }
}
