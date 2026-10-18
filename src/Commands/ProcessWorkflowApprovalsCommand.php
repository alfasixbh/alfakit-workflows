<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Commands;

use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\Context;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Escalates approvals pending past their step's `escalateAfter()` and reminds approvers
 * every `remindEvery()`. Each request is processed in the panel and tenant it was made
 * in. Schedule it:
 *
 * ```php
 * Schedule::command('alfakit-workflows:process')->everyFifteenMinutes();
 * ```
 */
class ProcessWorkflowApprovalsCommand extends Command
{
    protected $signature = 'alfakit-workflows:process
        {--no-reminders : Only escalate}
        {--no-escalations : Only remind}';

    protected $description = 'Send workflow approval reminders and escalate overdue approvals';

    public function handle(): int
    {
        $escalated = 0;
        $reminded = 0;
        $failed = 0;
        $remind = ! $this->option('no-reminders');
        $escalate = ! $this->option('no-escalations');

        Approval::query()
            ->pending()
            ->whereHas('request', static fn (Builder $query): Builder => $query->where('status', ApprovalRequest::PENDING))
            ->with('request')
            ->chunkById(200, static function (Collection $approvals) use (&$escalated, &$reminded, &$failed, $remind, $escalate): void {
                foreach ($approvals as $approval) {
                    if (($request = $approval->request) === null) {
                        continue;
                    }

                    try {
                        Context::runIn($request->panel, Context::findTenant($request->tenant_type, $request->tenant_id), static function () use ($approval, &$escalated, &$reminded, $remind, $escalate): void {
                            if ($escalate && Approvals::escalate($approval)) {
                                $escalated++;

                                return;
                            }

                            if ($remind && Approvals::remind($approval)) {
                                $reminded++;
                            }
                        });
                    } catch (Throwable $exception) {
                        $failed++;
                        report($exception);
                    }
                }
            });

        $this->components->info("Escalated {$escalated} and reminded {$reminded} workflow approvals.");

        if ($failed > 0) {
            $this->components->warn("{$failed} approvals could not be processed (see the log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
