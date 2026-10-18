<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Events\ApprovalDelegated;
use AlfaSix\AlfakitWorkflows\Events\ApprovalGranted;
use AlfaSix\AlfakitWorkflows\Events\ApprovalRejected;
use AlfaSix\AlfakitWorkflows\Events\ApprovalRequested;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Approval requests: opening steps, approving, rejecting, delegating, reassigning,
 * withdrawing, reminding and escalating. Every decision locks the request, the approval
 * and the record, and re-checks that it is still pending.
 */
final class Approvals
{
    private const MEMO = 'alfakit-workflows.memo';

    /**
     * The record's pending request. Remembered for the rest of the HTTP request (a table
     * asks once per row and action), forgotten whenever a request or approval changes;
     * `$fresh` always reads the database (the integrity checks do).
     */
    public static function pendingFor(Model $record, bool $fresh = false): ?ApprovalRequest
    {
        $key = 'pending.'.$record->getMorphClass().'#'.$record->getKey();

        return self::remember($key, $fresh, static fn (): ?ApprovalRequest => ApprovalRequest::query()->pending()->forSubject($record)->latest('id')->first());
    }

    /**
     * The user's open approval on the request's current step.
     */
    public static function assignmentFor(ApprovalRequest $request, ?Model $user): ?Approval
    {
        if ($user === null || ! $request->isPending()) {
            return null;
        }

        $key = 'assignment.'.$request->getKey().'.'.$request->current_step.'.'.$user->getMorphClass().'#'.$user->getKey();

        return self::remember($key, false, static fn (): ?Approval => $request->approvals()->pending()->assignedTo($user)->where('step', $request->current_step)->first());
    }

