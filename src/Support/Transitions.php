<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\AlfakitWorkflows\Events\TransitionApplied;
use AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Checks and applies transitions. Applying always happens in a transaction, on the row
 * read again with a lock, so two users clicking at once can't both move the record.
 */
final class Transitions
{
    /**
     * The transitions the user may run on the record now: the state allows them, no
     * approval is pending, the user is authorised and every guard passes.
     *
     * @return array<string, Transition>
     */
    public static function available(Model $record, ?Model $user): array
    {
        $workflow = Workflows::for($record);

        if (Approvals::pendingFor($record) !== null) {
            return [];
        }

        $available = [];

        foreach ($workflow->getTransitionsFrom($workflow->getStateOf($record)) as $name => $transition) {
            if (self::authorize($record, $transition, $workflow, $user) && $transition->checkGuards($record, $user, $workflow) === null) {
                $available[$name] = $transition;
            }
        }

        return $available;
    }

    /**
     * Why the user can't run the transition now, or null when they can.
     *
     * @param  array<string, mixed>  $data
     */
    public static function denial(Model $record, string $name, ?Model $user, array $data = [], bool $fresh = false): ?TransitionDenied
    {
        $workflow = Workflows::for($record);
        $transition = $workflow->getTransition($name);

        if ($transition === null) {
            return TransitionDenied::because(TransitionDenied::UNKNOWN, replace: ['transition' => $name]);
        }

        $state = $workflow->getStateOf($record);

        if (! $transition->allowsFrom($state, $workflow)) {
            return TransitionDenied::because(TransitionDenied::INVALID_STATE, replace: [
                'transition' => $transition->getLabel(),
                'state' => (string) ($workflow->getStateLabel($state) ?? '—'),
            ]);
        }

        if (Approvals::pendingFor($record, $fresh) !== null) {
            return TransitionDenied::because(TransitionDenied::AWAITING_APPROVAL);
        }

        if (! self::authorize($record, $transition, $workflow, $user)) {
            return TransitionDenied::because(TransitionDenied::UNAUTHORIZED);
        }

        $message = $transition->checkGuards($record, $user, $workflow, $data);

        return $message === null ? null : TransitionDenied::because(TransitionDenied::GUARD, $message);
    }

    /**
     * Whether the user may run the transition (ignoring state and guards). Code running
     * without a user (jobs, commands) acts as the system and is always authorised.
     */
    public static function authorize(Model $record, Transition $transition, Workflow $workflow, ?Model $user): bool
    {
        if ($user === null) {
            return true;
        }

        $authorization = $transition->getAuthorization();

        if (is_bool($authorization)) {
            return $authorization;
        }

        if (is_string($authorization)) {
            return Gate::forUser($user)->check($authorization, $record);
        }

        if ($authorization instanceof Closure) {
            $typed = [Model::class => $record, $record::class => $record, Workflow::class => $workflow];

            if (! $user instanceof $record) {
                $typed[$user::class] = $user;
            }

            $result = $transition->evaluate($authorization, ['record' => $record, 'user' => $user, 'workflow' => $workflow], $typed);

            return $result instanceof Response ? $result->allowed() : (bool) $result;
        }

        $policy = Gate::getPolicyFor($record);

        if ($policy !== null && method_exists($policy, 'transition')) {
            return Gate::forUser($user)->check('transition', [$record, $transition->getName()]);
        }

        if ($policy !== null && method_exists($policy, 'update')) {
            return Gate::forUser($user)->check('update', $record);
        }

        return ! Authorization::isStrict();
    }

