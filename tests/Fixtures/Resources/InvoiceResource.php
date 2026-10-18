<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources;

use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitWorkflows\Actions\WorkflowActions;
use AlfaSix\AlfakitWorkflows\Infolists\Components\WorkflowStateEntry;
use AlfaSix\AlfakitWorkflows\Infolists\Components\WorkflowTimeline;
use AlfaSix\AlfakitWorkflows\Schemas\Components\WorkflowDiagram;
use AlfaSix\AlfakitWorkflows\Tables\Columns\WorkflowStateColumn;
use AlfaSix\AlfakitWorkflows\Tables\Filters\WorkflowStateFilter;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Models\Invoice;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoices';

    protected static ?string $recordTitleAttribute = 'number';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('number'),
            WorkflowStateEntry::make(),
            Section::make('Workflow')->schema([WorkflowDiagram::make()]),
            Section::make('History')->schema([WorkflowTimeline::make()]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number'),
                WorkflowStateColumn::make()->showPendingApproval(),
            ])
            ->filters([WorkflowStateFilter::make()])
            ->recordActions([WorkflowActions::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => InvoiceResource\Pages\ListInvoices::route('/'),
            'view' => InvoiceResource\Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
