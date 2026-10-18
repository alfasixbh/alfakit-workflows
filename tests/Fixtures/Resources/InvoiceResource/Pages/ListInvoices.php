<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitWorkflows\Tests\Fixtures\Resources\InvoiceResource;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
