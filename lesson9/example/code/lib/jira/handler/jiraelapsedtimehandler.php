<?php

namespace Kt\Integration\Jira\Handler;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Entity\EventResult;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use CEventLog;
use KT\Integration\Jira\JiraExporter;
use Kt\Integration\Tables\ElapsedTimeIntegrationTable;
use Kt\Socialnetwork\Workgroup;
use Kt\Tasks\ElapsedTime;
use Kt\Tasks\ElapsedTimeCollection;
use Kt\Tasks\ElapsedTimeTable;

/**
 * Объект, содержащий обработчики событий логов времени,
 * связанные с интеграцией с Jira.
 */
class JiraElapsedTimeHandler
{
    /**
     * Обработчик события после добавления лога времени.
     * Отправляет запрос в Jira, для создания соответствующего лога времени.
     *
     * @param int $elapsedTimeId ID добавленного лога времени
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     * @throws \JiraRestApi\JiraException
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     * @throws \JsonMapper_Exception
     */
    public static function onTaskElapsedTimeAdd(int $elapsedTimeId): EventResult
    {
        $result = new EventResult();
        if ('prod' !== Context::getCurrent()->getEnvironment()->get('APP_ENV')) {
            return $result;
        }
        /*
         * Если идет импорт, ничего выгружать не нужно,
         * иначе у нас будет двойная работа: получаем данные и опять их отправляем обратно.
         */
        if (Application::getInstance()->getContext()
            ->getEnvironment()->get('KT_INTEGRATION_IMPORT_SESSION')) {
            return $result;
        }

        try {
            /** @var ElapsedTime $elapsedTime Объект трека времени */
            $elapsedTime = self::getFilledElapsedTime($elapsedTimeId);
            /** @var Workgroup $project */
            $project = $elapsedTime->getTask()->getWorkgroup();
            if (!$project) {
                return $result;
            }
            /*
             * Если у проекта нет интеграции с Jira
             * или лог времени уже был выгружен в Jira
             * или задача не выгружена в Jira,
             * то ничего не делаем
             */
            if (
                !($project && $project->hasJiraIntegration())
                || $elapsedTime->hasJiraId()
                || !($elapsedTime->hasTask() && $elapsedTime->getTask()->hasUfJiraTaskId())
            ) {
                return $result;
            }
            $collection = new ElapsedTimeCollection();
            $collection->add($elapsedTime);
            $exporter = new JiraExporter();

            $exportResult = $exporter->exportWorklogs($collection);
        } catch (\Throwable $e) { // Никакие фаталы не должны запрещать создавать лог времени
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_WORKLOG_EXPORT_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $elapsedTime->getId(),
                'DESCRIPTION' => $e->getMessage(),
            ]);

