<?php
namespace App\Services\Accounting;use App\Models\InvoiceExport;
interface AccountingExporter{public function test(array $credentials):array;public function export(InvoiceExport $invoice,array $credentials):string;}
