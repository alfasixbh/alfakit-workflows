# AlfaKit Workflows

<p><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-white.svg"><img src="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

> This is a read-only split of the [AlfaKit monorepo](https://github.com/alfasixbh/alfakit/tree/main/packages/plugins/workflows). Please open issues and pull requests there.

State machines and approval workflows for any model of an [AlfaKit](https://alfakit.dev) panel: guarded, policy-checked transitions with forms and comments; single, any-of, all-of and multi-level approvals with due dates, reminders, delegation, reassignment and escalation; an approvals inbox with bulk approve; a history timeline; and an accessible workflow diagram drawn on the server. It renders in React, Vue and Svelte with AlfaKit's built-in components.

## Installation

```bash
composer require alfasix/alfakit-workflows
php artisan vendor:publish --tag=alfakit-workflows-migrations
php artisan migrate
```

## Register

```php
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;

$panel->plugin(WorkflowsPlugin::make()->navigationGroup('Work'));
```

## Define a workflow

```php
use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Concerns\HasWorkflow;
use AlfaSix\AlfakitWorkflows\State;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;

class Invoice extends Model
{
    use HasWorkflow;

    public static function workflow(): Workflow
    {
        return Workflow::make('invoice')
            ->states([
                State::make('draft')->color('gray'),
                State::make('submitted')->color('warning'),
                State::make('approved')->color('success'),
                State::make('rejected')->color('danger'),
                State::make('paid')->color('primary')->final(),
            ])
            ->transitions([
                Transition::make('submit')->from('draft')->to('approved')
                    ->requiresApproval([ApprovalStep::make('manager')->role('manager')->dueIn('2 days')])
                    ->pendingState('submitted')
                    ->rejectedState('rejected'),
                Transition::make('pay')->from('approved')->to('paid')->requiresComment(),
            ])
            ->initial('draft');
    }
}
```

## Use it

```php
// Resource view page
protected function getHeaderActions(): array
{
    return [WorkflowActions::make()->dropdown(false)];
}

// Infolist
WorkflowStateEntry::make(),
WorkflowDiagram::make(),
WorkflowTimeline::make(),

// Table
WorkflowStateColumn::make(),
WorkflowStateFilter::make(),
->recordActions([WorkflowActions::make()])
```

Schedule reminders and escalation:

```php
Schedule::command('alfakit-workflows:process')->everyFifteenMinutes();
```

Read the full documentation at [alfakit.dev/docs/plugins/workflows](https://alfakit.dev/docs/plugins/workflows).

## License

MIT. See [LICENSE.md](LICENSE.md).
