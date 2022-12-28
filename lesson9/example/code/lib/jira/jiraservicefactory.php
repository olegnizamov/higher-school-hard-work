<?php

namespace KT\Integration\Jira;

use Bitrix\Main\ArgumentException;
use JiraRestApi\Attachment\AttachmentService;
use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\JiraException;
use KT\Integration\Jira\RestApi\JiraClient;
use KT\Integration\Jira\RestApi\JiraIssueService;
use Kt\Socialnetwork\Workgroup;

/**
 * Фабрика по созданию сервисов Jira.
 */
class JiraServiceFactory
{
    /**
     * Создать сервис для работы с задачами Jira.
     *
     * @param Workgroup $project Проект
     *
     * @throws ArgumentException
     * @throws JiraException
     */
    public function createIssueService(Workgroup $project): JiraIssueService
    {
        if (!$project->hasJiraIntegration()) {
            throw new ArgumentException('Project should have jira integration');
        }

        return new JiraIssueService(
            new ArrayConfiguration(
                [
                    'jiraHost' => $project->getUfJiraUrl(),
                    'jiraUser' => $project->getUfJiraLogin(),
                    'jiraPassword' => $project->getUfJiraPassword(),
                    'useV3RestApi' => 3 === (int) $project->getUfJiraApiVersion(),
                ]
            )
        );
    }

    /**
     * Создать сервис для работы с приложениями к задачам Jira.
     *
     * @param Workgroup $project Проект
     *
     * @throws ArgumentException
     * @throws JiraException
     */
    public function createAttachmentService(Workgroup $project): AttachmentService
    {
        if (!$project->hasJiraIntegration()) {
            throw new ArgumentException('Project should have jira integration');
        }

        return new AttachmentService(
            new ArrayConfiguration(
                [
                    'jiraHost' => $project->getUfJiraUrl(),
                    'jiraUser' => $project->getUfJiraLogin(),
                    'jiraPassword' => $project->getUfJiraPassword(),
                    'useV3RestApi' => 3 === (int) $project->getUfJiraApiVersion(),
                ]
            )
        );
    }

    /**
     * Создать клиент для работы Jira.
     *
     * @param Workgroup $project Проект
     *
     * @throws ArgumentException
     * @throws JiraException
     */
    public function createJiraClient(Workgroup $project): JiraClient
    {
        if (!$project->hasJiraIntegration()) {
            throw new ArgumentException('Project should have jira integration');
        }

        return new JiraClient(
            new ArrayConfiguration(
                [
                    'jiraHost' => $project->getUfJiraUrl(),
                    'jiraUser' => $project->getUfJiraLogin(),
                    'jiraPassword' => $project->getUfJiraPassword(),
                    'useV3RestApi' => 3 === (int) $project->getUfJiraApiVersion(),
                ]
            )
        );
    }
}
