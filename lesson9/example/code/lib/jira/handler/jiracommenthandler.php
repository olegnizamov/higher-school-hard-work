<?php

namespace KT\Integration\Jira\Handler;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Context;
use Bitrix\Main\Entity;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Integration\Forum\Task\Comment;
use CEventLog;
use Kt\Forum\Message;
use Kt\Forum\MessageCollection;
use Kt\Forum\MessageTable;
use KT\Integration\Jira\JiraExporter;
use Kt\Socialnetwork\Workgroup;
use Kt\Tasks\Task;
use Kt\Tasks\TaskTable;
use Throwable;

/**
 * Class CommentHandler - Класс обработчик событий для комментариев к задаче.
 */
class JiraCommentHandler
{
    /**
     * Обработчик события перед добавлением комментария.
     *
     * @param array $fields Массив полей комментария
     *
     * @see \Kt\Integration\Integration\Jira\Handler\JiraCommentHandlerTest::onBeforeMessageAdd test
     */
    public static function onBeforeMessageAdd($fields): bool
    {
        global $APPLICATION;

        $result = new Result();
        if (!(int) $fields[MessageTable::UF_JIRA_COMMENT_ID]) {
            return $result->isSuccess();
        }
        if ($fields['FORUM_ID'] != Comment::getForumId() || !preg_match('/^TASK_(.+)$/', $fields['XML_ID'])) {
            return $result->isSuccess();
        }
        $isDoubleExists = (bool) MessageTable::query()
            ->where(MessageTable::UF_JIRA_COMMENT_ID, $fields[MessageTable::UF_JIRA_COMMENT_ID])
            ->where('FORUM_ID', $fields['FORUM_ID'])
            ->where('TOPIC_ID', $fields['TOPIC_ID'])
            ->exec()
            ->getSelectedRowsCount()
            ;
        if ($isDoubleExists) {
            $result->addError(new Error(Loc::getMessage('DENIED_ADDING_COMMENT_DOUBLES')));
            $APPLICATION->ThrowException(Loc::getMessage('DENIED_ADDING_COMMENT_DOUBLES'));
        }

        return $result->isSuccess();
    }

    /**
     * Событие срабатывает при добавлении комментария.
     *
     * @param int   $messageId Id созданного комментария
     * @param array $data      Массив значений полей комментария
     */
    public static function onCommentAdd(int $messageId, array $data)
    {
        $result = new Entity\EventResult();
        if ('prod' !== Context::getCurrent()->getEnvironment()->get('APP_ENV')) {
            return $result;
        }
        /*
         * Комментарием к задаче считается только запись с параметром NEW_TOPIC = N.
         * Если NEW_TOPIC = Y - значит, это начальное сообщение скрытого форума,
         *      а именно скопированный текст описания задачи.
         * Поэтому такие записи мы пропускаем.
         */
        if ('Y' === $data['NEW_TOPIC'] || $data['FORUM_ID'] != Comment::getForumId()) {
            return $result;
        }
        /** @var Message $bitrixComment */
        $bitrixComment = MessageTable::query()
            ->where(MessageTable::FIELD_ID, $messageId)
            ->setSelect([
                '*',
                MessageTable::REF_TASK,
                MessageTable::REF_TASK . '.' . TaskTable::REF_FIELD_RESPONSIBLE,
                MessageTable::UF_JIRA_COMMENT_ID,
            ])
            ->setLimit(1)
            ->fetchObject()
            ;
        /*
         * Если уже есть привязка к комментарию в Jira, значит это комментарий, созданный из Jira.
         * Значит его выгружать повторно не нужно.
         */
        if ($bitrixComment->getUfJiraCommentId()) {
            return $result;
        }
        /** @var Task $bitrixTask */
        $bitrixTask = $bitrixComment->getTask();
        // Если сообщение не является комментарием к задаче - ничего не делаем
        if (!$bitrixTask) {
            return $result;
        }
        $bitrixTask->fillWorkgroup();
        /** @var Workgroup $bitrixProject */
        $bitrixProject = $bitrixTask->getWorkgroup();
        if (!$bitrixProject) {
            return $result;
        }
        $bitrixProject->fill('UF_*');
        if ($bitrixProject->hasJiraIntegration()) {
            $bitrixTask->fillUfJiraTaskId();
            /** @var null|int $jiraTaskId Id задачи в Jira */
            $jiraTaskId = $bitrixTask->getUfJiraTaskId();
            // Если задача не привязана к Jira, делаем выгрузку такой задачи в Jira.
            if (!$jiraTaskId) {
                $bitrixTask->createInJira();
                // Сохраняем в базу, чтобы записалось поле UF_JIRA_TASK_ID - "Id задачи в Jira".
                $taskItem = \CTaskItem::getInstance($bitrixTask->requireId(), 1);
                $taskItem->update([TaskTable::UF_JIRA_TASK_ID => $bitrixTask->requireUfJiraTaskId()]);
                $bitrixComment->setTask($bitrixTask);
            }

            $commentCollection = new MessageCollection();
            $commentCollection->add($bitrixComment);
            $jiraExporter = new JiraExporter($bitrixProject);
            $exportResult = $jiraExporter->exportComments($commentCollection);
            if (!$exportResult->isSuccess()) {
                $result->setErrors($exportResult->getErrors());
            }
        }

        return $result;
    }

