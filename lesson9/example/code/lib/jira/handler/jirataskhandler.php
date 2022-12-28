<?php

namespace KT\Integration\Jira\Handler;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use DateTimeInterface;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\TimeTracking;
use JiraRestApi\JiraException;
use JsonMapper_Exception;
use Kt\Forum\Message;
use Kt\Forum\MessageTable;
use KT\Integration\Jira\JiraExporter;
use KT\Integration\Jira\JiraSerializer;
use KT\Integration\Jira\JiraServiceFactory;
use KT\Integration\Jira\RestApi\JiraIssue;
use KT\Integration\Jira\RestApi\JiraTimeTracking;
use Kt\Main\User\User;
use Kt\Main\User\UserTable;
use Kt\Tasks\Task;
use Kt\Tasks\TaskTable;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Класс обработчик событий для задач.
 */
class JiraTaskHandler
{
    /**
     * Обработчик события после создания задачи.
     *
     * @param int   $id     Id записи
     * @param array $fields Массив полей
     *
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws ArgumentException
     * @throws LoaderException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws ExceptionInterface
     *
     * @return Result
     */
    public static function onTaskAdd($id, $fields)
    {
        $result = new Result();
        // Если задача относится к проекту, у которого есть интеграция с Jira, то выгружаем задачу в Jira
        /** @var Task $bitrixTask */
        $bitrixTask = TaskTable::query()
            ->where(TaskTable::FIELD_ID, $id)
            ->setSelect(
                [
                    '*',
                    TaskTable::REF_WORKGROUP,
                    TaskTable::REF_WORKGROUP . '.UF_*',
                    TaskTable::FIELD_SITE_ID,
                    TaskTable::UF_JIRA_TASK_ID,
                    TaskTable::FIELD_FORUM_TOPIC_ID,
                    TaskTable::FIELD_CREATED_BY,
                    TaskTable::FIELD_GROUP_ID,
                    TaskTable::REF_CREATOR,
                    TaskTable::UF_JIRA_TASK_ID,
                    TaskTable::REF_RESPONSIBLE,
                ]
            )
            ->setLimit(1)
            ->fetchObject();
        /** @var bool $isCommentWithLinksCreated Создан ли комментарий с ссылками на задачи */
        $isCommentWithLinksCreated = false;
        // Если задача импортируется из Jira, то добавляем комментарий с ссылками на задачи
        if (
            $bitrixTask
            && $bitrixTask->getUfJiraTaskId()
            && $bitrixTask->getWorkgroup()
            && $bitrixTask->getWorkgroup()->hasJiraIntegration()
        ) {
            $issueService = (new JiraServiceFactory())->createIssueService($bitrixTask->getWorkgroup());
            self::createCommentWithTasksUrls($bitrixTask, $issueService->get($bitrixTask->requireUfJiraTaskId()));
            $isCommentWithLinksCreated = true;
        }

        // Если задачи не существует, ничего не выгружать.
        // Если у задачи уже указан Id в Jira, значит она пришла из Jira. Ничего выгружать не нужно.
        // Если у задачи не установлена группа, значит эта задача не привязана к проекту. Ничего выгружать не нужно.
        // Если у группы нет интеграции с Jira. Ничего выгружать не нужно.
        if (
            !$bitrixTask
            || $bitrixTask->getUfJiraTaskId()
            || !$bitrixTask->getWorkgroup()
            || !$bitrixTask->getWorkgroup()->hasJiraIntegration()
        ) {
            return $result;
        }
        if ('prod' === Context::getCurrent()->getEnvironment()->get('APP_ENV')) {
            $addResult = $bitrixTask->createInJira();
            if (!$addResult->isSuccess()) {
                $result->addErrors($addResult->getErrors());

                return $result;
            }
        }

        $bitrixTask->save(); // Обязательно сохраняем задачу до остальных действий
        if (!$isCommentWithLinksCreated && $addResult) {
            self::createCommentWithTasksUrls($bitrixTask, $addResult->getData()['jira_issue']);
            $isCommentWithLinksCreated = true;
        }
        if ('prod' === Context::getCurrent()->getEnvironment()->get('APP_ENV')) {
            // Получаем заново, иначе UF_JIRA_TASK_ID не заполнен !!!
            $bitrixTask = TaskTable::getList(
                [
                    'filter' => ['ID' => $bitrixTask->requireId()],
                    'select' => [
                        'ID',
                        TaskTable::UF_JIRA_TASK_ID,
                        'ZOMBIE',
                        'TITLE',
                        'DESCRIPTION',
                        'CREATED_DATE',
                        'FORUM_TOPIC_ID',
                        TaskTable::REF_WORKGROUP,
                        TaskTable::REF_WORKGROUP . '.UF_*',
                    ],
                ]
            )->fetchObject();
            // Выгружаем файлы в Jira
            $exporter = new JiraExporter();
            $exportAttachResult = $exporter->exportAttachments($bitrixTask);
            if (!$exportAttachResult->isSuccess()) {
                $result->addErrors($exportAttachResult->getErrors());
            }
        }

        return $result;
    }

