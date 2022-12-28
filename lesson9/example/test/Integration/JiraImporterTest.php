<?php

namespace Kt\IntegrationTests\Integration;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\AttachedObjectTable;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Recyclebin\Internals\Models\RecyclebinTable;
use Bitrix\Recyclebin\Recyclebin;
use CSocNetGroup;
use CSocNetGroupSubject;
use CTaskItem;
use CUser;
use Exception;
use Faker\Factory;
use Faker\Generator;
use JiraRestApi\Issue\Attachment;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Comments;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueSearchResult;
use JiraRestApi\Issue\IssueType;
use JiraRestApi\Issue\PaginatedWorklog;
use JiraRestApi\Issue\Reporter;
use JiraRestApi\Issue\TimeTracking;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\Project\Project;
use Kt\Disk\DiskObject;
use Kt\Disk\ObjectTable;
use Kt\Forum\Message;
use Kt\Forum\MessageCollection;
use Kt\Forum\MessageTable;
use KT\Integration\Jira\JiraImporter;
use KT\Integration\Jira\JiraServiceFactory;
use KT\Integration\Jira\RestApi\JiraIssue;
use KT\Integration\Jira\RestApi\JiraIssueService;
use Kt\Integration\Tables\AttachmentIntegrationObject;
use Kt\Integration\Tables\AttachmentIntegrationTable;
use Kt\Main\FileTable;
use Kt\Main\User\User;
use Kt\Main\User\UserTable;
use Kt\Socialnetwork\Workgroup;
use Kt\Socialnetwork\WorkgroupTable;
use Kt\Tasks\ElapsedTime;
use Kt\Tasks\ElapsedTimeCollection;
use Kt\Tasks\ElapsedTimeTable;
use Kt\Tasks\Task;
use Kt\Tasks\TaskCollection;
use Kt\Tasks\TaskTable;
use Kt\Tests\BitrixTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @coversDefaultClass \KT\Integration\Jira\JiraImporter
 *
 * @internal
 */
class JiraImporterTest extends BitrixTestCase
{
    /** @var JiraImporter */
    private $importer;

    /** @var Workgroup */
    private static $project;

    /** @var JiraIssue[] */
    private static $issues;

    /** @var int Id созданного тестового пользователя */
    private static $testUserId;

    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass(): void
    {
        Application::getInstance()->getContext()->getEnvironment()->set('KT_INTEGRATION_IMPORT_SESSION', true);
        global $APPLICATION, $USER;
        $USER->Authorize(Option::get('kt.integration', 'default_user_id', 1));
        Loader::includeModule('socialnetwork');
        Loader::includeModule('kt.integration');
        Loader::includeModule('recyclebin');

        parent::setUpBeforeClass();
        /** @var EntityObject $subject */
        $subject = CSocNetGroupSubject::GetList(
            null,
            ['NAME' => 'Рабочие группы']
        )->Fetch();

        $user = new CUser();
        $pass = self::getFaker()->password;
        Loader::includeModule('highloadblock');

        $hlblock = HighloadBlockTable::getList([
            'filter' => [
                '=TABLE_NAME' => 'kt_hlb_userresource',
            ],
        ])->fetchObject();
        $resourceEntity = HighloadBlockTable::compileEntity($hlblock->getId());
        static::$testUserId = $user->Add([
            'LOGIN' => md5((string) microtime()),
            'EMAIL' => md5((string) microtime()) . '@example.com',
            'PASSWORD' => $pass,
            'CONFIRM_PASSWORD' => $pass,
            'UF_USER_RESOURCE' => $resourceEntity->getDataClass()::query()->addSelect('ID')->fetch()['ID'],
        ]);
        if (!static::$testUserId) {
            throw new Exception($user->LAST_ERROR);
        }
        $id = CSocNetGroup::Add([
            'SITE_ID' => SITE_ID,
            'NAME' => __CLASS__,
            'DESCRIPTION' => 'Тестовая группа для проверки ипорта задач из Jira',
            'VISIBLE' => 'Y',
            'OPENED' => 'Y',
            'SUBJECT_ID' => $subject['ID'],
            'KEYWORDS' => '',
            'SPAM_PERMS' => SONET_ROLES_USER,
            'INITIATE_PERMS' => SONET_ROLES_USER,
            'PROJECT' => 'N',
            WorkgroupTable::UF_JIRA_JQL_FILTER => 'project = BIT',
            WorkgroupTable::UF_JIRA_API_VERSION => '1',
            WorkgroupTable::UF_JIRA_LOGIN => 'bitrix-test',
            WorkgroupTable::UF_JIRA_PASSWORD => 'ogjh98GifsPje(#S',
            WorkgroupTable::UF_JIRA_URL => 'http://jira.kt-team.de',
            'UF_PROJECT_NAME' => self::getFaker()->word,
            'OWNER_ID' => static::$testUserId,
        ]);

        if (!$id) {
            throw new Exception($APPLICATION->LAST_ERROR);
        }

        static::$project = WorkgroupTable::query()
            ->where('ID', $id)
            ->addSelect('*')
            ->addSelect('UF_*')
            ->addSelect(WorkgroupTable::REF_OWNER)
            ->fetchObject()
            ;
    }

