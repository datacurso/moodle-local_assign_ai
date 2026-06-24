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

$string['actions'] = 'Aktionen';
$string['ai_response_language'] = 'Sprache der KI-Antworten';
$string['ai_response_language_help'] = 'Wählen Sie die Sprache aus, in der die KI bei der Überprüfung dieser Aufgabe antworten soll.';
$string['aiconfigheader'] = 'Datacurso Aufgaben-KI';
$string['aiprompt'] = 'Gib der KI Anweisungen';
$string['aiprompt_help'] = 'Zusätzliche Hinweise, die im Feld "prompt" an die KI gesendet werden.';
$string['aistatus'] = 'KI-Status';
$string['aistatus_initial_help'] = 'Senden Sie die Abgabe an die KI, um einen Vorschlag zu erstellen.';
$string['aistatus_initial_short'] = 'Ausstehende KI-Prüfung';
$string['aistatus_pending_help'] = 'Der KI-Vorschlag ist bereit. Öffnen Sie die Details, um ihn zu bearbeiten oder zu genehmigen.';
$string['aistatus_pending_short'] = 'Ausstehende Genehmigung';
$string['aistatus_processing_help'] = 'Die KI verarbeitet diese Abgabe gerade. Das kann etwas dauern.';
$string['aistatus_queued_help'] = 'Diese Abgabe wurde in die Warteschlange gestellt und wird bald verarbeitet.';
$string['aistatus_queued_short'] = 'in Warteschlange';
$string['aitaskdone'] = 'KI-Verarbeitung abgeschlossen. Insgesamt verarbeitete Einreichungen: {$a}';
$string['aitaskstart'] = 'KI-Einreichungen für den Kurs werden verarbeitet: {$a}';
$string['aitaskuserqueued'] = 'Einreichung in der Warteschlange für Benutzer mit ID {$a->id} ({$a->name})';
$string['altlogo'] = 'Datacurso-Logo';
$string['approveall'] = 'Alle genehmigen';
$string['assign_ai:changestatus'] = 'KI-Genehmigungsstatus ändern';
$string['assign_ai:review'] = 'KI-Vorschläge für Aufgaben überprüfen';
$string['assign_ai:viewdetails'] = 'KI-Kommentardetails anzeigen';
$string['attemptnumber'] = 'Versuch';
$string['autograde'] = 'KI-Feedback automatisch genehmigen';
$string['autograde_help'] = 'Wenn aktiviert, werden von der KI erzeugte Bewertungen und Kommentare automatisch auf die Abgaben der Teilnehmenden angewendet, ohne manuelle Genehmigung.';
$string['autogradegrader'] = 'Erfasster Bewertender für automatische Genehmigungen';
$string['autogradegrader_help'] = 'Wählen Sie den Benutzer aus, der als Bewertender eingetragen wird, wenn KI-Feedback automatisch genehmigt wird. Es werden nur Benutzer mit Bewertungsrecht in diesem Kurs angezeigt.';
$string['backtocourse'] = 'Zurück zum Kurs';
$string['backtoreview'] = 'Zurück zur KI-Überprüfung';
$string['confirm_approve_all'] = 'Alle aktuell ausstehenden KI-Vorschläge genehmigen und deren Bewertungen/Kommentare auf Studierende anwenden. Möchten Sie fortfahren?';
$string['confirm_cancel_review'] = 'Diese KI-Überprüfung abbrechen? Sie kehrt in den Status „Ausstehend“ zurück, damit Sie sie erneut versuchen können.';
$string['confirm_review_all'] = 'Alle als "Ausstehende KI-Prüfung" markierten Abgaben an die KI senden und die Verarbeitung starten. Dies kann einige Minuten dauern. Möchten Sie fortfahren?';
$string['current'] = 'Aktuell';
$string['defaultautograde'] = 'KI-Feedback standardmäßig automatisch genehmigen';
$string['defaultautograde_desc'] = 'Legt den Standardwert für neue Aufgaben fest.';
$string['defaultdelayminutes'] = 'Standardwartezeit (Minuten)';
$string['defaultdelayminutes_desc'] = 'Standardwartezeit, wenn verzögerte Überprüfung aktiviert ist.';
$string['defaultenableai'] = 'KI aktivieren';
$string['defaultenableai_desc'] = 'Steuert die globale Verfügbarkeit von KI für Aufgaben. Wenn deaktiviert, wird KI in allen bestehenden Aufgaben ausgeschaltet und kann pro Aufgabe erst wieder aktiviert werden, wenn die globale Option erneut eingeschaltet wird.';
$string['defaultprompt'] = 'Gib der KI standardmäßig Anweisungen';
$string['defaultprompt_desc'] = 'Dieser Text wird als Standard verwendet und im Feld "prompt" gesendet. Er kann pro Aufgabe überschrieben werden.';
$string['defaultusedelay'] = 'Verzögerte Überprüfung standardmäßig verwenden';
$string['defaultusedelay_desc'] = 'Legt fest, ob verzögerte Überprüfung bei neuen Aufgaben standardmäßig aktiviert ist.';
$string['delayminutes'] = 'Wartezeit (Minuten)';
$string['delayminutes_help'] = 'Anzahl der Minuten, die nach dem Beitrag des Teilnehmers gewartet werden soll, bevor die KI-Überprüfung ausgeführt wird.';
$string['downloadlog'] = 'Protokoll herunterladen';
$string['edited'] = 'Bearbeitet';
$string['editgrade'] = 'Bewertung bearbeiten';
$string['email'] = 'E-Mail';
$string['enableai'] = 'KI aktivieren';
$string['enableai_global_disabled_notice'] = 'Die Aktivierung der KI für diese Aufgabe ist nicht verfügbar, da sie von einer Administratorin bzw. einem Administrator global deaktiviert wurde.';
$string['enableai_help'] = 'Wenn deaktiviert, werden die übrigen Optionen dieses Abschnitts für diese Aufgabe nicht angezeigt.';
$string['enableassignai'] = 'Assign AI aktivieren';
$string['enableassignai_desc'] = 'Wenn deaktiviert, wird der Abschnitt "Datacurso Assign AI" in den Aufgabeneinstellungen ausgeblendet und die automatische Verarbeitung pausiert.';
$string['error_airequest'] = 'Fehler bei der Kommunikation mit dem KI-Dienst: {$a}';
$string['error_processing_timeout'] = 'Die Verarbeitung hat das Zeitlimit ohne Antwort überschritten; bitte erneut versuchen.';
$string['error_ws_not_configured'] = 'Aktionen zur KI-Überprüfung sind nicht verfügbar, da der Datacurso-Webservice nicht konfiguriert ist. Schließen Sie die Einrichtung unter <a href="{$a->url}">Datacurso-Webservice-Konfiguration</a> ab oder wenden Sie sich an Ihre Administration.';
$string['errorparsingrubric'] = 'Fehler beim Analysieren der Rubrik-Antwort: {$a}';
$string['feedbackcomments'] = 'Kommentare';
$string['feedbackcommentsfull'] = 'Feedback-Kommentare';
$string['fullname'] = 'Vollständiger Name';
$string['grade'] = 'Bewertung';
$string['gradesuccess'] = 'Bewertung erfolgreich eingefügt';
$string['lastmodified'] = 'Zuletzt geändert';
$string['log'] = 'Protokoll';
$string['logdetails'] = 'KI-Protokolldetails';
$string['logerror'] = 'Fehler';
$string['logfailed'] = 'Fehlgeschlagen';
$string['logsuccess'] = 'Erfolgreich';
$string['manytasksreviewed'] = '{$a} Aufgaben überprüft';
$string['missingtaskparams'] = 'Aufgabenparameter fehlen. KI-Batchverarbeitung kann nicht gestartet werden.';
$string['modaltitle'] = 'KI-Feedback';
$string['nopermissiontochangestatus'] = 'Sie haben keine Berechtigung, Änderungen an KI-Überprüfungen zu speichern oder zu genehmigen. Sie können nur die Details ansehen.';
$string['norecords'] = 'Keine Datensätze gefunden';
$string['nostatus'] = 'Kein Feedback';
$string['nosubmissions'] = 'Keine Einreichungen zum Verarbeiten gefunden.';
$string['notasksfound'] = 'Keine Aufgaben zur Überprüfung gefunden';
$string['onetaskreviewed'] = '1 Aufgabe überprüft';
$string['pluginname'] = 'Assignment AI';
$string['privacy:metadata:local_assign_ai_pending'] = 'Speichert KI-generiertes Feedback, das auf Genehmigung wartet.';
$string['privacy:metadata:local_assign_ai_pending:approval_token'] = 'Einzigartiges Token zur Verfolgung von Genehmigungen.';
$string['privacy:metadata:local_assign_ai_pending:assignmentid'] = 'Die Aufgabe, zu der dieses KI-Feedback gehört.';
$string['privacy:metadata:local_assign_ai_pending:attemptnumber'] = 'Die Nummer des Abgabeversuchs, auf den sich dieses KI-Feedback bezieht.';
$string['privacy:metadata:local_assign_ai_pending:courseid'] = 'Der Kurs, der mit diesem Feedback verknüpft ist.';
$string['privacy:metadata:local_assign_ai_pending:edited'] = 'Ob diese Bewertung eine Bearbeitung der Abgabe durch die Studierenden widerspiegelt.';
$string['privacy:metadata:local_assign_ai_pending:errormessage'] = 'Der gemeldete Fehler, wenn die KI-Verarbeitung fehlgeschlagen ist.';
$string['privacy:metadata:local_assign_ai_pending:grade'] = 'Die von der KI vorgeschlagene Bewertung.';
$string['privacy:metadata:local_assign_ai_pending:message'] = 'Die von der KI generierte Feedbacknachricht.';
$string['privacy:metadata:local_assign_ai_pending:rubric_response'] = 'Das von der KI generierte Rubrik-Feedback.';
$string['privacy:metadata:local_assign_ai_pending:status'] = 'Genehmigungsstatus des Feedbacks.';
$string['privacy:metadata:local_assign_ai_pending:submissionid'] = 'Der Abgabeversuch, für den dieses KI-Feedback erstellt wurde.';
$string['privacy:metadata:local_assign_ai_pending:submissionmodified'] = 'Der Änderungszeitpunkt der Abgabe, der bei der Erstellung dieses KI-Feedbacks erfasst wurde.';
$string['privacy:metadata:local_assign_ai_pending:title'] = 'Titel des generierten Feedbacks.';
$string['privacy:metadata:local_assign_ai_pending:userid'] = 'Der Benutzer, für den das KI-Feedback generiert wurde.';
$string['processed'] = '{$a} Einreichung(en) erfolgreich verarbeitet.';
$string['processing'] = 'Verarbeitung läuft';
$string['processingerror'] = 'Fehler bei der KI-Überprüfung aufgetreten.';
$string['promptdefaulttext'] = 'Antworte in einem empathischen und motivierenden Ton';
$string['qualify'] = 'Bewerten';
$string['queued'] = 'Alle Einreichungen wurden zur KI-Überprüfung in die Warteschlange gestellt. Sie werden in Kürze verarbeitet.';
$string['reloadpage'] = 'Seite neu laden, um die aktualisierten Ergebnisse zu sehen.';
$string['require_approval'] = 'KI-Antwort überprüfen';
$string['retry'] = 'Erneut versuchen';
$string['retryallfailed'] = 'Fehlgeschlagene erneut versuchen';
$string['retryallqueued'] = '{$a} fehlgeschlagene Überprüfung(en) erneut in die Warteschlange gestellt.';
$string['retryqueued'] = 'Überprüfung erneut in die Warteschlange gestellt; sie wird in Kürze verarbeitet.';
$string['review'] = 'Überprüfen';
$string['reviewaidisabled'] = 'Die KI-Überprüfung ist für diese Aufgabe deaktiviert.';
$string['reviewall'] = 'Alle überprüfen';
$string['reviewcancelled'] = 'KI-Überprüfung abgebrochen.';
$string['reviewhistory'] = 'Verlauf der KI-Überprüfung';
$string['reviewnotsubmitted'] = 'Der Abgabeversuch befindet sich nicht im Status „Eingereicht“ und kann daher nicht von der KI überprüft werden.';
$string['reviewwithai'] = 'KI-Überprüfung';
$string['rubricfailed'] = 'Rubrik konnte nach 20 Versuchen nicht eingefügt werden';
$string['rubricmustarray'] = 'Die Rubrik-Antwort muss ein Array sein.';
$string['rubricsuccess'] = 'Rubrik erfolgreich eingefügt';
$string['save'] = 'Speichern';
$string['saveapprove'] = 'Speichern und genehmigen';
$string['status'] = 'Status';
$string['statusapprove'] = 'Genehmigt';
$string['statuserror'] = 'Fehler';
$string['statuspending'] = 'Ausstehend';
$string['statusrejected'] = 'Abgelehnt';
$string['statussuperseded'] = 'Ersetzt (vorheriger Versuch)';
$string['submission_draft'] = 'Entwurf';
$string['submission_new'] = 'Neu';
$string['submission_none'] = 'Keine Einreichung';
$string['submission_submitted'] = 'Eingereicht';
$string['submittedfiles'] = 'Eingereichte Dateien';
$string['superseded'] = 'Ersetzt';
$string['task_process_ai_queue'] = 'Verzögerte Warteschlange von Assign AI verarbeiten';
$string['task_reap_stuck'] = 'Hängengebliebene KI-Einreichungen bereinigen';
$string['task_retry_failed'] = 'Fehlgeschlagene KI-Einreichungen erneut versuchen';
$string['unexpectederror'] = 'Unerwarteter Fehler: {$a}';
$string['usedelay'] = 'Verzögerte Überprüfung verwenden';
$string['usedelay_help'] = 'Wenn aktiviert, wird die KI-Überprüfung nach einer konfigurierbaren Wartezeit ausgeführt, anstatt sofort zu starten.';
$string['validity'] = 'Gültigkeit';
$string['viewaifeedback'] = 'KI-Feedback anzeigen';
$string['viewdetails'] = 'Details anzeigen';
$string['viewlog'] = 'Protokoll anzeigen';
