<?php

namespace App\Support;

/**
 * El plazo que tiene un colaborador nuevo para completar su inducción.
 *
 * Vive aquí, en un solo lugar, porque lo usan las DOS puntas de la misma regla y no pueden
 * separarse nunca:
 *
 *  - El colaborador lo ve en su app: *"Tienes N días para tu inducción."*
 *  - El encargado lo ve en su tablero: al cumplirse, el caso se pinta en ROJO.
 *
 * Decisión de producto (2026-08-05/06): **nada bloquea, todo avisa**. Ni el banner ni el tablero
 * le impiden nada a nadie — el colaborador puede fichar y trabajar con su inducción pendiente.
 * *"No quiero que el sistema castigue al nuevo; quiero que me presione a mí para acercarme a él."*
 */
class PlazoInduccion
{
    /** Días desde el ingreso para completar la inducción. */
    public const DIAS = 3;
}
