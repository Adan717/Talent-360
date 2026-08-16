<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte de Prenómina</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333333;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        .header {
            margin-bottom: 25px;
            border-bottom: 2px solid #10b981;
            padding-bottom: 10px;
        }
        .header h1 {
            font-size: 24px;
            margin: 0;
            color: #111827;
        }
        .header p {
            margin: 5px 0 0 0;
            color: #6b7280;
            font-size: 14px;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 4px 0;
        }
        .meta-table .label {
            font-weight: bold;
            color: #4b5563;
            width: 120px;
        }
        .payroll-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .payroll-table th {
            background-color: #f3f4f6;
            color: #374151;
            font-weight: bold;
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .payroll-table td {
            padding: 10px;
            border-bottom: 1px solid #f3f4f6;
            color: #4b5563;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-bold {
            font-weight: bold;
        }
        .badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
        }
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .summary-box {
            margin-top: 30px;
            float: right;
            width: 250px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            background-color: #fafafa;
        }
        .summary-box table {
            width: 100%;
        }
        .summary-box td {
            padding: 4px 0;
        }
        .summary-box .total-row td {
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            font-size: 14px;
            font-weight: bold;
            color: #10b981;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reporte de Prenómina</h1>
        <p>Talent360 — Gestión Automatizada de Nómina</p>
    </div>

    {{-- Un reporte de PRUEBA salía idéntico al real: en el escritorio de alguien no había
         forma de saber cuál era cuál. --}}
    @if (!empty($esSimulacion))
        <div style="background-color:#fef3c7; border:2px solid #f59e0b; color:#92400e; padding:10px; margin-bottom:12px; text-align:center; font-weight:bold;">
            DOCUMENTO DE SIMULACIÓN — datos del Simulador Matrix, NO es la nómina real de la empresa.
        </div>
    @endif

    <table class="meta-table">
        <tr>
            <td class="label">Fecha Emisión:</td>
            <td>{{ now()->format('d/m/Y H:i') }}</td>
            <td class="label">Rango Reporte:</td>
            {{-- Decía "Últimos 15 Días (Quincenal)" escrito a mano, sin importar qué periodo
                 se estuviera exportando: el PDF de la prenómina mentía sobre su propio rango
                 (una semana del 27-jul al 02-ago se archivaba como "quincenal"). --}}
            <td>Del {{ $startDate }} al {{ $endDate }}</td>
        </tr>
        <tr>
            <td class="label">Generado por:</td>
            <td>{{ auth()->user()->name }}</td>
            <td class="label">Estado:</td>
            <td><span class="badge" style="background-color: #d1fae5; color: #065f46;">Prenómina Operativa</span></td>
        </tr>
    </table>

    <table class="payroll-table">
        <thead>
            <tr>
                <th>Colaborador</th>
                <th>Puesto</th>
                <th class="text-center">Retardos</th>
                <th class="text-center">Faltas</th>
                {{-- Era "Salario Base" e imprimía `base` (el sueldo del expediente) mientras la
                     caja de totales de abajo sumaba `gross` (el bruto del periodo): la columna
                     NO daba su propio total, en el documento de una nómina. Se corrigieron los
                     totales en su día y esta mitad se quedó sin corregir. --}}
                <th class="text-right">Bruto del Periodo</th>
                <th class="text-right">Penalización</th>
                <th class="text-right">Neto a Pagar</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payroll as $emp)
                <tr>
                    <td class="font-bold">{{ $emp['name'] }}</td>
                    <td>{{ $emp['role'] }}</td>
                    <td class="text-center">
                        @if($emp['lates'] > 0)
                            <span class="badge badge-warning">{{ $emp['lates'] }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-center">
                        @if($emp['absences'] > 0)
                            <span class="badge badge-danger">{{ $emp['absences'] }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-right">
                        @if($emp['salary_pending'])
                            <span style="color: #ef4444; font-size: 10px;">Pendiente</span>
                        @else
                            ${{ number_format($emp['gross'], 2) }}
                        @endif
                    </td>
                    <td class="text-right" style="color: #ef4444;">
                        @if($emp['salary_pending'])
                            -
                        @else
                            -${{ number_format($emp['penalty'], 2) }}
                        @endif
                    </td>
                    <td class="text-right font-bold" style="color: #059669;">
                        @if($emp['salary_pending'])
                            <span style="color: #9ca3af; font-size: 10px;">Ajustar Salario</span>
                        @else
                            ${{ number_format($emp['net'], 2) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary-box">
        <table>
            <tr>
                <td class="font-bold">Nómina Base Bruta:</td>
                <td class="text-right">${{ number_format($totalBase, 2) }}</td>
            </tr>
            <tr>
                <td class="font-bold" style="color: #ef4444;">Deducciones Totales:</td>
                <td class="text-right" style="color: #ef4444;">-${{ number_format($totalPenalties, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td>Total Neto:</td>
                <td class="text-right">${{ number_format($totalNet, 2) }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
