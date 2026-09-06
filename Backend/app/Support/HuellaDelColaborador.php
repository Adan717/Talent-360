<?php

namespace App\Support;

/**
 * Todo lo que una persona deja escrito en la base, declarado en un solo sitio (2026-09-05).
 *
 * Lo usa `datos:purgar-vencidos` para saber qué borrar cuando vence la retención de cinco años, y
 * lo vigila `PurgaAlcanzaTodaLaHuellaTest`: si alguien añade mañana una tabla con `user_id` o
 * `employee_id` y no la apunta aquí, la prueba falla. Ésa es toda la gracia — el defecto que se
 * quiere evitar no es que falte una tabla, es que **sea posible olvidarla en silencio** y que la
 * empresa crea que purgó mientras deja datos personales regados.
 *
 * NO se descubre el mapa leyendo el esquema en tiempo de ejecución, a propósito, por dos razones:
 *
 * 1. **Hay columnas de AUTOR, no de sujeto.** `declared_by_user_id`, `created_by_user_id`,
 *    `uploaded_by`, `validated_by`, `admin_approved_by`: no dicen de quién trata la fila, sino
 *    quién la escribió. Borrar por ahí se llevaría registros de la empresa que no son de nadie en
 *    particular. Un descubridor automático no distingue el sujeto del autor; una lista escrita a
 *    mano sí, y encima se puede leer.
 *
 * 2. **El nombre de la columna MIENTE en media docena de tablas.** Ésta es la trampa gorda de este
 *    esquema (la familia §29/§30 de nombres engañosos): `meal_photo_evidences.employee_id`,
 *    `silla_requests.employee_id`, `pase_lista_ratings.employee_id`, `meal_queue_entries.employee_id`,
 *    `store_opening_events.employee_id` y `door_notices.from/to_employee_id` **NO apuntan a
 *    `employees`: apuntan a `users`** (así lo dicen sus llaves foráneas, `constrained('users')`).
 *    En cambio `daily_approvals`, `employee_documents`, `weekly_payrolls` y
 *    `store_opening_assignments` sí apuntan a `employees`.
 *
 *    Confundirlas no habría fallado ruidosamente: `employees.id` y `users.id` son ambos enteros y
 *    casi siempre existen los dos. Una purga que borrara `silla_requests` por `employees.id`
 *    habría **borrado los reposos de OTRA persona** —la que tuviera ese número en `users`— y
 *    dejado intactos los de la persona a purgar. Silencioso y en producción. Por eso aquí cada
 *    tabla declara su columna Y a qué tabla apunta de verdad.
 *
 * 3. **Y `user_id` tampoco apunta siempre a `users`.** `obsidian_exams`, `obsidian_exam_attempts`
 *    y `obsidian_read_progress` apuntan a `obsidian_users` (la Wiki tiene su propia tabla de
 *    cuentas) y `support_ticket_notes` apunta a `platform_users` (el personal de soporte de
 *    Talent 360). Mismo error, misma consecuencia: borrar los datos de un tercero y dejar los del
 *    purgado. Están en FUERA, con su motivo escrito.
 */
