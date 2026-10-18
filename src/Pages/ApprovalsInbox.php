<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitWorkflows\Actions\WorkflowActions;
use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\Context;
use AlfaSix\AlfakitWorkflows\Support\Records;
use AlfaSix\AlfakitWorkflows\Support\Users;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything waiting for the signed-in user's decision, in the current tenant: open
 * the record, approve, reject or delegate each item, or approve several at once (steps
 * that need an approval comment, or are not `bulkApprovable()`, can't be selected).
 */
class ApprovalsInbox extends Page
{
    protected static ?string $slug = 'approvals';

    protected static bool $isDiscovered = false;

    /** @var array<int, Model|null> */
    protected array $subjects = [];

    public static function canAccess(): bool
    {
        return Context::user() !== null;
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-workflows::workflows.inbox.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('alfakit-workflows::workflows.inbox.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-workflows::workflows.inbox.navigation');
    }

    public static function getNavigationIcon(): ?string
    {
        return WorkflowsPlugin::get()->getNavigationIcon();
    }

    public static function getNavigationGroup(): ?string
    {
        return WorkflowsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return WorkflowsPlugin::get()->getNavigationSort();
    }

    public static function getNavigationBadge(): string|int|null
    {
        if (! WorkflowsPlugin::get()->hasNavigationBadge()) {
            return null;
        }

        $count = Approvals::inboxQuery(Context::user())->count();

        return $count > 0 ? $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return (string) __('alfakit-workflows::workflows.inbox.badge_tooltip');
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->subjects = [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static fn (): Builder => Approvals::inboxQuery(Context::user())->with(['request', 'approver']))
            ->columns([
                TextColumn::make('subject')
                    ->label(__('alfakit-workflows::workflows.inbox.columns.record'))
                    ->getStateUsing(fn (Approval $record): string => ($subject = $this->subject($record)) === null ? '—' : Records::title($subject))
                    ->description(fn (Approval $record): ?string => $this->transitionLabel($record))
                    ->weight('medium'),
                TextColumn::make('step_name')
                    ->label(__('alfakit-workflows::workflows.inbox.columns.step'))
                    ->formatStateUsing(fn (Approval $record): string => $this->step($record)?->getLabel() ?? $record->step_name)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('requester')
                    ->label(__('alfakit-workflows::workflows.inbox.columns.requester'))
                    ->getStateUsing(static fn (Approval $record): string => Users::name(Users::find($record->request?->requester_type, $record->request?->requester_id)))
                    ->description(static fn (Approval $record): ?string => $record->request?->comment === null ? null : '“'.$record->request->comment.'”'),
                TextColumn::make('created_at')
                    ->label(__('alfakit-workflows::workflows.inbox.columns.requested'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('due_at')
                    ->label(__('alfakit-workflows::workflows.inbox.columns.due'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(__('alfakit-workflows::workflows.inbox.no_due_date'))
                    ->color(static fn (Approval $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->icon(static fn (Approval $record): ?string => $record->isOverdue() ? 'clock-alert' : null)
                    ->sortable(),
            ])
            ->filters([
                Filter::make('overdue')
                    ->label(__('alfakit-workflows::workflows.inbox.filters.overdue'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->whereNotNull('due_at')->where('due_at', '<', now())),
            ])
            ->recordUrl(fn (Approval $record): ?string => ($subject = $this->subject($record)) === null ? null : WorkflowsPlugin::get()->getRecordUrl($subject))
            ->recordActions([
                $this->approveAction(),
                $this->rejectAction(),
                $this->delegateAction(),
            ])
            ->bulkActions([$this->bulkApproveAction()])
            ->checkIfRecordIsSelectableUsing(fn (Approval $record): bool => (bool) $this->step($record)?->isBulkApprovable())
            ->defaultSort('created_at', 'asc')
            ->emptyStateIcon('inbox')
            ->emptyStateHeading(__('alfakit-workflows::workflows.inbox.empty_heading'))
            ->emptyStateDescription(__('alfakit-workflows::workflows.inbox.empty_description'));
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('alfakit-workflows::workflows.actions.approve.label'))
            ->icon('check')
            ->color('success')
            ->modalHeading(__('alfakit-workflows::workflows.actions.approve.heading'))
            ->modalDescription(fn (Approval $record): ?string => $this->summary($record))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.approve.submit'))
            ->form(fn (Approval $record): array => [WorkflowActions::commentField((bool) $this->step($record)?->isApprovalCommentRequired())])
            ->action(function (Approval $record, array $data): void {
                $this->attempt(static fn (): Approval => Approvals::approve($record, Context::user(), self::comment($data)));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.approve.done'))->send();
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('alfakit-workflows::workflows.actions.reject.label'))
            ->icon('x')
            ->color('danger')
            ->modalHeading(__('alfakit-workflows::workflows.actions.reject.heading'))
            ->modalDescription(fn (Approval $record): ?string => $this->summary($record))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.reject.submit'))
            ->form(fn (Approval $record): array => [WorkflowActions::commentField($this->step($record)?->isRejectionCommentRequired() ?? true)])
            ->action(function (Approval $record, array $data): void {
                $this->attempt(static fn (): Approval => Approvals::reject($record, Context::user(), self::comment($data)));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.reject.done'))->send();
            });
    }

    protected function delegateAction(): Action
    {
        return Action::make('delegate')
            ->label(__('alfakit-workflows::workflows.actions.delegate.label'))
            ->icon('forward')
            ->color('gray')
            ->visible(fn (Approval $record): bool => (bool) $this->step($record)?->isDelegable())
            ->modalHeading(__('alfakit-workflows::workflows.actions.delegate.heading'))
            ->modalDescription(__('alfakit-workflows::workflows.actions.delegate.description'))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.delegate.submit'))
            ->form(fn (Approval $record): array => [
                Select::make('approver')
                    ->label(__('alfakit-workflows::workflows.fields.approver'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search): array => Users::search((string) $search, $this->tenantOf($record), $this->excludedDelegates($record)))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_scalar($value) ? Users::name(Users::findCandidate($value, $this->tenantOf($record))) : null)
                    ->required(),
                WorkflowActions::commentField(false),
            ])
            ->action(function (Approval $record, array $data): void {
                $to = $data['approver'] ?? null;
                abort_unless(is_scalar($to), 422);

                $this->attempt(static fn (): Approval => Approvals::delegate($record, $to, Context::user(), self::comment($data)));

                Notification::make()->success()->title(__('alfakit-workflows::workflows.actions.delegate.done'))->send();
            });
    }

    protected function bulkApproveAction(): BulkAction
    {
        return BulkAction::make('approveSelected')
            ->label(__('alfakit-workflows::workflows.inbox.bulk_approve.label'))
            ->icon('check-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('alfakit-workflows::workflows.inbox.bulk_approve.heading'))
            ->modalDescription(__('alfakit-workflows::workflows.inbox.bulk_approve.description'))
            ->modalSubmitActionLabel(__('alfakit-workflows::workflows.actions.approve.submit'))
            ->form([WorkflowActions::commentField(false)])
            ->action(function (Collection $records, array $data): void {
                $approved = 0;
                $skipped = 0;
                $user = Context::user();

                foreach ($records as $record) {
                    if (! $record instanceof Approval || ! $this->step($record)?->isBulkApprovable()) {
                        $skipped++;

                        continue;
                    }

                    try {
                        Approvals::approve($record, $user, self::comment($data));
                        $approved++;
                    } catch (TransitionDenied) {
                        $skipped++;
                    }
                }

                Notification::make()
                    ->status($skipped === 0 ? 'success' : 'warning')
                    ->title(trans_choice('alfakit-workflows::workflows.inbox.bulk_approve.done', $approved, ['count' => (string) $approved]))
                    ->body($skipped === 0 ? null : trans_choice('alfakit-workflows::workflows.inbox.bulk_approve.skipped', $skipped, ['count' => (string) $skipped]))
                    ->send();
            });
    }

    // ---------------------------------------------------------------- Helpers

    protected function subject(Approval $approval): ?Model
    {
        $request = $approval->request;

        if ($request === null) {
            return null;
        }

        if (! array_key_exists($request->id, $this->subjects)) {
            $this->subjects[$request->id] = Records::find($request->subject_type, $request->subject_id);
        }

        return $this->subjects[$request->id];
    }

    protected function step(Approval $approval): ?ApprovalStep
    {
        $subject = $this->subject($approval);
        $request = $approval->request;

        if ($subject === null || $request === null || ! Workflows::uses($subject)) {
            return null;
        }

        return Workflows::for($subject)->getTransition($request->transition)?->getApprovalStep($approval->step);
    }

    protected function transitionLabel(Approval $approval): ?string
    {
        $subject = $this->subject($approval);
        $request = $approval->request;

        if ($subject === null || $request === null || ! Workflows::uses($subject)) {
            return null;
        }

        $workflow = Workflows::for($subject);

        return $workflow->getLabel().' · '.($workflow->getTransition($request->transition)?->getLabel() ?? $request->transition);
    }

    protected function summary(Approval $approval): ?string
    {
        $request = $approval->request;

        if (! $request instanceof ApprovalRequest) {
            return null;
        }

        $summary = (string) __('alfakit-workflows::workflows.actions.approve.summary', [
            'name' => Users::name(Users::find($request->requester_type, $request->requester_id)),
            'transition' => (string) ($this->transitionLabel($approval) ?? $request->transition),
            'step' => (string) ($this->step($approval)?->getLabel() ?? $approval->step_name),
        ]);

        return $request->comment === null ? $summary : $summary.' “'.e($request->comment).'”';
    }

    protected function tenantOf(Approval $approval): ?Model
    {
        $request = $approval->request;

        return $request === null ? Context::tenant() : Context::findTenant($request->tenant_type, $request->tenant_id);
    }

    /**
     * @return array<int, string>
     */
    protected function excludedDelegates(Approval $approval): array
    {
        $request = $approval->request;
        $keys = [$approval->approver_id];

        foreach ($request?->approvals()->pending()->where('step', $approval->step)->get() ?? [] as $open) {
            $keys[] = $open->approver_id;
        }

        $subject = $this->subject($approval);

        if ($request?->requester_id !== null && $subject !== null && Workflows::uses($subject) && ! Workflows::for($subject)->allowsSelfApproval()) {
            $keys[] = $request->requester_id;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function comment(array $data): ?string
    {
        $comment = $data[WorkflowActions::COMMENT_FIELD] ?? null;

        return is_string($comment) ? $comment : null;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function attempt(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (TransitionDenied $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }
}