    /**
     * Событие срабатывает при обновлении комментария
     * Комментарий выгружается в Jira, только если он ранее не был выгружен.
     * Обновление комментариев в API Redmnine не предусмотрено.
     *
     * @see https://www.jira.org/projects/jira/wiki/Rest_IssueJournals
     * @see https://www.jira.org/boards/1/topics/16513?r=16514#message-16514
     *
     * @param int   $messageId Id созданного комментария
     * @param array $data      Массив значений полей комментария
     */
    public static function onCommentUpdate(int $messageId, array $data)
    {
        $result = new Entity\EventResult();
        $env = Application::getInstance()->getContext()->getEnvironment();
        /*
         * Если идет импорт, ничего выгружать не нужно,
         * иначе у нас будет двойная работа: получаем данные и опять их отправляем обратно.
         */
        if ($env->get('KT_INTEGRATION_IMPORT_SESSION') || 'prod' !== $env->get('APP_ENV')) {
            return $result;
        }

        /*
         * Комментарием к задаче считается только запись с параметром NEW_TOPIC = N.
         * Если NEW_TOPIC = Y - значит, это начальное сообщение скрытого форума,
         *      а именно скопированный текст описания задачи.
         * Поэтому такие записи мы пропускаем.
         */
        if ('Y' === $data['NEW_TOPIC'] || $data['FORUM_ID'] !== Comment::getForumId()) {
            return $result;
        }
        /** @var Message $bitrixComment */
        $bitrixComment = self::getFilledComment($messageId);
        /** @var Task $task Задача, к которой относится комментарий */
        $task = $bitrixComment->getTask();
        // Если комментарий не привязан к задаче, ничего не делаем
        if (!$task || !($task->hasWorkgroup() && $task->getWorkgroup())) {
            return $result;
        }
        /** @var Workgroup $project */
        $project = $bitrixComment->requireTask()->requireWorkgroup();
        // Если проект интегрирован с Jira но комментарий не был выгружен в Jira при добавлении - ничего не делаем
        if (!$project->hasJiraIntegration() || !$task->requireUfJiraTaskId()) {
            //TODO: добавить выгрузку комментария в Jira, если она не выгружена
            return $result;
        }
        $collection = new MessageCollection();
        $collection->add($bitrixComment);
        $exporter = new JiraExporter();

        try {
            $exportResult = $exporter->exportComments($collection);
            foreach ($exportResult->getErrorCollection() as $error) {
                /**
                 * @var Error   $error
                 * @var Message $errorComment Комментарий, который выгрузился с ошибкой
                 */
                $errorComment = $error->getCustomData()['comment'];
                CEventLog::Add([
                    'SEVERITY' => 'WARNING',
                    'AUDIT_TYPE_ID' => 'JIRA_COMMENT_EXPORT_WARNING',
                    'MODULE_ID' => 'kt.integration',
                    'ITEM_ID' => $errorComment->getId(),
                    'DESCRIPTION' => Loc::getMessage('ERROR_JIRA_COMMENT_EXPORT_WARNING', [
                        '#COMMENT_ID#' => $errorComment->getId(),
                    ]),
                ]);
            }
        } catch (Throwable $e) { // Никакие фаталы не должны запрещать выгружать комментарии
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_COMMENT_EXPORT_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $bitrixComment->getId(),
                'DESCRIPTION' => $e->getMessage(),
            ]);

            return $result;
        }

        return $result;
    }

    /**
     * По ID комментария в битрикс, получить подготовленный объект для работы в обработчиках событий.
     *
     * @param int $commentId ID комментария
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private static function getFilledComment(int $commentId): Message
    {
        return MessageTable::query()
            ->where('ID', $commentId)
            ->setSelect([
                '*',
                MessageTable::UF_JIRA_COMMENT_ID,
                MessageTable::REF_TASK . '.*',
                MessageTable::REF_TASK . '.' . TaskTable::UF_JIRA_TASK_ID,
                MessageTable::REF_TASK . '.' . TaskTable::REF_WORKGROUP,
                MessageTable::REF_TASK . '.' . TaskTable::REF_WORKGROUP . 'UF_*',
            ])->fetchObject();
    }
}
