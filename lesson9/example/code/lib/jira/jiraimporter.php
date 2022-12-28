<?php

namespace KT\Integration\Jira;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\AttachedObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Integration\Forum\Task\Comment as BitrixTaskComment;
use CEventLog;
use CFile;
use CSocNetFeaturesPerms;
use CTaskItem;
use JiraRestApi\Issue\Attachment;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Comments;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueSearchResult;
use JiraRestApi\Issue\PaginatedWorklog;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\JiraException;
use Kt\Forum\Message;
use Kt\Forum\MessageTable;
use KT\Integration\Importer;
use KT\Integration\Jira\RestApi\JiraIssueService;
use Kt\Integration\Tables\AttachmentIntegrationObject;
use Kt\Integration\Tables\AttachmentIntegrationTable;
use Kt\Integration\Tables\ElapsedTimeIntegrationObject;
use Kt\Integration\Tables\ElapsedTimeIntegrationTable;
use Kt\Main\User\User;
use Kt\Main\User\UserTable;
use Kt\Socialnetwork\Workgroup;
use Kt\Tasks\ElapsedTime;
use Kt\Tasks\ElapsedTimeTable;
use Kt\Tasks\Task;
use Kt\Tasks\TaskCollection;
use Kt\Tasks\TaskTable;
use Throwable;

/**
 * Класс отвечает за импорт задач, комментариев и ворклогов из Jira.
 *
 * @see JiraImporterTest
 *
 * Class JiraImporter
 */
class JiraImporter extends Importer
{
    /** @var JiraServiceFactory */
    private $jiraServiceFactory;

    /**
     * JiraImporter constructor.
     *
     * @throws ArgumentException
     * @throws SystemException
     * @throws ObjectPropertyException
     */
    public function __construct()
    {
        Loader::includeModule('disk');
        $this->setSerializer(new JiraSerializer());
        $this->jiraServiceFactory = new JiraServiceFactory();
        parent::__construct();
    }

