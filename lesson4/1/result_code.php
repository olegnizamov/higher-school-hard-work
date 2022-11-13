<?php

namespace Korus\Authorization\Sms;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Sender;
use Bitrix\MessageService\Sender\MessageStatus;
use Bitrix\MessageService\Sender\Result\SendMessage;

Loader::includeModule('messageservice');

class MtsCommunicatorAPI extends Sender\BaseConfigurable
{
    const SMS_ЦЕНТР_МТС_API = 'SMS-центр МТС API';
    private $auth;

    const MTS_RU = 'mts.ru';
    const MTS_COMMUNICATOR_API = 'mts_communicator_api';
    const HOST = 'https://omnichannel.mts.ru/http-api/v1/';
    const TIME_OUT = 10;
    const MTS_NAME = 'PGK.RU';

    public function __construct()
    {
        $login = $this->getOption('account_user');
        $password = $this->getOption('account_password');
        $this->auth = "Basic " . base64_encode($login . ":" . $password);
    }

    private function sendSms($naming, $text, $phone)
    {
        $req = $this->getRequestBody($text, $phone, $naming);
        $respStr = $this->curlRequest("messages", json_encode($req), [], "POST");
        $resp = json_decode($respStr, true);
        return $resp["messages"][0]["internal_id"];
    }

    private function curlRequest($url, $data = NULL, $headers = NULL, $method = "GET")
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, self::HOST . $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIME_OUT);

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

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $headers = array_merge($headers, ["Authorization: " . $this->auth, "Content-Type: application/json; charset=utf-8"]);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $response = curl_exec($ch);

        curl_close($ch);
        return $response;
    }

    public function sendMessage(array $messageFields)
    {
        $id = $this->sendSms($messageFields);

        if (empty($id)) {
            return (new SendMessage())->addErrors(new Error('Произошла ошибка при отправке'));
        }

        $status = $this->getStatus($id);

        if ($status !== 200 && $status !== 201) {
            return (new SendMessage())->addErrors(new Error('Произошла ошибка при отправке'));
        }

        return (new SendMessage())->setExternalId($id)->setAccepted();
    }

    private function getSmsInfo($id)
    {
        $respStr = $this->curlRequest("messages/info", json_encode(["int_ids" => [$id]]), [], "POST");
        return json_decode($respStr, true);
    }

    public function getShortName()
    {
        return self::MTS_RU;
    }

    public function getId()
    {
        return self::MTS_COMMUNICATOR_API;
    }

    public function getName()
    {
        return self::SMS_ЦЕНТР_МТС_API;
    }

    public function canUse()
    {
        return true;
    }

    public function getFromList()
    {
        $from[] = [
            'id' => self::MTS_NAME,
        ];
        return $from;
    }

    public function isRegistered()
    {
        return ($this->getOption('account_user') !== null)
            || ($this->getOption('account_password') !== null);
    }

    public function register(array $fields)
    {
        $this->setOption('account_user', $fields['account_user']);
        $this->setOption('account_password', $fields['account_password']);
        return true;
    }

    public function setByDefault()
    {
        Option::set('main', 'sms_default_service', self::MTS_COMMUNICATOR_API);
        Option::set('main', 'sms_default_sender', self::MTS_NAME);
        return true;
    }

    /**
     * @param $text
     * @param $phone
     * @param $naming
     * @return array
     */
    private function getRequestBody($messageFields): array
    {
        return [
            "messages" => [
                [
                    "content" => [
                        "short_text" => $messageFields['MESSAGE_BODY']
                    ],
                    "to" => [
                        [
                            "msisdn" => $messageFields['MESSAGE_TO']
                        ]
                    ],
                ]
            ],
            "options" => [
                "class" => 1,
                "from" => [
                    "sms_address" => $messageFields['MESSAGE_FROM'],
                ],
            ]
        ];
    }

    /**
     * @param $id
     * @return mixed
     */
    public function getStatus($id)
    {
        $status = $this->getSmsInfo($id);

        return $status['events_info'][0]['events_info'][0]['status'];
    }
}