    /**
     * Run a transition. Returns the approval request when it needs approval (the record
     * then waits in its pending state), null when it was applied.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TransitionDenied
     */
    public static function apply(Model $record, string $name, array $data = [], ?string $comment = null, ?Model $user = null): ?ApprovalRequest
    {
        $workflow = Workflows::for($record);
        $transition = $workflow->getTransition($name) ?? throw TransitionDenied::because(TransitionDenied::UNKNOWN, replace: ['transition' => $name]);
        $user ??= Context::user();
        $comment = History::comment($comment);

        if ($transition->isCommentRequired() && $comment === null) {
            throw TransitionDenied::because(TransitionDenied::COMMENT_REQUIRED);
        }

        try {
            return self::transact($record, $workflow, $transition, $data, $comment, $user);
        } finally {
            Approvals::forget();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function transact(Model $record, Workflow $workflow, Transition $transition, array $data, ?string $comment, ?Model $user): ?ApprovalRequest
    {
        return DB::transaction(static function () use ($record, $workflow, $transition, $data, $comment, $user): ?ApprovalRequest {
            $locked = Records::lock($record);
            $denial = self::denial($locked, $transition->getName(), $user, $data, fresh: true);

            if ($denial !== null) {
                throw $denial;
            }

            $from = $workflow->getStateOf($locked);

            if ($transition->requiresApprovalSteps()) {
                $request = Approvals::open($locked, $workflow, $transition, $from, $user, $data, $comment);
            } else {
                $request = null;
                self::move($locked, $workflow, $transition, $from, (string) $transition->getTo(), $user, $data, $comment);
            }

            $record->setRawAttributes($locked->getAttributes(), true);

            return $request;
        });
    }

    /**
     * Save the new state: the `using()` hook, the history entry, the event, the
     * notifications and the `after()` hook (after commit). Call it inside a transaction
     * with a locked record.
     *
     * @param  array<string, mixed>  $data
     */
    public static function move(Model $locked, Workflow $workflow, Transition $transition, ?string $from, string $to, ?Model $user, array $data, ?string $comment, ?ApprovalRequest $request = null): void
    {
        $injections = ['record' => $locked, 'data' => $data, 'user' => $user, 'comment' => $comment, 'request' => $request, 'from' => $from, 'to' => $to];
        $typed = [Model::class => $locked, $locked::class => $locked, Workflow::class => $workflow];

        if (($using = $transition->getUsing()) !== null) {
            $transition->evaluate($using, $injections, $typed);
        }

        $locked->setAttribute($workflow->getStateColumn(), $to);
        Workflows::applying(static fn (): bool => $locked->save());

        History::log($workflow, $locked, WorkflowLog::TRANSITIONED, $user, [
            'transition' => $transition->getName(),
            'from_state' => $from,
            'to_state' => $to,
            'comment' => $comment,
            'data' => $data,
            'properties' => $request === null ? [] : ['approved' => true],
        ], $request);

        event(new TransitionApplied($locked, $workflow->getName(), $transition->getName(), $from, $to, $user, $comment, $data, $request));

        $recipients = $transition->getNotify();

        if ($recipients !== []) {
            $tenant = $request === null ? Context::tenantFor($locked) : Context::findTenant($request->tenant_type, $request->tenant_id);
            $users = Users::resolve($recipients, [], [], $locked, $tenant, $injections, static fn (Closure $closure, array $injections): mixed => $transition->evaluate($closure, $injections, $typed));

            Notifier::send(
                $users,
                $locked,
                $tenant,
                (string) __('alfakit-workflows::workflows.notifications.transitioned.title', [
                    'record' => Records::title($locked),
                    'state' => (string) $workflow->getStateLabel($to),
                ]),
                (string) __('alfakit-workflows::workflows.notifications.transitioned.body', [
                    'name' => Users::name($user),
                    'transition' => $transition->getLabel(),
                ]).($comment === null ? '' : ' “'.$comment.'”'),
                $workflow->getState($to)?->getIcon() ?? 'workflow',
                'info',
                except: $user,
            );
        }

        if (($after = $transition->getAfter()) !== null) {
            DB::afterCommit(static fn (): mixed => $transition->evaluate($after, $injections, $typed));
        }
    }
}
