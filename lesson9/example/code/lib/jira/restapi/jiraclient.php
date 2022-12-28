<?php

namespace KT\Integration\Jira\RestApi;

/**
 * Класс клиента Jira.
 *
 * Добавлены релогины при неудачной попытке.
 */
class JiraClient extends \JiraRestApi\JiraClient
{
    use JiraClientTrait;
}
