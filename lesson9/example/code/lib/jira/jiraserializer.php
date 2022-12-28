<?php

namespace KT\Integration\Jira;

use KT\Integration\Jira\Normalizer\JiraCommentNormalizer;
use Kt\Integration\Jira\Normalizer\JiraElapsedTimeNormalizer;
use KT\Integration\Jira\Normalizer\JiraTaskNormalizer;
use KT\Integration\Jira\Normalizer\JiraWorklogNormolizer;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Serializer;

/**
 * Класс-сериалайзер Jira.
 * Класс отвечает за преобразование поступающих из Jira и передаваемых в Jira данных.
 * При вызове функций normalize и denormalize он сам подбирает
 *  нужный нормалайзер из списка доступных в зависимости от типа переданного объекта.
 *
 * Class JiraSerializer
 */
class JiraSerializer extends Serializer
{
    /**
     * Конструктор сериалайзера.
     * В отличие от стандартного сериалайзера Symfony у нас жестко забит список доступных нормалайзеров.
     */
    public function __construct()
    {
        parent::__construct([
            new JiraTaskNormalizer(),
            new JiraWorklogNormolizer(),
            new JiraCommentNormalizer(),
        ]);
    }
}