    /**
     * @param int   $id     Id записи
     * @param array $fields Массив поле записи
     *
     * @throws ArgumentException
     * @throws ExceptionInterface
     * @throws JiraException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws JsonMapper_Exception
     *
     * @return Result
     */
    public function onTaskUpdate($id, $fields)
    {
        $result = new Result();
        $env = Context::getCurrent()->getEnvironment();
        /*
         * Если идет импорт, ничего выгружать не нужно,
         * иначе у нас будет двойная работа: получаем данные и опять их отправляем обратно.
         */
        if ($env->get('KT_INTEGRATION_IMPORT_SESSION') || 'prod' !== $env->get('APP_ENV')) {
            return  $result;
        }
        // Если задача относится к проекту, у которого есть интеграция с Jira, то выгружаем задачу в Jira
        /** @var Task $bitrixTask */
        $bitrixTask = TaskTable::query()
            ->where('ID', $id)
            ->setSelect(
                [
                    '*',
                    TaskTable::REF_WORKGROUP,
                    TaskTable::REF_WORKGROUP . '.UF_*',
                    TaskTable::UF_JIRA_TASK_ID,
                    'CHANGED_BY',
                ]
            )->fetchObject();
        // Если задачи не существует, ничего не выгружать.
        // Если у задачи не установлена группа, значит эта задача не привязана к проекту. Ничего выгружать не нужно.
        // Если проект не имеет интеграции с Jira, ничего выгружать не нужно.
        if (!$bitrixTask || !$bitrixTask->getWorkgroup() || !$bitrixTask->getWorkgroup()->hasJiraIntegration()) {
            return $result;
        }

        // ВАЖНО! Проверяем, что такой проект существует в Jira, иначе возможны ошибки.
        // Инициализируем работу с API
        $serviceFactory = new JiraServiceFactory();
        $issueService = $serviceFactory->createIssueService($bitrixTask->getWorkgroup());
        $serializer = new JiraSerializer();

        // Если уже есть привязка к задаче в Jira, значит задачу нужно обновить.
        if ($bitrixTask->getUfJiraTaskId()) {
            /** @var JiraIssue $issue Получаем объект задачи в Jira, с учетом того, что хотим её только обновить */
            $issue = $serializer->normalize($bitrixTask, null, ['update' => true]);
            /**
             * При неудачной попытке обновления задачи библиотека JiraRestApi выбрасывает исключение
             *   с кодом 401 (unauthorized) или с кодом 400 (например, когда недостаточно прав редактировать задачу,
             *   или когда задача находится в статусе, при котором её редактирование запрещено).
             * Также может быть и другой код исключения (@see \JiraRestApi\JiraClient::exec();).
             *
             * Пример сообщения ошибки:
             *   CURL HTTP Request Failed: Status Code : 400, URL:http://jira.kt-team.de/rest/api/2/issue/112867?
             *   Error Message : {"errorMessages":[],"errors":{"summary":"Field 'summary' cannot be set.
             *   It is not on the appropriate screen, or unknown.","description":"Field 'description' cannot be set.
             *   It is not on the appropriate screen, or unknown."}}
             */
            /** @var bool $issueUpdateSuccess Результат обновления задачи */
            try {
                /*
                 * Формируем окочательный корректный объект timeTracking.
                 * Получаем задачу из Jira
                 */
                $issueFromJira = $issueService->get($issue->id);
                // Вычисляем, сколько осталось времени на задачу, исходя из новой оценки
                /** @var int $estimateSeconds Оценка в секундах */
                $estimateSeconds = $issue->fields->timeTracking->getOriginalEstimateSeconds();
                /** @var int $timeSpentSeconds Сколько потрачено на задачу в секундах */
                $timeSpentSeconds = $issueFromJira->fields->timeTracking->getTimeSpentSeconds();
                /** @var int $remainingSeconds Сколько осталось (в секундах) */
                $remainingSeconds = $estimateSeconds - $timeSpentSeconds;
                $remainingSeconds < 0 ? $remainingSeconds = 0 : false;
                $remaining = JiraTimeTracking::convertSecondsToJiraString($remainingSeconds);
                $issue->fields->timeTracking->setRemainingEstimateSeconds($remainingSeconds);
                $issue->fields->timeTracking->setRemainingEstimate($remaining);
                // Выполняем обновление
                $issueUpdateSuccess = $issueService->update($issue->id, $issue->fields);
            } catch (JiraException $e) {
                $issueUpdateSuccess = false;
            }
            /*
             * Если не удалось обновить текст задачи, добавляем комментарий к задаче
             * Сначала проверяем, что название или текст задачи изменился.
             */
            if (!$issueUpdateSuccess) {
                if (
                    !$issueFromJira
                    || $issueFromJira->fields->summary != $issue->fields->summary
                    || $issueFromJira->fields->description != $issue->fields->description
                    || ($issueFromJira->fields->timeTracking instanceof TimeTracking
                        && $issue->fields->timeTracking instanceof TimeTracking
                        && ($issueFromJira->fields->timeTracking->getOriginalEstimate()
                            != $issue->fields->timeTracking->getOriginalEstimate()))
                    || $issueFromJira->fields->duedate /*string*/ != $issue->fields->duedate // string
                ) {
                    /*
                     * Запасная проверка из-за некорректной работы библиотеки.
                     * duedate должен иметь тип DateTimeInterface, но возвращается строка.
                     */
                    $dueDate = null;
                    if (is_object($issue->fields->duedate)) {
                        $dueDate = clone $issue->fields->duedate;
                    }
                    if ($dueDate instanceof DateTimeInterface) {
                        $dueDate = $dueDate->format('d.m.Y H:i:s');
                    }
                    /** @var User $userChanged */
                    $userChanged = UserTable::getById($bitrixTask->getChangedBy())->fetchObject();
                    $timeEstimate = '';
                    if (isset($issue->fields->timeTracking) && $issue->fields->timeTracking instanceof TimeTracking) {
                        $timeEstimate = $issue->fields->timeTracking->getOriginalEstimate();
                    }
                    $comment = new Comment();
                    $commentBody = Loc::getMessage('JIRA_ISSUE_CHANGES_AS_COMMENT', [
                        '#USER_NAME#' => $userChanged->getFormattedName(),
                        '#ISSUE_NAME#' => $issue->fields->summary,
                        '#DESCRIPTION#' => $issue->fields->description,
                        '#TIME_ESTIMATE#' => $timeEstimate,
                        '#DUE_DATE#' => $dueDate,
                    ]);
                    $comment->setBody($commentBody);
                    $commentAddResult = $issueService->addComment($issue->id, $comment);
                    if (!$commentAddResult) {
                        $result->addError(new Error(
                            Loc::getMessage('ERROR_JIRA_ISSUE_EXPORT_WARNING', [
                                '#TASK_ID#' => $bitrixTask->getId(),
                            ])
                        ));
                    }
                }
            }
        } else {
            $addResult = $bitrixTask->createInJira();
            if (!$addResult->isSuccess()) {
                $result->addErrors($addResult->getErrors());
            }
            $bitrixTask->save();
        }

        // Выгружаем файлы в Jira
        $exporter = new JiraExporter();
        $exportAttachResult = $exporter->exportAttachments($bitrixTask);
        $result->addErrors($exportAttachResult->getErrors());

        return $result;
    }

