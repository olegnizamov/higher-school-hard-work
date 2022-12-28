<?php

namespace KT\Integration\Jira;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\AttachedObjectTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Result;
use JiraRestApi\Issue\Attachment;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\JiraException;
use JiraRestApi\User\UserService;
use Kt\Disk\DiskObject;
use Kt\Disk\ObjectTable;
use Kt\Forum\Message;
use Kt\Forum\MessageCollection;
use Kt\Forum\MessageTable;
use KT\Integration\ExporterInterface;
use Kt\Integration\Factory\ElapsedTimeIntegrationFactory;
use KT\Integration\Jira\RestApi\JiraIssueService;
use Kt\Integration\Tables\AttachmentIntegrationObject;
use Kt\Integration\Tables\AttachmentIntegrationTable;
use Kt\Integration\Tables\ElapsedTimeIntegrationTable;
use Kt\Main\FileCollection;
use Kt\Main\Orm\ObjectManager;
use Kt\Main\Orm\ObjectManagerInterface;
use Kt\Tasks\CheckLists\CheckListItem;
use Kt\Tasks\CheckLists\CheckListItemCollection;
use Kt\Tasks\CheckLists\CheckListTreeIterator;
use Kt\Tasks\ElapsedTime;
use Kt\Tasks\ElapsedTimeCollection;
use Kt\Tasks\Task;
use Kt\Tasks\TaskCollection;
use Kt\UnitTests\Integration\JiraExporterTest;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Templating\EngineInterface;
use Symfony\Component\Templating\Loader\FilesystemLoader;
use Symfony\Component\Templating\PhpEngine;
use Symfony\Component\Templating\TemplateNameParser;

/**
 * Экспортёр в Jira.
 *
 * @see JiraExporterTest Unit test
 * @final
 */
class JiraExporter implements ExporterInterface
{
    /** @var Serializer */
    private $serializer;
    /** @var JiraServiceFactory */
    private $jiraServiceFactory;
    /** @var ElapsedTimeIntegrationFactory */
    private $elapsedTimeIntegrationFactory;
    /** @var ObjectManagerInterface */
    private $objectManager;
    /** @var EngineInterface Движок шаблонизации */
    private $templateEngine;

    /**
     * Конструктор
     *
     * @throws \JiraRestApi\JiraException
     * @throws ArgumentException
     */
    public function __construct()
    {
        $this->serializer = new JiraSerializer();
        $this->jiraServiceFactory = new JiraServiceFactory();
        $this->elapsedTimeIntegrationFactory = new ElapsedTimeIntegrationFactory();
        $this->objectManager = new ObjectManager();
        $this->templateEngine = new PhpEngine(new TemplateNameParser(), new FilesystemLoader(
            Loader::getLocal('modules/kt.integration/templates') . '/%name%'
        ));
    }

    /**
     * {@inheritdoc}
     *
     * @param TaskCollection $tasks Коллекция задач
     *
     * @throws NotImplementedException
     */
    public function exportTasks(TaskCollection $tasks): Result
    {
        throw new NotImplementedException();
    }

