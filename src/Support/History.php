<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Workflow;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit trail.
 */
final class History
{
    /**
     * @param  array<string, mixed>  $attributes  `transition`, `from_state`, `to_state`, `comment`, `data`, `properties`
     */
    public static function log(Workflow $workflow, Model $subject, string $event, ?Model $causer, array $attributes = [], ?ApprovalRequest $request = null): WorkflowLog
    {
        $tenantType = $request?->tenant_type;
        $tenantId = $request?->tenant_id;

        if ($request === null) {
            $tenant = Context::tenantFor($subject);
            $tenantType = $tenant?->getMorphClass();
            $tenantId = $tenant === null ? null : (string) $tenant->getKey();
        }

        $log = new WorkflowLog;
        $log->forceFill([
            'workflow' => $workflow->getName(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'tenant_type' => $tenantType,
            'tenant_id' => $tenantId,
            'event' => $event,
            'transition' => $attributes['transition'] ?? $request?->transition,
            'from_state' => $attributes['from_state'] ?? null,
            'to_state' => $attributes['to_state'] ?? null,
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer === null ? null : (string) $causer->getKey(),
            'request_id' => $request?->getKey(),
            'comment' => self::comment($attributes['comment'] ?? null),
            'data' => self::data($attributes['data'] ?? null),
            'properties' => is_array($attributes['properties'] ?? null) && $attributes['properties'] !== [] ? $attributes['properties'] : null,
            'created_at' => now(),
        ]);
        $log->save();

        return $log;
    }

    public static function comment(mixed $comment): ?string
    {
        if (! is_string($comment)) {
            return null;
        }

        $comment = trim($comment);

        return $comment === '' ? null : mb_substr($comment, 0, 5000);
    }

    /**
     * Form data that can be stored as JSON (uploads and objects are left out).
     *
     * @return array<string, mixed>|null
     */
    public static function data(mixed $data): ?array
    {
        if (! is_array($data) || $data === []) {
            return null;
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[(string) $key] = $value;
            } elseif (is_array($value)) {
                $nested = self::data($value);
                $clean[(string) $key] = $nested ?? [];
            } elseif ($value instanceof DateTimeInterface) {
                $clean[(string) $key] = $value->format(DATE_ATOM);
            } elseif ($value instanceof BackedEnum) {
                $clean[(string) $key] = $value->value;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
