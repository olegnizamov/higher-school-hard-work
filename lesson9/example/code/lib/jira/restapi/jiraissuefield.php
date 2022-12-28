<?php

namespace KT\Integration\Jira\RestApi;

use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\TimeTracking;


/**
 * Класс списка полей задачи Jira. Исправляет ошибку обмена, касающуюся поля timetracking.
 *
 * Class JiraImporter
 */
class JiraIssueField extends IssueField
{

    /**
     * JiraIssueField constructor.
     * @param bool $updateIssue Будет происходить обновление задачи в Jira?
     * @see \JiraRestApi\Issue\IssueField::__construct();
     */
    public function __construct($updateIssue = false)
    {
        parent::__construct($updateIssue);
        $this->timeTracking = new TimeTracking();
        $this->timeTracking->originalEstimate = '0h 0m';
        $this->timeTracking->originalEstimateSeconds = 0;
        /*
         * Прямая настройка потраченного времени в Jira запрещена.
         * Нельзя вручную установить количество потраченного времени.
         * Оно вычилсяется на основе записей о работе.
         * Если данные будут передаваться в Jira, так делать нальзя:
         * $this->timetracking->timeSpent = '0h 0m';
         * $this->timetracking->timeSpentSeconds = 0;
         */
    }
}
