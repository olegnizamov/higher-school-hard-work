<?php

/**
 * Класс, используемый для отпавки Смс сообщения пользователю с указанием смс-кода, телефона и тайтла отправителя.
 * Наследует общий интерфейс смс рассылок Битрикса, поэтому использует в стандартном интерфейсе и может быть выбран для оптравки
 * Настройки-> Настройки системы-> Настройки модулей-> Главный модуль -> Пункт Смс рассылки.
 * Данный класс не используется для смс рассылок пользотелей из функционала Шаблоны пользователей->Смс рассылки,
 * и является только коммуникатором для сообщений, которые находятся в таблице
 * b_messages_message. В случае успешной отправки сообщений данный класс генерирует объекта класса SendMessage(),
 * в котором в случае ошибок будет передаваться код ошибки в базу данных или статус отправки успешно.
 *
 *
 *  Настройки для отправки сообщений(креды) хранятся в таблице b_option или по пути Настройки-> Настройки системы-> Настройки модулей-> Смс рассалыка-> Креды
 *  Необходимо бережно относиться к кредам и стараться их менять раз в 3-4 месяца.
 */


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
    private $login;
    private $password;
    private $auth;

    const HOST = 'https://omnichannel.mts.ru/http-api/v1/';
    const TIME_OUT = 10;
    const MTS_NAME = 'PGK.RU';

    public function __construct()
    {
        $this->login = $this->getOption('account_user');
        $this->password = $this->getOption('account_password');
        $this->auth = "Basic " . base64_encode($this->login . ":" . $this->password);
    }

    public function getCred(): array
    {
        return [
            'login' => $this->login,
            'password' => $this->password,
        ];
    }

    private function sendSms($naming, $text, $phone)
    {
        $req = [
            "messages" => [
                [
                    "content" => [
                        "short_text" => $text
                    ],
                    "to" => [
                        [
                            "msisdn" => $phone
                        ]
                    ],
                ]
            ],
            "options" => [
                "class" => 1,
                "from" => [
                    "sms_address" => $naming,
                ],
            ]
        ];
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

        if (curl_error($ch)) {
            trigger_error('Curl Error:' . curl_error($ch));
        }

        curl_close($ch);
        return $response;
    }

    public function sendMessage(array $messageFields)
    {
        $client = new MtsCommunicatorAPI();
        $result = new SendMessage();
        $id = $client->sendSms(
            $messageFields['MESSAGE_FROM'],
            $messageFields['MESSAGE_BODY'],
            $messageFields['MESSAGE_TO'],
        );

        if ($id != "0") {
            $status = $client->getSmsInfo([$id]);
            $answer = $status['events_info'][0]['events_info'][0]['status'];
            if ($answer === 200 || $answer === 201) {
                $result->setExternalId($id);
                $result->setAccepted();
            } else $result->addError(new Error('Произошла ошибка при отправке'));
        } else $result->addErrors(new Error('Произошла ошибка при отправке'));

        return $result;
    }

    private function getSmsInfo($ids)
    {
        $respStr = $this->curlRequest("messages/info", json_encode(["int_ids" => $ids]), [], "POST");
        $resp = json_decode($respStr, true);
        return $resp;
    }

    public function getShortName()
    {
        return 'mts.ru';
    }

    public function getId()
    {
        return 'mts_communicator_api';
    }

    public function getName()
    {
        return 'SMS-центр МТС API';
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
        $login = (string)$fields['account_user'];
        $password = (string)$fields['account_password'];

        $this->setOption('account_user', $login);
        $this->setOption('account_password', $password);

        $this->login = $login;
        $this->password = $password;
        return true;
    }

    public function setByDefault()
    {
        Option::set('main', 'sms_default_service', 'mts_communicator_api');
        Option::set('main', 'sms_default_sender', self::MTS_NAME);
        return true;
    }

    public function getOwnerInfo()
    {
        // TODO: Implement getOwnerInfo() method.
    }

    public function getExternalManageUrl()
    {
        // TODO: Implement getExternalManageUrl() method.
    }

    public function getMessageStatus(array $messageFields)
    {
        // TODO: Implement getMessageStatus() method.
    }
}
