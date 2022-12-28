<?php

namespace KT\Integration\Jira\RestApi;

use ArrayObject;
use CURLFile;
use JiraRestApi\Issue\Attachment;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Comments;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraException;
use KT\Integration\Jira\JiraSerializer;
use Kt\Main\File;
use Kt\Main\FileCollection;

/**
 * Класс задачи Jira.
 *
 * Class JiraIssue
 */
class JiraIssueService extends IssueService
{
    use JiraClientTrait;

    /** @var string Url для запросов по задачам */
    protected $uri = '/issue';

    /**
     * File upload.
     *
     * @param string $context Url context
     * @param array  $files   Upload file path to file name. Format: ['filePath' => 'fileName']
     *
     * @throws JiraException
     *
     * @return array
     */
    public function upload($context, $files)
    {
        $url = $this->createUrlByContext($context);

        $results = [];

        $curlHandle = curl_init();

        $idx = 0;
        foreach ($files as $filePath => $fileName) {
            $this->createUploadHandle($url, $filePath, $curlHandle, $fileName);

            $response = curl_exec($curlHandle);

            // if request failed or have no result.
            if (!$response) {
                $http_response = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
                $body = curl_error($curlHandle);

                if (204 === $http_response || 201 === $http_response || 200 === $http_response) {
                    $results[$idx] = $response;
                } else {
                    $msg = sprintf('CURL Error: http response=%d, %s', $http_response, $body);
                    $this->log->error($msg);

                    curl_close($curlHandle);

                    throw new JiraException($msg);
                }
            } else {
                $results[$idx] = $response;
            }
            ++$idx;
        }

        curl_close($curlHandle);

        return $results;
    }

    /**
     * Add one or more file to an issue.
     *
     * @param int|string     $issueIdOrKey   Issue id or key
     * @param FileCollection $fileCollection Attachment files
     *
     * @throws JiraException
     * @throws \JsonMapper_Exception
     *
     * @return Attachment[]
     */
    public function addAttachments($issueIdOrKey, $fileCollection)
    {
        $filePathArray = [];
        /** @var File $file Файл */
        foreach ($fileCollection->getAll() as $file) {
            /** @var string $filePath Путь к файлу */
            $filePath = $_SERVER['DOCUMENT_ROOT'] . \CFile::GetFileSRC($file->collectValues());
            $filePathArray[$filePath] = $file->getOriginalName();
        }

        $results = $this->upload($this->uri . "/{$issueIdOrKey}/attachments", $filePathArray);

        $this->log->info('addAttachments result=' . var_export($results, true));

        $attachArr = [];
        foreach ($results as $ret) {
            $ret = json_decode($ret);
            if (is_array($ret)) {
                $tmpArr = $this->json_mapper->mapArray(
                    $ret,
                    new ArrayObject(),
                    '\JiraRestApi\Issue\Attachment'
                );

                foreach ($tmpArr as $t) {
                    array_push($attachArr, $t);
                }
            } elseif (is_object($ret)) {
                array_push(
                    $attachArr,
                    $this->json_mapper->map(
                        $ret,
                        new Attachment()
                    )
                );
            }
        }

        return $attachArr;
    }

    /**
     * Create new issue.
     * Функция скопирована из-за ошибки в отправке поля timeTracking.
     * Оно должно отправляться в виде "timetracking" строчными буквами.
     *
     * @param IssueField $issueField Объект полей Issue
     *
     * @throws JiraException
     * @throws \JsonMapper_Exception
     *
     * @return Issue|object created issue key
     */
    public function create($issueField)
    {
        $issue = new Issue();

        // serilize only not null field.
        $issue->fields = $issueField;

        $jiraSerialzer = new JiraSerializer();
        $data = $jiraSerialzer->denormalize($issue, 'json');

        $this->log->info("Create Issue=\n" . $data);

        $ret = $this->exec($this->uri, $data, 'POST');

        return $this->getIssueFromJSON(json_decode($ret));
    }

