<?php

namespace KT\Integration\Jira\Normalizer;

use Bitrix\Main\Type\DateTime;
use JiraRestApi\Issue\Worklog;
use Kt\Integration\Tables\ElapsedTimeIntegrationObject;
use Kt\Integration\Tables\ElapsedTimeIntegrationTable;
use Kt\Tasks\ElapsedTime;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Нормализатор {@link Worklog}
 */
class JiraWorklogNormolizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * Преобразовать {@link Worklog} в объект другого типа.
     *
     * @param Worklog            $worklog Ворклог jira
     * @param ElapsedTime|string $type    Класс объекта, в который нужно преобразовать
     * @param string             $format  Формат, в который требуется перевести ворклог
     * @param array              $context Опции
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\SystemException
     *
     * @return ElapsedTime
     */
    public function denormalize($worklog, $type, $format = null, array $context = [])
    {
        $elapsedTime = new ElapsedTime();
        /** @var mixed[] $properties Публичные свойства объекта со значениями */
        $properties = array_keys(array_filter(get_object_vars($worklog)));
        foreach ($properties as $property) {
            switch ($property) {
                case 'comment':
                    $elapsedTime->setCommentText($worklog->comment);

                    break;
                case 'timeSpentSeconds':
                    $elapsedTime->setSeconds($worklog->timeSpentSeconds);
                    $elapsedTime->setMinutes(round($worklog->timeSpentSeconds / 60));

                    break;
                case 'started':
                    $createdDate = DateTime::createFromTimestamp(strtotime($worklog->started));
                    $elapsedTime->setCreatedDate($createdDate);

                    break;
            }
        }

        return $elapsedTime;
    }

    /**
     * Доступна ли денормализация данной переменной $data в объект ворклога Bitrix.
     *
     * @param Worklog $jiraWorkLog Объект ворклога Jira, из которого получаем объект ворклога Bitrix
     * @param string  $type        Объект какого класса нужно получить
     * @param string  $format      Формат из которого происходит денормализация
     *
     * @return bool
     */
    public function supportsDenormalization($jiraWorkLog, $type, $format = null)
    {
        return $jiraWorkLog instanceof Worklog && ElapsedTime::class === $type;
    }

    /**
     * Преобразовать объект ворклога битрикса в объект ворклога Jira.
     *
     * @param ElapsedTime    $elapsedTime Ворклог битрикса
     * @param string|Worklog $format      Класс объекта ворклога в Jira
     * @param array          $context     Контекст
     *
     * @throws \Exception
     *
     * @return Worklog
     */
    public function normalize($elapsedTime, $format = null, array $context = [])
    {
        $workLog = new Worklog();
        $elapsedTime->getCommentText() ? $workLog->setComment($elapsedTime->getCommentText()) : false;
        $elapsedTime->getSeconds() ? $workLog->setTimeSpentSeconds($elapsedTime->getSeconds()) : false;
        $startedDateTime = new \DateTime();
        if ($elapsedTime->getCreatedDate()) {
            $startedDateTime->setTimestamp($elapsedTime->getCreatedDate()->getTimestamp());
            $workLog->setStartedDateTime($startedDateTime);
        }
        if ($elapsedTime->getIntegrationWorklog() && $elapsedTime->getIntegrationWorklog()->getJiraWorklogId()) {
            $workLog->id = $elapsedTime->getIntegrationWorklog()->getJiraWorklogId();
        }

        return $workLog;
    }

    /**
     * Доступна ли нормализация для данного объекта в данный формат
     *
     * @param ElapsedTime $elapsedTime Объект ворклога Bitrix
     * @param string      $format      Формат, в который нужно перевести задачу
     *
     * @return bool
     */
    public function supportsNormalization($elapsedTime, $format = null)
    {
        return $elapsedTime instanceof ElapsedTime && Worklog::class === $format;
    }
}