    /**
     * Импорт задач в проект
     * Также импортируются комментарии к задачам и отмеченное исполнителями затраченное время.
     *
     * @param Workgroup $bitrixProject Id проекта в Bitrix
     *
     * @return Result Объект результата Bitrix
     *
     * @see \Kt\IntegrationTests\Integration\JiraImporterTest::testImportTasks integration test
     */
    public function importTasks(Workgroup $bitrixProject): Result
    {
        assert(Loader::includeModule('socialnetwork'));
        /** @var Result $result Объект результата */
        $result = new Result();
        /** @var int[] $addIds Id новых задач, созданных в процессе импорта */
        $addIds = [];
        /**
         * @var int[] $ids Id всех задач, затронутых в процессе импорта (даже если импорт задачи произошел с ошибкой).
         *            Так нужно, чтобы можно было тем не менее попробовать импортировать ворклоги и комментарии
         *            а также приложения к задаче.
         */
        $ids = [];
        /** @var array $attachments Массив приложений к задачам, чтобы не отправлять новый запрос в Jira */
        $attachments = [];
        /*
         * Получаем все задачи проекта в Jira
         * Получаем полное количество задач в проекте, далее получаем задачи пачками.
         */
        /** @var JiraIssueService $issuesClient */
        $issuesClient = $this->getJiraServiceFactory()->createIssueService($bitrixProject);

        try {
            /** @var IssueSearchResult $totalCount */
            $totalCount = $issuesClient->search($bitrixProject->requireUfJiraJqlFilter(), 0, 1, ['id']);
        } catch (JiraException $jiraException) {
            $result->addError(new Error($jiraException->getMessage()));

            return $result;
        }

        if (!$totalCount || !$totalCount->getTotal()) {
            return $result;
        }

        /** @var int $totalCount */
        $totalCount = $totalCount->getTotal();

        $offset = 0;
        $limit = 100;
        /** @var array $selectedFields Массив выбираемых полей */
        $selectedFields = [
            'id', 'summary', 'description', 'created', 'assignee', 'creator', 'updated',
            'timetracking', 'duedate', 'attachment', 'parent',
        ];
        while ($offset < $totalCount) {
            try {
                /** @var IssueSearchResult $issues */
                $issues = $issuesClient->search(
                    $bitrixProject->requireUfJiraJqlFilter(),
                    $offset,
                    $limit,
                    $selectedFields
                );
                if (!$issues || !$issues->getIssues()) {
                    $offset += $limit;

                    continue;
                }
            } catch (JiraException $e) {
                $result->addError(new Error(
                    $e->getMessage(),
                    $e->getCode(),
                    ['trace' => $e->getTrace()]
                ));
                $offset += $limit;

                continue;
            }

            /** @var int[] $issuesIds Список Id всех полученных задач из Jira */
            $issuesIds = array_map(function (Issue $issue) {
                return intval($issue->id);
            }, $issues->getIssues());

            /** @var array $issuesById Массив задач Jira с индексацией по Id */
            $issuesById = array_combine($issuesIds, $issues->getIssues());
            /** @var Task[]|TaskCollection $foundTasks задачи, которые уже созданы */
            $foundTasks = TaskTable::query()
                ->whereIn(TaskTable::UF_JIRA_TASK_ID, $issuesIds)
                ->where('GROUP_ID', $bitrixProject->requireId())
                ->setSelect([
                    'ID',
                    TaskTable::UF_JIRA_TASK_ID,
                    'ZOMBIE',
                    'CHANGED_DATE',
                    'STATUS',
                ])
                ->setLimit($limit)
                ->fetchCollection()
                ;
            /** @var int[] $notFoundIssueIds Список Id задач, которые не найдены в Bitrix */
            $notFoundIssueIds = array_diff(
                $issuesIds,
                array_map('intval', array_unique($foundTasks->getUfJiraTaskIdList()))
            );
            // Создаем задачи и привязываем их к Jira
            foreach ($notFoundIssueIds as $jiraId) {
                /** @var Issue $issue Массив задачи в Jira */
                $issue = $issuesById[$jiraId];
                // Запрещаем импортировать задачи, где ответственным никто не назначен
                if (!$issue->fields->assignee || !$issue->fields->assignee->emailAddress) {
                    continue;
                }
                /** @var null|User Ответственный */
                $responsibleUser = UserTable::query()
                    ->where('EMAIL', '=', $issue->fields->assignee->emailAddress)
                    ->fetchObject()
                ;
                // Запрещаем импортировать задачи, где ответственным назначен не наш пользователь
                if (!$responsibleUser) {
                    continue;
                }
                /** @var Task $bitrixTask */
                $bitrixTask = $this->getSerializer()->denormalize($issue, Task::class);
                $bitrixTask->setGroupId($bitrixProject->requireId());
                $bitrixTask->setResponsibleId($responsibleUser->requireId());
                $creatorUser = null;
                if ($issue->fields->creator->emailAddress) {
                    /** @var User Создатель */
                    $creatorUser = UserTable::query()
                        ->where('EMAIL', '=', $issue->fields->creator->emailAddress)
                        ->fetchObject()
                    ;
                }
                $creatorUser = $creatorUser ?? $bitrixProject->requireOwner();

                $bitrixTask->setCreatedBy($creatorUser->requireId());
                $bitrixTask->setSiteId(SITE_ID);
                $bitrixTask->setUfJiraTaskId($issue->id);
                $addResult = new AddResult();

                try {
                    $arTask = self::taskToArray($bitrixTask);
                    unset($arTask['ZOMBIE']); // Убираем, т.к. API его почему-то обрабатывает
                    $taskItem = CTaskItem::add(
                        $arTask,
                        $this->getTaskExecutiveUserId($bitrixTask, 'create_tasks')
                    );
                    $addResult->setId($taskItem->getId());
                    $bitrixTask->setId($taskItem->getId());
                } catch (Throwable $e) {
                    $addResult->addError(
                        new Error($e->getMessage() . PHP_EOL . $e->getTraceAsString())
                    );
                }
                if (!$addResult->isSuccess()) {
                    $result->addError(new Error(Loc::getMessage('KT_INTEGRATION_JIRA_TASK_IMPORT_ERROR', [
                        '#TASK_TITLE#' => $bitrixTask->getTitle(),
                        '#JIRA_TASK_ID#' => $bitrixTask->getUfJiraTaskId(),
                    ])));
                    $result->addErrors($addResult->getErrors());
                } else {
                    $ids[] = $bitrixTask->requireId();
                    $addIds[] = $bitrixTask->requireId();
                    $attachments[$bitrixTask->requireId()] = $issue->fields->attachment;
                }
            }

            // Обновляем задачи, которые найдены
            foreach ($foundTasks as $bitrixTask) {
                // Завершённые задачи и задачи в корзине пропускаем
                if ($bitrixTask->requireZombie() || $bitrixTask->getStatusObject()->isCompleted()) {
                    continue;
                }
                /** @var int $jiraId Id задачи в Jira */
                $jiraId = $bitrixTask->requireUfJiraTaskId();
                /** @var Issue $issue Массив задачи в Jira */
                $issue = $issuesById[$jiraId];
                // Если по задаче не было изменений - пропускаем.
                if (
                    $bitrixTask->getChangedDate()
                    && $issue->fields->updated
                    && $bitrixTask->getChangedDate()->getTimestamp() > $issue->fields->updated->getTimestamp()
                ) {
                    continue;
                }

                /** @var Task $bitrixTask */
                $bitrixTask = $this->getSerializer()
                    ->denormalize($issue, Task::class, null, ['bitrixTask' => $bitrixTask])
                ;

                try {
                    $arTask = self::taskToArray($bitrixTask);
                    // убираем ZOMBIE, т.к. API его не обрабатывает
                    // и DEADLINE т.к. в Jira хранится только дата, но не время
                    unset($arTask['ZOMBIE'], $arTask['DEADLINE']);
                    CTaskItem::getInstance(
                        $bitrixTask->requireId(),
                        $this->getTaskExecutiveUserId($bitrixTask, 'edit_tasks')
                    )->update($arTask);
                } catch (Throwable $e) {
                    $result->addError(new Error($e->getMessage(), $e->getCode(), ['TRACE' => $e->getTraceAsString()]));
                }

                $attachments[$bitrixTask->requireId()] = $issue->fields->attachment;
            }
            unset($foundTasks);
            $offset += $limit;
        }
        /*
         * Нужно выстроить иерархию задач, какая из задач, является чьей подзадачей.
         * Это нужно делать именно после того, как все задачи были проимпортированы.
         * Для этого опять делаем запросы.
         */
        $offset = 0;
        while ($offset < $totalCount) {
            try {
                /** @var IssueSearchResult $issues */
                $issues = $issuesClient->search(
                    $bitrixProject->requireUfJiraJqlFilter(),
                    $offset,
                    $limit,
                    $selectedFields
                );
                if (!$issues || !$issues->getIssues()) {
                    $offset += $limit;

                    continue;
                }
            } catch (JiraException $e) {
                $result->addError(
                    new Error(
                        $e->getMessage(),
                        $e->getCode(),
                        ['trace' => $e->getTrace()]
                    )
                );
                $offset += $limit;

                continue;
            }
            foreach ($issues->getIssues() as $issue) {
                try {
                    if (isset($issue->fields->parent) && $issue->fields->parent instanceof Issue) {
                        /** @var Issue $parent Родительская задача в Jira */
                        $parent = $issue->fields->parent;
                        /** @var Task $bitrixTask Задача в битриксе */
                        $bitrixTask = TaskTable::query()
                            ->addSelect('CREATED_BY')
                            ->addSelect('PARENT_ID')
                            ->addSelect('ZOMBIE')
                            ->addSelect('GROUP_ID')
                            ->addSelect('RESPONSIBLE_ID')
                            ->where('GROUP_ID', $bitrixProject->requireId())
                            ->where(TaskTable::UF_JIRA_TASK_ID, $issue->id)
                            ->setLimit(1)
                            ->fetchObject()
                        ;
                        if ($bitrixTask) {
                            /** @var Task $bitrixParentTask Родительская задача */
                            $bitrixParentTask = TaskTable::query()
                                ->addSelect('GROUP_ID')
                                ->addSelect('ZOMBIE')
                                ->where('GROUP_ID', $bitrixProject->requireId())
                                ->where(TaskTable::UF_JIRA_TASK_ID, $parent->id)
                                ->setLimit(1)
                                ->fetchObject()
                            ;
                            // Если есть родительская задача в Jira, то делаем её родительской и в Bitrix
                            if ($bitrixParentTask
                                && !$bitrixParentTask->getZombie() && !$bitrixTask->getZombie()
                                && $bitrixTask->requireId() !== $bitrixParentTask->requireId()
                                && $bitrixTask->requireParentId() !== $bitrixParentTask->requireId()
                            ) {
                                try {
                                    $taskItem = CTaskItem::getInstance(
                                        $bitrixTask->requireId(),
                                        $this->getTaskExecutiveUserId($bitrixTask, 'edit_tasks')
                                    );
                                    $taskItem->update(['PARENT_ID' => $bitrixParentTask->requireId()]);
                                } catch (Throwable $e) {
                                    CEventLog::Add([
                                        'SEVERITY' => 'ERROR',
                                        'AUDIT_TYPE_ID' => 'KT_INTEGRATION_JIRA_IMPORT_ERROR_UPDATE_TASK',
                                        'MODULE_ID' => 'kt.integration',
                                        'ITEM_ID' => $bitrixTask->requireId(),
                                        'DESCRIPTION' => Loc::getMessage(
                                            'KT_INTEGRATION_JIRA_IMPORT_ERROR_UPDATE_TASK',
                                            [
                                                '#TASK_ID#' => $bitrixTask->requireId(),
                                                '#JIRA_ISSUE_ID#' => $bitrixTask->requireUfJiraTaskId(),
                                                '#ERRORS#' => implode(
                                                    PHP_EOL,
                                                    [$e->getMessage(), $e->getTraceAsString()]
                                                ),
                                            ]
                                        ),
                                    ]);
                                    $result->addError(
                                        new Error(implode(PHP_EOL, [$e->getMessage(), $e->getTraceAsString()]))
                                    );
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $result->addError(new Error($e->getMessage() . PHP_EOL . $e->getTraceAsString()));
                }
            }
            $offset += $limit;
        }

        $result->setData(['ATTACHMENTS' => $attachments]);

        return $result;
    }

    /**
     * Импорт комментариев в задачу Битрикс
     *
     * @param Task  $bitrixTask       Объект задачи.
     *                                Необходимо, чтобы у Таска были заполнены комментарии COMMENTS,
     *                                а у каждого комментария был заполнен UF_JIRA_COMMENT_ID
     *                                - id комментария в Jira
     * @param array $additionalParams Массив дополнительных параметров
     *
     * @return Result Объект результата Bitrix
     *
     * @see \Kt\IntegrationTests\Integration\JiraImporter::testImportComments integration test
     */
    public function importComments(Task $bitrixTask, $additionalParams = []): Result
    {
        assert($bitrixTask->hasComments(), 'В задаче не заполнены комментарии');
        assert($bitrixTask->hasWorkgroup(), 'В задаче не заполнен проект');
        assert(
            $bitrixTask->getWorkgroup()->hasJiraIntegration(),
            'Проект не интегрирован с Jira, или в объекте не заполнены необходимые поля'
        );
        /** @var Result $result Объект результатат */
        $result = new Result();
        /** @var JiraServiceFactory $serviceFactory Фабрика сервисов для работы с Jira */
        $serviceFactory = $this->getJiraServiceFactory();
        /** @var JiraIssueService $issuesClient Сервис получения задач и комментариев из Jira */
        $issuesClient = $serviceFactory->createIssueService($bitrixTask->requireWorkgroup());
        /** @var Comments $jiraComments Объект, содержащий в себе комментарии к задаче в Jira */
        try {
            $jiraComments = $issuesClient->getComments($bitrixTask->requireUfJiraTaskId());
        } catch (JiraException $e) {
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_COMMENTS_GET_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $bitrixTask->requireUfJiraTaskId(),
                'DESCRIPTION' => Loc::getMessage(
                    'JIRA_COMMENTS_GET_WARNING',
                    ['#MESSAGE#' => $e->getMessage()]
                ),
            ]);
        }

        if (!$jiraComments || !$jiraComments->total) {
            return $result;
        }

        /** @var JiraSerializer $serializer Объект-сериализатор, */
        $serializer = $this->getSerializer();

        /**
         * @var Comment $comment Объект комментария
         */
        foreach ($jiraComments->comments as $comment) {
            // Денормализация
            if (!$comment->id || !$serializer->supportsDenormalization($comment, Message::class)) {
                continue; // Комментарий не подходит по правилам денормализации
            }
            if (!$comment->author->emailAddress) { // без email не можем идентифицировать пользователя
                continue;
            }
            $comment->id = intval($comment->id);
            /** @var Message $denormalizedComment */
            $denormalizedComment = $serializer->denormalize($comment, Message::class);
            /** @var null|User Автор комментария */
            $commentAuthor = UserTable::query()
                ->addSelect(UserTable::FIELD_EMAIL)
                ->addSelect(UserTable::FIELD_NAME)
                ->addSelect(UserTable::FIELD_LAST_NAME)
                ->where('EMAIL', '=', $comment->author->emailAddress)
                ->setCacheTtl(3600 * 3)
                ->fetchObject()
            ;
            /*
             * Если не нашли пользователя, оставляем комментарий от лица владельца группы.
             * При этом в тело комментария добавляем имя комментатора.
             */
            if (!$commentAuthor) {
                $commentAuthor = $bitrixTask->requireWorkgroup()->requireOwner();
                $denormalizedComment->setPostMessage(
                    Loc::getMessage(
                        'KT_INTEGRATION_JIRA_COMMENT_FROM_ADMIN',
                        [
                            '#NAME#' => $comment->author->name,
                            '#MESSAGE#' => $denormalizedComment->requirePostMessage(),
                        ]
                    )
                );
            }
            // Привязываем комментарий к создателю
            $denormalizedComment
                ->setAuthorName($commentAuthor->getFormattedName())
                ->setAuthorId($commentAuthor->getId())
            ;
            $filteredByUfJiraCommentId = $bitrixTask->requireComments()->filterByUfJiraCommentId($comment->id);
            /** @var Message $commentInBitrix */
            $commentInBitrix = $filteredByUfJiraCommentId->count() ? $filteredByUfJiraCommentId->first() : null;
            // Если комментария еще нет - добавляем
            if (!$commentInBitrix) {
                // Если не создан форум, привязанный к задаче, создаем.
                $denormalizedComment->setUfJiraCommentId($comment->id);
                $addResult = BitrixTaskComment::add(
                    $bitrixTask->requireId(),
                    [
                        MessageTable::FIELD_AUTHOR_ID => $denormalizedComment->requireAuthorId(),
                        MessageTable::FIELD_AUTHOR_NAME => $denormalizedComment->requireAuthorName(),
                        MessageTable::FIELD_AUTHOR_EMAIL => $denormalizedComment->requireAuthorName(),
                        MessageTable::FIELD_POST_MESSAGE => $denormalizedComment->requirePostMessage(),
                        MessageTable::FIELD_POST_DATE => $denormalizedComment->requirePostDate(),
                        MessageTable::UF_JIRA_COMMENT_ID => $comment->id,
                    ]
                );
                $addResult->isSuccess() ? $bitrixTask->addToComments($denormalizedComment) :
                    $result->addErrors($addResult->getErrors()->getValues());
            } else {
                // Если по каким-то причинам есть лишние комментарии - удаляем их
                $filteredByUfJiraCommentId->remove($commentInBitrix);
                /** @var Message $duplicateComment Комментарий */
                foreach ($filteredByUfJiraCommentId as $duplicateComment) {
                    BitrixTaskComment::delete($duplicateComment->requireId(), $bitrixTask->requireId());
                    $bitrixTask->requireComments()->remove($duplicateComment);
                }
                // Если текст не изменился - пропускаем
                if ($commentInBitrix->requirePostMessage() === $denormalizedComment->requirePostMessage()) {
                    continue;
                }
                $commentInBitrix
                    ->setPostMessage($denormalizedComment->getPostMessage())
                    ->setPostMessageHtml($denormalizedComment->getPostMessageHtml())
                ;
                $updateResult = BitrixTaskComment::update(
                    $commentInBitrix->requireId(),
                    [
                        // При обновлении невозможно поменять автора
                        MessageTable::FIELD_POST_MESSAGE => $commentInBitrix->requirePostMessage(),
                        MessageTable::FIELD_POST_MESSAGE_HTML => $commentInBitrix->requirePostMessageHtml(),
                    ],
                    $bitrixTask->requireId() // Обязательно передавать ID задачи
                );
                if (!$updateResult->isSuccess()) {
                    $result->addErrors($updateResult->getErrors()->getValues());
                }
            }
        }

        /*
         * Все комментарии, у которых есть привязка к Jira,
         * но которых нет в самой Jira - удаляем
         */
        $jiraCommentIds = array_reduce($jiraComments->comments, function ($accumulator, Comment $comment) {
            $accumulator[] = (int) $comment->id;

            return $accumulator;
        }, []);
        $jiraCommentIdsForDeleting = array_diff(
            array_filter($bitrixTask->requireComments()->getUfJiraCommentIdList(), 'is_int'),
            $jiraCommentIds
        );
        foreach ($jiraCommentIdsForDeleting as $jiraCommentId) {
            BitrixTaskComment::delete(
                $bitrixTask->requireComments()->filterByUfJiraCommentId($jiraCommentId)->first()->requireId(),
                $bitrixTask->requireId()
            );
        }

        return $result;
    }

    /**
     * Импорт ворклогов в задачу.
     *
     * @param Task $bitrixTask Задача Bitrix
     *
     * @return Result Объект результата Bitrix
     *
     * @see \Kt\IntegrationTests\Integration\JiraImporterTest::testImportWorkLogs integration test
     */
    public function importWorkLogs(Task $bitrixTask): Result
    {
        assert($bitrixTask->hasWorkgroup(), 'В задаче не заполнен проект');
        assert($bitrixTask->hasUfJiraTaskId(), 'В задаче не заполнено поле связи с ID задачи в Jira');
        assert(
            $bitrixTask->getWorkgroup()->hasJiraIntegration(),
            'Проект не интегрирован с Jira, или в объекте не заполнены необходимые поля'
        );
        /** @var Result $result Объект результата */
        $result = new Result();
        $serviceFactory = $this->getJiraServiceFactory();
        /** @var JiraIssueService $issuesClient Сервис получения задач и воклогов из Jira */
        $issuesClient = $serviceFactory->createIssueService($bitrixTask->getWorkgroup());
        /** @var JiraSerializer $serializer Объект-сериализатор, */
        $serializer = $this->getSerializer();
        /** @var Worklog $jiraWorklogs Объект, содержащий в себе комментарии к задаче в Jira */
        try {
            $jiraTaskId = $bitrixTask->requireUfJiraTaskId();
            /** @var PaginatedWorklog $jiraWorklogs Коллекция ворклогов задачи */
            $jiraWorklogs = $issuesClient->getWorklog($jiraTaskId);
            /**
             * @var Worklog $jiraWorklog Объект ворклога
             */
            foreach ($jiraWorklogs->getWorklogs() as $jiraWorklog) {
                // Денормализация
                if (!$serializer->supportsDenormalization($jiraWorklog, ElapsedTime::class)) {
                    // Комментарий не подходит по правилам денормализации
                    continue;
                }
                /** @var ElapsedTime $denormalizedJiraWorklog */
                $denormalizedJiraWorklog = $serializer->denormalize($jiraWorklog, ElapsedTime::class);
                /** @var User $worklogAuthor Автор ворклога. Всегда должен быть. */
                $worklogAuthor = UserTable::query()
                    ->where('EMAIL', '=', $jiraWorklog->author['emailAddress'])
                    ->fetchObject()
                    ;
                // Если ворклог не нашего пользователя - не импортируем
                if (!$worklogAuthor || !$jiraWorklog->author['emailAddress']) {
                    continue;
                }
                $denormalizedJiraWorklog->setUserId($worklogAuthor->requireId());

                /** @var null|ElapsedTimeIntegrationObject $integrationRecord Обновляем или добавляем запись */
                $integrationRecord = ElapsedTimeIntegrationTable::query()
                    ->setSelect([
                        'ID',
                        ElapsedTimeIntegrationTable::ELAPSED_TIME_ID,
                        ElapsedTimeIntegrationTable::REF_ELAPSED_TIME,
                    ])
                    ->addFilter(ElapsedTimeIntegrationTable::JIRA_WORKLOG_ID, $jiraWorklog->id)
                    ->setLimit(1)
                    ->fetchObject()
                    ;
                if ($integrationRecord) {
                    /*
                    * Защита от возможных проблем при рассинхронизации записей
                    * в b_tasks_elapsed_time и интеграционной таблице kt_integration_elapsed_time.
                    */
                    if (!$integrationRecord->getElapsedTime()) {
                        ElapsedTimeIntegrationTable::delete($integrationRecord->getId());
                    }

                    continue;
                }
                $denormalizedJiraWorklog->setTaskId($bitrixTask->requireId());
                // Сохраняем не через API чтобы не было проверки прав
                /*
                 * todo Подумать над тем, чтобы сохранять через API обходя проверку прав.
                 * Есть вариант через статическое свойство класса хендлера запрещать отправку в Jira
                 * на событии создания ворклога
                 */
                $addResult = $denormalizedJiraWorklog->save();
                if ($addResult->isSuccess()) {
                    ElapsedTimeIntegrationTable::add([
                        ElapsedTimeIntegrationTable::ELAPSED_TIME_ID => $addResult->getId(),
                        ElapsedTimeIntegrationTable::JIRA_WORKLOG_ID => $jiraWorklog->id,
                    ]);
                } else {
                    $result->addError(new Error(Loc::getMessage('KT_INTEGRATION_JIRA_WORKLOG_IMPORT_ERROR', [
                        '#BITRIX_TASK_ID#' => $denormalizedJiraWorklog->requireTaskId(),
                        '#JIRA_WORKLOG_ID#' => $jiraWorklog->id,
                    ])));
                    $result->addErrors($addResult->getErrors());
                }
            }
        } catch (JiraException $e) {
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_WORKLOG_IMPORT_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $bitrixTask->getUfJiraTaskId(),
                'DESCRIPTION' => Loc::getMessage(
                    'JIRA_WORKLOG_IMPORT_WARNING',
                    [
                        '#CODE#' => $e->getCode(),
                        '#MESSAGE#' => $e->getMessage(),
                        '#STACKTRACE#' => $e->getTraceAsString(),
                    ]
                ),
            ]);
        }

        return $result;
    }

