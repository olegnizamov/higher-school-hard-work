<?php

namespace KT\Integration\Jira\Command;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;
use CEventLog;
use Kt\Forum\MessageTable;
use KT\Integration\ImportCommand;
use KT\Integration\Jira\JiraImporter;
use Kt\Socialnetwork\Workgroup;
use Kt\Socialnetwork\WorkgroupTable;
use Kt\Tasks\Task;
use Kt\Tasks\TaskCollection;
use Kt\Tasks\TaskTable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Консольная команда импорта задач из Jira.
 *
 * @example /home/bitrix/console kt:integration:import:jira
 */
class JiraImportCommand extends ImportCommand
{
    /** {@inheritdoc} */
    protected function configure()
    {
        $this->setName('kt:integration:import:jira');
        $this->setDescription('Import tasks for projects integrated with Jira');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     *
     * @param InputInterface  $input  Input
     * @param OutputInterface $output Output
     *
     * return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = Application::getInstance()->getContext()->getEnvironment();
        if ('prod' !== $environment->get('APP_ENV')) { // Только на проде
            return 0;
        }
        $this->setInput($input);
        $this->setOutput($output);
        $this->setIntegratedProjects(
            WorkgroupTable::query()
                ->where($this->getWorkgroupFilter())
                ->setSelect(
                    [
                        WorkgroupTable::FIELD_ID,
                        WorkgroupTable::FIELD_NAME,
                        WorkgroupTable::UF_JIRA_URL,
                        WorkgroupTable::UF_JIRA_LOGIN,
                        WorkgroupTable::UF_JIRA_PASSWORD,
                        WorkgroupTable::UF_JIRA_JQL_FILTER,
                        WorkgroupTable::REF_OWNER,
                    ]
                )
                ->fetchCollection()
        );
        $this->setImporter(new JiraImporter());
        $output->writeln(
            Loc::getMessage(
                'KT_INTEGRATION_IMPORT_FROM_JIRA',
                ['#PROJECTS_COUNT#' => $this->getIntegratedProjects()->count()]
            )
        );

        //=====================ИМПОРТ===================//

        /**
         * Устанавливаем блокировку @see createImportLock.
         * Она защищает от обратной отправки записей, созданных и измененных  во время импорта.
         * TODO: стоит обратить внимание, что саму заглушку лучше поставить даже не в команде импорта.
         *  Ведь есть вероятность, что команды классов-импортеров будут вызываться вручную.
         */
        parent::createImportLock();
        /** @var Result $result Объект результата */
        $result = new Result();
        // Основная логика
        $integratedProjects = $this->getIntegratedProjects();
        foreach ($integratedProjects as $bitrixProject) {
            /** @var Workgroup $bitrixProject */
            $output->writeln(
                Loc::getMessage(
                    'KT_INTEGRATION_JIRA_PROJECT_IMPORT',
                    ['#ID#' => $bitrixProject->getId(), '#NAME#' => $bitrixProject->getName()]
                )
            );
            CEventLog::Add(
                [
                    'SEVERITY' => 'INFO',
                    'AUDIT_TYPE_ID' => 'JIRA_PROJECT_IMPORT_START',
                    'MODULE_ID' => 'kt.integration',
                    'ITEM_ID' => $bitrixProject->getId(),
                    'DESCRIPTION' => Loc::getMessage(
                        'KT_INTEGRATION_JIRA_PROJECT_IMPORT',
                        ['#ID#' => $bitrixProject->getId(), '#NAME#' => $bitrixProject->getName()]
                    ),
                ]
            );
            $importResult = $this->getImporter()->importTasks($bitrixProject);
            if (!$importResult->isSuccess()) {
                $result->addErrors($importResult->getErrors());
            }
            // Импорт комментариев, ворклогов и приложений
            try {
                $tasksQuery = TaskTable::query()
                    ->where(TaskTable::FIELD_GROUP_ID, $bitrixProject->requireId())
                    ->where(TaskTable::FIELD_ZOMBIE, false)
                    ->where(TaskTable::UF_JIRA_TASK_ID, '>', 0)
                    ->whereNot(TaskTable::FIELD_STATUS, \CTasks::STATE_COMPLETED)
                    ->addSelect('UF_*')
                    ->addSelect('*')
                    ->addSelect(TaskTable::REF_COMMENTS . '.' . MessageTable::UF_JIRA_COMMENT_ID)
                    ->addSelect(TaskTable::REF_COMMENTS . '.' . MessageTable::FIELD_AUTHOR_ID)
                    ->addSelect(TaskTable::REF_COMMENTS . '.' . MessageTable::FIELD_AUTHOR_NAME)
                    ->addSelect(TaskTable::REF_COMMENTS . '.' . MessageTable::FIELD_POST_MESSAGE)
                    ->setOffset(0)
                    ->setLimit(100)
                ;
                /** @var Task[]|TaskCollection $bitrixTasks */
                while (($bitrixTasks = $tasksQuery->fetchCollection()) && $bitrixTasks->count()) {
                    $tasksQuery->setOffset($tasksQuery->getOffset() + $tasksQuery->getLimit());
                    foreach ($bitrixTasks as $bitrixTask) {
                        $bitrixTask->setWorkgroup($bitrixProject);

                        try {
                            $this->getImporter()->importComments($bitrixTask); // Импортируем комментарии в Bitrix
                            $this->getImporter()->importWorkLogs($bitrixTask); // Импортируем воклоги
                            $taskAttachments = $importResult->getData()['ATTACHMENTS'][$bitrixTask->requireId()];
                            $this->getImporter()->importAttachments($bitrixTask, $taskAttachments); // Импортируем файлы
                        } catch (Throwable $e) {
                            $result->addError(
                                new Error($e->getMessage(), $e->getCode(), ['trace' => $e->getTraceAsString()])
                            );
                        }
                    }
                }
            } catch (Throwable $e) {
                $result->addError(new Error($e->getMessage(), $e->getCode(), ['trace' => $e->getTraceAsString()]));
            }
        }

        // Отчет
        $errorMessages = $result->getErrorMessages();
        if (!empty($errorMessages)) {
            $output->writeln(implode(PHP_EOL, $errorMessages));
        }
        $output->writeln(Loc::getMessage('KT_INTEGRATION_JIRA_IMPORT_FINISHED'));
        // Снимаем блокировку
        self::removeImportLock();
        if ($output instanceof BufferedOutput) {
            CEventLog::Add(
                [
                    'SEVERITY' => 'DEBUG',
                    'AUDIT_TYPE_ID' => 'JIRA_AGENT_IMPORT_RESULT',
                    'MODULE_ID' => 'kt.integration',
                    'ITEM_ID' => '',
                    'DESCRIPTION' => $output->fetch(),
                ]
            );
        }

        // Возвращаем результат выполнения команды. 0 - успешное выполнение, любое другое значение - код ошибки.
        return count($errorMessages);
    }

    /**
     * Получить фильтр по проектам
     */
    private function getWorkgroupFilter(): ConditionTree
    {
        $filter = new ConditionTree();
        $projectIds = $this->getInput()->getOption('projects_id');
        $filter
            ->where('CLOSED', false)
            ->whereNotNull(WorkgroupTable::UF_JIRA_JQL_FILTER)
            ->whereNotNull(WorkgroupTable::UF_JIRA_URL)
            ->whereNotNull(WorkgroupTable::UF_JIRA_LOGIN)
            ->whereNotNull(WorkgroupTable::UF_JIRA_PASSWORD)
        ;
        !$projectIds ?: $filter->whereIn('ID', $projectIds);

        return $filter;
    }
}
