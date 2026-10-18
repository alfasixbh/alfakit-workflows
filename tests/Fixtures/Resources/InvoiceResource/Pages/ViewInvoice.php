<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\AlfakitWorkflows\Actions\WorkflowActions;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [WorkflowActions::make()->dropdown(false)];
    }
}
