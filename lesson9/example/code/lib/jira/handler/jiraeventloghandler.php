<?php

namespace KT\Integration\Jira\Handler;

use Bitrix\Main\Localization\Loc;

/**
 * Class EventLogHandler - Класс обработчик событий, необходимых для работы модуля с Журналом событий.
 */
class JiraEventLogHandler
{
    /**
     * Обработчик формирования списка событий в Журнале событий.
     * Добавляет новые события модуля.
     */
    public static function onEventLogGetAuditTypes()
    {
        return [
            'JIRA_COMMENT_ADD_WARNING' => Loc::getMessage('JIRA_COMMENT_ADD_WARNING'),
            'JIRA_AGENT_IMPORT_RESULT' => Loc::getMessage('JIRA_AGENT_IMPORT_RESULT'),
            'JIRA_ATTACHMENT_TO_DISK_WARNING' => Loc::getMessage('JIRA_ATTACHMENT_TO_DISK_WARNING'),
            'JIRA_TASK_GET_WARNING' => Loc::getMessage('JIRA_TASK_GET_WARNING'),
            'JIRA_USER_GET_WARNING' => Loc::getMessage('JIRA_USER_GET_WARNING'),
            'JIRA_WORKLOG_EXPORT_WARNING' => Loc::getMessage('JIRA_WORKLOG_EXPORT_WARNING'),
            'JIRA_WORKLOG_DELETE_WARNING' => Loc::getMessage('JIRA_WORKLOG_DELETE_WARNING'),
        ];
    }
}
