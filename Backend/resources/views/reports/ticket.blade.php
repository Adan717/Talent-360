<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ticket de Pago — {{ $payroll['name'] }}</title>
    <style>
        @page {
            size: 80mm 200mm;
            margin: 0;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            color: #000000;
            font-size: 11px;
            line-height: 1.3;
            margin: 5mm;
            padding: 0;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .font-bold {
            font-weight: bold;
        }
        .header {
            border-bottom: 1px dashed #000;
            padding-bottom: 3mm;
            margin-bottom: 3mm;
        }
        .header h1 {
            font-size: 14px;
            margin: 0;
            text-transform: uppercase;
        }
        .header p {
            margin: 1mm 0 0 0;
            font-size: 9px;
        }
        .details-table, .totals-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3mm;
        }
        .details-table td {
            padding: 0.5mm 0;
            font-size: 9px;
        }
        .divider {
            border-top: 1px dashed #000;
            margin: 2mm 0;
        }
        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            margin-bottom: 1mm;
        }
        .total-row {
            font-size: 12px;
            font-weight: bold;
        }
        .barcode {
            font-size: 8px;
            margin-top: 5mm;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>
    <div class="text-center header">
        <h1>TALENT360 SaaS</h1>
        <p>Comprobante de Pago de Nómina</p>
        <p>RFC: {{ auth()->user()->tenant->rfc ?? 'XAXX010101000' }}</p>
        <p>Razón Social: {{ auth()->user()->tenant->tax_name ?? 'Decorarte S.A. de C.V.' }}</p>
    </div>

    <div class="section-title">Datos del Colaborador</div>
    <table class="details-table">
        <tr>
            <td class="font-bold">Nombre:</td>
            <td class="text-right">{{ $payroll['name'] }}</td>
        </tr>
        <tr>
            <td class="font-bold">Periodo:</td>
            <td class="text-right">{{ \Carbon\Carbon::parse($payroll['period']['start'])->format('d/m/y') }} al {{ \Carbon\Carbon::parse($payroll['period']['end'])->format('d/m/y') }}</td>
        </tr>
        <tr>
            <td class="font-bold">Impreso:</td>
            <td class="text-right">{{ now()->format('d/m/y H:i') }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="section-title">Percepciones</div>
    <table class="details-table">
        <tr>
            <td>Sueldo Base (6 días):</td>
            <td class="text-right">${{ number_format($payroll['salary']['base'], 2) }}</td>
        </tr>
        <tr>
            <td>Séptimo Día (Dominical):</td>
            <td class="text-right">${{ number_format($payroll['salary']['daily'] * $payroll['incidents']['rest_day_proportion'], 2) }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="section-title">Deducciones (Incidencias LFT)</div>
    <table class="details-table">
        @if($payroll['incidents']['total_absences'] > 0)
        <tr>
            <td>Descuento Faltas ({{ $payroll['incidents']['total_absences'] }}d):</td>
            <td class="text-right" style="color:red;">-${{ number_format($payroll['deductions_breakdown']['absences'], 2) }}</td>
        </tr>
        @endif
        @if($payroll['deductions_breakdown']['rest_day'] > 0)
        <tr>
            <td>Descuento Séptimo Proporcional:</td>
            <td class="text-right" style="color:red;">-${{ number_format($payroll['deductions_breakdown']['rest_day'], 2) }}</td>
        </tr>
        @endif
        @if($payroll['deductions_breakdown']['lates'] > 0)
        <tr>
            <td>Sanción por Retardos ({{ $payroll['incidents']['lates'] }}):</td>
            <td class="text-right" style="color:red;">-${{ number_format($payroll['deductions_breakdown']['lates'], 2) }}</td>
        </tr>
        @endif
        @if($payroll['deductions_breakdown']['total'] == 0)
        <tr>
            <td>Sin deducciones esta semana:</td>
            <td class="text-right">$0.00</td>
        </tr>
        @endif
    </table>

    <div class="divider"></div>

    <table class="totals-table">
        <tr class="total-row">
            <td>Neto a Recibir:</td>
            <td class="text-right font-bold">${{ number_format($payroll['salary']['net'], 2) }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="text-center" style="margin-top: 4mm;">
        <p class="font-bold">Firma de Conformidad</p>
        <br><br>
        <p>___________________________</p>
        <p>{{ $payroll['name'] }}</p>
        <p style="font-size: 8px; color: #444;">Aceptado mediante firma biométrica/PIN en Kiosko</p>
    </div>

    <div class="text-center barcode">
        *T360-{{ $payroll['employee_id'] }}-{{ now()->format('Ymd') }}*
        <p style="font-size:7px;">Gracias por tu esfuerzo diario.</p>
    </div>
</body>
</html>
