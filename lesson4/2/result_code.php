<?php

namespace Korus\Authorization\Sms;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Encoding;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\MessageService\Sender;
use Bitrix\MessageService\Sender\Result\SendMessage;

Loc::loadMessages(__FILE__);

class MtsCommunicator extends Sender\BaseConfigurable
{
    const API_URL = 'https://api.mcommunicator.ru/v2';
    const MTS_COMMUNICATOR = 'mts_communicator';
    const MTS_COMMUNICATOR_TITLE = 'МТС Коммуникатор';
    const MTS_COMMUNICATOR_URL = 'mcommunicator.ru';
    const EXTERNAL_MANAGE_URL = 'https://login.mcommunicator.ru/';

    public function getId()
    {
        return self::MTS_COMMUNICATOR;
    }

    public function getName()
    {
        return self::MTS_COMMUNICATOR_TITLE;
    }

    public function getShortName()
    {
        return self::MTS_COMMUNICATOR_URL;
    }

    public function isRegistered()
    {
        return ($this->getOption('api_key') !== null);
    }

    public function isDemo()
    {
        return false;
    }

    public function canUse()
    {
        return $this->isRegistered() && $this->ping()->isSuccess();
    }

    public function getFromList()
    {
        $from = $this->getOption('from_list');
        return is_array($from) ? $from : [];
    }

    protected function ping($token = null): Result
    {
        return $this->callExternalMethod(
            HttpClient::HTTP_GET,
            '/messageManagement/messages',
            array(
                'dateFrom' => (new Date())->format('Y-m-d'),
                'dateTo' => (new Date())->format('Y-m-d')
            ),
            $token
        );
    }

    public function register(array $fields)
    {
        $token = (string)$fields['api_key'];

        $result = $this->ping($token);
        if ($result->isSuccess()) {
            $this->setOption('api_key', $token);
        }

        return $result;
    }

    public function getOwnerInfo()
    {
        return ['api_key' => $this->getOption('api_key')];
    }

    public function getExternalManageUrl()
    {
        return self::EXTERNAL_MANAGE_URL;
    }

    public function sendMessage(array $messageFields)
    {
        $params = $this->getRequestBody($messageFields);

        $apiResult = $this->callExternalMethod(
            HttpClient::HTTP_POST,
            '/messageManagement/messages',
            $params
        );

        if (!$apiResult->isSuccess()) {
            return (new SendMessage())->addErrors($apiResult->getErrors());
        }

        $resultData = $apiResult->getData();
        if (empty($resultData['sid'])) {
            return (new SendMessage())->addErrors($apiResult->getErrors());
        }
        return (new SendMessage())->setExternalId($resultData['sid'])->setAccepted();
    }


    public static function resolveStatus($serviceStatus)
    {
        return parent::resolveStatus($serviceStatus);
    }

    public function sync()
    {
        if ($this->isRegistered()) {
            $this->loadFromList();
        }
        return $this;
    }

    protected static function getProxyOptions(): array
    {
        return [
            'proxyHost' => Option::get('main', 'update_site_proxy_addr'),
            'proxyPort' => Option::get('main', 'update_site_proxy_port'),
            'proxyUser' => Option::get('main', 'update_site_proxy_user'),
            'proxyPassword' => Option::get('main', 'update_site_proxy_pass'),
        ];
    }

    private function callExternalMethod($httpMethod, $apiMethod, array $params = array(), $token = null)
    {
        $url = static::API_URL . $apiMethod;

        if (empty($token)) {
            $token = $this->getOption('api_key');
        }

        if (empty($token)) {
            return (new Result())->addError(new Error('Не указан ключ API.'));
        }

        $headers = $this->getHeaders($token);

        $isUtf = Application::getInstance()->isUtfMode();
        if (empty($isUtf)) {
            $params = Encoding::convertEncoding($params, SITE_CHARSET, 'UTF-8');
        }

        $ch = $this->getCurlConnection($params, $url, $headers);
        $res = curl_exec($ch);
        if (empty($res)) {
            return (new Result())->addError(new Error('SMS Service unavailable'));
        }
        $answer = Json::decode($res);
        if (!isset($answer['message']) || !isset($answer['code'])) {
            return (new Result())->addError(new Error($answer['message'], $answer['code']));
        }

        curl_close($ch);
        return (new Result())->setData($answer);
    }

    private function loadFromList()
    {
        $result = $this->callExternalMethod(
            HttpClient::HTTP_GET,
            '/accountManagement/namings'
        );

        if ($result->isSuccess()) {
            $from = array();
            $resultData = $result->getData();
            if (isset($resultData['data']) && is_array($resultData['data'])) {
                foreach ($resultData['data'] as $naming) {
                    $from[] = array(
                        'id' => (string)$naming['namingID'],
                        'name' => $naming['name']
                    );
                }
            }

            $this->setOption('from_list', $from);
        }
    }

    /**
     * @param array $messageFields
     * @return \array[][]
     */
    public function getRequestBody(array $messageFields): array
    {
        $params = [
            'submits' => [
                [
                    'msid' => \NormalizePhone($messageFields['MESSAGE_TO']),
                    'message' => $messageFields['MESSAGE_BODY'],
                ]
            ]
        ];

        if ($messageFields['MESSAGE_FROM']) {
            $params['naming'] = $messageFields['MESSAGE_FROM'];
        }
        return $params;
    }

    /**
     * @param $token
     * @return array
     */
    public function getHeaders($token): array
    {
        $headers = [
            'Content-type: application/json',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            sprintf('Authorization: Bearer %s', $token)
        ];
        return $headers;
    }

    /**
     * @param bool $ch
     * @param array $params
     * @param string $url
     * @param array $headers
     * @return void
     */
    public function getCurlConnection(array $params, string $url, array $headers): CurlHandle
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!empty(Option::get('main', 'update_site_proxy_addr'))) {
            curl_setopt($ch, CURLOPT_PROXY, Option::get('main', 'update_site_proxy_addr'));
        }

        if (!empty(Option::get('main', 'update_site_proxy_port'))) {
            curl_setopt($ch, CURLOPT_PROXYPORT, Option::get('main', 'update_site_proxy_port'));
        }

        if (!empty(Option::get('main', 'update_site_proxy_user'))) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, sprintf('%s:%s', Option::get('main', 'update_site_proxy_user'), Option::get('main', 'update_site_proxy_pass')));
        }

        $post = Json::encode($params);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        return $ch;
    }
}
