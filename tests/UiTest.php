<?php

declare(strict_types=1);

use AlfaSix\AlfakitWorkflows\Actions\WorkflowActions;
use AlfaSix\AlfakitWorkflows\Models\Approval;
use AlfaSix\AlfakitWorkflows\Models\ApprovalRequest;
use AlfaSix\AlfakitWorkflows\Models\WorkflowLog;
use AlfaSix\AlfakitWorkflows\Pages\ApprovalsInbox;
use AlfaSix\AlfakitWorkflows\Schemas\Components\WorkflowDiagram;
use AlfaSix\AlfakitWorkflows\Support\Approvals;
use AlfaSix\AlfakitWorkflows\Support\DiagramRenderer;
use AlfaSix\AlfakitWorkflows\Support\Workflows;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Invoice;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource\Pages\ListInvoices;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource\Pages\ViewInvoice;

uses(AlfaSix\AlfakitWorkflows\Tests\TestCase::class);

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('workflows'));
});

/**
 * @param  array<int|string, mixed>  $nodes
 * @return array<int, array<string, mixed>>
 */
function wfFlatten(array $nodes): array
{
    $flat = [];

    foreach ($nodes as $node) {
        if (! is_array($node)) {
            continue;
        }

        if (isset($node['type']) && is_string($node['type'])) {
            $flat[] = $node;
        }

        foreach (['components', 'schema', 'children', 'items', 'actions', 'content', 'footer'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                array_push($flat, ...wfFlatten(array_is_list($node[$key]) ? $node[$key] : [$node[$key]]));
            }
        }
    }

    return $flat;
}

/**
 * @param  array<string, mixed>  $page
 * @return array<int, string>
 */
function wfHeaderActionNames(array $page): array
{
    return array_column(wfFlatten($page['headerActions'] ?? []), 'name');
}

it('shows only the transitions the user may run in the record\'s state', function (): void {
    $invoice = $this->invoice(500);

    $this->actingAs($this->user('Ada'));
    expect(wfHeaderActionNames($this->pageProps('/workflows/invoices/'.$invoice->getKey())))->toBe(['workflow_submit', WorkflowActions::DIAGRAM]);

    $this->actingAs($this->user('Root', 'admin'));
    expect(wfHeaderActionNames($this->pageProps('/workflows/invoices/'.$invoice->getKey())))->toBe(['workflow_submit', 'workflow_void', WorkflowActions::DIAGRAM]);

    $this->actingAs($this->user('Vic', 'viewer'));
    expect(wfHeaderActionNames($this->pageProps('/workflows/invoices/'.$invoice->getKey())))->toBe([WorkflowActions::DIAGRAM]);
});

it('runs a transition with its form through the action endpoint', function (): void {
    $this->actingAs($this->user('Ada'));
    $invoice = $this->invoice(attributes: ['state' => 'approved']);
    $url = '/workflows/invoices/'.$invoice->getKey();

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice->getKey()], panel: 'workflows')
        ->assertActionVisible('workflow_pay')
        ->assertActionHidden('workflow_submit')
        ->callAction('workflow_pay', [])
        ->assertHasActionErrors(['reference']);

    expect($invoice->fresh()?->state)->toBe('approved');

    $this->runPageAction($url, 'workflow_pay', ['reference' => 'TRX-1'])->assertOk();

    expect($invoice->fresh()?->state)->toBe('paid')
        ->and($invoice->fresh()?->payment_reference)->toBe('TRX-1');
});

it('asks for a confirmation or the comment field in the modal', function (): void {
    $this->actingAs($this->user('Ada'));
    $invoice = $this->invoice(attributes: ['state' => 'rejected']);

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice->getKey()], panel: 'workflows')
        ->mountAction('workflow_reopen')
        ->assertActionMounted('workflow_reopen')
        ->callMountedAction()
        ->assertHasActionErrors([WorkflowActions::COMMENT_FIELD]);

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice->getKey()], panel: 'workflows')
        ->callAction('workflow_reopen', [WorkflowActions::COMMENT_FIELD => 'Fixed it'])
        ->assertHasNoActionErrors()
        ->assertNotified('Moved to Draft');

    expect($invoice->fresh()?->state)->toBe('draft')
        ->and(WorkflowLog::query()->where('event', WorkflowLog::TRANSITIONED)->sole()->comment)->toBe('Fixed it');
});

