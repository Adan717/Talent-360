<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use App\Services\Billing\BillingProviderInterface;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    protected BillingProviderInterface $billingProvider;

    public function __construct(BillingProviderInterface $billingProvider)
    {
        $this->billingProvider = $billingProvider;
    }

    /**
     * Actualiza los datos fiscales básicos de la empresa/tenant.
     */
    public function updateTaxData(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        $validated = $request->validate([
            'tax_name' => 'required|string|max:255',
            'rfc' => 'required|string|min:12|max:13',
            'tax_regimen' => 'required|string|max:5',
            'postal_code' => 'required|string|max:10',
        ]);

        $tenant->update($validated);

        // Disparar sincronización de organización en Facturapi de forma pasiva si tiene RFC configurado
        if (!empty($tenant->rfc)) {
            try {
                $this->billingProvider->forTenant($tenant)->listInvoices(['limit' => 1]);
            } catch (\Exception $e) {
                // Ignorar fallos de red en modo sandbox/offline
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Datos fiscales actualizados correctamente',
            'tenant' => $tenant
        ]);
    }

    /**
     * Sube y encripta los certificados de sellos digitales (CSD) de la empresa.
     */
    public function uploadCsd(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        $request->validate([
            'certificate' => 'required|string', // Base64 del archivo .cer
            'private_key' => 'required|string', // Base64 del archivo .key
            'password' => 'required|string',
        ]);

        $tenant->update([
            'csd_certificate' => $request->certificate,
            'csd_private_key' => $request->private_key,
            'csd_password' => $request->password,
        ]);

        // Intentar subir CSD a la organización en Facturapi
        $success = false;
        $errorMessage = 'No se pudo sincronizar con el PAC/SAT';
        try {
            $provider = $this->billingProvider->forTenant($tenant);
            // Esto gatilla getOrCreateOrganization si no tiene ID, lo cual a su vez sube el CSD
            $provider->listInvoices(['limit' => 1]);
            $success = true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Certificados CSD cargados y encriptados con éxito' : 'Certificados guardados localmente, pero falló la sincronización con el PAC: ' . $errorMessage,
            'tenant' => [
                'has_certificate' => !empty($tenant->csd_certificate),
                'has_private_key' => !empty($tenant->csd_private_key),
            ]
        ]);
    }

    /**
     * Consulta el historial de facturas emitidas por la empresa en Facturapi.
     */
    /**
     * Estado del timbrado: en qué ambiente fiscal opera la instancia y si hay llave de PAC.
     *
     * Existe porque la pantalla de Configuración traía un selector "Pruebas SAT / Producción
     * Fiscal" que se guardaba por empresa y no gobernaba nada: el ambiente lo decide la llave
     * del servidor. Un ajuste que dice "Producción" mientras el servidor timbra contra el
     * sandbox es la peor manera de equivocarse en algo fiscal, así que en vez de un control
     * que no manda, la pantalla ahora MUESTRA lo que de verdad está pasando.
     *
     * Nunca devuelve la llave, sólo lo que se deduce de ella.
     */
    public function estadoDelTimbrado()
    {
        return response()->json($this->billingProvider->estadoDelTimbrado() + [
            'timbrado_automatico' => false,
            'donde_se_cambia' => 'FACTURAPI_KEY en el .env del servidor',
        ]);
    }

    public function getInvoices(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        $params = [
            'limit' => $request->input('limit', 20),
            'page' => $request->input('page', 1)
        ];

        // N8: sin credenciales configuradas aquí salían tres facturas FICTICIAS ("JUAN PEREZ
        // LOPEZ", RFC inventado) marcadas como vigentes — un dueño podía creer que ya tenía
        // timbres emitidos. El resultado del proveedor se devuelve tal cual: si no hay
        // credenciales, el historial dice la verdad (vacío / error).
        return response()->json($this->billingProvider->forTenant($tenant)->listInvoices($params));
    }

    /**
     * Timbra el recibo de nómina CFDI de un colaborador.
     *
     * Auditoría N1: esto "fingía éxito" — si el proveedor fallaba con un error que
     * contuviera la palabra `key`, devolvía success:true con un UUID inventado que además
     * no se guardaba en ninguna tabla, y el monto venía DEL CLIENTE (net_salary editable
     * desde el navegador). Ahora sólo se timbra una nómina AUTORIZADA por la empresa, con
     * su net_pay guardado, el resultado real del proveedor se propaga tal cual, y el folio
     * fiscal queda sellado en la fila (cfdi_uuid / timbrada_at).
     */
    public function timbrarNomina(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        $validated = $request->validate([
            'employee_id' => 'required|integer', // id del EXPEDIENTE (employees), el sujeto de la nómina
            'period_start' => 'required|date',
            'period_end' => 'required|date',
        ]);

        // Acotado al tenant: los ids son globales y vienen del cliente.
        $empleado = \App\Models\Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $validated['employee_id'])
            ->with('user')
            ->first();
        if (!$empleado) {
            return response()->json(['success' => false, 'error' => 'Colaborador no encontrado'], 404);
        }

        $nomina = \App\Models\WeeklyPayroll::where('tenant_id', $tenantId)
            ->where('employee_id', $empleado->id)
            ->where('start_date', $validated['period_start'])
            ->where('end_date', $validated['period_end'])
            ->first();

        if (!$nomina || $nomina->status !== 'approved_by_admin') {
            return response()->json([
                'success' => false,
                'error' => 'Sólo se timbra una nómina autorizada por la empresa. '
                    . ($nomina
                        ? 'Estado actual del periodo: ' . $nomina->status . '.'
                        : 'No existe nómina para ese colaborador y periodo.'),
            ], 422);
        }

        // Idempotencia: un recibo fiscal no se emite dos veces.
        if ($nomina->timbrada_at) {
            return response()->json([
                'success' => false,
                'error' => 'Esta nómina ya fue timbrada el ' . $nomina->timbrada_at->format('Y-m-d H:i') . '.',
                'receipt' => [
                    'id' => $nomina->cfdi_receipt_id,
                    'uuid' => $nomina->cfdi_uuid,
                ],
            ], 409);
        }

        // Periodicidad configurable (2026-08-03): el código del SAT sale de la periodicidad
        // declarada del tenant (catálogo cerrado: 02 semanal, 04 quincenal, 05 mensual).
        // #17: los DÍAS del periodo salen del recibo REAL, no de un fijo — una quincena de
        // febrero son 13 días y una de mes de 31 son 16; el comprobante fiscal debe decir
        // lo que de verdad cubre.
        $mapaSat = [
            'semanal' => '02',
            'quincenal' => '04',
            'mensual' => '05',
        ];
        $periodicidadSat = $mapaSat[
            \App\Http\Controllers\PayrollSettingsController::periodicidadDe($tenantId)
        ];
        $diasDelPeriodo = (int) round(\Carbon\Carbon::parse($validated['period_start'])
            ->diffInDays(\Carbon\Carbon::parse($validated['period_end']))) + 1;

        // Estructurar un payload mínimo para SAT CFDI 4.0 Nómina en Facturapi. El monto sale
        // de la nómina autorizada (net_pay), nunca del cliente.
        $payrollPayload = [
            'customer' => [
                'legal_name' => $empleado->name,
                'rfc' => $empleado->rfc ?? 'XAXX010101000', // RFC genérico si no tiene
                'tax_system' => '605', // Régimen de Sueldos y Salarios
                'email' => $empleado->user->email ?? $empleado->email,
                'address' => [
                    'zip' => $tenant->postal_code ?? '01000'
                ]
            ],
            'payroll' => [
                'type' => 'O', // Nómina Ordinaria
                'payment_date' => now()->toIso8601String(),
                'start_date' => $validated['period_start'],
                'end_date' => $validated['period_end'],
                'working_days' => $diasDelPeriodo,
                'employee' => [
                    'curp' => $empleado->curp ?? 'AAAA000000HHHHHH00',
                    'contract_type' => '01', // Contrato de trabajo por tiempo indeterminado
                    'regime_type' => '02', // Sueldos
                    'employee_number' => (string)$empleado->id,
                    'periodicity' => $periodicidadSat,
                    'risk_position' => '1', // Clase I
                    'bank' => '012', // BBVA Bancomer
                    'bank_account' => $empleado->clabe ?? '012180000000000000',
                    'salary_rate' => round(((float) $nomina->net_pay) / $diasDelPeriodo, 2),
                    'state' => 'MEX'
                ]
            ]
        ];

        $res = $this->billingProvider->forTenant($tenant)->createPayrollReceipt($payrollPayload);

        // N1: sin "modo simulador". Si el proveedor falla (credenciales, red, rechazo del
        // PAC), el error se propaga tal cual — un timbre que no ocurrió no se reporta como
        // ocurrido, y nada se sella en la fila.
        if (empty($res['success'])) {
            return response()->json($res, 502);
        }

        $nomina->update([
            'cfdi_uuid' => $res['uuid'] ?? null,
            'cfdi_receipt_id' => $res['id'] ?? null,
            'timbrada_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Recibo de nómina timbrado.',
            'receipt' => [
                'id' => $res['id'] ?? null,
                'uuid' => $res['uuid'] ?? null,
                'pdf_url' => $res['pdf_url'] ?? null,
                'xml_url' => $res['xml_url'] ?? null,
            ],
        ]);
    }
}
