<?php

namespace App\Services\Accounting;

use App\Models\Booking;
use App\Models\IntegrationSetting;
use App\Models\InvoiceExport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use App\Models\CheckoutPayment;
use RuntimeException;
use Throwable;

class AccountingExportService
{
    public function createFromBooking(Booking $booking): InvoiceExport
    {
        $booking->loadMissing(['customer', 'services']);
        $total = (int) ($booking->agreed_price_cents ?? 0);
        $lines = $booking->services->isNotEmpty() ? $booking->services->map(function ($service) {
            $gross = (int)$service->pivot->price_cents; $rate = (float)$service->vat_rate;
            return ['description'=>$service->pivot->service_name ?: $service->name,'quantity'=>1,'unit_price_cents'=>$gross,
                'vat_rate'=>$rate,'total_cents'=>$gross];
        })->values()->all() : [['description'=>$booking->service_name,'quantity'=>1,'unit_price_cents'=>$total,'vat_rate'=>25,'total_cents'=>$total]];
        $lineTotal = (int) collect($lines)->sum('total_cents');
        // Tjenestelinjene er fasiten for eksporten. Dette hindrer differanser hvis
        // bookingens sammendragspris er blitt utdatert.
        if ($lineTotal > 0) $total = $lineTotal;
        $subtotal = (int) collect($lines)->sum(fn($line) => round($line['total_cents'] / (1 + $line['vat_rate'] / 100)));
        $attributes = [
            'public_id' => (string) Str::uuid(), 'organization_id' => $booking->organization_id,
            'customer_id' => $booking->customer_id, 'reference' => 'F-'.$booking->reference,
            'customer_snapshot' => $booking->customer->only(['customer_number','name','email','phone','organization_number','address','postal_code','city']),
            'lines' => $lines,
            'subtotal_cents' => $subtotal, 'vat_cents' => $total - $subtotal, 'total_cents' => $total,
        ];
        $invoice = InvoiceExport::firstOrCreate(['booking_id' => $booking->id], $attributes);
        $reactivated = $invoice->status === 'cancelled';
        if ($reactivated) $invoice->update([...$attributes, 'public_id'=>$invoice->public_id, 'status'=>'ready', 'provider'=>null, 'queued_at'=>null, 'failed_at'=>null, 'last_error'=>null]);

        $zettleCheckout = IntegrationSetting::where('organization_id',$booking->organization_id)->where('provider','sales_zettle')->where('active',true)->exists();
        if ($zettleCheckout && Schema::hasTable('checkout_payments')) {
            CheckoutPayment::firstOrCreate(['booking_id'=>$booking->id,'invoice_export_id'=>$invoice->id],['public_id'=>(string)Str::uuid(),'organization_id'=>$booking->organization_id,'amount_cents'=>$invoice->total_cents,'terminal_reference'=>'DP-'.Str::upper(Str::random(18)),'lookup_token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addHours(24)]);
        }
        if (!$zettleCheckout && ($invoice->wasRecentlyCreated || $reactivated) && ($connection = $this->activeConnection($booking->organization_id)) && $this->credentials($connection)['auto_export'] === true) {
            $this->queue($invoice, $this->providerName($connection));
        }
        return $invoice;
    }

    public function queue(InvoiceExport $invoice, string $provider): void
    {
        if ($invoice->status === 'exported') return;
        $invoice->update([
            'status' => 'queued',
            'provider' => $provider,
            'request_key' => $invoice->provider === $provider && $invoice->request_key ? $invoice->request_key : (string) Str::uuid(),
            'queued_at' => now(),
            'failed_at' => null,
            'last_error' => null,
        ]);
    }

    public function processQueued(InvoiceExport $invoice): bool
    {
        if (!InvoiceExport::whereKey($invoice->id)->where('status', 'queued')->update(['status' => 'processing'])) return false;
        $invoice->refresh();
        try {
            $connection = $this->activeConnection($invoice->organization_id);
            if (!$connection) throw new RuntimeException('Ingen aktiv regnskapskobling.');
            $provider = $this->providerName($connection);
            if ($invoice->provider && $invoice->provider !== $provider && $invoice->external_order_id) {
                throw new RuntimeException('Eksporten har allerede en ordre hos '.$invoice->provider.'.');
            }
            if (!$invoice->request_key) $invoice->update(['provider'=>$provider,'request_key'=>(string)Str::uuid()]);
            $externalId = $this->exporter($provider)->export($invoice, $this->credentials($connection));
            $invoice->update(['status'=>'exported','provider'=>$provider,'external_id'=>$externalId,'attempts'=>$invoice->attempts+1,'exported_at'=>now(),'last_error'=>null]);
            $this->audit($invoice, 'accounting.export.succeeded', ['provider'=>$provider,'external_id'=>$externalId]);
            return true;
        } catch (Throwable $exception) {
            report($exception);
            $invoice->update(['status'=>'failed','attempts'=>$invoice->attempts+1,'failed_at'=>now(),'last_error'=>mb_substr($exception->getMessage(),0,2000)]);
            $this->audit($invoice, 'accounting.export.failed', ['error'=>mb_substr($exception->getMessage(),0,500)]);
            return false;
        }
    }

    private function audit(InvoiceExport $invoice, string $action, array $metadata): void
    {
        DB::table('audit_logs')->insert(['organization_id'=>$invoice->organization_id,'action'=>$action,'subject_type'=>InvoiceExport::class,'subject_id'=>$invoice->id,'metadata'=>json_encode($metadata),'created_at'=>now()]);
    }

    public function activeConnection(int $organizationId): ?IntegrationSetting
    {
        return IntegrationSetting::where('organization_id', $organizationId)->whereIn('provider', ['accounting_fiken','accounting_tripletex','accounting_poweroffice'])->where('active', true)->first();
    }

    public function credentials(IntegrationSetting $setting): array
    {
        $credentials=json_decode(Crypt::decryptString($setting->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR);
        return $setting->provider==='accounting_fiken' ? app(FikenOAuthService::class)->refresh($setting,$credentials) : $credentials;
    }

    public function providerName(IntegrationSetting $setting): string
    {
        return str_replace('accounting_', '', $setting->provider);
    }

    public function exporter(string $provider): AccountingExporter
    {
        return match ($provider) {
            'fiken' => app(FikenExporter::class),
            'tripletex' => app(TripletexExporter::class),
            'poweroffice' => app(PowerOfficeExporter::class),
            default => throw new RuntimeException('Ukjent regnskapsleverandør.'),
        };
    }
}