class HuellaDelColaborador
{
    /**
     * Tablas donde la persona es el SUJETO de la fila y se la referencia por su `users.id`
     * (aunque la columna se llame `employee_id`: ver la cabecera).
     *
     * @var array<string,array{0:string,1:string}> tabla => [columna, qué guarda]
     */
    public const POR_ID_DE_USUARIO = [
        'archived_time_entries'          => ['user_id', 'fichajes archivados por un reinicio de jornada'],
        'asistencia_correcciones'        => ['empleado_user_id', 'correcciones hechas sobre sus fichajes'],
        'audit_logs'                     => ['user_id', 'bitácora operativa: retardos, faltas, desbloqueos'],
        'candidates'                     => ['user_id', 'su ficha del ATS (RFC, NSS, acta); arrastra sus entrevistas por cascade'],
        'contingencies'                  => ['user_id', 'eventualidades declaradas'],
        'contingency_days'               => ['user_id', 'días de contingencia a su nombre'],
        'device_registrations'           => ['user_id', 'huella del dispositivo, IP y user agent'],
        'early_departure_authorizations' => ['user_id', 'salidas anticipadas autorizadas'],
        'late_authorization_requests'    => ['user_id', 'entradas tardías pedidas y autorizadas'],
        'late_justifications'            => ['user_id', 'justificantes de retardo'],
        'meal_photo_evidences'           => ['employee_id', 'evidencia fotográfica de comedor (la columna apunta a users); los archivos se borran aparte'],
        'meal_queue_entries'             => ['employee_id', 'turnos en la fila del comedor (la columna apunta a users)'],
        'meal_reservations'              => ['user_id', 'reservas de comedor (queda `swapped_to_user_id` de terceros apuntando a la cuenta ya anonimizada: no es dato personal)'],
        'obsidian_suggestions'           => ['user_id', 'sugerencias que escribió en la Wiki (ésta sí apunta a users)'],
        'overtime_authorizations'        => ['user_id', 'horas extra autorizadas'],
        'panic_incidents'                => ['user_id', 'botones de pánico'],
        'report_intent_logs'             => ['user_id', 'lo que le pidió al asistente de reportes'],
        'saas_audit_logs'                => ['user_id', 'bitácora de seguridad de SUS accesos: IP y user agent son datos personales'],
        'sessions'                       => ['user_id', 'sesiones web'],
        'silla_requests'                 => ['employee_id', 'reposos de Ley Silla (la columna apunta a users)'],
        'store_logs'                     => ['user_id', 'bitácora de tienda'],
        'store_opening_events'           => ['employee_id', 'aperturas y cierres que ejecutó (la columna apunta a users)'],
        'task_assignments'               => ['user_id', 'tareas asignadas, tiempos y evidencia'],
        'team_chat_messages'             => ['user_id', 'mensajes del chat de equipo'],
        'user_course_progress'           => ['user_id', 'avance en Academia'],
        'user_wallets'                   => ['user_id', 'monedero'],
        'wallet_transactions'            => ['user_id', 'movimientos del monedero'],
    ];

    /**
     * Tablas donde la persona se referencia por su `employees.id` (el id del EXPEDIENTE, no el
     * número de empleado ni el id de usuario).
     *
     * @var array<string,array{0:string,1:string}> tabla => [columna, qué guarda]
     */
    public const POR_ID_DE_EXPEDIENTE = [
        'daily_approvals'           => ['employee_id', 'aprobaciones diarias de su jornada'],
        'employee_documents'        => ['employee_id', 'expediente digital (INE, acta, CURP); los archivos se borran aparte'],
        'store_opening_assignments' => ['employee_id', 'asignación de llaves y aperturas'],
        'weekly_payrolls'           => ['employee_id', 'recibos de nómina'],
    ];

    /**
     * Tablas donde la persona puede estar en CUALQUIERA de los lados (emisor/receptor,
     * evaluador/evaluado, calificado/calificador). Todas referencian `users.id`. Se borra la fila
     * entera: describe a una pareja, y purgada una de las dos partes la fila ya no se sostiene.
     *
     * @var array<string,array{0:array<int,string>,1:string}> tabla => [columnas, qué guarda]
     */
    public const PARES = [
        'door_notices'            => [['from_employee_id', 'to_employee_id'], 'recados de puerta (ambas columnas apuntan a users)'],
        'employee_reports'        => [['reporter_id', 'accused_id'], 'reportes entre colaboradores'],
        'internal_messages'       => [['sender_id', 'receiver_id'], 'mensajes privados y megáfono'],
        'key_transfers'           => [['sender_id', 'receiver_id'], 'cesiones de llaves'],
        'pase_lista_ratings'      => [['employee_id', 'rated_by_employee_id'], 'pase de lista: como calificado y como calificador (ambas apuntan a users)'],
        'performance_evaluations' => [['evaluator_user_id', 'evaluated_user_id'], 'evaluaciones de desempeño'],
        'supervisor_qr_tokens'    => [['supervisor_id'], 'códigos QR de autorización que emitió'],
    ];