    /**
     * {@inheritdoc}
     * В Jira ворклоги можно добавить только у авторизованного пользоваетля.
     * Нельзя добавить ворклог от другого пользователя.
     * Поэтому подразумевается что все проекты ведутся в битрикс,
     * а в Jira информация лишь импортируется время от времени.
     *
     * @param ElapsedTimeCollection $elapsedTimeCollection Коллекция логов времени
     * @see \Kt\UnitTests\Integration\JiraExporterTest::testExportWorklogs test
     * @throws ArgumentException
     * @throws \Bitrix\Main\SystemException
     * @throws \JsonMapper_Exception
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    public function exportWorklogs(ElapsedTimeCollection $elapsedTimeCollection): Result
    {
        $result = new Result();
        /** @var array $successWorklogs Массив успешно экспортируемых ворклогов */
        $successWorklogs = [];
        /** @var bool $isCollectionValid Валидна ли коллекция */
        $isCollectionValid = $elapsedTimeCollection->forAll(function ($elapsedTime) {
            /** @var ElapsedTime $elapsedTime */
            return $elapsedTime->hasUser()
                   && $elapsedTime->hasTask()
                   && $elapsedTime->getTask()->hasUfJiraTaskId()
                   && $elapsedTime->getTask()->hasWorkgroup()
                   && $elapsedTime->getTask()->getWorkgroup()->hasJiraIntegration();
        });
        if (!$isCollectionValid) {
            throw new ArgumentException('Collection is not valid');
        }
        // Экспортируем данные
        /** @var ElapsedTime $elapsedTime Ворклог в битрикс */
        foreach ($elapsedTimeCollection as $elapsedTime) {
            try {
                /** @var IssueService $issueService Сервис по работе с задачами Jira */
                $issueService = $this
                    ->getJiraServiceFactory()
                    ->createIssueService($elapsedTime->requireTask()->requireWorkgroup())
                ;
                /** @var Worklog $worklog Лог в Jira */
                $worklog = $this->getSerializer()->normalize($elapsedTime, Worklog::class);
                // Добавляем в комментарий пользователя, кто его оставил
                $worklog->setComment(
                    $elapsedTime->getUser()->getFormattedName('#NAME# #LAST_NAME#')
                    . ': ' . PHP_EOL . $worklog->comment
                );
                if (
                    $elapsedTime->getIntegrationWorklog()
                    && $elapsedTime->requireIntegrationWorklog()->requireJiraWorklogId()
                ) { // Если ворклог уже экспортирован - обновляем
                    $worklog = $issueService->editWorklog(
                        $elapsedTime->requireTask()->requireUfJiraTaskId(),
                        $worklog,
                        $elapsedTime->requireIntegrationWorklog()->requireJiraWorklogId()
                    );
                } else {
                    $worklog = $issueService->addWorklog($elapsedTime->requireTask()->requireUfJiraTaskId(), $worklog);
                    $elapsedTime->setIntegrationWorklog($this->getElapsedTimeIntegrationFactory()->createObject());
                    $elapsedTime->requireIntegrationWorklog()->setElapsedTimeId($elapsedTime->requireId());
                    $elapsedTime->requireIntegrationWorklog()->setJiraWorklogId($worklog->id);
                    $this->getObjectManager()->save($elapsedTime->requireIntegrationWorklog());
                }
            } catch (JiraException $e) {
                // Если не удалось добавить или обновить ворклог, записываем его в виде комментария к задаче
                $result->addError(
                    new Error(
                        implode(
                            PHP_EOL,
                            [Loc::getMessage('JIRA_WORKLOG_EXPORT_ERROR'), $e->getMessage(), $e->getTraceAsString()]
                        ),
                        ['elapsedTime' => $elapsedTime]
                    )
                );
            }
        }

