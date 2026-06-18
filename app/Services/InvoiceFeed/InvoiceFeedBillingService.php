<?php

namespace App\Services\InvoiceFeed;

use App\Enums\BillingStatus;
use App\Enums\PackageStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InvoiceFeedException;
use App\Models\Package;
use App\Support\PackageStatusTimeline;
use Illuminate\Support\Carbon;

/**
 * Orchestrates InvoiceFeed billing actions for packages.
 */
class InvoiceFeedBillingService
{
    public function __construct(
        private readonly InvoiceFeedClient $client,
    ) {}

    public function generateInvoice(Package $package): Package
    {
        if ($package->invoicefeed_invoice_id) {
            throw new InvoiceFeedException(
                'Package already has an InvoiceFeed invoice.',
                'An invoice already exists for this package.',
            );
        }

        $response = $this->client->createInvoiceForPackage($package);

        $package->fill([
            'invoicefeed_invoice_id' => $this->client->extractInvoiceId($response),
            'invoicefeed_invoice_number' => $this->client->extractInvoiceNumber($response),
            'invoicefeed_invoice_url' => $this->client->extractInvoiceUrl($response),
            'invoicefeed_public_invoice_url' => $this->client->extractPublicInvoiceUrl($response),
            'invoicefeed_status' => $this->client->extractStatus($response),
            'invoicefeed_synced_at' => now(),
            'billing_status' => BillingStatus::InvoiceCreated,
        ]);
        $package->save();

        return $package->fresh();
    }

    public function sendInvoice(Package $package): Package
    {
        $invoiceId = $this->requireInvoiceId($package);

        $response = $this->client->sendInvoice($invoiceId);
        $status = strtolower((string) ($this->client->extractStatus($response) ?? ''));

        $billingStatus = in_array($status, ['payment_pending', 'pending', 'awaiting_payment', 'sent'], true)
            ? BillingStatus::PaymentPending
            : BillingStatus::InvoiceSent;

        $package->fill([
            'invoicefeed_status' => $this->client->extractStatus($response) ?? $package->invoicefeed_status,
            'invoicefeed_synced_at' => now(),
            'billing_status' => $billingStatus,
        ]);
        $package->save();

        return $package->fresh();
    }

    public function createPaymentLink(Package $package): Package
    {
        $invoiceId = $this->requireInvoiceId($package);

        $response = $this->client->createPaymentLink($invoiceId);
        $paymentUrl = $this->client->extractPaymentUrl($response);

        $package->fill([
            'invoicefeed_payment_url' => $paymentUrl,
            'invoicefeed_status' => $this->client->extractStatus($response) ?? $package->invoicefeed_status,
            'invoicefeed_synced_at' => now(),
            'billing_status' => BillingStatus::PaymentPending,
        ]);
        $package->save();

        return $package->fresh();
    }

    public function syncPaymentStatus(Package $package): Package
    {
        $invoiceId = $this->requireInvoiceId($package);

        $response = $this->client->getInvoiceStatus($invoiceId);
        $status = strtolower((string) ($this->client->extractStatus($response) ?? ''));

        $package->fill([
            'invoicefeed_status' => $this->client->extractStatus($response) ?? $package->invoicefeed_status,
            'invoicefeed_synced_at' => now(),
        ]);

        if ($this->isPaidStatus($status)) {
            $this->markPackagePaid($package);
        } elseif ($status !== '') {
            $package->billing_status = match (true) {
                in_array($status, ['sent', 'invoice_sent'], true) => BillingStatus::InvoiceSent,
                in_array($status, ['payment_pending', 'pending', 'awaiting_payment'], true) => BillingStatus::PaymentPending,
                in_array($status, ['failed', 'error'], true) => BillingStatus::Failed,
                default => $package->billing_status,
            };
        }

        $package->save();

        return $package->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleInvoicePaidWebhook(array $payload): ?Package
    {
        $package = $this->findPackageFromWebhook($payload);

        if (! $package) {
            return null;
        }

        if ($package->billing_status === BillingStatus::Paid
            && $package->payment_status === PaymentStatus::Paid) {
            return $package;
        }

        $package->invoicefeed_status = 'paid';
        $package->invoicefeed_synced_at = now();

        if ($invoiceNumber = data_get($payload, 'invoice_number')) {
            $package->invoicefeed_invoice_number = (string) $invoiceNumber;
        }

        if ($invoiceId = data_get($payload, 'invoice_id')) {
            $package->invoicefeed_invoice_id = (string) $invoiceId;
        }

        $this->markPackagePaid($package, data_get($payload, 'paid_at'));

        $package->save();

        return $package->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhookPayload(array $payload): ?Package
    {
        return $this->handleInvoicePaidWebhook($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findPackageFromWebhook(array $payload): ?Package
    {
        $externalReference = data_get($payload, 'external_reference')
            ?? data_get($payload, 'metadata.external_reference');
        $invoiceId = data_get($payload, 'invoice_id')
            ?? data_get($payload, 'id');

        if ($externalReference && preg_match('/SHIPDJM-PKG-(\d+)/', (string) $externalReference, $matches)) {
            $package = Package::query()->find((int) $matches[1]);

            if ($package) {
                return $package;
            }
        }

        if ($invoiceId) {
            return Package::query()
                ->where('invoicefeed_invoice_id', (string) $invoiceId)
                ->first();
        }

        return null;
    }

    private function requireInvoiceId(Package $package): string
    {
        if (! $package->invoicefeed_invoice_id) {
            throw new InvoiceFeedException(
                'Package does not have an InvoiceFeed invoice.',
                'Generate an invoice before running this action.',
            );
        }

        return (string) $package->invoicefeed_invoice_id;
    }

    private function isPaidStatus(string $status): bool
    {
        return in_array($status, ['paid', 'complete', 'completed', 'settled'], true);
    }

    private function markPackagePaid(Package $package, mixed $paidAt = null): void
    {
        $package->billing_status = BillingStatus::Paid;
        $package->invoicefeed_status = 'paid';
        $package->payment_status = PaymentStatus::Paid;
        $package->payment_method = PaymentMethod::Online;
        $package->paid_at = $package->paid_at ?? ($paidAt ? Carbon::parse($paidAt) : now());

        if (in_array($package->status, [
            PackageStatus::ArrivedInJamaica,
            PackageStatus::CustomsProcessing,
        ], true)) {
            PackageStatusTimeline::apply($package, PackageStatus::ReadyForPickup);
            $package->status = PackageStatus::ReadyForPickup;
        }
    }
}
