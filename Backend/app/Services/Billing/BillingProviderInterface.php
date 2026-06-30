<?php

namespace App\Services\Billing;

use App\Models\Tenant;

interface BillingProviderInterface
{
    /**
     * Set the target tenant context for fiscal operations (CSD keys and configuration).
     */
    public function forTenant(Tenant $tenant): self;

    /**
     * Create/Timbrar a CFDI 4.0 invoice.
     *
     * @param array $data Invoice data (customer info, items, payment method, tax regimen, etc.)
     * @return array The response from the PAC/Billing provider (including ID, UUID, status, PDF/XML URLs)
     */
    public function createInvoice(array $data): array;

    /**
     * Create/Timbrar a CFDI 4.0 payroll receipt (recibo de nómina).
     *
     * @param array $data Payroll receipt data (employee fiscal details, perceptions, deductions, etc.)
     * @return array The response from the PAC/Billing provider
     */
    public function createPayrollReceipt(array $data): array;

    /**
     * Cancel a CFDI invoice.
     *
     * @param string $invoiceId ID of the invoice in the provider system
     * @param string $reason SAT cancellation reason (e.g. '01', '02')
     * @param string|null $substitutionUuid UUID of the invoice that replaces it (if reason is '01')
     * @return array Response confirmation
     */
    public function cancelInvoice(string $invoiceId, string $reason, ?string $substitutionUuid = null): array;

    /**
     * Retrieve the XML URL or raw XML for a generated invoice.
     */
    public function getInvoiceXml(string $invoiceId): string;

    /**
     * Retrieve the PDF URL or raw PDF for a generated invoice.
     */
    public function getInvoicePdf(string $invoiceId): string;
}
