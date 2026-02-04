<?php

declare(strict_types=1);

use App\Models\NotificationLogItem;
use Spatie\NotificationLog\Actions\ConvertNotificationSendingEventToLogItemAction;
use Spatie\NotificationLog\NotificationEventSubscriber;

return [
    /*
     * This model will be used to log all sent notifications
     */
    'model' => NotificationLogItem::class,

    /*
     * Log items older than this number of days will be automatically be removed.
     *
     * This feature uses Laravel's native pruning feature:
     * https://laravel.com/docs/10.x/eloquent#pruning-models
     */
    'prune_after_days' => 120,

    /*
     * If this is set to true, any notification that does not have a
     * `shouldLog` method will be logged.
     */
    'log_all_by_default' => false,

    /*
     * By overriding these actions, you can make low level customizations. You can replace
     * these classes by a class of your own that extends the original.
     *
     */
    'actions' => [
        'convertEventToModel' => ConvertNotificationSendingEventToLogItemAction::class,
    ],

    /*
     * The event subscriber that will listen for the notification events fire by Laravel.
     * In most cases, you don't need to touch this. You could replace this by
     * a class of your own that extends the original.
     */
    'event_subscriber' => NotificationEventSubscriber::class,
];