    /**
     * Импорт файлов, прикрепленных в задачу.
     *
     * @param Task         $bitrixTask  Задача Bitrix
     * @param Attachment[] $attachments Массив файлов, прикрепленных к задаче в Jira
     *
     * @return Result Объект результата Bitrix
     */
    public function importAttachments(Task $bitrixTask, $attachments = []): Result
    {
        assert($bitrixTask->hasWorkgroup(), 'В задаче не заполнен проект');
        assert(
            $bitrixTask->getWorkgroup()->hasJiraIntegration(),
            'Проект не интегрирован с Jira, или в объекте не заполнены необходимые поля'
        );
        assert(
            $bitrixTask->hasUfTaskWebdavFiles(),
            'В задаче не заполнены существующие приложенные файлы'
        );
        /** @var Result $result Объект результата */
        $result = new Result();
        $result->setData(['JIRA_ATTACHMENT_IDS' => []]);
        $serviceFactory = $this->getJiraServiceFactory();
        /** @var JiraIssueService $issuesClient Сервис получения задач и воклогов из Jira */
        $issuesClient = $serviceFactory->createIssueService($bitrixTask->requireWorkgroup());
        if (empty($attachments)) { // Если файлы не были переданы - получаем из Jira
            try {
                /** @var Issue $jiraIssue Объект задачи в Jira */
                $jiraIssue = $issuesClient->get($bitrixTask->requireUfJiraTaskId());
                if (isset($jiraIssue->fields->attachment)) {
                    $attachments = $jiraIssue->fields->attachment;
                }
            } catch (JiraException $e) {
                $result->addError(new Error(implode(PHP_EOL, [$e->getMessage(), $e->getTraceAsString()])));
                CEventLog::Add([
                    'SEVERITY' => 'WARNING',
                    'AUDIT_TYPE_ID' => 'JIRA_ATTACHMENT_IMPORT_WARNING',
                    'MODULE_ID' => 'kt.integration',
                    'ITEM_ID' => $bitrixTask->requireUfJiraTaskId(),
                    'DESCRIPTION' => Loc::getMessage(
                        'JIRA_ATTACHMENT_IMPORT_WARNING',
                        [
                            '#CODE#' => $e->getCode(),
                            '#MESSAGE#' => $e->getMessage(),
                            '#STACKTRACE#' => $e->getTraceAsString(),
                        ]
                    ),
                ]);
            }
        }

        if (empty($attachments)) {
            return $result;
        }

        /** @var int[] $jiraAttachmentIds Массив Id приложений к задаче в Jira */
        $jiraAttachmentIds = [];
        /** @var int[] $hashIdBitrixIdJira Массив Id файлов из Jira,
         *  загруженных в процессе импорта, с индексацией по Id в Bitrix.*/
        $hashIdBitrixIdJira = [];
        /** @var string[] $tmpFilePaths Массив путей к созданным временным файлам */
        $tmpFilePaths = [];
        /**
         * @var array $filesAttachedToTask Список Id файлов
         *            Вначале Id может стоять символ n, что означает, что файл только добавляется в задачу
         *            Но в данном случае мы получаем task из базы, поэтому такого не должно быть
         *
         *            @see Task::getUfTaskWebdavFiles();
         *            @see Task::setUfTaskWebdavFiles();
         */
        $filesAttachedToTask = $bitrixTask->requireUfTaskWebdavFiles();

        /** @var Attachment $attachment Объект приложения к задаче */
        foreach ($attachments as $attachment) {
            if (!isset($attachment->id)) {
                continue;
            }

            $jiraAttachmentIds[] = $attachment->id;
            $result->setData(['JIRA_ATTACHMENT_IDS' => $jiraAttachmentIds]);

            /**
             * Проверяем, привязан ли файл к задаче.
             * Ищем запись в интеграционной таблице.
             *
             * @var AttachmentIntegrationObject $integrationRecord Запись интеграционной таблицы,
             *                                  где хранится Id приложения к задаче (в Bitrix, Jira и Redmine)
             */
            $integrationRecords = AttachmentIntegrationTable::query()
                ->addFilter('JIRA_ID', $attachment->id)
                ->setSelect(['ID', 'ATTACHED_OBJECT_ID'])
                ->fetchCollection()
                ;
            /** @var bool $fileIsAttached Файл привязан к задаче */
            $fileIsAttached = false;
            /*
             * Файл может быть привязан к удаленной задаче, поэтому записей по нему может быть несколько
             * Поэтому пробегаем в цикле. TODO: реализовать удаление записей при удалении задачи
             */
            foreach ($integrationRecords as $integrationRecord) {
                $attachedObject = AttachedObjectTable::getById($integrationRecord->requireAttachedObjectId())
                    ->fetchObject()
                ;
                // todo удалять записи из интеграционной таблицы
                if (!$attachedObject) {
                    continue;
                }
                /*
                 * Если запись о связи с задачей удалена, или не создавалась.
                 * Либо ранее эта задача с файлами уже выгружалась в Bitrix,
                 *  и осталась старая привязка файла к другой задаче.
                 * То считаем, что файл к нашей задаче не привязан.
                 */
                if ($attachedObject->getEntityId() === $bitrixTask->requireId()) {
                    $fileIsAttached = true;

                    break;
                }
            }
            if ($fileIsAttached) {
                // Ничего выгружать не надо, прикрепленный файл уже есть в задаче Bitrix.
                continue;
            }
            /*
             * Приложение еще не загружалось в Bitrix или не привязалось к задаче
             * Загружаем файл.
             */
            /** @var string $dirPath Директория, куда загружаем файл */
            $dirPath = $this->getDirectoryForDownloads();
            /** @var string $fileName Имя файла */
            $fileName = $attachment->filename;
            /** @var bool $errorOnDownload При загрузке произошла ошибка */
            $errorOnDownload = false;

            try {
                /*
                 * Не сохраняем результат в переменную, потому что функция отдает в результате контент файла.
                 * Нет смысла хранить такие большие объемы данных в памяти.
                 */
                $issuesClient->download($attachment->content, $dirPath, $fileName);
            } catch (JiraException $e) {
                $result->addError(new Error(implode(PHP_EOL, [$e->getMessage(), $e->getTraceAsString()])));
                CEventLog::Add([
                    'SEVERITY' => 'WARNING',
                    'AUDIT_TYPE_ID' => 'JIRA_ATTACHMENT_GET_WARNING',
                    'MODULE_ID' => 'kt.integration',
                    'ITEM_ID' => $attachment->id,
                    'DESCRIPTION' => Loc::getMessage(
                        'JIRA_ATTACHMENT_GET_WARNING',
                        [
                            '#TASK_ID#' => $bitrixTask->requireId(),
                            '#JIRA_ISSUE_ID#' => $bitrixTask->requireUfJiraTaskId(),
                            '#JIRA_ATTACH_ID#' => $attachment->id,
                            '#CODE#' => $e->getCode(),
                            '#MESSAGE#' => $e->getMessage(),
                            '#STACKTRACE#' => $e->getTraceAsString(),
                        ]
                    ),
                ]);
                $errorOnDownload = true;
            }

            // Если ошибка при загрузке файла - переходим к следующему
            if ($errorOnDownload) {
                continue;
            }

            // Получаем Id хранилища на Диске, куда будем загружать файл
            $storage = Driver::getInstance()->getStorageByGroupId($bitrixTask->getWorkgroup()->getId());
            if (!$storage) {
                $storage = Driver::getInstance()->getStorageByUserId($bitrixTask->requireCreatedBy());
            }

            $folder = $storage->getRootObject();
            $fileArray = CFile::MakeFileArray($dirPath . '/' . $fileName);

            try {
                /** @var File $diskObject Специализированный объект записи в таблице b_disk_object */
                $diskObject = $folder->uploadFile(
                    $fileArray,
                    ['CREATED_BY' => $bitrixTask->requireCreatedBy()],
                    [],
                    true
                );
            } catch (Throwable $e) {
                $result->addError(new Error(implode(PHP_EOL, [$e->getMessage(), $e->getTraceAsString()])));
            } finally {
                if (!$diskObject) {
                    CEventLog::Add(
                        [
                            'SEVERITY' => 'WARNING',
                            'AUDIT_TYPE_ID' => 'JIRA_ATTACHMENT_TO_DISK_WARNING',
                            'MODULE_ID' => 'kt.integration',
                            'ITEM_ID' => $attachment->id,
                            'DESCRIPTION' => Loc::getMessage(
                                'JIRA_ATTACHMENT_TO_DISK_WARNING',
                                [
                                    '#TASK_ID#' => $bitrixTask->getId(),
                                    '#JIRA_ATTACH_ID#' => $attachment->id,
                                ]
                            ),
                        ]
                    );
                }
            }

            if (!$diskObject) {
                continue;
            }

            $hashIdBitrixIdJira[$diskObject->getId()] = $attachment->id;

            // Также нужно заполнить специальное пользовательское поле.
            $filesAttachedToTask[] = 'n' . $diskObject->getId();
            $filesAttachedToTask = array_unique($filesAttachedToTask);
            $bitrixTask->setUfTaskWebdavFiles($filesAttachedToTask);

            // Запоминаем путь к временному файлу
            $tmpFilePaths[] = $dirPath . '/' . $fileName;

            // Запоминаем Id созданного файла
            $newFiles[] = $diskObject->getId();
        }

        // Все файлы уже есть в задаче. Ничего привязывать не нужно.
        if (empty($newFiles)) {
            return $result;
        }

        try {
            $oTaskItem = CTaskItem::getInstance(
                $bitrixTask->getId(),
                $this->getTaskExecutiveUserId($bitrixTask, 'edit_tasks')
            );
            $oTaskItem->Update(['UF_TASK_WEBDAV_FILES' => $filesAttachedToTask]);
        } catch (Throwable $e) {
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_ATTACHMENT_TO_TASK_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $bitrixTask->getId(),
                'DESCRIPTION' => Loc::getMessage(
                    'JIRA_ATTACHMENT_TO_TASK_WARNING',
                    [
                        '#TASK_ID#' => $bitrixTask->getId(),
                        '#JIRA_ISSUE_ID#' => $bitrixTask->getUfJiraTaskId(),
                        '#ERRORS#' => implode(PHP_EOL, [$e->getMessage(), $e->getTraceAsString()]),
                    ]
                ),
            ]);
            $result->addError(new Error(implode(PHP_EOL, [$e->getMessage(), $e->getTraceAsString()])));
        }
        if ($result->isSuccess()) {
            // Получаем id из таблицы связей (b_disk_attached_object) и создаем записи в нашей интеграционной таблице.
            $attachedObjects = AttachedObjectTable::getList(
                [
                    'filter' => [
                        'OBJECT_ID' => array_keys($hashIdBitrixIdJira),
                        'MODULE_ID' => 'tasks',
                        'ENTITY_ID' => $bitrixTask->getId(),
                    ],
                ]
            )->fetchCollection();
            foreach ($attachedObjects as $attachedObject) {
                $addResult = AttachmentIntegrationTable::add(
                    [
                        'ATTACHED_OBJECT_ID' => $attachedObject->getId(),
                        'JIRA_ID' => $hashIdBitrixIdJira[$attachedObject->getObjectId()],
                    ]
                );
                $addResult->isSuccess() ?: $result->addErrors($addResult->getErrors());
            }
        }

        // Удаляем временные файлы
        foreach ($tmpFilePaths as $filePath) {
            unlink($filePath);
        }

        return $result;
    }

    /**
     * Получить путь к папке с загруженными файлами.
     */
    public static function getDirectoryForDownloads(): string
    {
        /** @var string $uploadDir Путь к папке upload */
        $uploadDir = Option::get('main', 'upload_dir', 'upload');
        /** @var string $dirPath Директория, куда загружаем файл */
        $dirPath = Application::getDocumentRoot() . '/' . $uploadDir . '/integration/jira/files';

        return realpath($dirPath);
    }

    /**
     * Получить фабрику по созданию сервисов Jira.
     */
    public function getJiraServiceFactory(): JiraServiceFactory
    {
        return $this->jiraServiceFactory;
    }

    /**
     * Проверить, что все комментарии уникальны.
     *
     * @param MessageCollection $comments Коллекция комментариев для проверки
     */
    private function assertCommentsAreUnique(MessageCollection $comments)
    {
        /** @var Message $comment */
        foreach ($comments as $comment) {
            $filteredComments = $comments->filterByUfJiraCommentId($comment->requireUfJiraCommentId());
            $this->assertCount(1, $filteredComments->count());
            $this->assertEquals($comment, $filteredComments->first());
        }
    }
}
