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

$string['actions'] = 'Ações';
$string['ai_response_language'] = 'Idioma das respostas da IA';
$string['ai_response_language_help'] = 'Selecione o idioma em que a IA responderá ao revisar esta tarefa.';
$string['aiconfigheader'] = 'Datacurso Tarefas IA';
$string['aiprompt'] = 'Dê instruções para a IA';
$string['aiprompt_help'] = 'Instruções adicionais enviadas para a IA no campo "prompt".';
$string['aistatus'] = 'Status da IA';
$string['aistatus_initial_help'] = 'Envie a entrega para a IA gerar uma proposta.';
$string['aistatus_initial_short'] = 'Pendente de revisão IA';
$string['aistatus_pending_help'] = 'A proposta da IA está pronta. Abra os detalhes para editar ou aprovar.';
$string['aistatus_pending_short'] = 'Pendente de aprovação';
$string['aistatus_processing_help'] = 'A IA está processando esta entrega no momento. Isso pode levar algum tempo.';
$string['aistatus_queued_help'] = 'Esta entrega foi colocada na fila e começará a ser processada em breve.';
$string['aistatus_queued_short'] = 'na fila';
$string['aitaskdone'] = 'Processamento de IA concluído. Total de envios processados: {$a}';
$string['aitaskstart'] = 'Processando envios de IA para o curso: {$a}';
$string['aitaskuserqueued'] = 'Envio na fila para o usuário com ID {$a->id} ({$a->name})';
$string['altlogo'] = 'Logo Datacurso';
$string['approveall'] = 'Aprovar todos';
$string['assign_ai:changestatus'] = 'Alterar o status de aprovação da IA';
$string['assign_ai:review'] = 'Revisar as sugestões da IA para as tarefas';
$string['assign_ai:viewdetails'] = 'Ver detalhes dos comentários da IA';
$string['attemptnumber'] = 'Tentativa';
$string['autograde'] = 'Aprovar automaticamente o feedback da IA';
$string['autograde_help'] = 'Quando ativado, as notas e comentários gerados pela IA são aplicados automaticamente às entregas dos estudantes, sem exigir aprovação manual.';
$string['autogradegrader'] = 'Avaliador registrado para aprovações automáticas';
$string['autogradegrader_help'] = 'Selecione o usuário que será registrado como avaliador sempre que o feedback da IA for aprovado automaticamente. Apenas usuários que podem avaliar tarefas neste curso são listados.';
$string['backtocourse'] = 'Voltar ao curso';
$string['backtoreview'] = 'Voltar à revisão com IA';
$string['confirm_approve_all'] = 'Aprovar todas as propostas de IA atualmente pendentes e aplicar suas notas/comentários aos estudantes. Deseja continuar?';
$string['confirm_cancel_review'] = 'Cancelar esta revisão com IA? Ela voltará ao estado pendente para que você possa tentar novamente.';
$string['confirm_review_all'] = 'Enviar todas as entregas marcadas como "Pendente de revisão IA" para a IA e iniciar o processamento. Isso pode levar alguns minutos. Deseja continuar?';
$string['current'] = 'Atual';
$string['defaultautograde'] = 'Aprovar automaticamente o feedback da IA por padrão';
$string['defaultautograde_desc'] = 'Define o valor padrão para novas tarefas.';
$string['defaultdelayminutes'] = 'Tempo de espera padrão (minutos)';
$string['defaultdelayminutes_desc'] = 'Tempo de espera padrão quando a revisão atrasada estiver habilitada.';
$string['defaultenableai'] = 'Habilitar IA';
$string['defaultenableai_desc'] = 'Controla a disponibilidade global da IA para as tarefas. Se desabilitada, a IA é pausada em todas as tarefas sem modificar a configuração armazenada; ao reativá-la, cada tarefa volta a funcionar com sua configuração original.';
$string['defaultprompt'] = 'Dê instruções para a IA por padrão';
$string['defaultprompt_desc'] = 'Este texto será usado por padrão e enviado no campo "prompt". Pode ser sobrescrito em cada tarefa.';
$string['defaultusedelay'] = 'Usar revisão atrasada por padrão';
$string['defaultusedelay_desc'] = 'Define se a revisão atrasada fica habilitada por padrão em novas tarefas.';
$string['delayminutes'] = 'Tempo de espera (minutos)';
$string['delayminutes_help'] = 'Quantidade de minutos a aguardar após o aluno publicar antes de executar a revisão com IA.';
$string['downloadlog'] = 'Baixar registro';
$string['edited'] = 'Editado';
$string['editgrade'] = 'Editar nota';
$string['email'] = 'E-mail';
$string['enableai'] = 'Habilitar IA';
$string['enableai_global_disabled_notice'] = 'A ativação de IA para esta tarefa não está disponível porque um administrador a desabilitou globalmente.';
$string['enableai_help'] = 'Se desabilitado, as demais opções desta seção não serão exibidas para esta tarefa.';
$string['enableassignai'] = 'Habilitar Tarefa IA';
$string['enableassignai_desc'] = 'Se desabilitado, a seção "Datacurso Assign AI" é ocultada nas configurações da atividade tarefa e o processamento automático é pausado.';
$string['error_advancedresponsemissing'] = 'A tarefa é avaliada com um método avançado ({$a}), mas a resposta da IA não contém dados para esse método. A nota não foi aplicada.';
$string['error_airequest'] = 'Erro ao se comunicar com o serviço de IA: {$a}';
$string['error_generic'] = 'A revisão por IA falhou. Consulte o registro do histórico de IA para mais detalhes.';
$string['error_guidemismatch'] = 'A resposta da IA não corresponde ao guia de avaliação desta tarefa. A nota não foi aplicada. Critérios sem correspondência: {$a}';
$string['error_processing_timeout'] = 'O processamento expirou sem resposta; tente novamente.';
$string['error_rubricmismatch'] = 'A resposta da IA não corresponde à rúbrica desta tarefa. A nota não foi aplicada. Critérios sem correspondência: {$a}';
$string['errorparsingguide'] = 'Erro ao analisar a resposta do guia de avaliação: {$a}';
$string['errorparsingrubric'] = 'Erro ao analisar a resposta da rúbrica: {$a}';
$string['feedbackcomments'] = 'Comentários';
$string['feedbackcommentsfull'] = 'Comentários de feedback';
$string['fullname'] = 'Nome completo';
$string['grade'] = 'Nota';
$string['gradesuccess'] = 'Nota inserida com sucesso';
$string['gradingfailed_body'] = 'A avaliação por IA da tarefa "{$a->assignment}" (estudante: {$a->student}) falhou e as novas tentativas automáticas se esgotaram. Último erro: {$a->error}';
$string['gradingfailed_subject'] = 'Falha na avaliação por IA: {$a}';
$string['invalidpendingrecord'] = 'O registro de IA não pertence a esta tarefa.';
$string['lastmodified'] = 'Última modificação';
$string['log'] = 'Registro';
$string['logdetails'] = 'Detalhes do registro de IA';
$string['logerror'] = 'Erro';
$string['logfailed'] = 'Falhou';
$string['logrubriccriterionbyid'] = 'corresponde por id ({$a})';
$string['logrubriccriterionbyname'] = 'corresponde por nome (id do critério {$a})';
$string['logrubriccriterionnotmatched'] = 'SEM correspondência';
$string['logrubricdiagfailed'] = 'Não foi possível gerar o diagnóstico de correspondência da rubrica.';
$string['logrubricfailurereason'] = 'motivo: {$a}';
$string['logrubricinvalidjson'] = 'O JSON da rubrica armazenado é inválido:';
$string['logrubricjson'] = 'JSON da rubrica (resposta da IA)';
$string['logrubriclevelbyid'] = 'id do nível {$a}';
$string['logrubriclevelbypoints'] = 'id do nível {$a} (corresponde por pontos)';
$string['logrubriclevelnotmatched'] = 'nível SEM correspondência';
$string['logrubricmatchfailed'] = 'Correspondência da rubrica: FALHOU ({$a->matched}/{$a->total})';
$string['logrubricmatchok'] = 'Correspondência da rubrica: OK ({$a->matched}/{$a->total})';
$string['logrubricmodelegacy'] = 'Modo de correspondência: legado (registro sem ids, salvo por uma versão anterior do plugin ou do serviço de IA)';
$string['logrubricmodestrict'] = 'Modo de correspondência: estrito (o registro inclui ids do Moodle)';
$string['logrubricnodefinition'] = 'Nenhuma definição de rubrica está disponível para a correspondência; apenas o JSON armazenado é exibido.';
$string['logsuccess'] = 'Sucesso';
$string['manytasksreviewed'] = '{$a} tarefas revisadas';
$string['messageprovider:gradingfailed'] = 'Notificações de falha na avaliação por IA';
$string['missingtaskparams'] = 'Parâmetros da tarefa ausentes. Não é possível iniciar o processamento em lote da IA.';
$string['modaltitle'] = 'Feedback da IA';
$string['nopermissiontochangestatus'] = 'Você não tem permissão para salvar ou aprovar alterações nas revisões com IA. Você só pode ver os detalhes.';
$string['norecords'] = 'Nenhum registro encontrado';
$string['nostatus'] = 'Sem feedback';
$string['nosubmissions'] = 'Nenhum envio encontrado para processar.';
$string['notasksfound'] = 'Nenhuma tarefa para revisar';
$string['onetaskreviewed'] = '1 tarefa revisada';
$string['pluginname'] = 'Assignment IA';
$string['privacy:metadata:datacurso_ai'] = 'Os dados do envio são enviados ao provedor de IA Datacurso para gerar notas e feedback. Os identificadores são pseudonimizados quando possível.';
$string['privacy:metadata:datacurso_ai:course_activity'] = 'O contexto do curso e da tarefa (nomes, descrição e instruções).';
$string['privacy:metadata:datacurso_ai:student_name'] = 'O nome completo do estudante (pseudonimizado antes do envio quando possível).';
$string['privacy:metadata:datacurso_ai:submission_files'] = 'Os arquivos anexados ao envio.';
$string['privacy:metadata:datacurso_ai:submission_text'] = 'O texto on-line do envio.';
$string['privacy:metadata:datacurso_ai:userid'] = 'O identificador do estudante cujo envio é avaliado.';
$string['privacy:metadata:local_assign_ai_config'] = 'Armazena a configuração de IA por tarefa.';
$string['privacy:metadata:local_assign_ai_config:assignmentid'] = 'A tarefa à qual a configuração pertence.';
$string['privacy:metadata:local_assign_ai_config:graderid'] = 'O usuário registrado como avaliador para notas aplicadas pela IA.';
$string['privacy:metadata:local_assign_ai_config:usermodified'] = 'O usuário que modificou a configuração por último.';
$string['privacy:metadata:local_assign_ai_pending'] = 'Armazena os feedbacks gerados pela IA pendentes de aprovação.';
$string['privacy:metadata:local_assign_ai_pending:approval_token'] = 'Token único usado para rastrear aprovações.';
$string['privacy:metadata:local_assign_ai_pending:assessment_guide_response'] = 'O feedback do guia de avaliação gerado pela IA.';
$string['privacy:metadata:local_assign_ai_pending:assignmentid'] = 'A tarefa à qual este feedback de IA pertence.';
$string['privacy:metadata:local_assign_ai_pending:attemptnumber'] = 'O número da tentativa de envio à qual este feedback da IA se refere.';
$string['privacy:metadata:local_assign_ai_pending:courseid'] = 'O curso associado a este feedback.';
$string['privacy:metadata:local_assign_ai_pending:edited'] = 'Se esta avaliação reflete uma edição do envio pelo estudante.';
$string['privacy:metadata:local_assign_ai_pending:errormessage'] = 'O erro relatado quando o processamento da IA falhou.';
$string['privacy:metadata:local_assign_ai_pending:grade'] = 'A nota proposta gerada pela IA.';
$string['privacy:metadata:local_assign_ai_pending:message'] = 'A mensagem de feedback gerada pela IA.';
$string['privacy:metadata:local_assign_ai_pending:rubric_response'] = 'O feedback de rúbrica gerado pela IA.';
$string['privacy:metadata:local_assign_ai_pending:status'] = 'O status de aprovação do feedback.';
$string['privacy:metadata:local_assign_ai_pending:submissionid'] = 'A tentativa de envio para a qual este feedback da IA foi gerado.';
$string['privacy:metadata:local_assign_ai_pending:submissionmodified'] = 'A data de modificação do envio registrada quando este feedback da IA foi gerado.';
$string['privacy:metadata:local_assign_ai_pending:title'] = 'O título do feedback gerado.';
$string['privacy:metadata:local_assign_ai_pending:userid'] = 'O usuário para quem o feedback da IA foi gerado.';
$string['privacy:metadata:local_assign_ai_queue'] = 'Enfileira tarefas de processamento de IA com atraso.';
$string['privacy:metadata:local_assign_ai_queue:payload'] = 'O conteúdo da tarefa, que inclui os identificadores de usuário e de módulo do curso do envio a processar.';
$string['processed'] = '{$a} envio(s) processado(s) com sucesso.';
$string['processing'] = 'Processando';
$string['processingerror'] = 'Ocorreu um erro ao processar a revisão com IA.';
$string['promptdefaulttext'] = 'Responda com tom empático e motivador';
$string['qualify'] = 'Avaliar';
$string['queued'] = 'Todos os envios foram colocados na fila para revisão pela IA. Serão processados em breve.';
$string['reloadpage'] = 'Recarregue a página para ver os resultados atualizados.';
$string['require_approval'] = 'Revisar resposta da IA';
$string['retry'] = 'Tentar novamente';
$string['retryallfailed'] = 'Tentar novamente os que falharam';
$string['retryallqueued'] = '{$a} revisão(ões) com falha recolocada(s) na fila.';
$string['retryqueued'] = 'Revisão recolocada na fila; será processada em breve.';
$string['review'] = 'Revisar';
$string['reviewaidisabled'] = 'A revisão com IA está desativada para esta tarefa.';
$string['reviewall'] = 'Revisar todos';
$string['reviewcancelled'] = 'Revisão com IA cancelada.';
$string['reviewhistory'] = 'Histórico de revisão com IA';
$string['reviewnotsubmitted'] = 'A tentativa de envio não está no estado enviado, portanto não pode ser revisada pela IA.';
$string['reviewwithai'] = 'Revisão com IA';
$string['rubricfailed'] = 'Falha ao injetar a rúbrica após 20 tentativas';
$string['rubricmustarray'] = 'A resposta da rúbrica deve ser uma matriz.';
$string['rubricsuccess'] = 'Rúbrica inserida com sucesso';
$string['save'] = 'Salvar';
$string['saveapprove'] = 'Salvar e Aprovar';
$string['status'] = 'Status';
$string['statusapprove'] = 'Aprovado';
$string['statuserror'] = 'Erro';
$string['statuspending'] = 'Pendente';
$string['statusrejected'] = 'Rejeitado';
$string['statussuperseded'] = 'Substituído (tentativa anterior)';
$string['submission_draft'] = 'Rascunho';
$string['submission_new'] = 'Novo';
$string['submission_none'] = 'Sem envio';
$string['submission_submitted'] = 'Enviado';
$string['submittedfiles'] = 'Arquivos enviados';
$string['superseded'] = 'Substituído';
$string['task_process_ai_queue'] = 'Processar fila adiada do Assign AI';
$string['task_reap_stuck'] = 'Limpar envios de IA travados';
$string['task_retry_failed'] = 'Tentar novamente os envios de IA com falha';
$string['unexpectederror'] = 'Ocorreu um erro inesperado: {$a}';
$string['usedelay'] = 'Usar revisão atrasada';
$string['usedelay_help'] = 'Se ativado, a revisão com IA será executada após um tempo de espera configurável em vez de ser executada imediatamente.';
$string['validity'] = 'Vigência';
$string['viewaifeedback'] = 'Ver feedback da IA';
$string['viewdetails'] = 'Ver detalhes';
$string['viewlog'] = 'Ver registro';
