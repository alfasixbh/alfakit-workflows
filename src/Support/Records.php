<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\Alfakit\Support\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Throwable;

/**
 * Titles and lookups of workflow subjects.
 */
final class Records
{
    /**
     * The record's title in its panel resource, else "Invoice #12".
     */
    public static function title(Model $record): string
    {
        $panel = Context::panel();
        $resource = $panel?->getModelResource($record);

        if ($resource !== null && $resource::hasRecordTitle()) {
            try {
                $title = $resource::getRecordTitle($record);

                if (is_string($title) && $title !== '') {
                    return Html::toText($title);
                }
            } catch (Throwable) {
                // Fall back to the generic title.
            }
        }

        return Str::headline(class_basename($record)).' #'.$record->getKey();
    }

    /**
     * A stored subject (`subject_type` / `subject_id`).
     */
    public static function find(string $type, string $id, bool $lock = false): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $query = (new $class)->newQueryWithoutScopes()->whereKey($id);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /**
     * The record read again with a row lock (inside a transaction), so concurrent
     * transitions are applied one after the other against the current state.
     */
    public static function lock(Model $record): Model
    {
        $locked = $record->newQueryWithoutScopes()->whereKey($record->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Model) {
            throw \AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied::because(\AlfaSix\AlfakitWorkflows\Exceptions\TransitionDenied::NOT_PENDING, (string) __('alfakit-workflows::workflows.errors.missing_record'));
        }

        return $locked;
    }
}
