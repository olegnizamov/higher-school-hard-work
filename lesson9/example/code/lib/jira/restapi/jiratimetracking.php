<?php

namespace KT\Integration\Jira\RestApi;

use JiraRestApi\Issue\TimeTracking;

/**
 * Класс задачи Jira.
 *
 * Class JiraIssue
 */
class JiraTimeTracking extends TimeTracking
{
    /**
     * Перевести секунды в строку вида 5h 20m (формат времени Jira).
     *
     * @param int $seconds Количество секунд
     * @return string
     */
    public static function convertSecondsToJiraString($seconds)
    {
        $minutes = floor($seconds / 60); // Считаем минуты
        $hours = floor($minutes / 60); // Считаем количество полных часов
        $minutes = $minutes - ($hours * 60);  // Считаем количество оставшихся минут
        return  $hours . 'h ' . $minutes . 'm';
    }
}
