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

$string['actions'] = 'Actions';
$string['ai_response_language'] = 'Langue des réponses de l\'IA';
$string['ai_response_language_help'] = 'Sélectionnez la langue dans laquelle l\'IA répondra lors de la révision de ce devoir.';
$string['aiconfigheader'] = 'Datacurso Devoirs IA';
$string['aiprompt'] = 'Donnez des consignes à l’IA';
$string['aiprompt_help'] = 'Consignes supplémentaires envoyées à l’IA dans le champ "prompt".';
$string['aistatus'] = 'Statut IA';
$string['aistatus_initial_help'] = 'Envoyez la soumission à l\'IA pour générer une proposition.';
$string['aistatus_initial_short'] = 'Révision IA en attente';
$string['aistatus_pending_help'] = 'La proposition de l\'IA est prête. Ouvrez les détails pour la modifier ou l\'approuver.';
$string['aistatus_pending_short'] = 'En attente d\'approbation';
$string['aistatus_processing_help'] = 'L\'IA traite actuellement cette soumission. Cela peut prendre un moment.';
$string['aistatus_queued_help'] = 'Cette soumission a été mise en file d\'attente et sera traitée sous peu.';
$string['aistatus_queued_short'] = 'en file';
$string['aitaskdone'] = 'Traitement IA terminé. Total des soumissions traitées : {$a}';
$string['aitaskstart'] = 'Traitement des soumissions IA pour le cours : {$a}';
$string['aitaskuserqueued'] = 'Soumission en file d’attente pour l’utilisateur avec ID {$a->id} ({$a->name})';
$string['altlogo'] = 'Logo Datacurso';
$string['approveall'] = 'Tout approuver';
$string['assign_ai:changestatus'] = 'Modifier le statut d’approbation de l’IA';
$string['assign_ai:review'] = 'Examiner les suggestions IA pour les devoirs';
$string['assign_ai:viewdetails'] = 'Voir les détails des commentaires IA';
$string['attemptnumber'] = 'Tentative';
$string['autograde'] = 'Approuver automatiquement le retour IA';
$string['autograde_help'] = 'Si activé, les notes et commentaires générés par l\'IA sont appliqués automatiquement aux soumissions des étudiants, sans approbation manuelle.';
$string['autogradegrader'] = 'Évaluateur enregistré pour les approbations automatiques';
$string['autogradegrader_help'] = 'Sélectionnez l\'utilisateur qui sera enregistré comme évaluateur lorsque le retour IA est approuvé automatiquement. Seuls les utilisateurs pouvant noter dans ce cours sont affichés.';
$string['backtocourse'] = 'Retour au cours';
$string['backtoreview'] = 'Retour à la révision IA';
$string['confirm_approve_all'] = 'Approuver toutes les propositions IA actuellement en attente et appliquer leurs notes/commentaires aux étudiants. Voulez-vous continuer ?';
$string['confirm_cancel_review'] = 'Annuler cette révision IA ? Elle reviendra à l’état « En attente » afin que vous puissiez la relancer.';
$string['confirm_review_all'] = 'Envoyer toutes les soumissions marquées "Révision IA en attente" à l\'IA et lancer le traitement. Cela peut prendre quelques minutes. Voulez-vous continuer ?';
$string['current'] = 'Actuel';
$string['defaultautograde'] = 'Approuver automatiquement le retour IA par défaut';
$string['defaultautograde_desc'] = 'Définit la valeur par défaut pour les nouveaux devoirs.';
$string['defaultdelayminutes'] = 'Délai d’attente par défaut (minutes)';
$string['defaultdelayminutes_desc'] = 'Délai par défaut utilisé lorsque la révision différée est activée.';
$string['defaultenableai'] = 'Activer l’IA';
$string['defaultenableai_desc'] = 'Contrôle la disponibilité globale de l’IA pour les devoirs. Si désactivée, l’IA est coupée pour tous les devoirs existants et ne peut pas être réactivée par devoir tant qu’elle n’est pas réactivée globalement.';
$string['defaultprompt'] = 'Donnez des consignes à l’IA par défaut';
$string['defaultprompt_desc'] = 'Ce texte est utilisé par défaut et envoyé dans le champ "prompt". Il peut être surchargé pour chaque devoir.';
$string['defaultusedelay'] = 'Utiliser une révision différée par défaut';
$string['defaultusedelay_desc'] = 'Définit si la révision différée est activée par défaut pour les nouveaux devoirs.';
$string['delayminutes'] = 'Délai d’attente (minutes)';
$string['delayminutes_help'] = 'Nombre de minutes à attendre après la publication de l’étudiant avant d’exécuter la révision par IA.';
$string['downloadlog'] = 'Télécharger le journal';
$string['edited'] = 'Modifié';
$string['editgrade'] = 'Modifier la note';
$string['email'] = 'E-mail';
$string['enableai'] = 'Activer l’IA';
$string['enableai_global_disabled_notice'] = 'L’activation de l’IA pour ce devoir n’est pas disponible, car elle a été désactivée globalement par un administrateur.';
$string['enableai_help'] = 'Si désactivé, les autres options de cette section ne sont pas affichées pour ce devoir.';
$string['enableassignai'] = 'Activer Assign AI';
$string['enableassignai_desc'] = 'Si désactivé, la section "Datacurso Assign AI" est masquée dans les paramètres de l’activité devoir et le traitement automatique est mis en pause.';
$string['error_airequest'] = 'Erreur de communication avec le service IA : {$a}';
$string['error_processing_timeout'] = 'Le traitement a expiré sans réponse ; veuillez réessayer.';
$string['error_ws_not_configured'] = 'Les actions de révision IA ne sont pas disponibles car le service web Datacurso n\'est pas configuré. Terminez la configuration à <a href="{$a->url}">Configuration du service web Datacurso</a> ou contactez votre administrateur.';
$string['errorparsingrubric'] = 'Erreur lors de l’analyse de la réponse de la grille : {$a}';
$string['feedbackcomments'] = 'Commentaires';
$string['feedbackcommentsfull'] = 'Commentaires de retour';
$string['fullname'] = 'Nom complet';
$string['grade'] = 'Note';
$string['gradesuccess'] = 'Note injectée avec succès';
$string['lastmodified'] = 'Dernière modification';
$string['log'] = 'Journal';
$string['logdetails'] = 'Détails du journal IA';
$string['logerror'] = 'Erreur';
$string['logfailed'] = 'Échec';
$string['logsuccess'] = 'Succès';
$string['manytasksreviewed'] = '{$a} tâches examinées';
$string['missingtaskparams'] = 'Paramètres de tâche manquants. Impossible de démarrer le traitement IA en lot.';
$string['modaltitle'] = 'Retour IA';
$string['nopermissiontochangestatus'] = 'Vous n’avez pas l’autorisation d’enregistrer ou d’approuver les modifications des révisions IA. Vous pouvez uniquement consulter les détails.';
$string['norecords'] = 'Aucun enregistrement trouvé';
$string['nostatus'] = 'Aucun retour';
$string['nosubmissions'] = 'Aucune soumission trouvée à traiter.';
$string['notasksfound'] = 'Aucune tâche à examiner';
$string['onetaskreviewed'] = '1 tâche examinée';
$string['pluginname'] = 'Assignment IA';
$string['privacy:metadata:local_assign_ai_pending'] = 'Stocke les retours IA en attente d’approbation.';
$string['privacy:metadata:local_assign_ai_pending:approval_token'] = 'Jeton unique utilisé pour le suivi des approbations.';
$string['privacy:metadata:local_assign_ai_pending:assignmentid'] = 'Le devoir auquel ce retour IA correspond.';
$string['privacy:metadata:local_assign_ai_pending:attemptnumber'] = 'Le numéro de la tentative de soumission auquel se rapporte ce retour IA.';
$string['privacy:metadata:local_assign_ai_pending:courseid'] = 'Le cours associé à ce retour.';
$string['privacy:metadata:local_assign_ai_pending:edited'] = 'Indique si cette évaluation reflète une modification de la soumission par l’étudiant.';
$string['privacy:metadata:local_assign_ai_pending:errormessage'] = 'L’erreur signalée lorsque le traitement IA a échoué.';
$string['privacy:metadata:local_assign_ai_pending:grade'] = 'La note proposée générée par l’IA.';
$string['privacy:metadata:local_assign_ai_pending:message'] = 'Le message de retour généré par l’IA.';
$string['privacy:metadata:local_assign_ai_pending:rubric_response'] = 'Le retour de grille généré par l’IA.';
$string['privacy:metadata:local_assign_ai_pending:status'] = 'Statut d’approbation du retour.';
$string['privacy:metadata:local_assign_ai_pending:submissionid'] = 'La tentative de soumission pour laquelle ce retour IA a été généré.';
$string['privacy:metadata:local_assign_ai_pending:submissionmodified'] = 'La date de modification de la soumission enregistrée lors de la génération de ce retour IA.';
$string['privacy:metadata:local_assign_ai_pending:title'] = 'Titre du retour généré.';
$string['privacy:metadata:local_assign_ai_pending:userid'] = 'L’utilisateur pour lequel le retour IA a été généré.';
$string['processed'] = '{$a} soumission(s) traitée(s) avec succès.';
$string['processing'] = 'Traitement en cours';
$string['processingerror'] = 'Une erreur s’est produite lors du traitement IA.';
$string['promptdefaulttext'] = 'Réponds avec un ton empathique et motivant';
$string['qualify'] = 'Noter';
$string['queued'] = 'Toutes les soumissions ont été placées en file d’attente pour révision IA. Elles seront traitées sous peu.';
$string['reloadpage'] = 'Rechargez la page pour voir les résultats mis à jour.';
$string['require_approval'] = 'Vérifier la réponse de l’IA';
$string['retry'] = 'Réessayer';
$string['retryallfailed'] = 'Réessayer les échecs';
$string['retryallqueued'] = '{$a} révision(s) en échec remise(s) en file d’attente.';
$string['retryqueued'] = 'Révision remise en file d’attente ; elle sera traitée sous peu.';
$string['review'] = 'Examiner';
$string['reviewaidisabled'] = 'La révision IA est désactivée pour ce devoir.';
$string['reviewall'] = 'Tout examiner';
$string['reviewcancelled'] = 'Révision IA annulée.';
$string['reviewhistory'] = 'Historique de révision IA';
$string['reviewnotsubmitted'] = 'La tentative de soumission n’est pas à l’état « Soumis » ; elle ne peut donc pas être révisée par l’IA.';
$string['reviewwithai'] = 'Révision IA';
$string['rubricfailed'] = 'Impossible d’injecter la grille après 20 tentatives';
$string['rubricmustarray'] = 'La réponse de la grille doit être un tableau.';
$string['rubricsuccess'] = 'Grille injectée avec succès';
$string['save'] = 'Enregistrer';
$string['saveapprove'] = 'Enregistrer et approuver';
$string['status'] = 'Statut';
$string['statusapprove'] = 'Approuvé';
$string['statuserror'] = 'Erreur';
$string['statuspending'] = 'En attente';
$string['statusrejected'] = 'Rejeté';
$string['statussuperseded'] = 'Remplacé (tentative précédente)';
$string['submission_draft'] = 'Brouillon';
$string['submission_new'] = 'Nouveau';
$string['submission_none'] = 'Aucune soumission';
$string['submission_submitted'] = 'Soumis';
$string['submittedfiles'] = 'Fichiers soumis';
$string['superseded'] = 'Remplacé';
$string['task_process_ai_queue'] = 'Traiter la file d’attente différée d’Assign AI';
$string['task_reap_stuck'] = 'Nettoyer les soumissions IA bloquées';
$string['task_retry_failed'] = 'Réessayer les soumissions IA en échec';
$string['unexpectederror'] = 'Erreur inattendue : {$a}';
$string['usedelay'] = 'Utiliser une révision différée';
$string['usedelay_help'] = 'Si activé, la révision par IA sera exécutée après un délai configurable au lieu d’être exécutée immédiatement.';
$string['validity'] = 'Validité';
$string['viewaifeedback'] = 'Voir le retour IA';
$string['viewdetails'] = 'Voir les détails';
$string['viewlog'] = 'Voir le journal';
