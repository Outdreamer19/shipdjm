<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingStatus;
use App\Enums\Carrier;
use App\Enums\PackageStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PreAlertStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageRequest;
use App\Models\ActivityLog;
use App\Models\AuthorisedPickupPerson;
use App\Models\Package;
use App\Models\PreAlert;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InvoiceFeed\InvoiceFeedClient;
use App\Services\PackageChargeCalculator;
use App\Services\PackageReferenceGenerator;
use App\Services\PackageStatusRecorder;
use App\Support\PackageStatusTimeline;
use App\Support\WhatsappLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Package::class);

        $status = $request->string('status')->toString();
        $paymentStatus = $request->string('payment_status')->toString();
        $search = $request->string('search')->trim()->value();

        $packages = Package::query()
            ->with(['user:id,name', 'user.customerProfile:user_id,customer_reference'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($paymentStatus, fn ($q) => $q->where('payment_status', $paymentStatus))
            ->when($search, function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('package_reference', 'like', "%{$search}%")
                        ->orWhere('tracking_number', 'like', "%{$search}%")
                        ->orWhere('merchant_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Package $package) => [
                'id' => $package->id,
                'package_reference' => $package->package_reference,
                'merchant_name' => $package->merchant_name,
                'status' => $package->status->value,
                'status_label' => $package->status->label(),
                'payment_status' => $package->payment_status->value,
                'payment_status_label' => $package->payment_status->label(),
                'amount_due' => (float) $package->amount_due,
                'customer_name' => $package->user?->name,
                'customer_reference' => $package->user?->customerProfile?->customer_reference,
            ]);

        return Inertia::render('admin/packages/Index', [
            'packages' => $packages,
            'filters' => [
                'status' => $status ?: null,
                'payment_status' => $paymentStatus ?: null,
                'search' => $search,
            ],
            'statuses' => collect(PackageStatus::cases())->mapWithKeys(
                fn (PackageStatus $s) => [$s->value => $s->label()],
            ),
            'paymentStatuses' => collect(PaymentStatus::cases())->mapWithKeys(
                fn (PaymentStatus $s) => [$s->value => $s->label()],
            ),
            'currency' => config('shipdjm.currency'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Package::class);

        $customers = User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->with('customerProfile')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'label' => "{$user->name} ({$user->customerProfile?->customer_reference})",
            ]);

        $preAlerts = PreAlert::query()
            ->when(
                $request->filled('user_id'),
                fn ($q) => $q->where('user_id', $request->integer('user_id')),
            )
            ->latest()
            ->limit(50)
            ->get(['id', 'user_id', 'merchant_name', 'tracking_number'])
            ->map(fn (PreAlert $pa) => [
                'id' => $pa->id,
                'user_id' => $pa->user_id,
                'label' => "{$pa->merchant_name} · ".($pa->tracking_number ?? 'no tracking'),
            ]);

        return Inertia::render('admin/packages/Create', [
            'customers' => $customers,
            'preAlerts' => $preAlerts,
            'carriers' => Carrier::options(),
            'statuses' => collect(PackageStatus::cases())->mapWithKeys(
                fn (PackageStatus $s) => [$s->value => $s->label()],
            ),
            'paymentStatuses' => collect(PaymentStatus::cases())->mapWithKeys(
                fn (PaymentStatus $s) => [$s->value => $s->label()],
            ),
            'defaultUserId' => $request->integer('user_id') ?: null,
            'defaultPreAlertId' => $request->integer('pre_alert_id') ?: null,
            'currency' => config('shipdjm.currency'),
        ]);
    }

    public function store(
        StorePackageRequest $request,
        PackageReferenceGenerator $references,
        PackageChargeCalculator $calculator,
        PackageStatusRecorder $statusRecorder,
    ): RedirectResponse {
        $this->authorize('create', Package::class);

        $data = $request->safe()->except(['auto_calculate_amount']);
        $data['package_reference'] = $references->next();

        $weight = isset($data['weight_lbs']) ? (float) $data['weight_lbs'] : null;

        if ($request->boolean('auto_calculate_amount', true) && $weight) {
            $data['amount_due'] = $calculator->calculate($weight);
        }

        $package = Package::create($data);
        PackageStatusTimeline::apply($package, $package->status);
        $package->save();

        $statusRecorder->record(
            $package,
            null,
            $package->status,
            $request->user(),
            'Package created by admin',
        );

        if ($package->pre_alert_id) {
            PreAlert::query()
                ->whereKey($package->pre_alert_id)
                ->update(['status' => PreAlertStatus::MatchedToPackage]);
        }

        $this->activityLogger->log(
            ActivityLog::ACTION_PACKAGE_CREATED,
            "{$request->user()->name} created package {$package->package_reference}.",
            $request->user(),
            $package,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Package created.']);

        return to_route('admin.packages.edit', ['package' => $package]);
    }

    public function edit(Package $package): Response
    {
        $this->authorize('update', $package);

        $package->load(['user.customerProfile.authorisedPickupPeople', 'preAlert', 'statusHistories.changedBy']);

        $customers = User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->with('customerProfile')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'label' => "{$user->name} ({$user->customerProfile?->customer_reference})",
            ]);

        $preAlerts = PreAlert::query()
            ->where('user_id', $package->user_id)
            ->latest()
            ->get(['id', 'merchant_name', 'tracking_number'])
            ->map(fn (PreAlert $pa) => [
                'id' => $pa->id,
                'label' => "{$pa->merchant_name} · ".($pa->tracking_number ?? 'no tracking'),
            ]);

        return Inertia::render('admin/packages/Edit', [
            'package' => $this->packagePayload($package),
            'customers' => $customers,
            'preAlerts' => $preAlerts,
            'carriers' => Carrier::options(),
            'statuses' => collect(PackageStatus::cases())->mapWithKeys(
                fn (PackageStatus $s) => [$s->value => $s->label()],
            ),
            'paymentStatuses' => collect(PaymentStatus::cases())->mapWithKeys(
                fn (PaymentStatus $s) => [$s->value => $s->label()],
            ),
            'paymentMethods' => collect(PaymentMethod::cases())->mapWithKeys(
                fn (PaymentMethod $m) => [$m->value => $m->label()],
            ),
            'currency' => config('shipdjm.currency'),
            'invoiceFeedEnabled' => app(InvoiceFeedClient::class)->isEnabled(),
            'billingRoutes' => [
                'generateInvoice' => route('admin.packages.billing.generate-invoice', $package),
                'sendInvoice' => route('admin.packages.billing.send-invoice', $package),
                'paymentLink' => route('admin.packages.billing.payment-link', $package),
                'syncPayment' => route('admin.packages.billing.sync-payment', $package),
            ],
        ]);
    }

    public function update(
        UpdatePackageRequest $request,
        Package $package,
        PackageChargeCalculator $calculator,
        PackageStatusRecorder $statusRecorder,
    ): RedirectResponse {
        $this->authorize('update', $package);

        $data = $request->safe()->except(['auto_calculate_amount', 'payment_notes']);
        $previousPaymentStatus = $package->payment_status;
        $oldStatus = $package->status;

        $weight = isset($data['weight_lbs']) ? (float) $data['weight_lbs'] : null;

        if ($request->boolean('auto_calculate_amount') && $weight) {
            $data['amount_due'] = $calculator->calculate($weight);
        }

        $newStatus = PackageStatus::from($data['status']);
        PackageStatusTimeline::apply($package, $newStatus);

        if (
            PaymentStatus::from($data['payment_status']) === PaymentStatus::Paid
            && $previousPaymentStatus !== PaymentStatus::Paid
        ) {
            $data['paid_at'] = now();

            if (empty($data['payment_method'])) {
                $data['payment_method'] = PaymentMethod::Manual;
            }
        }

        if ($request->filled('payment_notes')) {
            $note = '['.now()->toDateTimeString().'] Payment: '.$request->string('payment_notes');
            $data['admin_notes'] = trim(($package->admin_notes ?? '')."\n\n".$note);
        }

        $package->fill($data);
        $package->save();

        $statusRecorder->record(
            $package,
            $oldStatus,
            $package->status,
            $request->user(),
        );

        if ($oldStatus !== $package->status) {
            $this->activityLogger->log(
                ActivityLog::ACTION_PACKAGE_STATUS_CHANGED,
                "{$request->user()->name} changed package {$package->package_reference} status from {$oldStatus->label()} to {$package->status->label()}.",
                $request->user(),
                $package,
                ['old_status' => $oldStatus->value, 'new_status' => $package->status->value],
            );
        } else {
            $this->activityLogger->log(
                ActivityLog::ACTION_PACKAGE_UPDATED,
                "{$request->user()->name} updated package {$package->package_reference}.",
                $request->user(),
                $package,
            );
        }

        if ($package->pre_alert_id) {
            PreAlert::query()
                ->whereKey($package->pre_alert_id)
                ->update(['status' => PreAlertStatus::MatchedToPackage]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Package updated.']);

        return to_route('admin.packages.edit', ['package' => $package]);
    }

    /**
     * @return array<string, mixed>
     */
    private function packagePayload(Package $package): array
    {
        return [
            'id' => $package->id,
            'user_id' => $package->user_id,
            'pre_alert_id' => $package->pre_alert_id,
            'package_reference' => $package->package_reference,
            'tracking_number' => $package->tracking_number,
            'merchant_name' => $package->merchant_name,
            'carrier' => $package->carrier?->value,
            'weight_lbs' => $package->weight_lbs !== null ? (float) $package->weight_lbs : null,
            'declared_value' => $package->declared_value !== null ? (float) $package->declared_value : null,
            'amount_due' => (float) $package->amount_due,
            'billing_status' => $package->billing_status?->value ?? BillingStatus::NotInvoiced->value,
            'billing_status_label' => ($package->billing_status ?? BillingStatus::NotInvoiced)->label(),
            'invoicefeed_invoice_number' => $package->invoicefeed_invoice_number,
            'invoicefeed_invoice_url' => $package->invoicefeed_invoice_url,
            'invoicefeed_public_invoice_url' => $package->invoicefeed_public_invoice_url,
            'invoicefeed_payment_url' => $package->invoicefeed_payment_url,
            'invoicefeed_status' => $package->invoicefeed_status,
            'invoicefeed_synced_at' => $package->invoicefeed_synced_at?->toIso8601String(),
            'has_invoice' => filled($package->invoicefeed_invoice_id),
            'status' => $package->status->value,
            'payment_status' => $package->payment_status->value,
            'payment_method' => $package->payment_method?->value,
            'paid_at' => $package->paid_at?->toIso8601String(),
            'admin_notes' => $package->admin_notes,
            'customer_visible_notes' => $package->customer_visible_notes,
            'customer_name' => $package->user?->name,
            'customer_reference' => $package->user?->customerProfile?->customer_reference,
            'authorised_pickup_people' => $package->user?->customerProfile?->authorisedPickupPeople
                ->map(fn (AuthorisedPickupPerson $person) => $person->toSummaryArray())
                ->values()
                ->all() ?? [],
            'whatsapp_url' => WhatsappLink::forPhone(
                $package->user?->customerProfile?->whatsapp_number
                    ?? $package->user?->customerProfile?->phone,
                "Hi, this is Ship'd JM regarding package ".$package->package_reference.'.',
            ),
            'status_history' => $package->relationLoaded('statusHistories')
                ? $package->statusHistories->map(fn ($entry) => [
                    'id' => $entry->id,
                    'old_status_label' => $entry->oldStatusLabel(),
                    'new_status_label' => $entry->new_status->label(),
                    'changed_by' => $entry->changedBy?->name,
                    'notes' => $entry->notes,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])
                : [],
        ];
    }
}
