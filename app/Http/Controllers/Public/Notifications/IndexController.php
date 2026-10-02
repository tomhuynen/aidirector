<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Notifications;

use App\Http\Resources\Public\NotificationResource;
use App\Models\Director;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The director's latest notifications and how many are unread. With
 * `after`, only the ones that arrived since then: the app polls this while
 * it is open to show new results as toasts, passing back the `now` of the
 * previous answer. Timestamps have whole seconds, so that second is included
 * again and the app skips the ones it already has.
 */
class IndexController
{
    public const LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['after' => ['nullable', 'date']]);

        /** @var Director $director */
        $director = $request->user('director');

        $now = now();
        $notifications = $director->notifications()
            ->when(isset($validated['after']), fn($query) => $query->where('created_at', '>=', Carbon::parse((string) $validated['after'])))
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            /** @var int */
            'unread' => $director->unreadNotifications()->count(),
            /** @var array<int, array{id: string, title: string, url: string, failed: bool, imageUrl: string|null, read: bool, createdAt: string}> */
            'notifications' => NotificationResource::collection($notifications)->resolve($request),
            /** @var string */
            'now' => $now->toIso8601String(),
        ]);
    }
}
