<?php

namespace Korus\Authorization\Senders;


use Bitrix\Main\Config\Option;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Loader;
use Bitrix\Main\PhoneNumber\Format;
use Bitrix\Main\PhoneNumber\Parser;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\MessageService\Sender\SmsManager;
use Korus\Authorization\AuthSession;
use Korus\Authorization\Entity\SmsCodeTable;

class SmsCodeSender extends AbstractCodeSender
{
    private string $phone;
    private string $smsCode;
    private string $senderId;

    public function __construct(string $phone, string $smsCode)
    {
        $this->phone = $this->validatePhone($phone);
        $this->smsCode = $smsCode;
        $this->senderId = Option::get("main", "sms_default_service");
    }

    private function validatePhone(string $phone): string
    {
        $phone = Parser::getInstance()->parse($phone);
        if (!$phone->isValid()) {
            throw new InvalidOperationException('Номер телефона пользователя некорректный либо отсутствует.');
        }

        return $phone->format(Format::E164);
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidOperationException
     * @throws \Bitrix\Main\ArgumentTypeException
     * @throws \Bitrix\Main\LoaderException
     */
    public function send(): bool
    {
        if (empty($this->senderId)) {
            throw new InvalidOperationException('СМС шлюз не настроен.');
        }
        Loader::includeModule('messageservice');

        try {
            $smsMessage = SmsManager::createMessage([
                'SENDER_ID' => $this->senderId,
                'MESSAGE_TO' => $this->phone,
                'MESSAGE_BODY' => sprintf('ПГК: %s - код для входа на портал', $this->smsCode),
            ]);

            $result = $smsMessage->send();
            if (!$result->isSuccess()) {
                throw new InvalidOperationException('Не удалось отправить СМС, либо СМС шлюз не доступен.');
            }
            $res = SmsCodeTable::add([
                'USER_ID' => $this->phone,
                'CODE' => $this->smsCode,
                'IP' => $_SERVER['REMOTE_ADDR']
            ]);

            if (!$res->isSuccess()) {
                throw new InvalidOperationException('Ошибка добавления смс кода');
            }

        } catch (\Throwable $exception) {
            throw new InvalidOperationException('Не удалось отправить СМС, либо СМС шлюз не доступен.');
        }

        return true;
    }
}
