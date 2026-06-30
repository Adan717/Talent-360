<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Services\Billing\BillingProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    protected BillingProviderInterface $billingProvider;

    public function __construct(BillingProviderInterface $billingProvider)
    {
        $this->billingProvider = $billingProvider;
    }

    /**
     * Handle incoming Stripe Webhook.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = env('STRIPE_WEBHOOK_SECRET');

        Log::info("Stripe Webhook received.");

        $event = null;

        // Verify webhook signature if secret is configured
        if ($endpointSecret && $sigHeader) {
            try {
                if (class_exists('\Stripe\Webhook')) {
                    $event = \Stripe\Webhook::constructEvent(
                        $payload, $sigHeader, $endpointSecret
                    );
                } else {
                    Log::warning("Stripe SDK not loaded. Decoding payload directly.");
                    $event = json_decode($payload, true);
                }
            } catch (\Exception $e) {
                Log::error("Stripe Webhook signature verification failed: " . $e->getMessage());
                return response()->json(['error' => 'Invalid signature'], 400);
            }
        } else {
            // For testing/sandbox/simulator without signature
            Log::info("Skipping Stripe signature verification (sandbox/local mode).");
            $event = json_decode($payload, true);
        }

        if (!$event) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $eventType = is_array($event) ? ($event['type'] ?? '') : $event->type;
        $eventData = is_array($event) ? ($event['data']['object'] ?? []) : $event->data->object;

        Log::info("Processing Stripe event type: {$eventType}");

        switch ($eventType) {
            case 'invoice.payment_succeeded':
            case 'charge.succeeded':
                $this->handlePaymentSucceeded($eventData, $eventType);
                break;
            default:
                Log::info("Unhandled Stripe event type: {$eventType}");
                break;
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle payment succeeded event and trigger CFDI 4.0 invoice creation.
     */
    protected function handlePaymentSucceeded($data, string $eventType)
    {
        // Extract Stripe customer ID and subscription ID
        $customerId = $data['customer'] ?? null;
        $subscriptionId = $data['subscription'] ?? null;
        
        // Amount paid is usually in cents
        $amountPaidCents = $data['amount_paid'] ?? ($data['amount'] ?? 0);
        $amountPaid = $amountPaidCents / 100;
        
        $currency = strtoupper($data['currency'] ?? 'mxn');
        
        Log::info("Payment succeeded event: Customer={$customerId}, Subscription={$subscriptionId}, Amount={$amountPaid} {$currency}");

        if (!$customerId) {
            Log::error("Stripe event data does not contain a customer ID. Cannot proceed.");
            return;
        }

        // Find tenant by stripe_customer_id or stripe_subscription_id
        $tenant = Tenant::where('stripe_customer_id', $customerId)
            ->orWhere('stripe_subscription_id', $subscriptionId)
            ->first();

        // Fallback: search by email inside metadata or customer details
        if (!$tenant) {
            $customerEmail = $data['customer_email'] ?? ($data['billing_details']['email'] ?? null);
            if ($customerEmail) {
                // Find admin user with that email
                $adminUser = DB::table('users')
                    ->where('email', strtolower($customerEmail))
                    ->where('role', 'admin')
                    ->first();
                
                if ($adminUser) {
                    $tenant = Tenant::find($adminUser->tenant_id);
                }
            }
        }

        if (!$tenant) {
            Log::error("No Tenant found matching Stripe Customer ID: {$customerId} or Subscription ID: {$subscriptionId}");
            return;
        }

        // Provision/upgrade the tenant's plan status based on payment
        DB::transaction(function() use ($tenant, $subscriptionId) {
            $tenant->update([
                'subscription_status' => 'active',
                'stripe_subscription_id' => $subscriptionId ?? $tenant->stripe_subscription_id,
                'current_period_end' => now()->addMonth()
            ]);
        });

        // Trigger CFDI 4.0 timbrado for this SaaS subscription
        $adminEmail = DB::table('users')
            ->where('tenant_id', $tenant->id)
            ->where('role', 'admin')
            ->value('email') ?? 'billing@' . $tenant->subdomain . '.com';

        // Construct CFDI 4.0 structure
        $invoiceData = [
            'customer' => [
                'legal_name' => $tenant->tax_name ?? $tenant->name,
                'rfc' => $tenant->rfc ?? 'XAXX010101000', // General test RFC if none set
                'tax_system' => $tenant->tax_regimen ?? '601',
                'email' => $adminEmail,
                'address' => [
                    'zip' => $tenant->postal_code ?? '01000'
                ]
            ],
            'items' => [
                [
                    'quantity' => 1,
                    'product' => [
                        'description' => "Suscripción mensual Plataforma Talent360 - Plan " . strtoupper($tenant->plan ?? 'PRO'),
                        'product_key' => '84111506', // Servicios de facturación de software SAT key
                        'price' => $amountPaid > 0 ? $amountPaid : 199.00,
                        'tax_included' => true,
                        'taxes' => [
                            [
                                'municipality' => 'IVA',
                                'rate' => 0.16,
                                'type' => 'transfer'
                            ]
                        ]
                    ]
                ]
            ],
            'payment_form' => '03', // Transferencia electrónica de fondos
            'payment_method' => 'PUE',
            'use' => 'G03' // Gastos en general
        ];

        Log::info("Triggering SAT CFDI 4.0 invoice creation for Tenant ID: {$tenant->id} via Facturapi.");

        // Call the abstract Billing Provider (does not set tenant context, so it bills from platform account to customer/tenant)
        $result = $this->billingProvider->createInvoice($invoiceData);

        if ($result['success']) {
            Log::info("SAT CFDI 4.0 Timbrado Successful! Invoice ID: {$result['id']}, UUID: {$result['uuid']}");

            // Log event in saas_audit_logs
            DB::table('saas_audit_logs')->insert([
                'tenant_id' => $tenant->id,
                'event_type' => 'stripe.payment_succeeded_facturapi_timbrado',
                'description' => "Pago Stripe de $" . $amountPaid . " " . $currency . " procesado con éxito. Factura CFDI 4.0 timbrada automáticamente con UUID: " . $result['uuid'] . ". PDF: " . $result['pdf_url'],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            Log::error("SAT CFDI 4.0 Timbrado Failed: " . ($result['error'] ?? 'Unknown error'));

            // Log failure event in saas_audit_logs
            DB::table('saas_audit_logs')->insert([
                'tenant_id' => $tenant->id,
                'event_type' => 'stripe.payment_succeeded_facturapi_failed',
                'description' => "Pago Stripe de $" . $amountPaid . " " . $currency . " procesado con éxito, pero falló el timbrado automático CFDI: " . ($result['error'] ?? 'Error desconocido de Facturapi.'),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}
