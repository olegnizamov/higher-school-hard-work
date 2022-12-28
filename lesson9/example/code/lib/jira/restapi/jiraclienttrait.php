<?php

namespace KT\Integration\Jira\RestApi;

use JiraRestApi\JiraException;

/**
 * Класс клиента Jira.
 *
 * Добавлены релогины при неудачной попытке.
 */
trait JiraClientTrait
{
    /**
     * @var bool Выполнять повторный запрос при 500-ой ошибке
     */
    private $reloginOn500Error = true;

    public function setReloginOn500Error(bool $value)
    {
        $this->reloginOn500Error = $value;
    }

    public function getReloginOn500Error()
    {
        return $this->reloginOn500Error;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $url        full url
     * @param string $outDir     save dir
     * @param string $file       save filename
     * @param string $cookieFile cookie filename
     *
     * @throws JiraException
     *
     * @return bool|mixed
     */
    public function download($url, $outDir, $file, $cookieFile = null)
    {
        $fileHandle = fopen($outDir . DIRECTORY_SEPARATOR . $file, 'w');

        curl_reset($this->curl);
        $ch = $this->curl;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);

        // output to file handle
        curl_setopt($ch, CURLOPT_FILE, $fileHandle);

        $this->authorization($ch, $cookieFile);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->getConfiguration()->isCurlOptSslVerifyHost());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->getConfiguration()->isCurlOptSslVerifyPeer());
        $this->proxyConfigCurlHandle($ch);

        // curl_setopt(): CURLOPT_FOLLOWLOCATION cannot be activated when an open_basedir is set
        if (!function_exists('ini_get') || !ini_get('open_basedir')) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        }

        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            ['Accept: */*', 'Content-Type: application/json', 'X-Atlassian-Token: no-check']
        );

        curl_setopt($ch, CURLOPT_VERBOSE, $this->getConfiguration()->isCurlOptVerbose());

        if ($this->isRestApiV3()) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-atlassian-force-account-id: true']);
        }

        $this->log->debug('Curl exec=' . $url);
        /**
         * @var string $response Ответ сервера обычно в формате json, кроме 500-ой ошибки
         *             Пример ответа: {"errorMessages":[],"errors":{"summary":"Field 'summary' cannot be set.
         *             It is not on the appropriate screen, or unknown.", "description":"Field 'description'
         *             cannot be set.
         *             It is not on the appropriate screen, or unknown."}}
         */
        $response = curl_exec($ch);

        // if request failed.
        if (!$response) {
            $this->http_response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_error($ch);
            fclose($fileHandle);

            /*
             * 201: The request has been fulfilled, resulting in the creation of a new resource.
             * 204: The server successfully processed the request, but is not returning any content.
             */
            if (204 === $this->http_response || 201 === $this->http_response) {
                return true;
            }

            // HostNotFound, No route to Host, etc Network error
            $msg = sprintf('CURL Error: http response=%d, %s', $this->http_response, $body);

            $this->log->error($msg);

            throw new JiraException($body, $this->http_response);
        } else {
            // if request was ok, parsing http response code.
            $this->http_response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            fclose($fileHandle);

            /**
             * Повторная попытка при коде ошибки 500.
             *
             * В версиях Jira до 7.4 иногда возникает ошибка при частых запросах
             * Ошибка вида UserNotFoundException: User <...> does not exist.
             * В теле ответа возвращается при этом чистый html (во всяком случае, на версии 7.1.0)
             *
             * @see https://jira.atlassian.com/browse/JRASERVER-62500?focusedCommentId=1006651&page=com.atlassian.jira.plugin.system.issuetabpanels%3Acomment-tabpanel
             */
            if ($this->reloginOn500Error && 500 === $this->http_response) {
                $index = 0;
                // Совершаем до 3 попыток, пока сервер не отдаст корректный результат
                while ($index < 3 && 500 === $this->http_response) {
                    $response = $this->download($url, $outDir, $file, $cookieFile = null);
                    ++$index;
                }
            } else {
                // don't check 301, 302 because setting CURLOPT_FOLLOWLOCATION
                if (200 != $this->http_response && 201 != $this->http_response) {
                    throw new JiraException($response, $this->http_response);
                }
            }
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $context        Rest API context (ex.:issue, search, etc..)
     * @param string $post_data
     * @param string $custom_request [PUT|DELETE]
     * @param string $cookieFile     cookie file
     *
     * @throws JiraException
     *
     * @return string
     */
    public function exec($context, $post_data = null, $custom_request = null, $cookieFile = null)
    {
        $url = $this->createUrlByContext($context);

        if (is_string($post_data)) {
            $this->log->info("Curl {$custom_request}: {$url} JsonData=" . $post_data);
        } elseif (is_array($post_data)) {
            $this->log->info("Curl {$custom_request}: {$url} JsonData=" . json_encode($post_data, JSON_UNESCAPED_UNICODE));
        }

        curl_reset($this->curl);
        $ch = $this->curl;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);

        // post_data
        if (!is_null($post_data)) {
            // PUT REQUEST
            if (!is_null($custom_request) && 'PUT' == $custom_request) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            }
            if (!is_null($custom_request) && 'DELETE' == $custom_request) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            } else {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            }
        } else {
            if (!is_null($custom_request) && 'DELETE' == $custom_request) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }
        }

        $this->authorization($ch, $cookieFile);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->getConfiguration()->isCurlOptSslVerifyHost());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->getConfiguration()->isCurlOptSslVerifyPeer());
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getConfiguration()->getCurlOptUserAgent());

        // curl_setopt(): CURLOPT_FOLLOWLOCATION cannot be activated when an open_basedir is set
        if (!function_exists('ini_get') || !ini_get('open_basedir')) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        }

        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            ['Accept: */*', 'Content-Type: application/json', 'X-Atlassian-Token: no-check']
        );

        curl_setopt($ch, CURLOPT_VERBOSE, $this->getConfiguration()->isCurlOptVerbose());

        // Add proxy settings to the curl.
        $this->proxyConfigCurlHandle($ch);

        $this->log->debug('Curl exec=' . $url);
        /**
         * @var string $response Ответ сервера обычно в формате json, кроме 500-ой ошибки
         *             Пример ответа: {"errorMessages":[],"errors":{"summary":"Field 'summary' cannot be set.
         *             It is not on the appropriate screen, or unknown.","description":"Field 'description'
         *             cannot be set.
         *             It is not on the appropriate screen, or unknown."}}
         */
        $response = curl_exec($ch);

        // if request failed or have no result.
        if (!$response) {
            $this->http_response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_error($ch);

            /*
             * 201: The request has been fulfilled, resulting in the creation of a new resource.
             * 204: The server successfully processed the request, but is not returning any content.
             */
            if (204 === $this->http_response || 201 === $this->http_response || 200 === $this->http_response) {
                return true;
            }

            // HostNotFound, No route to Host, etc Network error
            $msg = sprintf('CURL Error: http response=%d, %s', $this->http_response, $body);

            $this->log->error($msg);

            throw new JiraException($body, $this->http_response);
        } else {
            // if request was ok, parsing http response code.
            $this->http_response = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            /**
             * Повторная попытка при коде ошибки 500.
             *
             * В версиях Jira до 7.4 иногда возникает ошибка при частых запросах
             * Ошибка вида UserNotFoundException: User <...> does not exist.
             * В теле ответа возвращается при этом чистый html (во всяком случае, на версии 7.1.0)
             *
             * @see https://jira.atlassian.com/browse/JRASERVER-62500?focusedCommentId=1006651&page=com.atlassian.jira.plugin.system.issuetabpanels%3Acomment-tabpanel
             */
            if ($this->reloginOn500Error && 500 === $this->http_response) {
                $index = 0;
                // Совершаем до 3 попыток, пока сервер не отдаст корректный результат
                while ($index < 3 && 500 === $this->http_response) {
                    $response = $this->exec($context, $post_data, $custom_request, $cookieFile);
                    ++$index;
                }
            } else {
                // don't check 301, 302 because setting CURLOPT_FOLLOWLOCATION
                if (200 != $this->http_response && 201 != $this->http_response) {
                    throw new JiraException($response, $this->http_response);
                }
            }
        }

        return $response;
    }

    /**
     * Config a curl handle with proxy configuration (if set) from ConfigurationInterface.
     *
     * @param $ch
     */
    protected function proxyConfigCurlHandle($ch)
    {
        // Add proxy settings to the curl.
        if ($this->getConfiguration()->getProxyServer()) {
            curl_setopt($ch, CURLOPT_PROXY, $this->getConfiguration()->getProxyServer());
            curl_setopt($ch, CURLOPT_PROXYPORT, $this->getConfiguration()->getProxyPort());

            $username = $this->getConfiguration()->getProxyUser();
            $password = $this->getConfiguration()->getProxyPassword();
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$username}:{$password}");
        }
    }
}