it('lists only the approval steps that apply to the record in the submit modal', function (): void {
    $this->actingAs($this->user('Ada'));
    $description = static function (Invoice $invoice): ?string {
        $action = collect(WorkflowActions::make()->record($invoice)->getFlatActions())
            ->first(static fn ($action): bool => $action->getName() === 'workflow_submit');

        return $action?->record($invoice)->getModalDescription();
    };

    expect($description($this->invoice(500)))->toBe('This needs approval (Manager approval) before the record moves to Approved.')
        ->and($description($this->invoice(5000)))->toBe('This needs approval (Manager approval → Finance approval) before the record moves to Approved.');
});

it('refuses a double submit of the same transition', function (): void {
    $this->actingAs($this->user('Ada'));
    $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $url = '/workflows/invoices/'.$invoice->getKey();
    $token = $this->pageProps($url)['token'];

    $this->runPageAction($url, 'workflow_submit', [], token: $token)->assertOk();
    $this->runPageAction($url, 'workflow_submit', [], token: $token)->assertForbidden();

    expect(ApprovalRequest::query()->count())->toBe(1)
        ->and(WorkflowLog::query()->where('event', WorkflowLog::REQUESTED)->count())->toBe(1);
});

it('shows approve, reject and delegate to the approvers only', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);
    $url = '/workflows/invoices/'.$invoice->getKey();

    $this->actingAs($bob);
    expect(wfHeaderActionNames($this->pageProps($url)))->toBe([WorkflowActions::APPROVE, WorkflowActions::REJECT, WorkflowActions::DELEGATE, WorkflowActions::DIAGRAM]);

    $this->actingAs($ada);
    expect(wfHeaderActionNames($this->pageProps($url)))->toBe([WorkflowActions::WITHDRAW, WorkflowActions::DIAGRAM]);
    $this->runPageAction($url, WorkflowActions::APPROVE)->assertForbidden();

    $this->actingAs($this->user('Root', 'admin'));
    expect(wfHeaderActionNames($this->pageProps($url)))->toBe([WorkflowActions::REASSIGN, WorkflowActions::WITHDRAW, WorkflowActions::DIAGRAM]);
});

it('approves and rejects from the record page', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);
    $url = '/workflows/invoices/'.$invoice->getKey();
    $this->actingAs($bob);

    $this->runPageAction($url, WorkflowActions::REJECT, [])->assertUnprocessable();
    $this->runPageAction($url, WorkflowActions::APPROVE, [WorkflowActions::COMMENT_FIELD => 'Fine by me'])->assertOk();

    expect($invoice->fresh()?->state)->toBe('approved')
        ->and(Approval::query()->where('approver_id', (string) $bob->id)->sole()->comment)->toBe('Fine by me');
});

it('delegates from the record page with a tenant-aware user search', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $dan = $this->user('Dan');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);
    $this->actingAs($bob);

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice->getKey()], panel: 'workflows')
        ->callAction(WorkflowActions::DELEGATE, ['approver' => (string) $dan->id, WorkflowActions::COMMENT_FIELD => 'Away'])
        ->assertHasNoActionErrors()
        ->assertNotified('Approval delegated');

    expect(Approvals::assignmentFor(ApprovalRequest::query()->sole(), $dan))->not->toBeNull()
        ->and(AlfaSix\AlfakitWorkflows\Support\Users::search('', null, [(string) $bob->id, (string) $ada->id]))->toBe([(string) $dan->id => 'Dan']);
});

it('reassigns and withdraws from the record page', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $dan = $this->user('Dan');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);
    $approval = Approval::query()->sole();

    $this->actingAs($this->user('Root', 'admin'));
    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice->getKey()], panel: 'workflows')
        ->callAction(WorkflowActions::REASSIGN, ['approval' => (string) $approval->id, 'approver' => (string) $dan->id])
        ->assertHasNoActionErrors();

    expect(Approvals::assignmentFor(ApprovalRequest::query()->sole(), $dan))->not->toBeNull()
        ->and(Approvals::assignmentFor(ApprovalRequest::query()->sole(), $bob))->toBeNull();

    $this->actingAs($ada);
    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice->getKey()], panel: 'workflows')
        ->callAction(WorkflowActions::WITHDRAW, [])
        ->assertNotified('Request withdrawn');

    expect($invoice->fresh()?->state)->toBe('draft');
});

