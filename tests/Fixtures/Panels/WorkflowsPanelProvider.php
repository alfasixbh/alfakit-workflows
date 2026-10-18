<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource;
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;

class WorkflowsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('workflows')
            ->path('workflows')
            ->login()
            ->middleware(['web'])
            ->databaseNotifications()
            ->resources([InvoiceResource::class])
            ->plugin(WorkflowsPlugin::make()->navigationGroup('Work'));
    }
}
