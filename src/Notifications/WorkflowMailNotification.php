<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Notifications;

use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Support\Credit;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email twin of a workflow bell notification, sent with `WorkflowsPlugin::mailNotifications()`,
 * in the AlfaKit mail theme and editable (`workflows.notification`).
 */
class WorkflowMailNotification extends Notification
{
    use UsesEditableTemplate;

    public const TEMPLATE = 'workflows.notification';

    public function __construct(
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $url = null,
        public readonly ?string $actionLabel = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): BaseMailMessage
    {
        $message = (new MailMessage)->subject($this->title)->line($this->title);

        if ($this->body !== null && $this->body !== '') {
            $message->line($this->body);
        }

        if ($this->url !== null) {
            $message->action($this->getActionLabel(), $this->url);
        }

        return $this->templateMailMessage(Credit::addToMail($message), Credit::emailLine());
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        return [
            'title' => $this->title,
            'message' => (string) $this->body,
            'url' => (string) $this->url,
            'button' => $this->url === null ? '' : EditableTemplates::button($this->url, $this->getActionLabel()),
            'app' => MailTheme::brand()->name,
        ];
    }

    protected function getActionLabel(): string
    {
        return $this->actionLabel ?? (string) __('alfakit-workflows::workflows.notifications.open');
    }
}
