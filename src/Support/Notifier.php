<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\AlfakitWorkflows\Notifications\WorkflowMailNotification;
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bell (and optional mail) notifications, sent once the surrounding transaction commits.
 */
final class Notifier
{
    /**
     * @param  iterable<Model>  $users
     */
    public static function send(iterable $users, Model $record, ?Model $tenant, string $title, ?string $body = null, string $icon = 'workflow', string $status = 'info', ?Model $except = null): void
    {
        $recipients = [];

        foreach ($users as $user) {
            if ($user instanceof Authenticatable && method_exists($user, 'notify') && ! Users::same($user, $except)) {
                $recipients[$user->getMorphClass().'#'.$user->getKey()] = $user;
            }
        }

        if ($recipients === []) {
            return;
        }

        $plugin = WorkflowsPlugin::get();
        $url = $plugin->getRecordUrl($record);
        $database = $plugin->sendsDatabaseNotifications();
        $mail = $plugin->sendsMailNotifications();

        DB::afterCommit(static function () use ($recipients, $tenant, $title, $body, $icon, $status, $url, $database, $mail): void {
            if ($database) {
                $notification = Notification::make()->title($title)->body($body)->icon($icon)->status($status);

                if ($tenant !== null) {
                    $notification->tenant($tenant);
                }

                if ($url !== null) {
                    $notification->actions([
                        NotificationAction::make('open')
                            ->label(__('alfakit-workflows::workflows.notifications.open'))
                            ->url($url)
                            ->markAsRead(),
                    ]);
                }

                $notification->sendToDatabase(array_values($recipients));
            }

            if ($mail) {
                foreach ($recipients as $user) {
                    try {
                        if (method_exists($user, 'routeNotificationFor') && $user->routeNotificationFor('mail') !== null) {
                            $user->notify(new WorkflowMailNotification($title, $body, $url));
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }
        });
    }
}