    /**
     * Run a decision in a transaction and forget what was remembered, whatever happens.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private static function write(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } finally {
            self::forget();
        }
    }

    /**
     * Forget what `pendingFor()` and `assignmentFor()` remembered.
     */
    public static function forget(): void
    {
        self::memo()?->remove(self::MEMO);
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private static function remember(string $key, bool $fresh, Closure $callback): mixed
    {
        $memo = self::memo();

        if ($memo === null || $fresh) {
            return $callback();
        }

        $values = $memo->get(self::MEMO, []);
        $values = is_array($values) ? $values : [];

        if (! array_key_exists($key, $values)) {
            $values[$key] = $callback();
            $memo->set(self::MEMO, $values);
        }

        return $values[$key];
    }

    /**
     * The HTTP request's attributes: queue workers and commands keep one request for every
     * job, so nothing is remembered there.
     */
    private static function memo(): ?\Symfony\Component\HttpFoundation\ParameterBag
    {
        if ((app()->runningInConsole() && ! app()->runningUnitTests()) || ! app()->bound('request')) {
            return null;
        }

        return request()->attributes;
    }

    /**
     * The open approvals waiting for the user, in the current tenant.
     *
     * @return Builder<Approval>
     */
    public static function inboxQuery(?Model $user): Builder
    {
        $query = Approval::query();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->pending()
            ->assignedTo($user)
            ->whereHas('request', static function (Builder $query): void {
                $query->where('status', ApprovalRequest::PENDING);
                Context::scopeToTenant($query);
            });
    }

    // ---------------------------------------------------------------- Opening

    /**
     * Open a request for a transition that needs approval (called by `Transitions::apply()`
     * inside its transaction).
     *
     * @param  array<string, mixed>  $data
     */
    public static function open(Model $locked, Workflow $workflow, Transition $transition, ?string $from, ?Model $requester, array $data, ?string $comment): ApprovalRequest
    {
        $tenant = Context::tenantFor($locked);

        $request = new ApprovalRequest;
        $request->forceFill([
            'workflow' => $workflow->getName(),
            'transition' => $transition->getName(),
            'subject_type' => $locked->getMorphClass(),
            'subject_id' => (string) $locked->getKey(),
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant === null ? null : (string) $tenant->getKey(),
            'panel' => Context::panel()?->getId(),
            'requester_type' => $requester?->getMorphClass(),
            'requester_id' => $requester === null ? null : (string) $requester->getKey(),
            'status' => ApprovalRequest::PENDING,
            'current_step' => 0,
            'from_state' => $from,
            'data' => History::data($data),
            'comment' => $comment,
        ]);
        $request->save();
        $request->setRelation('subject', $locked);
        self::forget();

        $pending = $transition->getPendingState();

        if ($pending !== null && $pending !== $from) {
            $locked->setAttribute($workflow->getStateColumn(), $pending);
            Workflows::applying(static fn (): bool => $locked->save());
        }

        History::log($workflow, $locked, WorkflowLog::REQUESTED, $requester, [
            'transition' => $transition->getName(),
            'from_state' => $from,
            'to_state' => $pending,
            'comment' => $comment,
            'data' => $data,
        ], $request);

        self::startStep($request, $locked, $workflow, $transition, 0, $requester, $tenant, $requester);

        return $request;
    }

    /**
     * Open the first step from `$index` that applies to the record; complete the request
     * when none is left.
     */
    private static function startStep(ApprovalRequest $request, Model $locked, Workflow $workflow, Transition $transition, int $index, ?Model $requester, ?Model $tenant, ?Model $actor): void
    {
        $steps = $transition->getApprovalSteps();

        for ($i = $index; $i < count($steps); $i++) {
            $step = $steps[$i];

            if (! $step->appliesTo($locked)) {
                continue;
            }

            $approvers = self::resolveApprovers($step, $locked, $workflow, $request, $requester, $tenant);

            if ($approvers->isEmpty()) {
                throw TransitionDenied::because(TransitionDenied::NO_APPROVERS, replace: ['step' => $step->getLabel()]);
            }

            $request->current_step = $i;
            $request->save();

            $due = $step->getDueIn();

            foreach ($approvers as $approver) {
                self::assign($request, $step, $i, $approver, $due === null ? null : now()->add($due));
            }

            event(new ApprovalRequested($request, $locked, $step->getName(), $approvers, $requester));

            Notifier::send(
                $approvers,
                $locked,
                $tenant,
                (string) __('alfakit-workflows::workflows.notifications.requested.title', ['record' => Records::title($locked)]),
                (string) __('alfakit-workflows::workflows.notifications.requested.body', [
                    'name' => Users::name($requester),
                    'transition' => $transition->getLabel(),
                    'step' => $step->getLabel(),
                ]),
                'clipboard-check',
                'warning',
            );

            return;
        }

        self::complete($request, $locked, $workflow, $transition, $actor);
    }

    /**
     * @return Collection<int, Model>
     */
    private static function resolveApprovers(ApprovalStep $step, Model $record, Workflow $workflow, ApprovalRequest $request, ?Model $requester, ?Model $tenant): Collection
    {
        $injections = ['record' => $record, 'request' => $request, 'tenant' => $tenant, 'requester' => $requester, 'workflow' => $workflow];
        $typed = [Model::class => $record, $record::class => $record, Workflow::class => $workflow, ApprovalRequest::class => $request];

        $approvers = Users::resolve(
            $step->getApprovers(),
            $step->getRoles(),
            $step->getPermissions(),
            $record,
            $tenant,
            $injections,
            static fn (Closure $closure, array $injections): mixed => $step->evaluate($closure, $injections, $typed),
        );

        if (! $workflow->allowsSelfApproval() && $requester !== null) {
            $approvers = $approvers->reject(static fn (Model $user): bool => Users::same($user, $requester))->values();
        }

        return $approvers;
    }

    private static function assign(ApprovalRequest $request, ApprovalStep $step, int $index, Model $approver, mixed $due, ?Approval $replaces = null): Approval
    {
        $approval = new Approval;
        $approval->forceFill([
            'request_id' => $request->getKey(),
            'step' => $index,
            'step_name' => $step->getName(),
            'approver_type' => $approver->getMorphClass(),
            'approver_id' => (string) $approver->getKey(),
            'status' => Approval::PENDING,
            'replaces_id' => $replaces?->getKey(),
            'due_at' => $due,
        ]);
        $approval->save();
        $approval->setRelation('approver', $approver);
        self::forget();

        return $approval;
    }

    private static function complete(ApprovalRequest $request, Model $locked, Workflow $workflow, Transition $transition, ?Model $actor): void
    {
        $state = $workflow->getStateOf($locked);
        $expected = $transition->getPendingState() ?? $request->from_state;

        if ($state !== $expected) {
            throw TransitionDenied::because(TransitionDenied::INVALID_STATE, replace: [
                'transition' => $transition->getLabel(),
                'state' => (string) ($workflow->getStateLabel($state) ?? '—'),
            ]);
        }

        $request->forceFill(['status' => ApprovalRequest::APPROVED, 'completed_at' => now()])->save();

        Transitions::move($locked, $workflow, $transition, $state, (string) $transition->getTo(), $actor, $request->data ?? [], null, $request);

        $tenant = Context::findTenant($request->tenant_type, $request->tenant_id);

        Notifier::send(
            array_filter([Users::find($request->requester_type, $request->requester_id)]),
            $locked,
            $tenant,
            (string) __('alfakit-workflows::workflows.notifications.approved.title', ['record' => Records::title($locked)]),
            (string) __('alfakit-workflows::workflows.notifications.approved.body', [
                'transition' => $transition->getLabel(),
                'state' => (string) $workflow->getStateLabel($transition->getTo()),
            ]),
            'badge-check',
            'success',
            except: $actor,
        );
    }

    // ---------------------------------------------------------------- Decisions

    /**
     * Approve the approval as its approver. Completes the step when it has enough
     * approvals, then opens the next step or applies the transition.
     *
     * @throws TransitionDenied
     */
    public static function approve(Approval $approval, ?Model $user = null, ?string $comment = null): Approval
    {
        $user ??= Context::user();
        $comment = History::comment($comment);

        return self::write(static function () use ($approval, $user, $comment): Approval {
            [$request, $locked, $record, $workflow, $transition, $step] = self::lockDecision($approval);

            self::ensureApprover($locked, $user);

            if ($step->isApprovalCommentRequired() && $comment === null) {
                throw TransitionDenied::because(TransitionDenied::COMMENT_REQUIRED);
            }

            /** @var Model $user */
            $locked->forceFill(['status' => Approval::APPROVED, 'comment' => $comment, 'decided_at' => now()])->save();

            History::log($workflow, $record, WorkflowLog::APPROVED, $user, [
                'comment' => $comment,
                'properties' => ['step' => $step->getName(), 'step_label' => $step->getLabel()],
            ], $request);

            event(new ApprovalGranted($request, $locked, $record, $user, $comment));

            if (self::isStepComplete($request, $step)) {
                self::closeOpenApprovals($request);

                $requester = Users::find($request->requester_type, $request->requester_id);
                $tenant = Context::findTenant($request->tenant_type, $request->tenant_id);

                self::startStep($request, $record, $workflow, $transition, $request->current_step + 1, $requester, $tenant, $user);
            }

            $approval->setRawAttributes($locked->getAttributes(), true);

            return $approval;
        });
    }

    /**
     * Reject the request as one of the current step's approvers. The record moves to the
     * transition's rejected state (or back to where it started).
     *
     * @throws TransitionDenied
     */
    public static function reject(Approval $approval, ?Model $user = null, ?string $comment = null): Approval
    {
        $user ??= Context::user();
        $comment = History::comment($comment);

        return self::write(static function () use ($approval, $user, $comment): Approval {
            [$request, $locked, $record, $workflow, $transition, $step] = self::lockDecision($approval);

            self::ensureApprover($locked, $user);

            if ($step->isRejectionCommentRequired() && $comment === null) {
                throw TransitionDenied::because(TransitionDenied::COMMENT_REQUIRED);
            }

            /** @var Model $user */
            $locked->forceFill(['status' => Approval::REJECTED, 'comment' => $comment, 'decided_at' => now()])->save();
            self::closeOpenApprovals($request);
            $request->forceFill(['status' => ApprovalRequest::REJECTED, 'completed_at' => now()])->save();

            $from = $workflow->getStateOf($record);
            $to = $transition->getRejectedState() ?? ($transition->getPendingState() !== null ? $request->from_state : null);

            if ($to !== null && $to !== $from) {
                $record->setAttribute($workflow->getStateColumn(), $to);
                Workflows::applying(static fn (): bool => $record->save());
            }

            History::log($workflow, $record, WorkflowLog::REJECTED, $user, [
                'from_state' => $from,
                'to_state' => $to !== null && $to !== $from ? $to : null,
                'comment' => $comment,
                'properties' => ['step' => $step->getName(), 'step_label' => $step->getLabel()],
            ], $request);

            event(new ApprovalRejected($request, $locked, $record, $user, $comment));

            Notifier::send(
                array_filter([Users::find($request->requester_type, $request->requester_id)]),
                $record,
                Context::findTenant($request->tenant_type, $request->tenant_id),
                (string) __('alfakit-workflows::workflows.notifications.rejected.title', ['record' => Records::title($record)]),
                (string) __('alfakit-workflows::workflows.notifications.rejected.body', ['name' => Users::name($user), 'transition' => $transition->getLabel()]).($comment === null ? '' : ' “'.$comment.'”'),
                'circle-x',
                'danger',
                except: $user,
            );

            $approval->setRawAttributes($locked->getAttributes(), true);

            return $approval;
        });
    }

    /**
     * Hand the approval to another user: by its approver (`delegate`, when the step is
     * `delegable()`), or by someone allowed to reassign (`$reassign`).
     *
     * @throws TransitionDenied
     */
    public static function delegate(Approval $approval, Model|int|string $to, ?Model $user = null, ?string $comment = null, bool $reassign = false): Approval
    {
        $user ??= Context::user();
        $comment = History::comment($comment);

        return self::write(static function () use ($approval, $to, $user, $comment, $reassign): Approval {
            [$request, $locked, $record, $workflow, , $step] = self::lockDecision($approval);

            if ($reassign) {
                if (! WorkflowsPlugin::get()->canReassign($record, $user)) {
                    throw TransitionDenied::because(TransitionDenied::UNAUTHORIZED);
                }
            } else {
                self::ensureApprover($locked, $user);

                if (! $step->isDelegable()) {
                    throw TransitionDenied::because(TransitionDenied::UNAUTHORIZED);
                }
            }

            $tenant = Context::findTenant($request->tenant_type, $request->tenant_id);
            $target = $to instanceof Model ? $to : Users::findCandidate($to, $tenant);
            $current = Users::find($locked->approver_type, $locked->approver_id);

            if (
                $target === null
                || ! Users::canAccessTenant($target, $tenant)
                || Users::same($target, $current)
                || $request->approvals()->pending()->assignedTo($target)->where('step', $request->current_step)->exists()
                || (! $workflow->allowsSelfApproval() && $request->isRequestedBy($target))
            ) {
                throw TransitionDenied::because(TransitionDenied::INVALID_DELEGATE);
            }

            $locked->forceFill(['status' => Approval::DELEGATED, 'comment' => $comment, 'decided_at' => now()])->save();
            $new = self::assign($request, $step, $request->current_step, $target, $locked->due_at, $locked);

            History::log($workflow, $record, $reassign ? WorkflowLog::REASSIGNED : WorkflowLog::DELEGATED, $user, [
                'comment' => $comment,
                'properties' => ['step' => $step->getName(), 'step_label' => $step->getLabel(), 'from' => Users::name($current), 'to' => Users::name($target)],
            ], $request);

            event(new ApprovalDelegated($request, $locked, $new, $user, reassigned: $reassign));

            Notifier::send(
                [$target],
                $record,
                $tenant,
                (string) __('alfakit-workflows::workflows.notifications.delegated.title', ['record' => Records::title($record)]),
                (string) __('alfakit-workflows::workflows.notifications.delegated.body', ['name' => Users::name($user), 'step' => $step->getLabel()]),
                'user-round-check',
                'warning',
            );

            $approval->setRawAttributes($locked->getAttributes(), true);

            return $new;
        });
    }

    /**
     * Withdraw a pending request (its requester, or someone allowed to reassign). The
     * record goes back to the state the request started from.
     *
     * @throws TransitionDenied
     */
    public static function cancel(ApprovalRequest $request, ?Model $user = null, ?string $comment = null): ApprovalRequest
    {
        $user ??= Context::user();
        $comment = History::comment($comment);

        return self::write(static function () use ($request, $user, $comment): ApprovalRequest {
            self::forget();
            $locked = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();
            $record = $locked === null ? null : Records::find($locked->subject_type, $locked->subject_id, lock: true);

            if ($locked === null || $record === null || ! $locked->isPending() || ! Context::isCurrentTenant($locked->tenant_type, $locked->tenant_id)) {
                throw TransitionDenied::because(TransitionDenied::NOT_PENDING);
            }

            if (! $locked->isRequestedBy($user) && ! WorkflowsPlugin::get()->canReassign($record, $user)) {
                throw TransitionDenied::because(TransitionDenied::UNAUTHORIZED);
            }

            $workflow = Workflows::for($record);
            $transition = $workflow->getTransition($locked->transition);

            self::closeOpenApprovals($locked);
            $locked->forceFill(['status' => ApprovalRequest::CANCELLED, 'completed_at' => now()])->save();

            $from = $workflow->getStateOf($record);
            $to = $transition?->getPendingState() !== null && $from === $transition->getPendingState() ? $locked->from_state : null;

            if ($to !== null && $to !== $from) {
                $record->setAttribute($workflow->getStateColumn(), $to);
                Workflows::applying(static fn (): bool => $record->save());
            }

            History::log($workflow, $record, WorkflowLog::CANCELLED, $user, [
                'from_state' => $from,
                'to_state' => $to,
                'comment' => $comment,
            ], $locked);

            $request->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    // ---------------------------------------------------------------- Reminders and escalation

    /**
     * Hand an approval pending past `escalateAfter()` to the `escalateTo()` users. Returns
     * whether it was escalated.
     */
    public static function escalate(Approval $approval): bool
    {
        return self::write(static function () use ($approval): bool {
            try {
                [$request, $locked, $record, $workflow, , $step] = self::lockDecision($approval, checkTenant: false);
            } catch (TransitionDenied) {
                return false;
            }

            $after = $step->getEscalateAfter();

            if ($after === null || $locked->escalated_at !== null || $locked->created_at === null || $locked->created_at->copy()->add($after)->isFuture()) {
                return false;
            }

            $tenant = Context::findTenant($request->tenant_type, $request->tenant_id);
            $current = Users::find($locked->approver_type, $locked->approver_id);
            $injections = ['record' => $record, 'request' => $request, 'tenant' => $tenant, 'approver' => $current, 'workflow' => $workflow];
            $typed = [Model::class => $record, $record::class => $record, Workflow::class => $workflow, ApprovalRequest::class => $request];

            $pending = $request->approvals()->pending()->where('step', $request->current_step)->get();
            $targets = Users::resolve($step->getEscalateTo(), $step->getEscalationRoles(), [], $record, $tenant, $injections, static fn (Closure $closure, array $injections): mixed => $step->evaluate($closure, $injections, $typed))
                ->reject(static fn (Model $user): bool => $pending->contains(static fn (Approval $open): bool => $open->isAssignedTo($user)))
                ->reject(static fn (Model $user): bool => ! $workflow->allowsSelfApproval() && $request->isRequestedBy($user))
                ->values();

            if ($targets->isEmpty()) {
                // Nobody to escalate to: flag it once instead of trying every run.
                $locked->forceFill(['escalated_at' => now()])->save();

                return false;
            }

            $locked->forceFill(['status' => Approval::ESCALATED, 'escalated_at' => now(), 'decided_at' => now()])->save();
            $due = $step->getDueIn();

            foreach ($targets as $target) {
                $new = self::assign($request, $step, $request->current_step, $target, $due === null ? null : now()->add($due), $locked);

                event(new ApprovalDelegated($request, $locked, $new, null, escalated: true));
            }

            History::log($workflow, $record, WorkflowLog::ESCALATED, null, [
                'properties' => [
                    'step' => $step->getName(),
                    'step_label' => $step->getLabel(),
                    'from' => Users::name($current),
                    'to' => $targets->map(static fn (Model $user): string => Users::name($user))->implode(', '),
                ],
            ], $request);

            Notifier::send(
                $targets,
                $record,
                $tenant,
                (string) __('alfakit-workflows::workflows.notifications.escalated.title', ['record' => Records::title($record)]),
                (string) __('alfakit-workflows::workflows.notifications.escalated.body', ['name' => Users::name($current), 'step' => $step->getLabel()]),
                'siren',
                'danger',
            );

            return true;
        });
    }

    /**
     * Remind the approver when `remindEvery()` has passed since the approval was assigned
     * or last reminded. Returns whether a reminder was sent.
     */
    public static function remind(Approval $approval): bool
    {
        $request = $approval->request;
        $record = $request === null ? null : Records::find($request->subject_type, $request->subject_id);

        if ($request === null || $record === null || ! $request->isPending() || ! $approval->isPending() || $approval->step !== $request->current_step || ! Workflows::uses($record)) {
            return false;
        }

        $step = Workflows::for($record)->getTransition($request->transition)?->getApprovalStep($request->current_step);
        $every = $step?->getRemindEvery();
        $since = $approval->last_reminded_at ?? $approval->created_at;

        if ($every === null || $since === null || $since->copy()->add($every)->isFuture()) {
            return false;
        }

        $approval->forceFill(['last_reminded_at' => now(), 'reminders_sent' => $approval->reminders_sent + 1])->save();

        $approver = Users::find($approval->approver_type, $approval->approver_id);

        if ($approver !== null) {
            Notifier::send(
                [$approver],
                $record,
                Context::findTenant($request->tenant_type, $request->tenant_id),
                (string) __('alfakit-workflows::workflows.notifications.reminder.title', ['record' => Records::title($record)]),
                $approval->isOverdue()
                    ? (string) __('alfakit-workflows::workflows.notifications.reminder.overdue', ['step' => $step->getLabel()])
                    : (string) __('alfakit-workflows::workflows.notifications.reminder.body', ['step' => $step->getLabel()]),
                'bell-ring',
                $approval->isOverdue() ? 'danger' : 'warning',
            );
        }

        return true;
    }

    // ---------------------------------------------------------------- Internals

    /**
     * Lock the request, the approval and the record and check the approval is still open
     * on the current step of a pending request in the current tenant.
     *
     * @return array{0: ApprovalRequest, 1: Approval, 2: Model, 3: Workflow, 4: Transition, 5: ApprovalStep}
     */
    private static function lockDecision(Approval $approval, bool $checkTenant = true): array
    {
        self::forget();
        $request = ApprovalRequest::query()->whereKey($approval->request_id)->lockForUpdate()->first();
        $locked = Approval::query()->whereKey($approval->getKey())->lockForUpdate()->first();

        if ($request === null || $locked === null || ! $request->isPending() || ! $locked->isPending() || $locked->step !== $request->current_step) {
            throw TransitionDenied::because(TransitionDenied::NOT_PENDING);
        }

        if ($checkTenant && ! Context::isCurrentTenant($request->tenant_type, $request->tenant_id)) {
            throw TransitionDenied::because(TransitionDenied::NOT_PENDING);
        }

        $record = Records::find($request->subject_type, $request->subject_id, lock: true);

        if ($record === null || ! Workflows::uses($record)) {
            throw TransitionDenied::because(TransitionDenied::NOT_PENDING);
        }

        $workflow = Workflows::for($record);
        $transition = $workflow->getTransition($request->transition);
        $step = $transition?->getApprovalStep($request->current_step);

        if ($transition === null || $step === null) {
            throw TransitionDenied::because(TransitionDenied::UNKNOWN, replace: ['transition' => $request->transition]);
        }

        $request->setRelation('subject', $record);
        $locked->setRelation('request', $request);

        return [$request, $locked, $record, $workflow, $transition, $step];
    }

    private static function ensureApprover(Approval $approval, ?Model $user): void
    {
        if (! $approval->isAssignedTo($user)) {
            throw TransitionDenied::because(TransitionDenied::UNAUTHORIZED);
        }
    }

    private static function isStepComplete(ApprovalRequest $request, ApprovalStep $step): bool
    {
        $approvals = $request->approvals()->where('step', $request->current_step)->get();
        $approved = $approvals->where('status', Approval::APPROVED)->count();

        if ($step->requiresAll()) {
            return $approved > 0 && $approvals->where('status', Approval::PENDING)->isEmpty();
        }

        return $approved > 0;
    }

    private static function closeOpenApprovals(ApprovalRequest $request): void
    {
        $request->approvals()->pending()->update(['status' => Approval::SKIPPED, 'updated_at' => now()]);
        self::forget();
    }
}
