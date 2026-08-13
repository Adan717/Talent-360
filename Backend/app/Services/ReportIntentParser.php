<?php

namespace App\Services;

/**
 * Bloque 6 (2026-08-13): contrato del intérprete de frases del asistente de reportes.
 *
 * UNA sola implementación (OpenAI). La interfaz compra la opción de cambiar de proveedor;
 * la segunda implementación se escribe el día que se compare, no antes (diseño acordado en
 * el plan). En pruebas se sustituye por un doble — por eso el contrato es tan angosto.
 *
 * El parser devuelve INTENCIÓN, nunca cifras ni fechas resueltas: todo lo que dependa de
 * la configuración del inquilino (semana fiscal, zona horaria, qué es "la semana 25") lo
 * resuelve el servidor con el código que ya usa la pantalla (PayrollWeekService).
 *
 * @return array{reporte:string, periodo:array{tipo:string,dias:?int,numero:?int,desde:?string,hasta:?string}, motivo_rechazo:?string}
 * @throws \RuntimeException si el proveedor no responde o responde basura.
 */
interface ReportIntentParser
{
    public function parse(string $frase): array;
}
