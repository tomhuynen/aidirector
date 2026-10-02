<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Notifications;

use App\Models\Director;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marks notifications as read: the ones given, or all of them.
 */
class ReadController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array', 'max:100'],
            'ids.*' => ['string', 'uuid'],
        ]);

        /** @var Director $director */
        $director = $request->user('director');

        $director->unreadNotifications()
            ->when(isset($validated['ids']), fn($query) => $query->whereKey($validated['ids']))
            ->update(['read_at' => now()]);

        return response()->json([
            /** @var int */
            'unread' => $director->unreadNotifications()->count(),
        ]);
    }
}