    /**
     * Update issue.
     * Функция скопирована из-за ошибки в отправке поля timeTracking.
     * Оно должно отправляться в виде "timetracking" строчными буквами.
     *
     * @param int|string $issueIdOrKey Issue Key
     * @param IssueField $issueField   Объект полей Issue
     * @param array      $paramArray   Query Parameter key-value Array.
     *
     * @throws JiraException
     *
     * @return string created issue key
     */
    public function update($issueIdOrKey, $issueField, $paramArray = [])
    {
        $issue = new Issue();

        // serilize only not null field.
        $issue->fields = $issueField;

        $jiraSerialzer = new JiraSerializer();
        $data = $jiraSerialzer->denormalize($issue, 'json');

        $this->log->info("Update Issue=\n" . $data);

        $queryParam = '?' . http_build_query($paramArray);

        return $this->exec($this->uri . "/{$issueIdOrKey}" . $queryParam, $data, 'PUT');
    }

    /**
     * Get all comments on an issue.
     *
     * @todo remove after https://github.com/lesstif/php-jira-rest-client/pull/305 is merged
     *
     * @param int|string $issueIdOrKey Issue id or key
     *
     * @throws JiraException
     * @throws \JsonMapper_Exception
     *
     * @return Comments Comment class
     */
    public function getComments($issueIdOrKey)
    {
        $this->log->info("getComments=\n");

        $ret = $this->exec($this->uri . "/{$issueIdOrKey}/comment");

        $this->log->debug('get comments result=' . var_export($ret, true));

        return $this->json_mapper->map(
            json_decode($ret),
            new Comments()
        );
    }

    /**
     * Config a curl handle with proxy configuration (if set) from ConfigurationInterface.
     *
     * @param resource $curlHandle Дескриптор cURL
     */
    private function proxyConfigCurlHandle($curlHandle)
    {
        // Add proxy settings to the curl.
        if ($this->getConfiguration()->getProxyServer()) {
            curl_setopt($curlHandle, CURLOPT_PROXY, $this->getConfiguration()->getProxyServer());
            curl_setopt($curlHandle, CURLOPT_PROXYPORT, $this->getConfiguration()->getProxyPort());

            $username = $this->getConfiguration()->getProxyUser();
            $password = $this->getConfiguration()->getProxyPassword();
            curl_setopt($curlHandle, CURLOPT_PROXYUSERPWD, "{$username}:{$password}");
        }
    }

    /**
     * Create upload handle.
     *
     * @param string   $url        Request URL
     * @param string   $filePath   Filepath
     * @param resource $curlHandle Curl handle
     * @param string   $fileName   Filename
     *
     * @return resource
     */
    private function createUploadHandle($url, $filePath, $curlHandle, $fileName = '')
    {
        $fileName = $fileName ?? basename($filePath);

        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_URL, $url);

        // send file
        curl_setopt($curlHandle, CURLOPT_POST, true);

        if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION < 5) {
            $attachments = realpath($filePath);

            curl_setopt(
                $curlHandle,
                CURLOPT_POSTFIELDS,
                ['file' => '@' . $attachments . ';filename=' . $fileName]
            );

            $this->log->debug('using legacy file upload');
        } else {
            // CURLFile require PHP > 5.5
            $attachments = new CURLFile(realpath($filePath));
            $attachments->setPostFilename($fileName);

            curl_setopt(
                $curlHandle,
                CURLOPT_POSTFIELDS,
                ['file' => $attachments]
            );

            $this->log->debug('using CURLFile=' . var_export($attachments, true));
        }

        $this->authorization($curlHandle);

        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $this->getConfiguration()->isCurlOptSslVerifyHost());
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $this->getConfiguration()->isCurlOptSslVerifyPeer());

        $this->proxyConfigCurlHandle($curlHandle);

        // curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true); cannot be activated when an open_basedir is set
        if (!function_exists('ini_get') || !ini_get('open_basedir')) {
            curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
        }
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, [
            'Accept: */*',
            'Content-Type: multipart/form-data',
            'X-Atlassian-Token: nocheck',
        ]);

        curl_setopt($curlHandle, CURLOPT_VERBOSE, $this->getConfiguration()->isCurlOptVerbose());

        $this->log->debug('Curl exec=' . $url);

        return $curlHandle;
    }
}
