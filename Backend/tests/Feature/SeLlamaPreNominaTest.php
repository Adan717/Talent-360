<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Lo que este sistema produce es una PRE-nómina (2026-08-28, punto 4 del cierre de venta).
 *
 * Es un cálculo a partir de la asistencia y del reglamento de la empresa: no lleva ISR ni IMSS
 * retenidos, no está timbrado y no es un CFDI. Llamarlo "Nómina" promete un documento fiscal que
 * no es — y es lo primero que un contador detecta.
 *
 * ESTA PRUEBA FIJA EL CRITERIO, no la ortografía: vigila los DOCUMENTOS IMPRESOS, que son los que
 * salen del sistema y llegan a manos de un contador, un colaborador o un inspector. Lo que se
 * queda dentro de la pantalla se puede discutir; un papel que dice "Comprobante de Pago de
 * Nómina" sin serlo, no.
 *
 * LO QUE NO VIGILA, A PROPÓSITO: el módulo "Nómina CFDI 4.0". Ese nombra el documento FISCAL, que
 * es exactamente lo que sería si se timbrara; renombrarlo sería el error contrario. Hoy además
 * está apagado y responde 503 explicando por qué (ver TimbradoDesactivadoTest).
 */
class SeLlamaPreNominaTest extends TestCase
{
    /** El PDF que se entrega no puede presentarse como gestión de nómina. */
    public function test_el_pdf_de_prenomina_no_se_presenta_como_nomina(): void
    {
        $plantilla = file_get_contents(resource_path('views/reports/payroll.blade.php'));

        $this->assertStringNotContainsString(
            'Gestión Automatizada de Nómina',
            $plantilla,
            'el PDF se anunciaba como gestión de NÓMINA: promete un documento fiscal que no emite'
        );
        $this->assertStringContainsString('pre-nómina', $plantilla);
    }

    /** El ticket que recibe el colaborador tampoco es un comprobante de pago. */
    public function test_el_ticket_no_se_llama_comprobante_de_pago(): void
    {
        $ticket = file_get_contents(resource_path('views/reports/ticket.blade.php'));

        $this->assertStringNotContainsString(
            'Comprobante de Pago de Nómina',
            $ticket,
            'un ticket que dice "Comprobante de Pago" sin ser recibo fiscal es justo lo que no se puede imprimir'
        );
        $this->assertStringContainsString('no es un recibo fiscal', $ticket, 'y lo dice con todas sus letras');
    }

    /** Los reportes con dinero se nombran por lo que son en su portada impresa. */
    public function test_los_reportes_con_dinero_se_llaman_prenomina(): void
    {
        $catalogo = \App\Support\CatalogoDeReportes::REPORTES;

        $this->assertSame('Pre-nómina Histórica', $catalogo['nomina_historica']['titulo']);
        $this->assertStringContainsString('Pre-nómina', $catalogo['costo_por_puesto']['titulo']);
    }

    /**
     * El id del catálogo NO cambia aunque cambie el título: es el nombre del archivo de la ruta
     * y la llave que el asistente devuelve. Renombrar la etiqueta no puede romper la descarga.
     */
    public function test_renombrar_la_etiqueta_no_movio_los_identificadores(): void
    {
        $ids = \App\Support\CatalogoDeReportes::ids();

        $this->assertContains('nomina_historica', $ids);
        $this->assertContains('costo_por_puesto', $ids);
    }
}
