<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitWorkflows\ApprovalStep;
use AlfaSix\AlfakitWorkflows\Concerns\HasWorkflow;
use AlfaSix\AlfakitWorkflows\State;
use AlfaSix\AlfakitWorkflows\Transition;
use AlfaSix\AlfakitWorkflows\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The docs' invoice approval: managers approve, finance too above 1000, then it is paid.
 */
class Invoice extends Model
{
    use HasWorkflow;

    protected $table = 'wf_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['total' => 'float', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function workflow(): Workflow
    {
        return Workflow::make('invoice')
            ->label('Invoice')
            ->states([
                State::make('draft')->label('Draft')->color('gray')->icon('file-pen'),
                State::make('submitted')->label('Submitted')->color('warning')->icon('hourglass'),
                State::make('approved')->label('Approved')->color('success')->icon('badge-check'),
                State::make('rejected')->label('Rejected')->color('danger')->icon('circle-x'),
                State::make('paid')->label('Paid')->color('primary')->icon('banknote')->final(),
                State::make('void')->label('Void')->color('gray')->final(),
            ])
            ->transitions([
                Transition::make('submit')
                    ->label('Submit')
                    ->from('draft')
                    ->to('approved')
                    ->guard(fn (Invoice $record): bool => $record->total > 0, 'Add an amount first.')
                    ->withComment()
                    ->requiresApproval([
                        ApprovalStep::make('manager')
                            ->label('Manager approval')
                            ->role('manager')
                            ->dueIn('2 days')
                            ->remindEvery('1 day')
                            ->escalateAfter('3 days')
                            ->escalateToRole('director'),
                        ApprovalStep::make('finance')
                            ->label('Finance approval')
                            ->role('finance')
                            ->allOf()
                            ->onlyWhen(fn (Invoice $record): bool => $record->total >= 1000),
                    ])
                    ->pendingState('submitted')
                    ->rejectedState('rejected'),
                Transition::make('pay')
                    ->label('Mark as paid')
                    ->from('approved')
                    ->to('paid')
                    ->form([TextInput::make('reference')->label('Payment reference')->required()->maxLength(64)])
                    ->using(function (Invoice $record, array $data): void {
                        $record->payment_reference = (string) $data['reference'];
                        $record->paid_at = now();
                    })
                    ->notify(fn (Invoice $record) => WfUser::query()->where('role', 'finance')->get()),
                Transition::make('reopen')
                    ->label('Reopen')
                    ->from('rejected')
                    ->to('draft')
                    ->requiresComment(),
                Transition::make('void')
                    ->label('Void')
                    ->fromAny()
                    ->to('void')
                    ->color('danger')
                    ->authorize('void'),
            ])
            ->initial('draft');
    }
}
