<?php

namespace KT\Integration\Jira\Normalizer;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use DateTimeInterface;
use forumTextParser;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\TimeTracking;
use KT\Integration\Jira\RestApi\JiraIssue;
use KT\Integration\Jira\RestApi\JiraIssueField;
use KT\Integration\Jira\RestApi\JiraTimeTracking;
use Kt\Tasks\Task;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Нормализатор {@link Issue}.
 */
class JiraTaskNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /** @var string Формат даты в задачах Jira */
    public const JIRA_DATE_FORMAT = 'Y-m-d\\TH:i:s.000O';

    /**
     * {@inheritdoc}
     *
     * @param Issue  $data    Объект задачи Jira, который нужно преобразовать в объект задачи Bitrix
     * @param string $type    Класс, в который нужно преобразовать массив
     * @param string $format  Формат, из которого была получен переданный массив
     * @param array  $context Опции, доступные денормалайзеру
     *
     * @throws \Exception
     *
     * @return string|Task
     */
    public function denormalize($data, $type, $format = null, array $context = [])
    {
        switch ($type) {
            case 'json':
                $data = json_decode(json_encode($data));

                // Исправляем ошибку в библиотеке. Отправляться должно timetracking, а не timeTracking.
                if (isset($data->fields->timeTracking)) {
                    $data->fields->timetracking = $data->fields->timeTracking;
                    unset($data->fields->timeTracking);
                }

                return json_encode($data);

                break;
            case Task::class:
                // Cоздаем объект задачи
                if ($context['bitrixTask'] instanceof Task) {
                    // Обновляем задачу из Jira.
                    $bitrixTask = $context['bitrixTask'];
                } else {
                    $bitrixTask = new Task();
                }
                $bitrixTask->setUfJiraTaskId($data->id);
                if ($data->fields instanceof IssueField) {
                    $bitrixTask->setTitle($data->fields->summary);
                    $bitrixTask->setDescription($data->fields->description);
                    if (isset($data->fields->created) && $data->fields->created instanceof DateTimeInterface) {
                        $bitrixTask->setCreatedDate(
                            DateTime::createFromTimestamp($data->fields->created->getTimestamp())
                        );
                    }
                    if (isset($data->fields->timeTracking) && $data->fields->timeTracking instanceof TimeTracking) {
                        $bitrixTask->setAllowTimeTracking(true);
                        $bitrixTask->setTimeEstimate($data->fields->timeTracking->getOriginalEstimateSeconds());
                    } elseif (isset($data->fields->timeestimate)) {
                        $bitrixTask->setAllowTimeTracking(true);
                        $bitrixTask->setTimeEstimate($data->fields->timeestimate);
                    }
                    if ($data->fields->duedate) {
                        $bitrixTask->setDeadline(new DateTime($data->fields->duedate, self::JIRA_DATE_FORMAT));
                    }
                }

                return $bitrixTask;
        }
    }

    /**
     * Доступна ли денормализация данной переменной $data в объект задачи Bitrix.
     *
     * @param array  $data   Объект задачи Jira, из которого получаем объект задачи Bitrix
     * @param string $type   Объект какого класса нужно получить
     * @param string $format Формат из которого происходит денормализация
     *
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null)
    {
        return $data instanceof Issue &&
            (Task::class === $type || 'json' === $type);
    }

    /**
     * {@inheritdoc}
     *
     * @param Task   $task    Объект задачи Bitrix
     * @param string $format  Формат, в который нужно перевести задачу
     * @param array  $context Опции контекста
     *                        Если значение [update = true] - формируем объект, предназначенный для обновления таски
     *
     * @return Issue
     */
    public function normalize($task, $format = null, array $context = [])
    {
        // Создаем объект результата с необходимыми полями
        $result = new JiraIssue();
        $result->fields = new JiraIssueField($context['update']);

        // Формируем результат
        $task->getUfJiraTaskId() ? $result->id = $task->getUfJiraTaskId() : false;
        $task->getTitle() ? $result->fields->setSummary($task->getTitle()) : false;
        $description = html_entity_decode($task->getDescription());
        // Обрезаем все BB-код теги
        if ($task->getDescriptionInBbcode() && Loader::includeModule('forum')) {
            $parser = new forumTextParser();
            $description = $parser->convert($description, ['NL2BR' => 'N']);
            $description = preg_replace('#<br\s*/?>#', "\r\n", $description);
            $description = str_replace(' ', "", $description);
            $description = html_entity_decode(strip_tags($description, '<a>'));
            // Т.к. Jira не поддерживает ссылки в HTML, то вырезаем их и формируем в виде строки
            $description = preg_replace('#<a.*href=[\'"](.+?)[\'"].*\/a>#', '$1', $description);
        }

        /*
         * TODO:
         *  1. Ссылки вставлять текстом, они сами обрабатываются.
         *  <a href='www.site.ru">текст</a> => текст (www.site.ru).
         *  2. Картинки вставлять в приложение к задаче, а в тексте вставлять ("см. файл такой-то").
         *  3. Выгружать приложения к задаче в виде приложения к задаче.
         *  4. Выгружать чеклисты в виде чеклистов, или маркированного списка, или комментария. Уточнить.
         */

        $description ? $result->fields->setDescription($description) : false;
        if (!$context['update']) {
            if ($task->getWorkgroup() && $task->getWorkgroup()->getUfJiraJqlFilter()) {
                $matches = [];
                preg_match('/project\s*=\s*(\w+)/', $task->getWorkgroup()->getUfJiraJqlFilter(), $matches);
                isset($matches[1]) ? $result->fields->setProjectId($matches[1]) : null;
            }
            if ($task->getResponsible() && $task->getResponsible()->getEmail()) {
                $jiraLogin = explode('@', $task->getResponsible()->getEmail())[0];
                $result->fields->setAssigneeName($jiraLogin);
            }
        }

        if ($task->getTimeEstimate()) {
            $originalEstimateString = JiraTimeTracking::convertSecondsToJiraString($task->getTimeEstimate());
            $result->fields->timeTracking->setOriginalEstimate($originalEstimateString);
            $result->fields->timeTracking->setOriginalEstimateSeconds($task->getTimeEstimate());
        }

        if ($task->getDeadline()) {
            $deadline = new \DateTime();
            $deadline->setTimestamp($task->getDeadline()->getTimestamp());
            $result->fields->setDueDate($deadline);
        }

        // Возвращаем результат
        return $result;
    }

    /**
     * Доступна ли нормализация для данного объекта в данный формат
     *
     * @param Task   $task   Объект задачи Bitrix
     * @param string $format Формат, в который нужно перевести задачу
     *
     * @return bool
     *
     * {@inheritdoc}
     */
    public function supportsNormalization($task, $format = null)
    {
        return $task instanceof Task && $format = Issue::class;
    }
}
