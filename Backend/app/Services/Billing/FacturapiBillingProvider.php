<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacturapiBillingProvider implements BillingProviderInterface
{
    protected ?Tenant $tenant = null;
    protected string $apiKey;
    protected string $baseUrl = 'https://api.facturapi.com/v1';

    public function __construct()
    {
        // Reads the key from env, defaults to a placeholder sandbox key if not set
        $this->apiKey = env('FACTURAPI_KEY', 'sk_test_default_facturapi_key_talent360');
    }

    /**
     * Set the target tenant context for fiscal operations.
     */
    public function forTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    /**
     * Helper to get headers for the HTTP request.
     * If tenant context is active, includes the Facturapi-Organization header.
     */
    protected function getHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($this->tenant) {
            // Ensure tenant has an organization in Facturapi
            $orgId = $this->getOrCreateOrganization();
            if ($orgId) {
                $headers['Facturapi-Organization'] = $orgId;
            }
        }

        return $headers;
    }

    /**
     * Get or create a Facturapi Organization for the current tenant context.
     */
    protected function getOrCreateOrganization(): ?string
    {
        if (!$this->tenant) {
            return null;
        }

        if ($this->tenant->facturapi_organization_id) {
            return $this->tenant->facturapi_organization_id;
        }

        // If no organization ID exists, we register it on Facturapi
        try {
            $taxRegimen = $this->tenant->tax_regimen ?? '601'; // Default: General de Ley Personas Morales
            $rfc = $this->tenant->rfc ?? 'EKU9003173C9'; // Default: SAT test RFC

            Log::info("Creating Facturapi Organization for Tenant: {$this->tenant->name} (RFC: {$rfc})");

            $response = Http::withBasicAuth($this->apiKey, '')
                ->post("{$this->baseUrl}/organizations", [
                    'name' => $this->tenant->name,
                    'legal_name' => $this->tenant->tax_name ?? $this->tenant->name,
                    'rfc' => $rfc,
                    'tax_system' => $taxRegimen,
                ]);

            if ($response->successful()) {
                $orgData = $response->json();
                $orgId = $orgData['id'];

                // Update tenant in Postgres
                $this->tenant->update([
                    'facturapi_organization_id' => $orgId
                ]);

                Log::info("Facturapi Organization created successfully with ID: {$orgId}");

                // Proactively upload CSD if they exist
                $this->uploadCsdToOrganization($orgId);

                return $orgId;
            } else {
                Log::error("Failed to create Facturapi Organization: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Exception in getOrCreateOrganization: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Upload CSD certificates (decrypted from Postgres) to the tenant's Facturapi organization.
     */
    protected function uploadCsdToOrganization(string $orgId): bool
    {
        if (!$this->tenant || !$this->tenant->csd_certificate || !$this->tenant->csd_private_key) {
            Log::info("Skipping CSD upload: No certificates stored for Tenant ID: {$this->tenant->id}");
            return false;
        }

        try {
            Log::info("Uploading CSD keys to Facturapi Organization: {$orgId}");

            // Decode base64 certificate and private key from encrypted database fields
            $cerContent = base64_decode($this->tenant->csd_certificate);
            $keyContent = base64_decode($this->tenant->csd_private_key);
            $password = $this->tenant->csd_password ?? '';

            $response = Http::withBasicAuth($this->apiKey, '')
                ->attach('cer', $cerContent, 'certificate.cer')
                ->attach('key', $keyContent, 'private_key.key')
                ->post("{$this->baseUrl}/organizations/{$orgId}/csd", [
                    'password' => $password
                ]);

            if ($response->successful()) {
                Log::info("CSD keys uploaded successfully to Facturapi for Tenant ID: {$this->tenant->id}");
                return true;
            } else {
                Log::error("Failed to upload CSD keys to Facturapi: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Exception in uploadCsdToOrganization: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Create/Timbrar a CFDI 4.0 invoice.
     */
    public function createInvoice(array $data): array
    {
        Log::info("Facturapi: Creating invoice. Context Tenant: " . ($this->tenant ? $this->tenant->name : 'Platform/Self'));

        // Default SAT CFDI 4.0 requirements for invoice
        if (!isset($data['type'])) {
            $data['type'] = 'invoice';
        }

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->withBasicAuth($this->apiKey, '')
                ->post("{$this->baseUrl}/invoices", $data);

            if ($response->successful()) {
                $invoice = $response->json();
                Log::info("Facturapi: Invoice created successfully. ID: {$invoice['id']}, UUID: " . ($invoice['uuid'] ?? 'N/A'));
                return [
                    'success' => true,
                    'id' => $invoice['id'],
                    'uuid' => $invoice['uuid'] ?? 'sandbox-uuid-' . uniqid(),
                    'status' => $invoice['status'] ?? 'valid',
                    'pdf_url' => "{$this->baseUrl}/invoices/{$invoice['id']}/pdf",
                    'xml_url' => "{$this->baseUrl}/invoices/{$invoice['id']}/xml",
                    'raw_data' => $invoice
                ];
            } else {
                $errorMsg = $response->json()['message'] ?? $response->body();
                Log::error("Facturapi Invoice generation failed: {$errorMsg}");
                return [
                    'success' => false,
                    'error' => $errorMsg,
                    'status_code' => $response->status()
                ];
            }
        } catch (\Exception $e) {
            Log::error("Facturapi Invoice exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create/Timbrar a CFDI 4.0 payroll receipt (recibo de nómina).
     */
    public function createPayrollReceipt(array $data): array
    {
        Log::info("Facturapi: Creating payroll receipt. Context Tenant: " . ($this->tenant ? $this->tenant->name : 'Platform/Self'));

        // Force payroll type and attributes
        $data['type'] = 'payroll';

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->withBasicAuth($this->apiKey, '')
                ->post("{$this->baseUrl}/invoices", $data);

            if ($response->successful()) {
                $invoice = $response->json();
                Log::info("Facturapi: Payroll receipt created successfully. ID: {$invoice['id']}, UUID: " . ($invoice['uuid'] ?? 'N/A'));
                return [
                    'success' => true,
                    'id' => $invoice['id'],
                    'uuid' => $invoice['uuid'] ?? 'sandbox-uuid-' . uniqid(),
                    'status' => $invoice['status'] ?? 'valid',
                    'pdf_url' => "{$this->baseUrl}/invoices/{$invoice['id']}/pdf",
                    'xml_url' => "{$this->baseUrl}/invoices/{$invoice['id']}/xml",
                    'raw_data' => $invoice
                ];
            } else {
                $errorMsg = $response->json()['message'] ?? $response->body();
                Log::error("Facturapi Payroll generation failed: {$errorMsg}");
                return [
                    'success' => false,
                    'error' => $errorMsg,
                    'status_code' => $response->status()
                ];
            }
        } catch (\Exception $e) {
            Log::error("Facturapi Payroll exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel a CFDI invoice.
     */
    public function cancelInvoice(string $invoiceId, string $reason, ?string $substitutionUuid = null): array
    {
        Log::info("Facturapi: Cancelling invoice ID: {$invoiceId}, Reason: {$reason}");

        try {
            $params = [
                'motivo' => $reason
            ];
            if ($substitutionUuid) {
                $params['sustituto'] = $substitutionUuid;
            }

            $response = Http::withHeaders($this->getHeaders())
                ->withBasicAuth($this->apiKey, '')
                ->delete("{$this->baseUrl}/invoices/{$invoiceId}", $params);

            if ($response->successful()) {
                $cancelResult = $response->json();
                Log::info("Facturapi: Invoice cancelled successfully.");
                return [
                    'success' => true,
                    'status' => $cancelResult['status'] ?? 'cancelled',
                    'raw_data' => $cancelResult
                ];
            } else {
                $errorMsg = $response->json()['message'] ?? $response->body();
                Log::error("Facturapi cancel request failed: {$errorMsg}");
                return [
                    'success' => false,
                    'error' => $errorMsg
                ];
            }
        } catch (\Exception $e) {
            Log::error("Facturapi cancel exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Retrieve the XML URL for a generated invoice.
     */
    public function getInvoiceXml(string $invoiceId): string
    {
        return "{$this->baseUrl}/invoices/{$invoiceId}/xml";
    }

    /**
     * Retrieve the PDF URL for a generated invoice.
     */
    public function getInvoicePdf(string $invoiceId): string
    {
        return "{$this->baseUrl}/invoices/{$invoiceId}/pdf";
    }
}