    /**
     * Tablas que TIENEN `user_id` o `employee_id` y aun así NO se purgan. Están aquí para que la
     * prueba de guardia no las reclame y, sobre todo, para que la exclusión sea una decisión
     * escrita y no un olvido.
     *
     * @var array<string,string> tabla => por qué se queda
     */
    public const FUERA = [
        'employees' => 'no se borra: se ANONIMIZA. Hay llaves foráneas colgando de ella y el '
            . 'reporte de rotación necesita seguir contando la fila. Además su columna '
            . '`employee_id` es el número de empleado en texto, no una referencia.',
        'users' => 'no se borra: se ANONIMIZA y se le revocan los tokens. Borrar la fila '
            . 'arrastraría por cascade media base de datos.',
        'time_entries' => 'camino propio: se borran DENTRO de una transacción firmada con '
            . 'BitacoraDeAsistencia, para que las filas DELETE que escribe el trigger queden '
            . 'atribuidas antes de borrarse; sólo después se purga el historial.',
        // LA OTRA TRAMPA: `user_id` que NO apunta a `users`. Borrar aquí por el id de un
        // colaborador habría borrado los datos de OTRA persona —la que tuviera ese número en la
        // tabla a la que la columna sí apunta— dejando intactos los del purgado.
        'obsidian_exams' => 'su `user_id` apunta a `obsidian_users`, la tabla de identidades PROPIA '
            . 'de la Wiki, no a `users`. Ver `obsidian_users`.',
        'obsidian_exam_attempts' => 'igual que obsidian_exams: `user_id` apunta a `obsidian_users`.',
        'obsidian_read_progress' => 'igual que obsidian_exams: `user_id` apunta a `obsidian_users`.',
        'obsidian_users' => 'la Wiki tiene su PROPIA tabla de cuentas, sin ninguna llave que la '
            . 'ate a `users` o a `employees`: lo único en común es el correo. Emparejar personas '
            . 'por correo es adivinar, y una coincidencia equivocada borraría la cuenta de alguien '
            . 'más. Queda fuera de esta versión; el comando AVISA cuando detecta un correo que '
            . 'coincide, para que quede a la vista en vez de perderse.',
        'support_ticket_notes' => 'su `user_id` apunta a `platform_users` (el personal de Talent '
            . '360 que atiende el ticket), no a los usuarios del inquilino. Borrar aquí por el id '
            . 'de un colaborador borraría las notas de un agente de soporte.',
        'course_certificates' => 'FUERA de esta versión por decisión de producto: el folio de un '
            . 'certificado de Academia se verifica en una página PÚBLICA que muestra el nombre de '
            . 'la persona. Purgarlo rompe folios ya entregados; conservarlo mantiene publicado un '
            . 'dato personal. Lo decide el dueño (ver docs/DECISIONES_PRODUCTO.md, D11).',
    ];

    /**
     * Las tablas que esta purga sí toca, con su columna y a qué id responde.
     *
     * @return array<int,array{tabla:string,columnas:array<int,string>,llave:string}>
     */
    public static function plan(): array
    {
        $plan = [];

        foreach (self::POR_ID_DE_USUARIO as $tabla => [$columna, $_]) {
            $plan[] = ['tabla' => $tabla, 'columnas' => [$columna], 'llave' => 'usuario'];
        }

        foreach (self::POR_ID_DE_EXPEDIENTE as $tabla => [$columna, $_]) {
            $plan[] = ['tabla' => $tabla, 'columnas' => [$columna], 'llave' => 'expediente'];
        }

        foreach (self::PARES as $tabla => [$columnas, $_]) {
            $plan[] = ['tabla' => $tabla, 'columnas' => $columnas, 'llave' => 'usuario'];
        }

        return $plan;
    }
}
