<?php
namespace App\Console\Commands;

use App\Models\CheckoutPayment;
use App\Services\Accounting\AccountingExportService;
use App\Services\MerchantStripeService;
use Illuminate\Console\Command;

class InvoiceExpiredCheckouts extends Command
{
    protected $signature='checkout:invoice-expired {--limit=100}';
    protected $description='Sender ubetalte utsjekkinger til regnskap ved dagsavslutning';

    public function handle(AccountingExportService $accounting,MerchantStripeService $stripe): int
    {
        $items=CheckoutPayment::whereIn('status',['pending','processing','failed'])->whereNull('invoiced_at')->where('expires_at','<=',now())->oldest('expires_at')->limit((int)$this->option('limit'))->get();
        foreach($items as $payment) {
            try {
                $stripe->locked($payment,function(CheckoutPayment $payment) use($stripe,$accounting) {
                    if ($payment->status==='paid' || $payment->invoiced_at) return;
                    // A live Stripe session must be settled or expired before invoicing elsewhere.
                    if ($payment->stripe_checkout_key) $payment=$stripe->cancel($payment);
                    if ($payment->status==='paid') return;
                    // Staff must resolve a card-reader payment before an invoice can be sent.
                    if ($payment->payment_method==='zettle' && $payment->status==='processing') {
                        $payment->update(['last_error'=>'Zettle-betalingen må avklares av en ansatt før fakturering.']); return;
                    }
                    $connection=$accounting->activeConnection($payment->organization_id);
                    if (!$connection) {$payment->update(['status'=>'failed','last_error'=>'Dagsavslutning: Ingen aktiv regnskapskobling.']);return;}
                    $invoice=$payment->invoiceExport;
                    if ($invoice->status==='exported') {$payment->update(['status'=>'expired','provider_status'=>'INVOICED','invoiced_at'=>now()]);return;}
                    $accounting->queue($invoice,$accounting->providerName($connection));
                    if ($accounting->processQueued($invoice)) $payment->update(['status'=>'expired','provider_status'=>'INVOICED','invoiced_at'=>now(),'last_error'=>null]);
                    else $payment->update(['status'=>'failed','last_error'=>$invoice->fresh()->last_error]);
                });
            } catch (\Throwable) {
                $payment->update(['last_error'=>'Betalingsstatus kunne ikke avklares. Ingen faktura er sendt; kontroller betalingen før nytt forsøk.']);
            }
        }
        $this->info($items->count().' ubetalte betalinger behandlet i dagsavslutningen.');
        return self::SUCCESS;
    }
}
