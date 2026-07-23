<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     local_assign_ai
 * @category    string
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Acciones';
$string['ai_response_language'] = 'Idioma de respuesta de la IA';
$string['ai_response_language_help'] = 'Selecciona el idioma en el que la IA responderá al revisar esta tarea.';
$string['aiconfigheader'] = 'Datacurso Tareas IA';
$string['aiprompt'] = 'Dale indicaciones a la IA';
$string['aiprompt_help'] = 'Indicaciones adicionales que se envían a la IA en el campo "prompt".';
$string['aistatus'] = 'Estado IA';
$string['aistatus_initial_help'] = 'Envía la entrega a la IA para que genere una propuesta.';
$string['aistatus_initial_short'] = 'Pendiente de revisión IA';
$string['aistatus_pending_help'] = 'La propuesta de la IA está lista. Abre los detalles para editarla o aprobarla.';
$string['aistatus_pending_short'] = 'Pendiente de aprobación';
$string['aistatus_processing_help'] = 'La IA está procesando esta entrega. Esto puede tardar un poco.';
$string['aistatus_queued_help'] = 'Esta entrega se ha puesto en cola y comenzará a procesarse pronto.';
$string['aistatus_queued_short'] = 'en cola';
$string['aitaskdone'] = 'Procesamiento de IA completado. Total de envíos procesados: {$a}';
$string['aitaskstart'] = 'Procesando envíos de IA para el curso: {$a}';
$string['aitaskuserqueued'] = 'Envío en cola para el usuario con ID {$a->id} ({$a->name})';
$string['altlogo'] = 'Logo Datacurso';
$string['approveall'] = 'Aprobar todos';
$string['assign_ai:changestatus'] = 'Cambiar el estado de aprobación de IAs';
$string['assign_ai:review'] = 'Revisar las sugerencias de IA para las tareas';
$string['assign_ai:viewdetails'] = 'Ver detalles de comentarios de IA';
$string['attemptnumber'] = 'Intento';
$string['autograde'] = 'Aprobar automáticamente la retroalimentación de IA';
$string['autograde_help'] = 'Si está habilitado, las calificaciones y comentarios generados por la IA se aplican automáticamente a los envíos del estudiante sin que el docente intervenga.';
$string['autogradegrader'] = 'Usuario calificador para aprobaciones automáticas';
$string['autogradegrader_help'] = 'Selecciona al usuario que se registrará como calificador cuando la retroalimentación de IA se apruebe automáticamente. Solo se listan usuarios con permiso para calificar en este curso.';
$string['backtocourse'] = 'Regresar al curso';
$string['backtoreview'] = 'Volver a Revisión con IA';
$string['confirm_approve_all'] = 'Aprobar todas las propuestas de IA pendientes y aplicar sus calificaciones/comentarios a los estudiantes. No se puede deshacer desde aquí. ¿Deseas continuar?';
$string['confirm_cancel_review'] = '¿Cancelar esta revisión de IA? Volverá al estado pendiente para que puedas reintentarla.';
$string['confirm_review_all'] = 'Enviar todas las entregas marcadas como "Pendiente de revisión IA" a la IA y comenzar el procesamiento. Esto puede tardar unos minutos. ¿Deseas continuar?';
$string['current'] = 'Actual';
$string['defaultautograde'] = 'Aprobar automáticamente la retroalimentación de IA por defecto';
$string['defaultautograde_desc'] = 'Define el valor por defecto para tareas nuevas.';
$string['defaultdelayminutes'] = 'Tiempo de espera por defecto (minutos)';
$string['defaultdelayminutes_desc'] = 'Tiempo de espera por defecto cuando la revisión diferida está habilitada.';
$string['defaultenableai'] = 'Habilitar IA';
$string['defaultenableai_desc'] = 'Controla la disponibilidad global de IA en las tareas. Si se deshabilita, la IA se desactiva en todas las tareas existentes y no se puede activar individualmente hasta que se vuelva a habilitar.';
$string['defaultprompt'] = 'Dale indicaciones a la IA por defecto';
$string['defaultprompt_desc'] = 'Este texto se usará por defecto.';
$string['defaultusedelay'] = 'Usar revisión diferida por defecto';
$string['defaultusedelay_desc'] = 'Define si la revisión diferida queda habilitada por defecto en tareas nuevas.';
$string['delayminutes'] = 'Tiempo de espera (minutos)';
$string['delayminutes_help'] = 'Cantidad de minutos que se debe esperar después de que el estudiante publique antes de ejecutar la revisión con IA.';
$string['downloadlog'] = 'Descargar registro';
$string['edited'] = 'Editado';
$string['editgrade'] = 'Editar calificación';
$string['email'] = 'Correo electrónico';
$string['enableai'] = 'Habilitar IA';
$string['enableai_global_disabled_notice'] = 'La activación de IA para esta tarea no está disponible porque un administrador la ha deshabilitado de manera global.';
$string['enableai_help'] = 'Si está deshabilitado, no se mostrarán las demás opciones de esta sección para esta tarea.';
$string['enableassignai'] = 'Habilitar Tarea IA';
$string['enableassignai_desc'] = 'Si se deshabilita, la sección "Datacurso Tareas IA" se oculta en la configuración de la actividad tarea y se pausa el procesamiento automático.';
$string['error_advancedresponsemissing'] = 'La tarea se califica con un método avanzado ({$a}) pero la respuesta de la IA no contiene datos para ese método. La calificación no se aplicó.';
$string['error_airequest'] = 'Error al comunicarse con el servicio de IA: {$a}';
$string['error_generic'] = 'La revisión con IA falló. Consulta el registro del historial de IA para más detalles.';
$string['error_guidemismatch'] = 'La respuesta de la IA no coincide con la guía de evaluación de esta tarea. La calificación no se aplicó. Criterios sin coincidencia: {$a}';
$string['error_processing_timeout'] = 'El procesamiento superó el tiempo límite sin respuesta; reinténtalo.';
$string['error_rubricmismatch'] = 'La respuesta de la IA no coincide con la rúbrica de esta tarea. La calificación no se aplicó. Criterios sin coincidencia: {$a}';
$string['errorparsingguide'] = 'Error al analizar la respuesta de la guía de evaluación: {$a}';
$string['errorparsingrubric'] = 'Error al analizar la respuesta de la rúbrica: {$a}';
$string['feedbackcomments'] = 'Comentarios';
$string['feedbackcommentsfull'] = 'Comentarios de retroalimentación';
$string['fullname'] = 'Nombre completo';
$string['grade'] = 'Calificación';
$string['gradesuccess'] = 'Calificación inyectada con éxito';
$string['gradingfailed_body'] = 'La calificación por IA de la tarea "{$a->assignment}" (estudiante: {$a->student}) falló y se agotaron los reintentos automáticos. Último error: {$a->error}';
$string['gradingfailed_subject'] = 'Falló la calificación por IA: {$a}';
$string['invalidpendingrecord'] = 'El registro de IA no pertenece a esta tarea.';
$string['lastmodified'] = 'Última modificación';
$string['log'] = 'Registro';
$string['logdetails'] = 'Detalle del registro de IA';
$string['logerror'] = 'Error';
$string['logfailed'] = 'Fallido';
$string['logsuccess'] = 'Éxito';
$string['manytasksreviewed'] = 'Se revisaron {$a} tareas';
$string['messageprovider:gradingfailed'] = 'Notificaciones de fallos de calificación por IA';
$string['missingtaskparams'] = 'Faltan parámetros de la tarea. No se puede iniciar el procesamiento por lotes de IA.';
$string['modaltitle'] = 'Retroalimentación IA';
$string['nopermissiontochangestatus'] = 'No tienes permisos para guardar o aprobar cambios en las revisiones de IA. Solo puedes ver los detalles.';
$string['norecords'] = 'No se encontraron registros';
$string['nostatus'] = 'Sin retroalimentación';
$string['nosubmissions'] = 'No se encontraron entregas para procesar.';
$string['notasksfound'] = 'No hay tarea para revisar';
$string['onetaskreviewed'] = 'Se revisó 1 tarea';
$string['pluginname'] = 'Tareas IA';
$string['privacy:metadata:datacurso_ai'] = 'Los datos de la entrega se envían al proveedor de IA Datacurso para generar calificaciones y retroalimentación. Los identificadores se seudonimizan cuando es posible.';
$string['privacy:metadata:datacurso_ai:course_activity'] = 'El contexto del curso y la tarea (nombres, descripción e instrucciones).';
$string['privacy:metadata:datacurso_ai:student_name'] = 'El nombre completo del estudiante (se seudonimiza antes del envío cuando es posible).';
$string['privacy:metadata:datacurso_ai:submission_files'] = 'Los archivos adjuntos a la entrega.';
$string['privacy:metadata:datacurso_ai:submission_text'] = 'El texto en línea de la entrega.';
$string['privacy:metadata:datacurso_ai:userid'] = 'El identificador del estudiante cuya entrega se califica.';
$string['privacy:metadata:local_assign_ai_config'] = 'Almacena la configuración de IA por tarea.';
$string['privacy:metadata:local_assign_ai_config:assignmentid'] = 'La tarea a la que pertenece la configuración.';
$string['privacy:metadata:local_assign_ai_config:graderid'] = 'El usuario registrado como calificador para las notas aplicadas por la IA.';
$string['privacy:metadata:local_assign_ai_config:usermodified'] = 'El usuario que modificó por última vez la configuración.';
$string['privacy:metadata:local_assign_ai_pending'] = 'Almacena las retroalimentaciones generadas por IA pendientes de aprobación.';
$string['privacy:metadata:local_assign_ai_pending:approval_token'] = 'Token único utilizado para el seguimiento de aprobaciones.';
$string['privacy:metadata:local_assign_ai_pending:assessment_guide_response'] = 'La retroalimentación de guía de evaluación generada por la IA.';
$string['privacy:metadata:local_assign_ai_pending:assignmentid'] = 'La tarea a la que corresponde esta retroalimentación de IA.';
$string['privacy:metadata:local_assign_ai_pending:attemptnumber'] = 'El número de intento del envío al que corresponde esta retroalimentación de IA.';
$string['privacy:metadata:local_assign_ai_pending:courseid'] = 'El curso asociado a esta retroalimentación.';
$string['privacy:metadata:local_assign_ai_pending:edited'] = 'Si esta evaluación corresponde a una edición del envío por parte del estudiante.';
$string['privacy:metadata:local_assign_ai_pending:errormessage'] = 'El error reportado cuando falló el procesamiento de IA.';
$string['privacy:metadata:local_assign_ai_pending:grade'] = 'La calificación propuesta generada por la IA.';
$string['privacy:metadata:local_assign_ai_pending:message'] = 'El mensaje de retroalimentación generado por la IA.';
$string['privacy:metadata:local_assign_ai_pending:rubric_response'] = 'La retroalimentación de rúbrica generada por la IA.';
$string['privacy:metadata:local_assign_ai_pending:status'] = 'El estado de aprobación de la retroalimentación.';
$string['privacy:metadata:local_assign_ai_pending:submissionid'] = 'El envío (intento) para el que se generó esta retroalimentación de IA.';
$string['privacy:metadata:local_assign_ai_pending:submissionmodified'] = 'La fecha de modificación del envío capturada al generar esta retroalimentación de IA.';
$string['privacy:metadata:local_assign_ai_pending:title'] = 'El título de la retroalimentación generada.';
$string['privacy:metadata:local_assign_ai_pending:userid'] = 'El usuario para quien se generó la retroalimentación de la IA.';
$string['privacy:metadata:local_assign_ai_queue'] = 'Encola tareas de procesamiento de IA diferido.';
$string['privacy:metadata:local_assign_ai_queue:payload'] = 'El contenido de la tarea, que incluye los identificadores de usuario y de módulo del curso de la entrega a procesar.';
$string['processed'] = 'Se procesaron correctamente {$a} entrega(s).';
$string['processing'] = 'Procesando';
$string['processingerror'] = 'Ocurrió un error al procesar la revisión con IA.';
$string['promptdefaulttext'] = 'Responde con tono empático y motivador';
$string['qualify'] = 'Calificar';
$string['queued'] = 'Todas las entregas han sido enviadas a la cola para revisión con IA. Serán procesadas en breve.';
$string['reloadpage'] = 'Recarga la página para ver los resultados actualizados.';
$string['require_approval'] = 'Revisar respuesta IA';
$string['retry'] = 'Reintentar';
$string['retryallfailed'] = 'Reintentar fallidos';
$string['retryallqueued'] = '{$a} revisión(es) fallida(s) reencolada(s).';
$string['retryqueued'] = 'Revisión reencolada; se procesará en breve.';
$string['review'] = 'Revisar';
$string['reviewaidisabled'] = 'La revisión con IA está desactivada para esta tarea.';
$string['reviewall'] = 'Revisar todos';
$string['reviewcancelled'] = 'Revisión de IA cancelada.';
$string['reviewhistory'] = 'Historial de revisión con IA';
$string['reviewnotsubmitted'] = 'El intento del envío no está en estado enviado, por lo que la IA no puede revisarlo.';
$string['reviewwithai'] = 'Revisión con IA';
$string['rubricfailed'] = 'No se logró inyectar la rúbrica después de 20 intentos';
$string['rubricmustarray'] = 'La respuesta a la rúbrica debe ser una matriz.';
$string['rubricsuccess'] = 'Rúbrica inyectada con éxito';
$string['save'] = 'Guardar';
$string['saveapprove'] = 'Guardar y Aprobar';
$string['status'] = 'Estado';
$string['statusapprove'] = 'Aprobado';
$string['statuserror'] = 'Error';
$string['statuspending'] = 'Pendiente';
$string['statusrejected'] = 'Rechazado';
$string['statussuperseded'] = 'Reemplazado (intento anterior)';
$string['submission_draft'] = 'Borrador';
$string['submission_new'] = 'Nuevo';
$string['submission_none'] = 'Sin entrega';
$string['submission_submitted'] = 'Enviado';
$string['submittedfiles'] = 'Archivos enviados';
$string['superseded'] = 'Reemplazado';
$string['task_process_ai_queue'] = 'Procesar cola diferida de Assign AI';
$string['task_reap_stuck'] = 'Marcar revisiones de IA atascadas';
$string['task_retry_failed'] = 'Reintentar revisiones de IA fallidas';
$string['unexpectederror'] = 'Ocurrió un error inesperado: {$a}';
$string['usedelay'] = 'Usar revisión diferida';
$string['usedelay_help'] = 'Si está activado, la revisión con IA se ejecutará después de un tiempo de espera configurable en lugar de ejecutarse inmediatamente.';
$string['validity'] = 'Vigencia';
$string['viewaifeedback'] = 'Ver AI feedback';
$string['viewdetails'] = 'Ver detalles';
$string['viewlog'] = 'Ver registro';