it('turns refusals into a danger notification', function (): void {
    $this->actingAs($this->user('Ada', 'manager'));
    $invoice = $this->invoice(500);

    // Ada is the only manager, and can't approve her own request.
    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice->getKey()], panel: 'workflows')
        ->callAction('workflow_submit')
        ->assertNotified('Nobody can approve the step "Manager approval".');

    expect($invoice->fresh()?->state)->toBe('draft');
});

it('renders the timeline with transitions, comments and pending approvers', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $this->actingAs($ada);
    $invoice = $this->invoice(500);
    $invoice->transition('submit', comment: 'Ready for review');

    $content = json_encode($this->pageProps('/workflows/invoices/'.$invoice->getKey())['content'], JSON_UNESCAPED_UNICODE);

    expect($content)
        ->toContain('data-workflow-timeline')
        ->toContain('Ada asked for approval: Submit')
        ->toContain('“Ready for review”')
        ->toContain('Ada created it')
        ->toContain('Waiting for approval: Submit (Manager approval)')
        ->toContain('Step 1 of 2. One approval is enough.')
        ->toContain('"Bob"');

    Approvals::approve(Approval::query()->sole(), $bob, 'OK');

    $content = json_encode($this->pageProps('/workflows/invoices/'.$invoice->getKey())['content'], JSON_UNESCAPED_UNICODE);

    expect($content)
        ->toContain('Bob approved (Manager approval)')
        ->toContain('Submit approved')
        ->not->toContain('Waiting for approval');
});

it('renders the state entry with the built-in text entry', function (): void {
    $this->actingAs($this->user('Ada'));
    $invoice = $this->invoice(500);

    $entry = collect(wfFlatten($this->pageProps('/workflows/invoices/'.$invoice->getKey())['content']))
        ->first(fn (array $node): bool => ($node['name'] ?? $node['key'] ?? null) === 'state');

    // A renderer only knows built-in types: `WorkflowStateEntry` would be an unknown component.
    expect($entry)->not->toBeNull()
        ->and($entry['type'])->toBe('TextEntry')
        ->and($entry['badge'] ?? null)->toBeTrue();
});

it('hides the timeline from users who may not view the record', function (): void {
    $this->actingAs($this->user('Ada'));
    $invoice = $this->invoice(500, attributes: ['number' => 'SECRET']);

    $schema = AlfaSix\Alfakit\Schemas\Schema::make()
        ->components([AlfaSix\AlfakitWorkflows\Infolists\Components\WorkflowTimeline::make()])
        ->record($invoice)
        ->toSchema();

    expect(json_encode($schema))->not->toContain('data-workflow-timeline');
    $this->inertiaGet('/workflows/invoices/'.$invoice->getKey())->assertForbidden();
});

it('draws an accessible diagram with the current state and the path taken', function (): void {
    $this->actingAs($ada = $this->user('Ada'));
    $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit');

    $html = (string) collect(wfFlatten($this->pageProps('/workflows/invoices/'.$invoice->getKey())['content']))
        ->first(fn (array $node): bool => ($node['type'] === 'Html') && str_contains((string) ($node['content'] ?? ''), 'data-workflow-diagram'))['content'];

    expect($html)
        ->toContain('<svg')
        ->toContain('role="img"')
        ->toContain('<title id="')
        ->toContain('Invoice workflow')
        ->toContain('Current state: Submitted.')
        ->toContain('data-state="submitted" data-current="true"')
        ->toContain('data-edge="draft&gt;submitted" data-kind="transition" style="fill:none;stroke:var(--foreground);stroke-width:2')
        // `fromAny()` transitions are one stub, not an arrow from every state.
        ->toContain('Void (from any state)')
        ->toContain('data-kind="any"')
        ->not->toContain('data-edge="approved&gt;void"')
        ->toContain('<ul class="sr-only">')
        ->toContain('Draft (initial state): Submit → Submitted; Void → Void')
        ->not->toContain('<script');
});

