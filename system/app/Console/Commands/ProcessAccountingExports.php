<?php

namespace App\Console\Commands;

use App\Models\InvoiceExport;
use App\Services\Accounting\AccountingExportService;
use Illuminate\Console\Command;

class ProcessAccountingExports extends Command
{
    protected $signature = 'accounting:process {--limit=20}';
    protected $description = 'Eksporterer fakturagrunnlag i regnskapskøen';

    public function handle(AccountingExportService $service): int
    {
        InvoiceExport::where('status','queued')->oldest('queued_at')->limit((int)$this->option('limit'))->get()->each(fn(InvoiceExport $invoice) => $service->processQueued($invoice));
        return self::SUCCESS;
    }
}