    /**
     * Обработчик события перед добавлением задачи. если в проекте уже есть задача с таким же
     * ID задачи в Jira, то её импортировать не нужно.
     *
     * @param array $fields Поля задачи
     *
     * @see \Kt\Integration\Integration\Jira\Handler\JiraTaskHandler::onBeforeTaskAdd
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function onBeforeTaskAdd(array $fields): bool
    {
        global $APPLICATION;

        if (!$fields['GROUP_ID'] || !(int) $fields[TaskTable::UF_JIRA_TASK_ID]) {
            return true;
        }
        $result = self::checkIfJiraTaskIdIsUnique($fields['GROUP_ID'], $fields[TaskTable::UF_JIRA_TASK_ID]);
        if (!$result->isSuccess()) {
            foreach ($result->getErrorMessages() as $message) {
                $APPLICATION->ThrowException($message);
            }
        }

        return $result->isSuccess();
    }

    /**
     * Обработчик события перед сохранением задачи.
     * Првоеряет, если в проекте нет задачи с таким же UF_JIRA_TASK_ID,
     * то разрешает изменения.
     *
     * @param int   $id       ID задачи
     * @param array $fields   Массив полей для изменения
     * @param array $taskCopy Копия задачи до изменения
     *
     * @see \Kt\Integration\Integration\Jira\Handler\JiraTaskHandlerTest::onBeforeTaskUpdate
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function onBeforeTaskUpdate($id, $fields, $taskCopy): bool
    {
        global $APPLICATION;
        $realFields = array_merge($taskCopy, $fields);
        if (!$realFields['GROUP_ID']) { // Задачи без проекта игнорируем
            return true;
        }
        if (
            (int) $realFields[TaskTable::UF_JIRA_TASK_ID] > 0
            && $realFields[TaskTable::UF_JIRA_TASK_ID] != $taskCopy[TaskTable::UF_JIRA_TASK_ID]
        ) {
            $result = self::checkIfJiraTaskIdIsUnique($realFields['GROUP_ID'], $realFields[TaskTable::UF_JIRA_TASK_ID]);
            if (!$result->isSuccess()) {
                foreach ($result->getErrorMessages() as $message) {
                    $APPLICATION->ThrowException($message);
                }
            }

            return $result->isSuccess();
        }

        return true;
    }

    /**
     * Создать комментарий с ссылками на задачи.
     *
     * @param Task  $task  Задача в битриксе
     * @param Issue $issue Задача в Jira
     *
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     */
    private static function createCommentWithTasksUrls(Task $task, Issue $issue): \Bitrix\Tasks\Util\Result
    {
        // todo переделать на получение настройки из главного модуля
        $httpOrigin = Context::getCurrent()->getServer()['HTTP_ORIGIN'] ?? 'https://crm.kt-team.de';

        return \Bitrix\Tasks\Integration\Forum\Task\Comment::add(
            $task->requireId(),
            [
                'POST_MESSAGE' => Loc::getMessage(
                    'COMMENT_WITH_LINKS',
                    [
                        '#BITRIX_TASK_URL#' => $httpOrigin . $task->getDetailUrlInGroup(),
                        '#JIRA_TASK_URL#' => preg_replace(
                            '/\/$/',
                            '',
                            $task->requireWorkgroup()->requireUfJiraUrl()
                        )
                         . '/browse/'
                         . $issue->key,
                    ]
                ),
                'AUTHOR_ID' => $task->requireCreatedBy(),
                'NEW_TOPIC' => 'N',
            ]
        );
    }

    /**
     * Проверить, является ли ID задачи в jira для задачи уникальным в рамках проекта.
     *
     * @param int $groupId    ID проекта
     * @param int $jiraTaskId ID задачи в Jira
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * @return bool|Result
     */
    private static function checkIfJiraTaskIdIsUnique(int $groupId, int $jiraTaskId): Result
    {
        assert($jiraTaskId > 0);
        assert($groupId > 0);

        $result = new Result();

        /** @var Task $task */
        $task = TaskTable::query()
            ->addOrder('ID', 'ASC')
            ->where('GROUP_ID', $groupId)
            ->where(TaskTable::UF_JIRA_TASK_ID, $jiraTaskId)
            ->addSelect(TaskTable::UF_JIRA_TASK_ID)
            ->fetchObject()
        ;
        if ($task) {
            $result->addError(new Error(
                Loc::getMessage('WORKGROUP_ALREADY_HAVE_THIS_JIRA_TASK', [
                    '#BITRIX_TASK_ID#' => $task->requireId(),
                    '#JIRA_TASK_ID#' => $task->requireUfJiraTaskId(),
                ])
            ));
        }

        return $result;
    }
}
