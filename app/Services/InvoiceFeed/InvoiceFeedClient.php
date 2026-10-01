<?php

namespace App\Services\InvoiceFeed;

use App\Exceptions\InvoiceFeedException;
use App\Models\Package;
use App\Models\ShippingRate;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the InvoiceFeed billing API.
 */
class InvoiceFeedClient
{
    /**
     * @param  array<string, mixed>  $customerData
     * @return array<string, mixed>
     */
    public function createClient(array $customerData): array
    {
        return $this->request('post', '/clients', $customerData);
    }

    /**
     * @return array<string, mixed>
     */
    public function createInvoiceForPackage(Package $package): array
    {
        $package->loadMissing('user.customerProfile');

        $payload = [
            'external_reference' => $this->externalReference($package),
            'client' => $this->clientPayload($package),
            'currency' => config('shipdjm.currency', 'JMD'),
            'items' => $this->invoiceItems($package),
            'notes' => "Ship'd JM package {$package->package_reference}",
            'metadata' => [
                'package_id' => $package->id,
                'package_reference' => $package->package_reference,
                'tracking_number' => $package->tracking_number,
            ],
        ];

        return $this->request('post', '/invoices', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendInvoice(string|int $invoiceId): array
    {
        return $this->request('post', "/invoices/{$invoiceId}/send");
    }

    /**
     * @return array<string, mixed>
     */
    public function createPaymentLink(string|int $invoiceId): array
    {
        return $this->request('post', "/invoices/{$invoiceId}/payment-link");
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoiceStatus(string|int $invoiceId): array
    {
        return $this->request('get', "/invoices/{$invoiceId}");
    }

    public function isEnabled(): bool
    {
        return (bool) config('invoicefeed.enabled')
            && filled(config('invoicefeed.api_token'));
    }

    public function externalReference(Package $package): string
    {
        return 'SHIPDJM-PKG-'.$package->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientPayload(Package $package): array
    {
        $user = $package->user;
        $profile = $user?->customerProfile;

        return [
            'name' => $user?->name,
            'email' => $user?->email,
            'phone' => $profile?->phone ?? $profile?->whatsapp_number,
            'address' => $profile?->jamaica_address,
            'parish' => $profile?->parish,
            'external_reference' => $user?->customerReference(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceItems(Package $package): array
    {
        $amountDue = round((float) $package->amount_due, 2);

        if ($amountDue <= 0) {
            return [[
                'description' => "Package charges · {$package->package_reference}",
                'quantity' => 1,
                'unit_price' => 0,
            ]];
        }

        $weight = $package->weight_lbs !== null ? (float) $package->weight_lbs : null;
        $items = [];

        if ($weight !== null && $weight > 0) {
            $rate = ShippingRate::forWeight($weight);
            $perLb = $rate
                ? (float) $rate->rate_per_lb
                : (float) config('shipdjm.default_rate_per_lb', 500);
            $minimum = $rate ? (float) $rate->minimum_charge : $perLb;
            $shippingCharge = round(max($minimum, $weight * $perLb), 2);

            $items[] = [
                'description' => "Shipping charge - {$weight} lbs",
                'quantity' => 1,
                'unit_price' => $shippingCharge,
            ];

            if ($rate && $rate->handling_fee) {
                $items[] = [
                    'description' => 'Handling fee',
                    'quantity' => 1,
                    'unit_price' => round((float) $rate->handling_fee, 2),
                ];
            }
        }

        $itemTotal = round(collect($items)->sum(fn (array $item) => (float) $item['unit_price']), 2);

        if ($items === [] || abs($itemTotal - $amountDue) >= 0.01) {
            return [[
                'description' => "Package charges · {$package->package_reference}",
                'quantity' => 1,
                'unit_price' => $amountDue,
            ]];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        if (! config('invoicefeed.enabled')) {
            throw new InvoiceFeedException(
                'InvoiceFeed integration is disabled.',
                'Billing is not enabled. Contact your administrator.',
            );
        }

        $token = config('invoicefeed.api_token');

        if (! filled($token)) {
            throw new InvoiceFeedException(
                'InvoiceFeed API token is missing.',
                'Billing is not configured yet. Add your InvoiceFeed API token.',
            );
        }

        $url = config('invoicefeed.api_url').$path;

        try {
            $pending = Http::withToken($token)
                ->acceptJson()
                ->timeout(30);

            if (app()->environment('local')) {
                $pending = $pending->withoutVerifying();
            }

            $response = match (strtolower($method)) {
                'get' => $pending->get($url),
                'post' => $pending->post($url, $payload),
                'put' => $pending->put($url, $payload),
                'patch' => $pending->patch($url, $payload),
                default => throw new InvoiceFeedException("Unsupported HTTP method: {$method}"),
            };

            $response->throw();

            $body = $response->json();

            return is_array($body) ? $body : ['data' => $body];
        } catch (RequestException $exception) {
            $responseBody = $exception->response?->json();
            $status = $exception->response?->status();

            Log::error('InvoiceFeed API request failed', [
                'method' => strtoupper($method),
                'url' => $url,
                'status' => $status,
                'response' => $responseBody ?? $exception->response?->body(),
                'payload' => $payload,
            ]);

            $message = $this->extractErrorMessage($responseBody);

            throw new InvoiceFeedException(
                $message,
                $this->userFacingErrorMessage($message, $status),
                [
                    'status' => $status,
                    'response' => $responseBody,
                ],
            );
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            Log::error('InvoiceFeed API connection failed', [
                'method' => strtoupper($method),
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            throw new InvoiceFeedException(
                $exception->getMessage(),
                'Could not reach InvoiceFeed. Check INVOICEFEED_API_URL and server connectivity.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    private function extractErrorMessage(?array $responseBody): string
    {
        if (! is_array($responseBody)) {
            return 'InvoiceFeed request failed.';
        }

        if (filled($responseBody['message'] ?? null)) {
            return (string) $responseBody['message'];
        }

        if (filled($responseBody['error'] ?? null)) {
            return (string) $responseBody['error'];
        }

        if (isset($responseBody['errors']) && is_array($responseBody['errors'])) {
            $first = collect($responseBody['errors'])->flatten()->first();

            if (filled($first)) {
                return (string) $first;
            }
        }

        return 'InvoiceFeed request failed.';
    }

    private function userFacingErrorMessage(string $message, ?int $status): string
    {
        if ($message === 'InvoiceFeed request failed.') {
            return 'We could not complete that billing action. Please try again or check the logs.';
        }

        $prefix = $status !== null ? "InvoiceFeed ({$status}): " : 'InvoiceFeed: ';

        return $prefix.$message;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractInvoiceId(array $response): ?string
    {
        $value = $this->extract($response, ['invoice_id', 'id']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractInvoiceNumber(array $response): ?string
    {
        $value = $this->extract($response, ['invoice_number', 'number']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractInvoiceUrl(array $response): ?string
    {
        return $this->extract($response, ['invoice_url', 'url']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractPublicInvoiceUrl(array $response): ?string
    {
        return $this->extract($response, ['public_invoice_url', 'public_url']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractPaymentUrl(array $response): ?string
    {
        return $this->extract($response, ['payment_url', 'payment_link', 'url']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractStatus(array $response): ?string
    {
        $value = $this->extract($response, ['status', 'payment_status', 'invoice_status']);

        return $value !== null ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractTotal(array $response): ?float
    {
        $value = $this->extract($response, ['total', 'amount_due', 'amount']);

        return $value !== null ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $keys
     */
    private function extract(array $response, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $response) && $response[$key] !== null && $response[$key] !== '') {
                return $response[$key];
            }
        }

        if (isset($response['data']) && is_array($response['data'])) {
            return $this->extract($response['data'], $keys, $default);
        }

        if (isset($response['invoice']) && is_array($response['invoice'])) {
            return $this->extract($response['invoice'], $keys, $default);
        }

        return $default;
    }
}