        return $result->setData($successWorklogs);
    }

    /**
     * {@inheritdoc}
     *
     * @param ElapsedTimeCollection $elapsedTimeCollection Коллекция записей об отработанном времени
     *
     * @throws ArgumentException
     */
    public function deleteWorklogs(ElapsedTimeCollection $elapsedTimeCollection): Result
    {
        $result = new Result();
        /** @var array $successWorklogs Массив успешно экспортируемых ворклогов */
        $successWorklogs = [];
        /** @var bool $isCollectionValid Валидна ли коллекция */
        $isCollectionValid = $elapsedTimeCollection->forAll(function ($elapsedTime) {
            /** @var ElapsedTime $elapsedTime */
            return $elapsedTime->hasUser()
                   && $elapsedTime->hasJiraId()
                   && $elapsedTime->hasTask()
                   && $elapsedTime->getTask()->hasUfJiraTaskId()
                   && $elapsedTime->getTask()->hasWorkgroup()
                   && $elapsedTime->getTask()->getWorkgroup()->hasJiraIntegration();
        });
        if (!$isCollectionValid) {
            throw new ArgumentException('Collection is not valid');
        }
        // Удаляем комментарии из Jira
        foreach ($elapsedTimeCollection as $elapsedTime) {
            /** @var ElapsedTime $elapsedTime */
            try {
                $jiraClient = $this->getJiraServiceFactory()->createJiraClient($elapsedTime->getTask()->getWorkgroup());
                /** @var int $issueId ID задачи в Jira */
                $issueId = $elapsedTime->getTask()->getUfJiraTaskId();
                /** @var bool $deleteResult Результат удаления ворклога */
                $deleteResult = $jiraClient->exec(
                    "/issue/{$issueId}/worklog/{$elapsedTime->getJiraId()}",
                    null,
                    'DELETE'
                );
                if ($deleteResult) {
                    $elapsedTime->getIntegrationWorklog()->setJiraWorklogId(null);
                    $this->getObjectManager()->save($elapsedTime->getIntegrationWorklog());
                }
            } catch (JiraException $e) {
                $result->addError(
                    new Error(
                        $e->getMessage() . PHP_EOL . $e->getTraceAsString(),
                        $e->getCode(),
                        ['elapsedTime' => $elapsedTime]
                    )
                );
            }
        }

        return $result->setData($successWorklogs);
    }

    /** {@inheritdoc}
     *
     * @param MessageCollection $comments Коллекция комментариев
     *
     * @throws ArgumentException
     * @throws \JsonMapper_Exception
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    public function exportComments(MessageCollection $comments): Result
    {
        $result = new Result();
        /** @var array $data Массив успешно обработанных комментариев */
        $data = [];
        /** @var Message $comment */
        foreach ($comments as $comment) {
            try {
                /** @var Comment $jiraComment */
                $jiraComment = $this->getSerializer()->normalize($comment);
                $jiraComment->setBody(
                    Loc::getMessage(
                        'KT_INTEGRATION_JIRA_COMMENT_FROM_ADMIN',
                        [
                            '#NAME#' => $jiraComment->author->displayName,
                            '#MESSAGE#' => $jiraComment->body,
                        ]
                    )
                );
                /*
                 * В данный момент и создателем, и редактирующим будет пользователь, от лица которого идет обмен
                 * В будущем можно сделать, чтобы происходил поиск и сопоставление пользователей по Email.
                 */
                /*
                $userService = new UserService($comment->getTask()->getWorkgroup()->createJiraConfiguration());
                $jiraComment->author = $userService->getMyself();
                if ($jiraComment->updateAuthor) {
                    $jiraComment->updateAuthor = $userService->getMyself();
                }
                */
                $issueId = $comment->getTask()->getUfJiraTaskId();
                $issueService = $this->getJiraServiceFactory()->createIssueService($comment->getTask()->getWorkgroup());
                if ($comment->getUfJiraCommentId()) {
                    $actionResult = $issueService->updateComment($issueId, $jiraComment->id, $jiraComment);
                } else {
                    $actionResult = $issueService->addComment($issueId, $jiraComment);
                    if (isset($actionResult->id) && $actionResult->id) {
                        $comment->setUfJiraCommentId($actionResult->id);
                        // Сохраняем специально не через API. Не записывался через API
                        MessageTable::update(
                            $comment->requireId(),
                            [MessageTable::UF_JIRA_COMMENT_ID => $comment->requireUfJiraCommentId()]
                        );
                    }
                }
                $data[] = $actionResult;
            } catch (JiraException $e) {
                $result->addError(
                    new Error(
                        $e->getMessage() . PHP_EOL . $e->getTraceAsString(),
                        $e->getCode(),
                        ['comment' => $comment]
                    )
                );
            }
        }
        $result->setData($data);

        return $result;
    }

    /**
     * Экспортировать чеклисты.
     *
     * @param CheckListItemCollection $collection Коллекция чеклистов для экспорта.
     *                                            Весь список должен принадлежать одной задаче.
     *
     * @see \Kt\UnitTests\Integration\JiraExporterTest::testExportChecklistsAdd Unit test
     * @see \Kt\UnitTests\Integration\JiraExporterTest::testExportChecklistsUpdate Unit test
     *
     * @throws ArgumentException
     * @throws JiraException
     * @throws \JsonMapper_Exception
     */
    public function exportCheckLists(CheckListItemCollection $collection): Result
    {
        $result = new Result();
        if (!$collection->count()) {
            return $result;
        }
        /** @var bool $isTaskTheSame Вся коллекция относится к одной задаче */
        $isTaskTheSame = $collection->forAll(function ($item) use ($collection) {
            /** @var CheckListItem $item */
            return $item->requireTaskId() === $collection->first()->requireTaskId();
        });
        assert($isTaskTheSame);
        /** @var Task $task Задача */
        $task = $collection->first()->requireTask();
        assert($task->hasUfJiraCommentChecklistId());
        /** @var JiraIssueService $issueService Сервис по работе с задачами jira */
        $issueService = $this->getJiraServiceFactory()->createIssueService($task->requireWorkgroup());
        $commentText = $this->templateEngine->render('check_list_tree.php', [
            'iterator' => new CheckListTreeIterator($collection),
        ]);
        $comment = new Comment();
        $comment->setBody($commentText);

        try {
            /** @var null|Comment $jiraComment Комментарий в Jira */
            $jiraComment = null;
            if ($task->getUfJiraCommentChecklistId()) {
                try {
                    $jiraComment = $issueService->getComment(
                        $task->requireUfJiraTaskId(),
                        $task->requireUfJiraCommentChecklistId()
                    );
                } catch (JiraException $jiraException) {
                    // Если 404, значит комментария нет в Jira, это нормально
                    if (404 !== $jiraException->getCode()) {
                        throw $jiraException;
                    }
                }
            }
            // Если уже есть комментарий с чеклистом - обновляем
            if ($jiraComment) {
                $issueService->updateComment(
                    $task->requireUfJiraTaskId(),
                    $task->requireUfJiraCommentChecklistId(),
                    $comment
                );
            } else { // Иначе - добавляем
                $newComment = $issueService->addComment($task->requireUfJiraTaskId(), $comment);
                $task->setUfJiraCommentChecklistId($newComment->id);
                // Сохранение $task вызывает почему-то фатал при сохранении связанных сущностей
                /** @var Result $updateError */
                $updateError = $this->getObjectManager()->save($task);
                $updateError->isSuccess() ?: $result->addErrors($updateError->getErrors());
            }
        } catch (JiraException $jiraException) {
            $result->addError(
                new Error(
                    $jiraException->getMessage() . PHP_EOL . $jiraException->getTraceAsString(),
                    $jiraException->getCode()
                )
            );
        }

        return $result;
    }

    /**
     * Получить сериализатор
     */
    public function getSerializer(): Serializer
    {
        return $this->serializer;
    }

    /**
     * Получить фабрику по созданию сервисов Jira.
     */
    public function getJiraServiceFactory(): JiraServiceFactory
    {
        return $this->jiraServiceFactory;
    }

    /**
     * @param TaskCollection $tasks Коллекция задач, которые нужно удалить
     *
     * {@inheritdoc}
     */
    public function deleteTasks(TaskCollection $tasks): Result
    {
        throw new NotImplementedException();
    }

    /**
     * @param MessageCollection $comments Коллекция комментариев, которые нужно удалить
     *
     * {@inheritdoc}
     */
    public function deleteComments(MessageCollection $comments): Result
    {
        throw new NotImplementedException();
    }

    /**
     * Получить фабрику для создания объектов ORM таблицы {@link ElapsedTimeIntegrationTable}.
     */
    public function getElapsedTimeIntegrationFactory(): ElapsedTimeIntegrationFactory
    {
        return $this->elapsedTimeIntegrationFactory;
    }

    /**
     * Получить менеджер для работы с объектами ORM.
     */
    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
    }

    /**
     * {@inheritdoc}
     *
     * // TODO: сделать возможность выгружать коллекцию файлов без укзания задачи.
     *
     * @param Task $bitrixTask Объект задачи Bitrix
     */
    public function exportAttachments(Task $bitrixTask): Result
    {
        /** @var Result $result Объект результата */
        $result = new Result();
        /** @var int $issueId Id задачи в Jira */
        $issueId = $bitrixTask->getUfJiraTaskId();
        /** @var array $data Массив успешно обработанных файлов */
        $data = [];
        $files = AttachedObjectTable::getList([
            'filter' => [
                'MODULE_ID' => 'tasks',
                // С этим почему-то не работает
                // 'ENTITY_TYPE' => "Bitrix\Tasks\Integration\Disk\Connector\Task",
                'ENTITY_ID' => $bitrixTask->getId(),
            ],
        ])->fetchCollection();
        /** @var FileCollection $fileCollection Коллекция файлов, отправляемых в Jira */
        $fileCollection = new FileCollection();
        /**
         * @var AttachmentIntegrationObject[] $integrationRecords Массив записей интеграционной таблицы,
         *                                    накапливающий данные в процессе цикла
         */
        $integrationRecords = [];
        /** @var AttachedObject $bitrixAttach Объект связи "Задача" - "Файл на диске" */
        foreach ($files as $bitrixAttach) {
            /**
             * @var AttachmentIntegrationObject $integrationRecord Запись интеграционной таблицы,
             *                                  где хранится Id приложения к задаче (в Bitrix, Jira и Redmine)
             */
            $integrationRecord = AttachmentIntegrationTable::getList([
                'filter' => [
                    'ATTACHED_OBJECT_ID' => $bitrixAttach->getId(),
                ],
            ])->fetchObject();
            if ($integrationRecord && $integrationRecord->getJiraId()) {
                // Ничего выгружать не надо, приложение уже есть в Jira.
                continue;
            }
            if (!$integrationRecord) {
                // Приложение еще никуда не выгружалось. Создаем запись в интеграционной таблице.
                $integrationRecord = AttachmentIntegrationTable::getById(
                    AttachmentIntegrationTable::add([
                        'ATTACHED_OBJECT_ID' => $bitrixAttach->getId(),
                    ])->getId()
                )->fetchObject();
            }
            /** @var DiskObject $bitrixObject Объект файла на Диске B24 */
            $bitrixObject = ObjectTable::getList([
                'filter' => ['ID' => $bitrixAttach->getObjectId()],
                'select' => ['ID', 'FILE_CONTENT'],
            ])->fetchObject();
            if ($bitrixObject) {
                /** @var File $bitrixFile Объект файла на сервере */
                $bitrixFile = $bitrixObject->getFileContent();
                $fileCollection->add($bitrixFile);
                $integrationRecords[$bitrixFile->getId()] = $integrationRecord;
            }
        }

        if ($fileCollection->count()) {
            $issueService = $this->getJiraServiceFactory()->createIssueService($bitrixTask->getWorkgroup());

            try {
                /**
                 * @var Attachment[] $actionResult Массив результирующих записей в Jira
                 *                   (в поле errorMessages может быть массив ошибок)
                 */
                $actionResult = $issueService->addAttachments($issueId, $fileCollection);
                $index = 0;
                foreach ($fileCollection as $file) {
                    if (isset($actionResult[$index]->id)) {
                        $integrationRecord = $integrationRecords[$file->getId()];
                        $integrationRecord->setJiraId($actionResult[$index]->id);
                        $this->getObjectManager()->save($integrationRecord);
                    } else {
                        // Обработка ошибок
                        if (isset($actionResult[$index]->errorMessages)) {
                            foreach ($actionResult[$index]->errorMessages as $error) {
                                $result->addError(new Error($error));
                            }
                        } else {
                            $result->addError(new Error(
                                'Task attachment was not loaded to Jira. File Id = ' . $file->getId()
                            ));
                        }
                    }

                    ++$index;
                }

                $data = $actionResult;
            } catch (JiraException $e) {
                $result->addError(new Error($e->getMessage(), $e->getCode(), ['task' => $bitrixTask]));
            }
        }

        $result->setData($data);

        return $result;
    }
}
