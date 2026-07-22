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

$string['actions'] = 'Действия';
$string['ai_response_language'] = 'Язык ответов ИИ';
$string['ai_response_language_help'] = 'Выберите язык, на котором ИИ будет отвечать при проверке этого задания.';
$string['aiconfigheader'] = 'Datacurso Задания ИИ';
$string['aiprompt'] = 'Дайте инструкции для ИИ';
$string['aiprompt_help'] = 'Дополнительные инструкции, отправляемые ИИ в поле "prompt".';
$string['aistatus'] = 'Статус ИИ';
$string['aistatus_initial_help'] = 'Отправьте работу в ИИ, чтобы сформировать предложение.';
$string['aistatus_initial_short'] = 'Ожидает проверки ИИ';
$string['aistatus_pending_help'] = 'Предложение ИИ готово. Откройте детали, чтобы отредактировать или утвердить его.';
$string['aistatus_pending_short'] = 'Ожидает утверждения';
$string['aistatus_processing_help'] = 'ИИ сейчас обрабатывает эту работу. Это может занять некоторое время.';
$string['aistatus_queued_help'] = 'Эта работа поставлена в очередь и скоро начнет обрабатываться.';
$string['aistatus_queued_short'] = 'в очереди';
$string['aitaskdone'] = 'Обработка ИИ завершена. Всего обработано отправок: {$a}';
$string['aitaskstart'] = 'Обработка отправок ИИ для курса: {$a}';
$string['aitaskuserqueued'] = 'Отправка в очереди для пользователя с ID {$a->id} ({$a->name})';
$string['altlogo'] = 'Логотип Datacurso';
$string['approveall'] = 'Одобрить все';
$string['assign_ai:changestatus'] = 'Изменить статус утверждения ИИ';
$string['assign_ai:review'] = 'Проверить предложения ИИ для заданий';
$string['assign_ai:viewdetails'] = 'Просмотреть детали комментариев ИИ';
$string['attemptnumber'] = 'Попытка';
$string['autograde'] = 'Автоодобрение обратной связи ИИ';
$string['autograde_help'] = 'Если включено, оценки и комментарии, созданные ИИ, автоматически применяются к работам студентов без ручного утверждения.';
$string['autogradegrader'] = 'Оценщик для автоматических одобрений';
$string['autogradegrader_help'] = 'Выберите пользователя, который будет записан как оценщик при автоматическом одобрении обратной связи ИИ. Показаны только пользователи, которые могут оценивать задания в этом курсе.';
$string['backtocourse'] = 'Назад к курсу';
$string['backtoreview'] = 'Назад к проверке ИИ';
$string['confirm_approve_all'] = 'Одобрить все текущие предложения ИИ и применить их оценки/комментарии студентам. Продолжить?';
$string['confirm_cancel_review'] = 'Отменить эту проверку ИИ? Она вернётся в состояние ожидания, чтобы вы могли повторить её.';
$string['confirm_review_all'] = 'Отправить все работы со статусом "Ожидает проверки ИИ" в ИИ и запустить обработку. Это может занять несколько минут. Продолжить?';
$string['current'] = 'Текущая';
$string['defaultautograde'] = 'Автоодобрение обратной связи ИИ по умолчанию';
$string['defaultautograde_desc'] = 'Задает значение по умолчанию для новых заданий.';
$string['defaultdelayminutes'] = 'Время ожидания по умолчанию (в минутах)';
$string['defaultdelayminutes_desc'] = 'Стандартная задержка, когда включена отложенная проверка.';
$string['defaultenableai'] = 'Включить ИИ';
$string['defaultenableai_desc'] = 'Управляет глобальной доступностью ИИ для заданий. Если отключено, ИИ выключается для всех существующих заданий и не может быть включён на уровне отдельного задания, пока глобальная настройка снова не будет включена.';
$string['defaultprompt'] = 'Дайте инструкции для ИИ по умолчанию';
$string['defaultprompt_desc'] = 'Этот текст используется по умолчанию и отправляется в поле "prompt". Его можно переопределить для каждого задания.';
$string['defaultusedelay'] = 'Использовать отложенную проверку по умолчанию';
$string['defaultusedelay_desc'] = 'Определяет, включена ли отложенная проверка по умолчанию в новых заданиях.';
$string['delayminutes'] = 'Время ожидания (в минутах)';
$string['delayminutes_help'] = 'Количество минут, которое нужно подождать после публикации ответа студентом перед запуском проверки с помощью ИИ.';
$string['downloadlog'] = 'Скачать журнал';
$string['edited'] = 'Изменено';
$string['editgrade'] = 'Изменить оценку';
$string['email'] = 'Электронная почта';
$string['enableai'] = 'Включить ИИ';
$string['enableai_global_disabled_notice'] = 'Включение ИИ для этого задания недоступно, так как администратор глобально отключил эту возможность.';
$string['enableai_help'] = 'Если отключено, остальные параметры этого раздела для данного задания не отображаются.';
$string['enableassignai'] = 'Включить Assign AI';
$string['enableassignai_desc'] = 'Если отключено, раздел "Datacurso Assign AI" скрывается в настройках задания, а автоматическая обработка приостанавливается.';
$string['error_advancedresponsemissing'] = 'Задание оценивается расширенным методом ({$a}), но ответ ИИ не содержит данных для этого метода. Оценка не была применена.';
$string['error_airequest'] = 'Ошибка при связи со службой ИИ: {$a}';
$string['error_guidemismatch'] = 'Ответ ИИ не соответствует руководству по оцениванию этого задания. Оценка не была применена. Несовпадающие критерии: {$a}';
$string['error_processing_timeout'] = 'Время обработки истекло без ответа; повторите попытку.';
$string['error_rubricmismatch'] = 'Ответ ИИ не соответствует рубрике этого задания. Оценка не была применена. Несовпадающие критерии: {$a}';
$string['errorparsingguide'] = 'Ошибка при разборе ответа руководства по оцениванию: {$a}';
$string['errorparsingrubric'] = 'Ошибка при разборе ответа рубрики: {$a}';
$string['feedbackcomments'] = 'Комментарии';
$string['feedbackcommentsfull'] = 'Комментарии обратной связи';
$string['fullname'] = 'Полное имя';
$string['grade'] = 'Оценка';
$string['gradesuccess'] = 'Оценка успешно добавлена';
$string['gradingfailed_body'] = 'Оценивание с помощью ИИ для задания «{$a->assignment}» (студент: {$a->student}) не удалось, автоматические повторные попытки исчерпаны. Последняя ошибка: {$a->error}';
$string['gradingfailed_subject'] = 'Сбой оценивания с помощью ИИ: {$a}';
$string['invalidpendingrecord'] = 'Запись ИИ не относится к этому заданию.';
$string['lastmodified'] = 'Последнее изменение';
$string['log'] = 'Журнал';
$string['logdetails'] = 'Сведения журнала ИИ';
$string['logerror'] = 'Ошибка';
$string['logfailed'] = 'Не удалось';
$string['logsuccess'] = 'Успешно';
$string['manytasksreviewed'] = 'Проверено заданий: {$a}';
$string['messageprovider:gradingfailed'] = 'Уведомления о сбоях оценивания с помощью ИИ';
$string['missingtaskparams'] = 'Отсутствуют параметры задания. Невозможно начать пакетную обработку ИИ.';
$string['modaltitle'] = 'Обратная связь ИИ';
$string['nopermissiontochangestatus'] = 'У вас нет прав сохранять или утверждать изменения в проверках ИИ. Вы можете только просматривать сведения.';
$string['norecords'] = 'Записей не найдено';
$string['nostatus'] = 'Нет обратной связи';
$string['nosubmissions'] = 'Не найдено отправок для обработки.';
$string['notasksfound'] = 'Нет заданий для проверки';
$string['onetaskreviewed'] = 'Проверено 1 задание';
$string['pluginname'] = 'Assignment AI';
$string['privacy:metadata:local_assign_ai_pending'] = 'Хранит обратную связь, сгенерированную ИИ, ожидающую утверждения.';
$string['privacy:metadata:local_assign_ai_pending:approval_token'] = 'Уникальный токен для отслеживания утверждений.';
$string['privacy:metadata:local_assign_ai_pending:assignmentid'] = 'Задание, к которому относится эта обратная связь ИИ.';
$string['privacy:metadata:local_assign_ai_pending:attemptnumber'] = 'Номер попытки отправки, к которой относится этот отзыв ИИ.';
$string['privacy:metadata:local_assign_ai_pending:courseid'] = 'Курс, связанный с этой обратной связью.';
$string['privacy:metadata:local_assign_ai_pending:edited'] = 'Отражает ли эта оценка редактирование отправки студентом.';
$string['privacy:metadata:local_assign_ai_pending:errormessage'] = 'Ошибка, зарегистрированная при сбое обработки ИИ.';
$string['privacy:metadata:local_assign_ai_pending:grade'] = 'Предложенная оценка, созданная ИИ.';
$string['privacy:metadata:local_assign_ai_pending:message'] = 'Сообщение обратной связи, созданное ИИ.';
$string['privacy:metadata:local_assign_ai_pending:rubric_response'] = 'Обратная связь по рубрике, созданная ИИ.';
$string['privacy:metadata:local_assign_ai_pending:status'] = 'Статус утверждения обратной связи.';
$string['privacy:metadata:local_assign_ai_pending:submissionid'] = 'Попытка отправки, для которой был создан этот отзыв ИИ.';
$string['privacy:metadata:local_assign_ai_pending:submissionmodified'] = 'Время изменения отправки, зафиксированное при создании этого отзыва ИИ.';
$string['privacy:metadata:local_assign_ai_pending:title'] = 'Заголовок сгенерированной обратной связи.';
$string['privacy:metadata:local_assign_ai_pending:userid'] = 'Пользователь, для которого была создана обратная связь ИИ.';
$string['processed'] = 'Успешно обработано отправок: {$a}.';
$string['processing'] = 'Обработка';
$string['processingerror'] = 'Произошла ошибка при обработке проверки ИИ.';
$string['promptdefaulttext'] = 'Отвечай в эмпатичном и мотивирующем тоне';
$string['qualify'] = 'Оценить';
$string['queued'] = 'Все отправки помещены в очередь для проверки ИИ. Они будут обработаны в ближайшее время.';
$string['reloadpage'] = 'Перезагрузите страницу, чтобы увидеть обновленные результаты.';
$string['require_approval'] = 'Проверить ответ ИИ';
$string['retry'] = 'Повторить';
$string['retryallfailed'] = 'Повторить неудавшиеся';
$string['retryallqueued'] = 'Повторно поставлено в очередь неудавшихся проверок: {$a}.';
$string['retryqueued'] = 'Проверка повторно поставлена в очередь; она будет обработана в ближайшее время.';
$string['review'] = 'Проверить';
$string['reviewaidisabled'] = 'Проверка ИИ отключена для этого задания.';
$string['reviewall'] = 'Проверить все';
$string['reviewcancelled'] = 'Проверка ИИ отменена.';
$string['reviewhistory'] = 'История проверки ИИ';
$string['reviewnotsubmitted'] = 'Попытка отправки не находится в состоянии «Отправлено», поэтому ИИ не может её проверить.';
$string['reviewwithai'] = 'Проверка с ИИ';
$string['rubricfailed'] = 'Не удалось вставить рубрику после 20 попыток';
$string['rubricmustarray'] = 'Ответ рубрики должен быть массивом.';
$string['rubricsuccess'] = 'Рубрика успешно вставлена';
$string['save'] = 'Сохранить';
$string['saveapprove'] = 'Сохранить и утвердить';
$string['status'] = 'Статус';
$string['statusapprove'] = 'Утверждено';
$string['statuserror'] = 'Ошибка';
$string['statuspending'] = 'Ожидает';
$string['statusrejected'] = 'Отклонено';
$string['statussuperseded'] = 'Заменено (предыдущая попытка)';
$string['submission_draft'] = 'Черновик';
$string['submission_new'] = 'Новое';
$string['submission_none'] = 'Нет отправки';
$string['submission_submitted'] = 'Отправлено';
$string['submittedfiles'] = 'Отправленные файлы';
$string['superseded'] = 'Заменено';
$string['task_process_ai_queue'] = 'Обработать отложенную очередь Assign AI';
$string['task_reap_stuck'] = 'Очистка зависших отправок ИИ';
$string['task_retry_failed'] = 'Повтор неудавшихся отправок ИИ';
$string['unexpectederror'] = 'Произошла непредвиденная ошибка: {$a}';
$string['usedelay'] = 'Использовать отложенную проверку';
$string['usedelay_help'] = 'Если включено, проверка с помощью ИИ будет выполнена после настраиваемой задержки, а не сразу.';
$string['validity'] = 'Актуальность';
$string['viewaifeedback'] = 'Просмотреть отзыв ИИ';
$string['viewdetails'] = 'Просмотреть детали';
$string['viewlog'] = 'Просмотреть журнал';
