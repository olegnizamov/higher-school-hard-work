<?php

namespace KT\Integration\Jira\RestApi;

use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueField;

/**
 * Класс задачи Jira.
 *
 * Class JiraIssue
 */
class JiraIssue extends Issue
{
    /** @var JiraIssueField Объект списка полей задачи Jira */
    public $fields;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->fields = new JiraIssueField();
    }
}
