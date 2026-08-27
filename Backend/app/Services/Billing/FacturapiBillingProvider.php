<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacturapiBillingProvider implements BillingProviderInterface
{
    protected ?Tenant $tenant = null;
    protected string $baseUrl = 'https://api.facturapi.com/v1';

    /** La llave de fábrica: está en el código, no timbra nada, y hay que poder reconocerla. */
    public const LLAVE_DE_RELLENO = 'sk_test_default_facturapi_key_talent360';

    /**
     * INTERRUPTOR DE APAGADO DEL TIMBRADO DE NÓMINA (2026-08-26, decisión del dueño).
     *
     * El circuito de timbrado está construido a medias A PROPÓSITO y el sistema no calcula
     * retenciones: no hay ISR, ni IMSS, ni subsidio al empleo —el propio reporte de nómina lo
     * declara— y el payload viaja con RFC genérico, CURP de relleno, banco y clase de riesgo
     * fijos. Con una llave real eso NO falla: **timbra**, y lo que sale es un documento fiscal
     * presentado ante el SAT a nombre del cliente con datos falsos. Un CFDI mal emitido no se
     * corrige: se cancela y se explica.
     *
     * POR QUÉ ESTÁ EN EL CÓDIGO Y NO EN EL `.env`: el riesgo concreto es que alguien ponga una
     * `FACTURAPI_KEY` y el timbrado se encienda solo. Una bandera de entorno la apagaría la misma
     * persona que puso la llave, sin saber lo que enciende. Esto exige tocar el código a
     * propósito, que es exactamente la barrera que se quiere.
     *
     * PARA RESCATARLO EN EL FUTURO: se quita esta constante y las dos comprobaciones que la usan,
     * y antes se cierra lo que falta — ver `docs/DECISIONES_PRODUCTO.md` y la conversación del
     * 2026-08-26. El código de abajo se conserva íntegro justamente para poder retomarlo.
     */
    public const TIMBRADO_DESACTIVADO = true;

    public const MOTIVO_DESACTIVADO = 'El timbrado CFDI nativo está desactivado por decisión estratégica. '
        . 'Utilice la exportación de pre-nómina en su lugar.';

    /**
     * La llave del PAC, leída CUANDO se usa.
     *
     * Antes se guardaba en el constructor, y este servicio es un `singleton`: la llave quedaba
     * congelada para toda la vida del proceso. Por `config()` y no por `env()`, porque fuera de
     * los archivos de configuración `env()` devuelve null en cuanto alguien cachea la config —
     * y esto caería a la llave de relleno sin avisar, dejando de timbrar con toda la pinta de
     * estar configurado.
     */
    protected function llave(): string
    {
        return trim((string) config('services.facturapi.key', '')) ?: self::LLAVE_DE_RELLENO;
    }

    /**
     * En qué ambiente fiscal está operando la instancia, sin exponer la llave.
     *
     * Fuente ÚNICA del dato: la misma llave con la que se timbra. La pantalla de
     * Configuración ofrecía un selector "Pruebas / Producción Fiscal" que se guardaba por
     * empresa y no mandaba sobre nada — el ambiente lo decide la llave del servidor, y una
     * pantalla que dice "Producción" mientras el servidor timbra contra el sandbox es la peor
     * forma posible de equivocarse en algo fiscal.
     *
     * @return array{configurado: bool, ambiente: ?string}
     */
    public function estadoDelTimbrado(): array
    {
        $llave = $this->llave();

        if ($llave === '' || $llave === self::LLAVE_DE_RELLENO) {
            return ['configurado' => false, 'ambiente' => null];
        }

        // Convención de Facturapi: las llaves de prueba llevan el prefijo `sk_test`.
        return [
            'configurado' => true,
            'ambiente' => str_starts_with($llave, 'sk_test') ? 'pruebas' : 'produccion',
        ];
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

        // Only create an organization if the tenant has explicitly configured an RFC
        if (empty($this->tenant->rfc)) {
            return null;
        }

        // Avoid making HTTP calls if API key is empty or default sandbox placeholder
        if ($this->llave() === self::LLAVE_DE_RELLENO) {
            return null;
        }

        // If no organization ID exists, we register it on Facturapi
        try {
            $taxRegimen = $this->tenant->tax_regimen ?? '601'; // Default: General de Ley Personas Morales
            $rfc = $this->tenant->rfc;

            Log::info("Creating Facturapi Organization for Tenant: {$this->tenant->name} (RFC: {$rfc})");

            $response = Http::timeout(5)
                ->withBasicAuth($this->llave(), '')
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
                $this->uploadCsd($orgId);

                return $orgId;
            } else {
                Log::warning("Failed to create Facturapi Organization: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::warning("Could not reach Facturapi service in getOrCreateOrganization: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Upload CSD certificates (decrypted from Postgres) to the tenant's Facturapi organization.
     */
    public function uploadCsd(string $orgId): bool
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

            $response = Http::withBasicAuth($this->llave(), '')
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
                ->withBasicAuth($this->llave(), '')
                ->post("{$this->baseUrl}/invoices", $data);

            if ($response->successful()) {
                $invoice = $response->json();
                Log::info("Facturapi: Invoice created successfully. ID: {$invoice['id']}, UUID: " . ($invoice['uuid'] ?? 'N/A'));
                return [
                    'success' => true,
                    'id' => $invoice['id'],
                    // Sin UUID del PAC se devuelve null, no un folio inventado (N1).
                    'uuid' => $invoice['uuid'] ?? null,
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
        // CORTAFUEGOS. Va aquí, en el punto donde se dispara la llamada al PAC, y no sólo en el
        // controlador: cualquier vía futura —un comando, un job, otra pantalla— choca contra esto.
        if (self::TIMBRADO_DESACTIVADO) {
            throw new \RuntimeException(self::MOTIVO_DESACTIVADO);
        }

        Log::info("Facturapi: Creating payroll receipt. Context Tenant: " . ($this->tenant ? $this->tenant->name : 'Platform/Self'));

        // Force payroll type and attributes
        $data['type'] = 'payroll';

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->withBasicAuth($this->llave(), '')
                ->post("{$this->baseUrl}/invoices", $data);

            if ($response->successful()) {
                $invoice = $response->json();
                Log::info("Facturapi: Payroll receipt created successfully. ID: {$invoice['id']}, UUID: " . ($invoice['uuid'] ?? 'N/A'));
                return [
                    'success' => true,
                    'id' => $invoice['id'],
                    // Sin UUID del PAC se devuelve null, no un folio inventado (N1).
                    'uuid' => $invoice['uuid'] ?? null,
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
                ->withBasicAuth($this->llave(), '')
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

    /**
     * List invoices from the billing provider.
     */
    public function listInvoices(array $params = []): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->withBasicAuth($this->llave(), '')
                ->get("{$this->baseUrl}/invoices", $params);

            if ($response->successful()) {
                $json = $response->json();
                return [
                    'success' => true,
                    'data' => $json['data'] ?? $json
                ];
            } else {
                $errorMsg = $response->json()['message'] ?? $response->body();
                return [
                    'success' => false,
                    'error' => $errorMsg
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