it('escapes state labels in the diagram and mirrors it right to left', function (): void {
    $workflow = AlfaSix\AlfakitWorkflows\Workflow::make('xss')
        ->states([AlfaSix\AlfakitWorkflows\State::make('a')->label('<img src=x onerror=alert(1)>'), 'b'])
        ->transitions([AlfaSix\AlfakitWorkflows\Transition::make('go')->from('a')->to('b')->label('"><script>')])
        ->initial('a')
        ->validate();

    $ltr = DiagramRenderer::render($workflow, 'a', rtl: false);
    $rtl = DiagramRenderer::render($workflow, 'a', rtl: true);

    expect($ltr)->not->toContain('<img src=x')->not->toContain('"><script>')->toContain('&lt;img src=x')
        ->and($rtl)->toContain('direction="rtl"');

    preg_match('/data-state="a"[^>]*>.*?<rect x="([\d.]+)"/s', $ltr, $left);
    preg_match('/data-state="a"[^>]*>.*?<rect x="([\d.]+)"/s', $rtl, $right);

    expect((float) $left[1])->toBeLessThan((float) $right[1]);
});

it('draws a model workflow without a record', function (): void {
    $diagram = WorkflowDiagram::make()->workflow(Invoice::class);
    $schema = AlfaSix\Alfakit\Schemas\Schema::make()->components([$diagram])->toSchema();

    expect(json_encode($schema))->toContain('data-workflow-diagram')->not->toContain('data-current');
});

it('shows the state as a badge column and filters by state', function (): void {
    $this->actingAs($this->user('Ada'));
    $this->user('Bob', 'manager');
    $draft = $this->invoice(500);
    $approved = $this->invoice(attributes: ['state' => 'approved']);
    $pending = $this->invoice(500);
    $pending->transition('submit');

    alfakit()->testPage(ListInvoices::class, panel: 'workflows')
        ->assertTableColumnFormattedStateSet('state', 'Draft', $draft)
        ->assertTableColumnFormattedStateSet('state', 'Approved', $approved)
        ->assertTableColumnHasDescription('state', 'Awaiting approval', $pending)
        ->filterTable('state', ['approved', 'submitted'])
        ->assertCanSeeTableRecords([$approved, $pending])
        ->assertCanNotSeeTableRecords([$draft]);

    $table = alfakit()->testPage(ListInvoices::class, panel: 'workflows')->getTable();
    $row = collect($table['records'])->firstWhere('key', $approved->getKey());

    expect($row['cells']['state']['color'] ?? null)->toBe('success')
        ->and($row['cells']['state']['icon'] ?? null)->toBe('badge-check');
});

it('runs transitions from the table row actions', function (): void {
    $this->actingAs($this->user('Ada'));
    $invoice = $this->invoice(attributes: ['state' => 'approved']);

    alfakit()->testPage(ListInvoices::class, panel: 'workflows')
        ->assertTableActionVisible('workflow_pay', $invoice)
        ->assertTableActionHidden('workflow_submit', $invoice)
        ->callTableAction('workflow_pay', $invoice, ['reference' => 'R-9'])
        ->assertHasNoActionErrors();

    expect($invoice->fresh()?->state)->toBe('paid');
});

it('lists what waits for the user in the approvals inbox', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $cyd = $this->user('Cyd', 'finance');
    $mine = $this->invoice(500, attributes: ['number' => 'INV-A']);
    $mine->transition('submit', comment: 'Urgent', user: $ada);
    $other = $this->invoice(5000, attributes: ['number' => 'INV-B']);
    $other->transition('submit', user: $ada);

    $this->actingAs($bob);

    expect(alfakit()->getPanel('workflows')->getPages())->toContain(ApprovalsInbox::class)
        ->and(ApprovalsInbox::getNavigationBadge())->toBe(2)
        ->and(ApprovalsInbox::getNavigationGroup())->toBe('Work');

    $page = alfakit()->testPage(ApprovalsInbox::class, panel: 'workflows')
        ->assertOk()
        ->assertTitle('Approvals')
        ->assertCountTableRecords(2);
    $records = collect($page->getTable()['records']);

    expect($records->pluck('cells.subject.state')->all())->toEqualCanonicalizing(['INV-A', 'INV-B'])
        ->and($records->firstWhere('cells.subject.state', 'INV-A')['cells']['requester']['description'] ?? null)->toBe('“Urgent”')
        ->and($records->first()['url'] ?? null)->toStartWith('http://localhost/workflows/invoices/');

    $this->actingAs($cyd);
    alfakit()->testPage(ApprovalsInbox::class, panel: 'workflows')->assertCountTableRecords(0);
});

