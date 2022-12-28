<?php

namespace KT\Integration\Jira\Normalizer;

use Bitrix\Main\Type\DateTime;
use Exception;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Reporter;
use Kt\Forum\Message;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Class JiraCommentNormalizer.
 */
class JiraCommentNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /** @var string Формат даты в логах времени Jira */
    public const JIRA_DATE_FORMAT = 'Y-m-d\\TH:i:s.000O';

    /**
     * {@inheritdoc}
     *
     * @param Comment $jiraComment Объект комментария Jira, который нужно преобразовать в объект комментария Bitrix
     * @param string  $type        Класс, в который нужно преобразовать массив
     * @param string  $format      Формат, из которого была получен переданный массив
     * @param array   $context     Опции, доступные денормалайзеру
     *
     * @throws Exception
     *
     * @return Message
     */
    public function denormalize($jiraComment, $type, $format = null, array $context = [])
    {
        // Cоздаем объект комментария
        $bitrixComment = new Message();

        // Заполняем
        foreach (get_object_vars($jiraComment) as $key => $value) {
            if (!$value) {
                continue;
            }

            switch ($key) {
                case 'id':
                    $bitrixComment->setUfJiraCommentId($value);

                    break;
                case 'body':
                    $bitrixComment->setPostMessageHtml($value);
                    $bitrixComment->setPostMessage(strip_tags($value));

                    break;
                case 'created':
                    /** @var \DateTime $value */
                    $bitrixComment->setPostDate(DateTime::createFromPhp($value));

                    break;
                case 'updated':
                    /** @var \DateTime $value */
                    $bitrixComment->setEditDate(DateTime::createFromPhp($value));

                    break;
                case 'author':
                    /** @var Reporter $value */
                    $bitrixComment->setAuthorName($value->displayName);
                    $bitrixComment->setAuthorEmail($value->emailAddress);

                    break;
                case 'updateAuthor':
                    /** @var Reporter $value */
                    $bitrixComment->setEditorName($value->displayName);
                    $bitrixComment->setEditorEmail($value->emailAddress);

                    break;
            }
        }

        return $bitrixComment;
    }

    /**
     * Доступна ли денормализация переменной $data в объект сообщения Bitrix.
     *
     * @param array  $data   Массив, из которого получаем объект
     * @param string $type   Объект какого класса нужно получить
     * @param string $format Формат из которого происходит денормализация
     *
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null)
    {
        return $data instanceof Comment && Message::class === $type;
    }

    /**
     * {@inheritdoc}
     *
     * @param Message $bitrixComment Объект комментария Bitrix
     * @param string  $format        Формат, в который нужно перевести задачу
     * @param array   $context       Опции контекста
     *
     * @return Comment
     */
    public function normalize($bitrixComment, $format = null, array $context = [])
    {
        // Cоздаем объект комментария
        $jiraComment = new Comment();

        // Заполняем
        $bitrixComment->getUfJiraCommentId() ? $jiraComment->id = $bitrixComment->getUfJiraCommentId() : false;
        $bitrixComment->getPostMessageHtml()
            ? $jiraComment->setBody($bitrixComment->getPostMessageHtml())
            : $jiraComment->setBody($bitrixComment->getPostMessage());
        if ($bitrixComment->getPostDate()) {
            $dateTime = new \DateTime();
            $dateTime->setTimestamp($bitrixComment->getPostDate()->getTimestamp());
            $dateTime->setTimezone($bitrixComment->getPostDate()->getTimeZone());
            $jiraComment->created = $dateTime->format(self::JIRA_DATE_FORMAT);
        }
        if ($bitrixComment->getEditDate()) {
            $dateTime = new \DateTime();
            $dateTime->setTimestamp($bitrixComment->getEditDate()->getTimestamp());
            $dateTime->setTimezone($bitrixComment->getEditDate()->getTimeZone());
            $jiraComment->updated = $dateTime->format(self::JIRA_DATE_FORMAT);
        }

        if ($bitrixComment->getAuthorName() || $bitrixComment->getAuthorEmail()) {
            $jiraComment->author = new Reporter();
            $bitrixComment->getAuthorName() ?
                $jiraComment->author->displayName = $bitrixComment->getAuthorName() :
                false;
            $bitrixComment->getAuthorEmail() ?
                $jiraComment->author->emailAddress = $bitrixComment->getAuthorEmail() :
                false;
        }

        if ($bitrixComment->getEditorName() || $bitrixComment->getEditorEmail()) {
            $jiraComment->updateAuthor = new Reporter();
            $bitrixComment->getAuthorName() ?
                $jiraComment->updateAuthor->displayName = $bitrixComment->getEditorName() :
                false;
            $bitrixComment->getAuthorEmail() ?
                $jiraComment->updateAuthor->emailAddress = $bitrixComment->getEditorEmail() :
                false;
        }

        return $jiraComment;
    }

    /**
     * Доступна ли нормализация для данного объекта в данный формат
     *
     * @param Message $message Объект сообщения Bitrix
     * @param string  $format  Формат, в который нужно перевести задачу
     *
     * @return bool
     *
     * {@inheritdoc}
     */
    public function supportsNormalization($message, $format = null)
    {
        // Проверяем, что это объект класса "Message"
        return $message instanceof Message && $format = Comment::class;
    }
}
