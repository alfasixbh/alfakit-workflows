<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource;
use AlfaSix\AlfakitWorkflows\WorkflowsPlugin;

class WorkflowsTenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('workflows-team')
            ->path('workflows-team')
            ->login()
            ->middleware(['web'])
            ->databaseNotifications()
            ->tenant(Team::class, slugAttribute: 'slug')
            ->resources([InvoiceResource::class])
            ->plugin(WorkflowsPlugin::make());
    }
}