it('approves, rejects and delegates from the inbox', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $dan = $this->user('Dan');
    $one = $this->invoice(500);
    $two = $this->invoice(500);
    $three = $this->invoice(500);

    foreach ([$one, $two, $three] as $invoice) {
        $invoice->transition('submit', user: $ada);
    }

    $this->actingAs($bob);
    $approval = fn (Invoice $invoice): Approval => Approvals::assignmentFor($invoice->getPendingApprovalRequest() ?? throw new RuntimeException, $bob) ?? throw new RuntimeException;

    alfakit()->testPage(ApprovalsInbox::class, panel: 'workflows')
        ->callTableAction('approve', $approval($one), [])
        ->assertNotified('Approved')
        ->callTableAction('reject', $approval($two), [WorkflowActions::COMMENT_FIELD => 'No'])
        ->assertNotified('Rejected')
        ->callTableAction('delegate', $approval($three), ['approver' => (string) $dan->id])
        ->assertNotified('Approval delegated')
        ->assertCountTableRecords(0);

    expect($one->fresh()?->state)->toBe('approved')
        ->and($two->fresh()?->state)->toBe('rejected')
        ->and(ApprovalsInbox::getNavigationBadge())->toBeNull();
});

it('bulk approves the selected approvals', function (): void {
    $ada = $this->user('Ada');
    $bob = $this->user('Bob', 'manager');
    $invoices = [$this->invoice(500), $this->invoice(500), $this->invoice(500)];

    foreach ($invoices as $invoice) {
        $invoice->transition('submit', user: $ada);
    }

    $this->actingAs($bob);

    alfakit()->testPage(ApprovalsInbox::class, panel: 'workflows')
        ->callTableBulkAction('approveSelected', Approval::query()->get(), [])
        ->assertNotified('3 items approved')
        ->assertCountTableRecords(0);

    expect(Invoice::query()->pluck('state')->unique()->all())->toBe(['approved']);
});

it('never lets the inbox act on other users\' approvals', function (): void {
    $ada = $this->user('Ada');
    $this->user('Bob', 'manager');
    $invoice = $this->invoice(500);
    $invoice->transition('submit', user: $ada);

    $this->actingAs($this->user('Eve', 'manager'));

    // Eve became a manager after the request: it isn't hers, so the row can't be found.
    alfakit()->testPage(ApprovalsInbox::class, panel: 'workflows')->assertCountTableRecords(0);

    expect(fn () => alfakit()->testPage(ApprovalsInbox::class, panel: 'workflows')->callTableAction('approve', Approval::query()->sole()))
        ->toThrow(Exception::class);

    expect($invoice->fresh()?->state)->toBe('submitted');
});

it('can turn the inbox off', function (): void {
    expect(Workflows::uses(Invoice::class))->toBeTrue()
        ->and((new AlfaSix\AlfakitWorkflows\WorkflowsPlugin)->inbox(false)->getInboxPage())->toBeNull()
        ->and(fn () => (new AlfaSix\AlfakitWorkflows\WorkflowsPlugin)->inbox(Invoice::class))->toThrow(InvalidArgumentException::class);
});

it('stacks the pending approvers of the timeline on phones', function (): void {
    $this->user('Bob', 'manager');
    $this->actingAs($this->user('Ada'));
    $invoice = $this->invoice(500);
    $invoice->transition('submit');

    $content = json_encode($this->pageProps('/workflows/invoices/'.$invoice->getKey())['content']);

    // The approver row (name, status badge, due date) and each entry's header (summary, time)
    // are Flex components that only lay out in a row from `sm`.
    expect(substr_count($content, '"from":"sm"'))->toBeGreaterThanOrEqual(2);
});
