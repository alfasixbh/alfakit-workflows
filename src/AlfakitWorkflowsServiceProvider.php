<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows;

use AlfaSix\Alfakit\Mail\CoreMails;
use AlfaSix\Alfakit\Mail\MailDefinition;
use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\AlfakitWorkflows\Commands\ProcessWorkflowApprovalsCommand;
use AlfaSix\AlfakitWorkflows\Notifications\WorkflowMailNotification;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use Illuminate\Support\ServiceProvider;

class AlfakitWorkflowsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Workflows::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-workflows');

        // The approval email, for the mail preview page and editable templates.
        Mails::register(
            MailDefinition::make(WorkflowMailNotification::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit-workflows::workflows.mail_templates.notification'))
                ->group(static fn (): string => (string) __('alfakit-workflows::workflows.mail_templates.group'))
                ->preview(static fn (): WorkflowMailNotification => new WorkflowMailNotification('Purchase order PO-1042 needs your approval', 'Ada Lovelace submitted it for approval (step 2 of 3).', url('/approvals/preview')))
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['title', 'message', 'button', 'url', 'app']),
                    subject: '{{ title }}',
                    body: static fn (): string => MailDefinition::paragraphs('{{ title }}', '{{ message }}', '{{ button }}'),
                ),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessWorkflowApprovalsCommand::class]);

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alfakit-workflows-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit-workflows'),
            ], 'alfakit-workflows-translations');
        }
    }
}
