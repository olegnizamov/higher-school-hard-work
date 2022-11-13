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

    public function getId()
    {
        return 'mts_communicator';
    }

    public function getName()
    {
        return 'МТС Коммуникатор';
    }

    public function getShortName()
    {
        return 'mcommunicator.ru';
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
        return is_array($from) ? $from : array();
    }

    protected function ping($token = null) : Result
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
        return 'https://login.mcommunicator.ru/';
    }

    public function sendMessage(array $messageFields)
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

        $result = new SendMessage();
        $apiResult = $this->callExternalMethod(
            HttpClient::HTTP_POST,
            '/messageManagement/messages',
            $params
        );
        if (!$apiResult->isSuccess()) {
            $result->addErrors($apiResult->getErrors());
        } else {
            $resultData = $apiResult->getData();
            if (isset($resultData['sid'])) {
                $result->setExternalId($resultData['sid']);
            }
            $result->setAccepted();
        }

        return $result;
    }

    public function getMessageStatus(array $messageFields)
    {
        // todo: реализовать, если понадобится.
//        $result = new MessageStatus();
//        $result->setId($messageFields['ID']);
//        $result->setExternalId($messageFields['EXTERNAL_ID']);
//
//        $sid = $this->getOption('account_sid');
//        if (!$sid) {
//            $result->addError(new Error(Loc::getMessage('MESSAGESERVICE_SENDER_SMS_TWILIO_CAN_USE_ERROR')));
//            return $result;
//        }
//
//        $apiResult = $this->callExternalMethod(
//            HttpClient::HTTP_GET,
//            'Accounts/' . $sid . '/Messages/' . $result->getExternalId()
//        );
//        if (!$apiResult->isSuccess()) {
//            $result->addErrors($apiResult->getErrors());
//        } else {
//            $resultData = $apiResult->getData();
//            $result->setStatusCode($resultData['status']);
//            $result->setStatusText($resultData['status']);
//            if (in_array($resultData['status'],
//                array('accepted', 'queued', 'sending', 'sent', 'delivered', 'undelivered', 'failed'))) {
//                $result->setStatusText(
//                    Loc::getMessage('MESSAGESERVICE_SENDER_SMS_TWILIO_MESSAGE_STATUS_' . mb_strtoupper($resultData['status']))
//                );
//            }
//        }
//
//        return $result;
    }

    public static function resolveStatus($serviceStatus)
    {
        $status = parent::resolveStatus($serviceStatus);

        // todo: реализовать, если понадобится.
//        switch ((string)$serviceStatus) {
//            case 'accepted':
//                return MessageService\MessageStatus::ACCEPTED;
//                break;
//            case 'queued':
//                return MessageService\MessageStatus::QUEUED;
//                break;
//            case 'sending':
//                return MessageService\MessageStatus::SENDING;
//                break;
//            case 'sent':
//                return MessageService\MessageStatus::SENT;
//                break;
//            case 'delivered':
//                return MessageService\MessageStatus::DELIVERED;
//                break;
//            case 'undelivered':
//                return MessageService\MessageStatus::UNDELIVERED;
//                break;
//            case 'failed':
//                return MessageService\MessageStatus::FAILED;
//                break;
//        }

        return $status;
    }

    public function sync()
    {
        if ($this->isRegistered()) {
            $this->loadFromList();
        }
        return $this;
    }

    protected static function getProxyOptions() : array
    {
        $res = [];

        if ($host = Option::get('main', 'update_site_proxy_addr')) {
            $res['proxyHost'] = $host;
        }

        if ($port = Option::get('main', 'update_site_proxy_port')) {
            $res['proxyPort'] = $port;
        }

        if ($user = Option::get('main', 'update_site_proxy_user')) {
            $res['proxyUser'] = $user;
        }

        if ($pass = Option::get('main', 'update_site_proxy_pass')) {
            $res['proxyPassword'] = $pass;
        }

        return $res;
    }

    private function callExternalMethod($httpMethod, $apiMethod, array $params = array(), $token = null)
    {
        $url = static::API_URL . $apiMethod;

        $result = new Result();

        if (!$token) {
            $token = $this->getOption('api_key');
        }

        if (empty($token)) {
            $result->addError(new Error('Не указан ключ API.'));
        }

        $headers = array(
            'Content-type: application/json',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            sprintf('Authorization: Bearer %s', $token)
        );

        $isUtf = Application::getInstance()->isUtfMode();
        if (!$isUtf) {
            $params = Encoding::convertEncoding($params, SITE_CHARSET, 'UTF-8');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($host = Option::get('main', 'update_site_proxy_addr')) {
            curl_setopt($ch, CURLOPT_PROXY, $host);

            if ($port = Option::get('main', 'update_site_proxy_port')) {
                curl_setopt($ch, CURLOPT_PROXYPORT, $port);
            }

            $user = Option::get('main', 'update_site_proxy_user');
            $pass = Option::get('main', 'update_site_proxy_pass');
            if ($user) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, sprintf('%s:%s', $user, $pass));
            }
        }

        switch ($httpMethod) {
            case HttpClient::HTTP_GET:
                if ($query = http_build_query($params)) {
                    $url .= '?' . $query;
                }
                break;

            case HttpClient::HTTP_POST:
                $post = Json::encode($params);

                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                break;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $answer = array();
        $res = curl_exec($ch);
        if ($res !== false) {
            try {
                $answer = Json::decode($res);
            } catch (ArgumentException $e) {
                $result->addError(new Error('Service error'));
            }

            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatus >= 400) {
                if (isset($answer['message']) && isset($answer['code'])) {
                    $result->addError(new Error($answer['message'], $answer['code']));
                } else {
                    $result->addError(new Error('Service error (HTTP Status ' . $httpStatus . ')'));
                }
            }
        } else {
            $result->addError(new Error('SMS Service unavailable'));
        }

        if ($result->isSuccess()) {
            $result->setData($answer);
        }

        curl_close($ch);

        return $result;
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
}