            return $result;
        }

        foreach ($exportResult->getErrorCollection() as $error) {
            /**
             * @var Error       $error
             * @var ElapsedTime $errorElapsedTime Лог времени, который выгрузился с ошибкой
             */
            $errorElapsedTime = $error->getCustomData()['elapsedTime'];
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_WORKLOG_EXPORT_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $errorElapsedTime->getId(),
                'DESCRIPTION' => Loc::getMessage('ERROR_JIRA_WORKLOG_EXPORT_WARNING', [
                    '#ELAPSED_TIME_ID#' => $errorElapsedTime->getId(),
                ]),
            ]);
        }

        return $result;
    }

    /**
     * Обработчик события после обновления лога времени.
     * Отправляет запрос в Jira, для обновления соответствующего лога времени.
     *
     * @param int $elapsedTimeId ID добавленного лога времени
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     * @throws \JiraRestApi\JiraException
     * @throws \JsonMapper_Exception
     */
    public static function onTaskElapsedTimeUpdate(int $elapsedTimeId): EventResult
    {
        $result = new EventResult();
        if ('prod' !== Context::getCurrent()->getEnvironment()->get('APP_ENV')) {
            return $result;
        }
        /*
         * Если идет импорт, ничего выгружать не нужно,
         * иначе у нас будет двойная работа: получаем данные и опять их отправляем обратно.
         */
        if (Application::getInstance()->getContext()->getEnvironment()->get('KT_INTEGRATION_IMPORT_SESSION')) {
            return $result;
        }

        try {
            /** @var ElapsedTime $elapsedTime Объект трека времени */
            $elapsedTime = self::getFilledElapsedTime($elapsedTimeId);
            /** @var Workgroup $project */
            $project = $elapsedTime->getTask()->getWorkgroup();
            if (!$project) {
                return $result;
            }
            if (
                !($project && $project->hasJiraIntegration())
                || !($elapsedTime->hasTask() && $elapsedTime->getTask()->hasUfJiraTaskId())
            ) {
                return $result;
            }
            $collection = new ElapsedTimeCollection();
            $collection->add($elapsedTime);
            $exporter = new JiraExporter();
            $exportResult = $exporter->exportWorklogs($collection);
        } catch (\Throwable $e) { // Никакие фаталы не должны запрещать обновление времени
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_WORKLOG_EXPORT_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $elapsedTime->getId(),
                'DESCRIPTION' => $e->getMessage(),
            ]);

            return $result;
        }

        foreach ($exportResult->getErrorCollection() as $error) {
            /**
             * @var Error       $error
             * @var ElapsedTime $errorElapsedTime Лог времени, который выгрузился с ошибкой
             */
            $errorElapsedTime = $error->getCustomData()['elapsedTime'];
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_WORKLOG_EXPORT_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $errorElapsedTime->getId(),
                'DESCRIPTION' => Loc::getMessage('ERROR_JIRA_WORKLOG_EXPORT_WARNING', [
                    '#ELAPSED_TIME_ID#' => $errorElapsedTime->getId(),
                ]),
            ]);
        }

        return $result;
    }

    /**
     * Обработчик события до удаления лога времени.
     * Отправляет запрос в Jira, для удаление соответствующего лога времени.
     *
     * @param int $elapsedTimeId ID добавленного лога времени
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     * @throws \JiraRestApi\JiraException
     */
    public static function onBeforeTaskElapsedTimeDelete(int $elapsedTimeId): EventResult
    {
        try {
            $result = new EventResult();
            if ('prod' !== Context::getCurrent()->getEnvironment()->get('APP_ENV')) {
                return $result;
            }
            /** @var ElapsedTime $elapsedTime Объект трека времени */
            $elapsedTime = self::getFilledElapsedTime($elapsedTimeId);
            /** @var Workgroup $project */
            $project = $elapsedTime->getTask()->getWorkgroup();
            if (!$project) {
                return $result;
            }
            /*
             * Если у проекта нет интеграции с Jira
             * или задача не выгружена в Jira
             * или лог времени не был выгружен в Jira,
             * то ничего не делаем
             */
            if (
                !($project && $project->hasJiraIntegration())
                || !$elapsedTime->hasJiraId()
                || !($elapsedTime->hasTask() && $elapsedTime->getTask()->hasUfJiraTaskId())
            ) {
                return $result;
            }
            $collection = new ElapsedTimeCollection();
            $collection->add($elapsedTime);
            $exporter = new JiraExporter();

            $deleteResult = $exporter->deleteWorklogs($collection);
        } catch (\Throwable $e) { // Никакие фаталы не должны запрещать удаление лога
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_WORKLOG_DELETE_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $elapsedTimeId,
                'DESCRIPTION' => $e->getMessage(),
            ]);

            return $result;
        }

        foreach ($deleteResult->getErrorCollection() as $error) {
            /**
             * @var Error       $error
             * @var ElapsedTime $errorElapsedTime Лог времени, который выгрузился с ошибкой
             */
            $errorElapsedTime = $error->getCustomData()['elapsedTime'];
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'JIRA_WORKLOG_DELETE_WARNING',
                'MODULE_ID' => 'kt.integration',
                'ITEM_ID' => $errorElapsedTime->getId(),
                'DESCRIPTION' => Loc::getMessage('ERROR_JIRA_WORKLOG_DELETE_WARNING', [
                    '#ELAPSED_TIME_ID#' => $errorElapsedTime->getId(),
                ]),
            ]);
        }

        return $result;
    }

    /**
     * По ID трека времени в битрикс, получить подготовленный объект для работы в обработчиках событий.
     *
     * @param int $elapsedTimeId ID удаляемого лога времени
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function getFilledElapsedTime(int $elapsedTimeId): ElapsedTime
    {
        /** @var ElapsedTime $elapsedTime Объект трека времени */
        $elapsedTime = ElapsedTimeTable::query()
            ->where('ID', $elapsedTimeId)
            ->setSelect([
                            '*',
                            ElapsedTimeTable::REF_FIELD_TASK,
                            ElapsedTimeTable::REF_FIELD_USER,
                            ElapsedTimeTable::REF_INTEGRATION_WORKLOG,
                        ])
            ->fetchObject()
            ;
        $elapsedTime->requireTask()->fillUfJiraTaskId();
        $elapsedTime->requireTask()->fillWorkgroup();
        if ($elapsedTime->getTask()->getWorkgroup()) {
            $elapsedTime->getTask()->getWorkgroup()->fill('UF_*');
        }

        return $elapsedTime;
    }
}
