<?php

namespace KT\Integration\Jira\Normalizer;

use Bitrix\Disk\File;
use JiraRestApi\Issue\Attachment;
use Kt\Disk\AttachedObject;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Class JiraFileNormalizer.
 */
class JiraFileNormalizer implements NormalizerInterface // todo: uncomment, DenormalizerInterface
{
    /**
     * {@inheritdoc}
     *
     * @param AttachedObject $bitrixAttach Объект приложения Bitrix
     * @param string         $format       Формат, в который нужно перевести задачу
     * @param array          $context      Опции контекста
     *
     * @return Attachment
     */
    public function normalize($bitrixAttach, $format = null, array $context = [])
    {
        /** @var Attachment $jiraFile Объект приложения к задаче / комментарию */
        $jiraFile = new Attachment();
        /** @var File $bitrixFile Объект файла на Диске B24 */
        $bitrixFile = $bitrixAttach->getObject()->getFileContent();
        /** @var array $bitrixFileArray Массив файла на сервере */
        $bitrixFileArray = $bitrixFile->getFile();
        /** @var string $filePath Путь к файлу */
        // TODO: лезет в базу! перенести вовне
        $filePath = \CFile::GetFileSRC($bitrixFileArray);
        $fileContent = file_get_contents($_SERVER['DOCUMENT_ROOT'] . $filePath);
        // Заполняем
        $bitrixAttach->getUfJiraFileId() ? $jiraFile->id = $bitrixAttach->getUfJiraFileId() : false;
        $bitrixFile->getContentType()
            ? $jiraFile->mimeType = $bitrixAttach->getObject()->getFileContent()->getContentType() : false;
        $bitrixFile->getName()
            ? $jiraFile->filename = $bitrixAttach->getObject()->getFileContent()->getName() : false;
        $fileContent ? $jiraFile->content = $fileContent : false;
        $bitrixFile->getSize() ? $jiraFile->size = $bitrixFile->getSize() : false;

        return $jiraFile;
    }

    /**
     * Доступна ли нормализация для данного объекта в данный формат
     *
     * @param AttachedObject $bitrixAttach Объект приложения к задаче на стороне Bitrix
     * @param string         $format       Формат, в который нужно перевести задачу
     *
     * @return bool
     *
     * {@inheritdoc}
     */
    public function supportsNormalization($bitrixAttach, $format = null)
    {
        // Проверяем, что это объект класса AttachedObjecte"
        return $bitrixAttach instanceof AttachedObject && $format = Attachment::class;
    }
}