    /** {@inheritdoc} */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        $taskIds = TaskTable::getList(['filter' => [
            'GROUP_ID' => static::$project->getId(),
        ]])->fetchCollection()->getIdList();

        foreach ($taskIds as $taskId) {
            // Удаляем задачи в корзину
            $taskItem = new CTaskItem($taskId, Option::get('kt.integration', 'default_user_id', 1));
            $elapsedTimeCollection = ElapsedTimeTable::query()->where('TASK_ID', $taskId)->fetchCollection();
            foreach ($elapsedTimeCollection as $elapsedTime) {
                /** @var ElapsedTime $elapsedTime */
                $worklog = new \CTaskElapsedItem($taskItem, $elapsedTime->requireId());
                $worklog->delete();
            }
            $taskItem->delete();
        }
        // Удаляем задачи из корзины
        $tasksInRecycle = RecyclebinTable::getList([
            'filter' => [
                'MODULE_ID' => 'tasks', 'ENTITY_TYPE' => 'tasks_task', 'ENTITY_ID' => $taskIds,
            ],
        ])->fetchCollection();
        foreach ($tasksInRecycle as $deletedTask) {
            Recyclebin::remove($deletedTask->getId());
        }
        CSocNetGroup::Delete(static::$project->getId());
        CUser::Delete(static::$testUserId);
        Application::getInstance()->getContext()->getEnvironment()->set('KT_INTEGRATION_IMPORT_SESSION', false);
    }

    /** {@inheritdoc} */
    public function setUp(): void
    {
        parent::setUp();
        if (!static::$issues) {
            $project = $this->createJiraProject();
            /** @var Issue $parentIssue Родительская задача */
            $parentIssue = $this->createJiraIssue($project);
            /** @var Issue Дочерняя задача */
            $issueChild1 = $this->createJiraIssue($project);
            $issueChild1->fields->parent = $parentIssue;
            /** @var Issue Дочерняя задача */
            $issueChild2 = $this->createJiraIssue($project);
            $issueChild2->fields->parent = $parentIssue;
            static::$issues = [
                'parent' => $parentIssue,
                'child1' => $issueChild1,
                'child2' => $issueChild2,
                $this->createJiraIssue($project),
            ];
        }

        $this->importer = $this->createJiraImporter();
    }

    /**
     * Тест импорта задач из Jira.
     *
     * @covers ::importTasks
     *
     * @return TaskCollection
     */
    public function testImportTasks()
    {
        $oldTasksCount = 0;
        for ($i = 0; $i < 3; ++$i) {
            $result = $this->importer->importTasks(static::$project);
            // Проверяем, что импорт задач прошёл успешно
            $this->assertTrue($result->isSuccess(), implode(PHP_EOL, $result->getErrorMessages()));
            /** @var TaskCollection $tasks */
            $tasks = TaskTable::query()
                ->where('GROUP_ID', static::$project->getId())
                ->setSelect(['ID',
                    '*',
                     TaskTable::REF_COMMENTS,
                    'PARENT_ID',
                    TaskTable::REF_WORKGROUP,
                     TaskTable::REF_WORKGROUP . '.' . WorkgroupTable::REF_OWNER,
                     TaskTable::REF_WORKGROUP . '.UF_*',
                    TaskTable::REF_COMMENTS . '.' . MessageTable::UF_JIRA_COMMENT_ID,
                    'UF_*', ])
                ->fetchCollection()
            ;
            $this->assertTrue((bool) $tasks);
            $this->assertTrue($tasks->count() > 0);
            if ($oldTasksCount) {
                $this->assertEquals($tasks->count(), $oldTasksCount);
            }
            // Проверяем, что у всех задач заполнено соответствующее поле
            $this->assertTasksHaveJiraTaskId($tasks);
            $this->assertTasksAreUnique($tasks);
            $oldTasksCount = $tasks->count();
            // Проверяем, что у задач правильно выстроена иерархия
            /** @var TaskCollection $childTasks Дочерние задачи */
            $childTasks = TaskTable::query()
                ->addSelect('PARENT_ID')
                ->addSelect(TaskTable::UF_JIRA_TASK_ID)
                ->where('GROUP_ID', self::$project->requireId())
                ->whereIn(TaskTable::UF_JIRA_TASK_ID, [self::$issues['child1']->id, self::$issues['child2']->id])
                ->fetchCollection()
                ;
            /** @var Task $parentTask */
            $parentTask = TaskTable::query()
                ->where('GROUP_ID', self::$project->requireId())
                ->where(TaskTable::UF_JIRA_TASK_ID, self::$issues['parent']->id)
                ->fetchObject()
                ;
            /** @var Task $child */
            foreach ($childTasks as $child) {
                $this->assertEquals($parentTask->requireId(), $child->requireParentId());
            }
        }

        return $tasks;
    }

    /**
     * @covers::importAttachments
     * @depends testImportTasks
     *
     * @param TaskCollection $tasks Коллекция задач для которых импортируются комментарии
     */
    public function testImportAttachments(TaskCollection $tasks)
    {
        foreach ($tasks as $bitrixTask) {
            unset($oldFilesCount);
            for ($i = 0; $i < 5; ++$i) {
                $result = $this->importer->importAttachments($bitrixTask);
                // Проверяем, что импорт задач прошёл успешно
                $this->assertTrue($result->isSuccess(), implode(',', $result->getErrorMessages()));
                /** @var TaskCollection $tasks Файлы, привязанные к задаче */
                $files = AttachedObjectTable::getList([
                    'filter' => [
                        'MODULE_ID' => 'tasks',
                        'ENTITY_ID' => $bitrixTask->getId(),
                    ],
                ])->fetchCollection();
                $filesCount = $files->count();
                if ($filesCount) {
                    // Проверяем, что у задачи заполнено соответствующее поле
                    /** @var Task $taskFromDb Задача из БД */
                    $taskFromDb = TaskTable::query()
                        ->addFilter('ID', $bitrixTask->getId())
                        ->setSelect(['ID', 'UF_TASK_WEBDAV_FILES'])
                        ->fetchObject()
                    ;
                    $this->assertNotEmpty($taskFromDb->requireUfTaskWebdavFiles());
                    $this->assertEmpty(array_diff(
                        $files->getIdList(),
                        array_map('intval', $taskFromDb->requireUfTaskWebdavFiles())
                    ));
                }
                // Проверяем, что импорт возвращает необходимые данные
                $this->assertTrue(isset($result->getData()['JIRA_ATTACHMENT_IDS']));
                // Если в задаче в Jira есть приложения, значит они должны быть и в Bitrix
                if (!empty($result->getData()['JIRA_ATTACHMENT_IDS'])) {
                    $this->assertTrue($filesCount > 0);
                } else {
                    // Если нет в Jira - нет и в Bitrix
                    $this->assertTrue(0 === $filesCount);
                }
                /*
                 * Проверяем, что количество привязанных к задаче файлов в Bitrix = Количеству приложений в Jira.
                 * То есть проверяем, что все файлы скачались и привязались.
                 */
                $this->assertEquals($filesCount, count($result->getData()['JIRA_ATTACHMENT_IDS']));
                // Проверяем, что при повторном запросе к Jira и импорте количество приложений в задаче не меняется
                if (isset($oldFilesCount)) {
                    $this->assertEquals($filesCount, $oldFilesCount);
                }
                $jiraIds = [];
                /** @var AttachedObject $bitrixAttach Объект связи "Задача" - "Файл на диске" */
                foreach ($files as $bitrixAttach) {
                    /**
                     * @var AttachmentIntegrationObject $integrationRecord Запись интеграционной таблицы,
                     *                                  где хранится Id приложения к задаче (в Bitrix, Jira и Redmine)
                     */
                    $integrationRecord = AttachmentIntegrationTable::getList([
                        'filter' => [
                            AttachmentIntegrationTable::ATTACHED_OBJECT_ID => $bitrixAttach->getId(),
                        ],
                    ])->fetchObject();
                    if (!$integrationRecord) {
                        continue;
                    }
                    $jiraIds[] = $integrationRecord->requireJiraId();
                    $this->assertTrue($integrationRecord && $integrationRecord->getJiraId());
                    /** @var DiskObject $bitrixObject Объект файла на Диске B24 */
                    $bitrixObject = ObjectTable::getList([
                        'filter' => ['ID' => $bitrixAttach->getObjectId()],
                        'select' => ['ID', 'FILE_CONTENT'],
                    ])->fetchObject();
                    $this->assertTrue((bool) $bitrixObject);
                    /** @var File $bitrixFile Объект файла на сервере */
                    $bitrixFile = $bitrixObject->getFileContent();
                    $this->assertTrue((bool) $bitrixFile);
                }
                $this->assertEmpty(array_diff($result->getData()['JIRA_ATTACHMENT_IDS'], $jiraIds));
                $oldFilesCount = $filesCount;
                // Запускаем импорт заново и проверяем, что приложения больше не добавляются
            }
        }
    }

    /**
     * Тест импорта комментариев из Jira.
     *
     * @covers ::importComments
     * @depends testImportTasks
     *
     * @param TaskCollection $tasks Коллекция задач для которых импортируются комментарии
     */
    public function testImportComments(TaskCollection $tasks)
    {
        for ($i = 0; $i < 5; ++$i) {
            /** @var Task $task */
            foreach ($tasks as $task) {
                $result = $this->importer->importComments($task);
                $this->assertTrue($result->isSuccess(), implode(PHP_EOL, $result->getErrorMessages()));
                /** @var MessageCollection $comments */
                $comments = MessageTable::query()
                    ->where('TOPIC_ID', $task->requireForumTopicId())
                    ->where('NEW_TOPIC', false)
                    ->addSelect('*')
                    ->addSelect('UF_*')
                    ->fetchCollection()
                ;
                // У всех комментариев должно быть заполнено поле связи с Jira
                $this->assertTrue($comments->forAll(function ($message) {
                    /** @var Message $message */
                    return $message->requireUfJiraCommentId();
                }));
                $this->assertCommentsAreUnique($comments);
            }
        }
    }

    /**
     * @depends testImportTasks
     * @covers ::importWorklogs
     *
     * @param TaskCollection $tasks Коллекция задач
     */
    public function testImportWorkLogs(TaskCollection $tasks)
    {
        for ($i = 0; $i < 5; ++$i) {
            /** @var Task $task */
            foreach ($tasks as $task) {
                $result = $this->importer->importWorkLogs($task);
                $this->assertTrue($result->isSuccess(), implode(PHP_EOL, $result->getErrorMessages()));
                /** @var ElapsedTimeCollection $worklogs */
                $worklogs = ElapsedTimeTable::query()
                    ->addSelect('*')
                    ->addSelect('USER')
                    ->addSelect('INTEGRATION_WORKLOG')
                    ->addSelect(ElapsedTimeTable::REF_FIELD_TASK)
                    ->where('TASK_ID', $task->requireId())
                    ->fetchCollection()
                ;
                $this->assertTrue($worklogs->count() > 0);
                foreach ($worklogs as $worklog) { // У каждого ворклога есть соответствующая запись
                    /** @var ElapsedTime $worklog */
                    $this->assertIsNumeric($worklog->getJiraId());
                }
                $this->assertWorklogsUnique($worklogs);
            }
        }
    }

    /**
     * Создать клиент Jira, который на запросы возвращает предустановленные ответы,
     * а сами запросы не делает.
     */
    private function createJiraImporter(): JiraImporter
    {
        $importer = new JiraImporter();
        $reflection = new \ReflectionObject($importer);
        $jiraServiceFactoryProperty = $reflection->getProperty('jiraServiceFactory');
        $jiraServiceFactoryProperty->setAccessible(true);
        /** @var JiraServiceFactory|MockObject $jiraServiceFactory Фабрика по созданию сервисов для работы с Jira */
        $jiraServiceFactory = $this->getMockBuilder(JiraServiceFactory::class)
            ->setMethods(['createIssueService'])
            ->getMock()
        ;
        $jiraServiceFactoryProperty->setValue($importer, $jiraServiceFactory);
        /** @var JiraIssueService|MockObject $issueService */
        $issueService = $this->getMockBuilder(JiraIssueService::class)
            ->disableOriginalConstructor()
            ->setMethods(['search', 'getComments', 'getWorklog', 'get', 'download'])
            ->getMock()
            ;

        $issueService->method('search')->will($this->returnCallback(
            function ($jqlQuery, $startAt, $maxResults, $fields, $expand, $validateQuery) {
                $issueSearchResult = new IssueSearchResult();
                $issueSearchResult->setExpand($expand);
                $issueSearchResult->setStartAt($startAt);
                $issueSearchResult->setMaxResults($maxResults);
                $issueSearchResult->setIssues(static::$issues);
                $issueSearchResult->setTotal(count(static::$issues));
                $issueSearchResult->setIssues(array_slice(static::$issues, 0, $maxResults));

                return $issueSearchResult;
            }
        ));
        $issueService->method('get')->will($this->returnCallback(
            function ($issueIdOrKey) use ($issueService): JiraIssue {
                /** @var JiraIssue[] $issues Массив задач из Jira */
                $issues = $issueService->search(static::$project->requireUfJiraJqlFilter())->getIssues();
                /** @var JiraIssue $issue */
                foreach ($issues as $issue) {
                    if ($issue->id === $issueIdOrKey || $issue->key === $issueIdOrKey) {
                        return $issue;
                    }
                }
            }
        ));
        $issueService->method('download')->will($this->returnCallback(
            function ($content, $dirPath, $fileName) {
                return file_put_contents($dirPath . DIRECTORY_SEPARATOR . $fileName, file_get_contents($content));
            }
        ));
        $issueService->method('getComments')->will($this->returnCallback(
            function ($issueIdOrKey) use ($issueService): Comments {
                /** @var JiraIssue[] $issues Массив задач из Jira */
                $issues = $issueService->search(static::$project->requireUfJiraJqlFilter())->getIssues();
                /** @var JiraIssue $issue */
                foreach ($issues as $issue) {
                    if ($issue->id === $issueIdOrKey || $issue->key === $issueIdOrKey) {
                        return $issue->fields->comment;
                    }
                }
            }
        ));
        $issueService->method('getWorklog')->will($this->returnCallback(
            function ($issueIdOrKey) use ($issueService): PaginatedWorklog {
                /** @var JiraIssue[] $issues Массив задач из Jira */
                $issues = $issueService->search(static::$project->requireUfJiraJqlFilter())->getIssues();
                /** @var JiraIssue $issue */
                foreach ($issues as $issue) {
                    if ($issue->id === $issueIdOrKey || $issue->key === $issueIdOrKey) {
                        return $issue->fields->worklog;
                    }
                }
            }
        ));
        $jiraServiceFactory->method('createIssueService')->willReturn($issueService);

        return $importer;
    }

    /**
     * Создать проект Jira.
     */
    private function createJiraProject(): Project
    {
        $project = new Project();
        $project->key = strtoupper(
            self::getFaker()->randomLetter
            . self::getFaker()->randomLetter
            . self::getFaker()->randomLetter
        );

        return $project;
    }

    /**
     * Создать объект задачи Jira.
     *
     * @param Project $project Проект, к которому прикрепляется задача
     */
    private function createJiraIssue(Project $project): JiraIssue
    {
        $issue = new JiraIssue();
        $issue->id = self::getFaker()->unique()->numberBetween(1);
        $issue->key = self::getFaker()->unique()->numberBetween(1);
        $issue->fields = new IssueField();
        $issue->fields->setSummary(ucfirst(self::getFaker()->text(100)));
        $issue->fields->created = self::getFaker()->dateTimeThisYear;
        $issue->fields->reporter = $this->createIssueReporter();
        $issue->fields->creator = $this->createIssueReporter();
        $issue->fields->timeTracking = new TimeTracking();
        $issue->fields->issuetype = new IssueType();
        $issue->fields->project = $project;
        $issue->fields->assignee = $this->createIssueReporter();
        $issue->fields->description = self::getFaker()->unique()->text;
        // Заполняем комментарии
        $issue->fields->comment = new Comments();
        $issue->fields->comment->comments = [
            $this->createJiraComment(),
            $this->createJiraComment(),
            $this->createJiraComment(),
        ];
        $issue->fields->comment->total = count($issue->fields->comment->comments);
        $issue->fields->comment->startAt = 0;
        // Заполняем ворклоги
        $issue->fields->worklog = new PaginatedWorklog();
        $issue->fields->worklog->worklogs = [
            $this->createJiraWorklog(),
            $this->createJiraWorklog(),
            $this->createJiraWorklog(),
        ];
        $issue->fields->worklog->total = count($issue->fields->worklog->worklogs);
        $issue->fields->worklog->startAt = 0;
        $issue->fields->worklog->maxResults = PHP_INT_MAX;
        // Заполняем приложения
        $issue->fields->attachment = [
            $this->createJiraAttachment(),
            $this->createJiraAttachment(),
            $this->createJiraAttachment(),
        ];

        return $issue;
    }

    /**
     * Создать комментарий Jira.
     */
    private function createJiraComment(): Comment
    {
        $comment = new Comment();
        $comment->author = $this->createIssueReporter();
        $comment->id = self::getFaker()->unique()->numberBetween(1);
        $comment->setBody(self::getFaker()->unique()->text);
        $comment->created = self::getFaker()->dateTimeThisMonth;

        return $comment;
    }

    /**
     * Создать комментарий Jira.
     */
    private function createJiraAttachment(): Attachment
    {
        $attachment = new Attachment();
        $attachment->id = self::getFaker()->unique()->numberBetween(1);
        /** @var File $file */
        $file = FileTable::getList([
            'limit' => 1,
            'select' => ['ID', 'FILE_SIZE', 'CONTENT_TYPE', 'ORIGINAL_NAME'],
        ])->fetchObject();
        $filePath = \CFile::GetPath($file->getId());
        $attachment->content = $filePath;
        $attachment->filename = $file->requireOriginalName();
        $attachment->created = self::getFaker()->dateTimeThisMonth;
        $attachment->author = $this->createIssueReporter();
        $attachment->size = $file->requireFileSize();
        $attachment->mimeType = $file->requireContentType();
        $attachment->thumbnail = $attachment->content;

        return $attachment;
    }

    /**
     * Создать ворклог Jira.
     */
    private function createJiraWorklog(): Worklog
    {
        $worklog = new Worklog();
        $worklog->id = self::getFaker()->unique()->numberBetween(1);
        $worklog->setComment(self::getFaker()->unique()->text);
        $worklog->setTimeSpentSeconds(3600 * rand(1, 10));
        $worklog->setStartedDateTime(self::getFaker()->dateTimeThisMonth);
        $worklog->author = [
            'emailAddress' => static::$project->requireOwner()->requireEmail(),
        ];

        return $worklog;
    }

    /**
     * Создать пользователя задаче.
     */
    private function createIssueReporter(): Reporter
    {
        /** @var User $user */
        $user = UserTable::query()
            ->addFilter('ACTIVE', true)
            ->setLimit(1)
            ->addSelect('*')
            ->fetchObject()
            ;
        $reporter = new Reporter();
        $reporter->name = $user->getLogin();
        $reporter->displayName = $user->getFormattedName();
        $reporter->active = '1';
        $reporter->emailAddress = $user->getEmail();
        $reporter->self = self::getFaker()->url;

        return $reporter;
    }

    /**
     * Проверка, что ворклоги уникальны.
     *
     * @param ElapsedTimeCollection $worklogs Ворклоги
     */
    private function assertWorklogsUnique(ElapsedTimeCollection $worklogs)
    {
        /** @var ElapsedTime $worklog1 */
        foreach ($worklogs as $worklog1) { // Проверяем, что нет дублирующихся задач
            /** @var ElapsedTime $worklog2 */
            foreach ($worklogs as $worklog2) {
                if ($worklog1->requireId() !== $worklog2->requireId()) {
                    $this->assertNotEquals($worklog1->getJiraId(), $worklog2->getJiraId());
                    $this->assertNotEquals($worklog1->getCommentText(), $worklog2->getCommentText());
                }
            }
        }
    }

    /**
     * Проверка, что задачи являются уникальными.
     *
     * @param TaskCollection $tasks Задачи
     */
    private function assertTasksAreUnique(TaskCollection $tasks)
    {
        /** @var Task $task1 */
        foreach ($tasks as $task1) { // Проверяем, что нет дублирующихся задач
            /** @var Task $task2 */
            foreach ($tasks as $task2) {
                if ($task1->requireId() !== $task2->requireId()) {
                    $this->assertNotEquals($task1->requireUfJiraTaskId(), $task2->requireUfJiraTaskId());
                    $this->assertNotEquals($task1->requireTitle(), $task2->requireTitle());
                    $this->assertNotEquals($task1->requireDescription(), $task2->requireDescription());
                }
            }
        }
    }

    /**
     * @param TaskCollection $tasks Коллекция импортированных задач
     *
     * Проверка, что у всех задач, заполнено поле связи с JIRA
     */
    private function assertTasksHaveJiraTaskId(TaskCollection $tasks)
    {
        $ids = array_reduce(self::$issues, function ($accumulator, $issue) {
            $accumulator[] = $issue->id;

            return $accumulator;
        }, []);

        $this->assertTrue($tasks->forAll(function ($task) use ($ids) {
            /** @var Task $task */
            return in_array($task->requireUfJiraTaskId(), $ids);
        }));
    }
}
