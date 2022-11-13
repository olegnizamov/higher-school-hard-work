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
    /**
     * @inheritDoc
     *
     * @throws InvalidOperationException
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public function getUserCredential(): string
    {
        $row = UserTable::getRow([
            'select' => ['UF_MOB_PHONE_PER'],
            'filter' => ['=ID' => $this->userID]
        ]);

        $phone = Parser::getInstance()->parse((string)$row['UF_MOB_PHONE_PER']);

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
        $senderId = Option::get("main", "sms_default_service");
        if (empty($senderId)) {
            throw new InvalidOperationException('СМС шлюз не настроен.');
        }

        $phone = $this->getUserCredential();

        Loader::includeModule('messageservice');

        $code = $this->generateSmsCode();

        try {
            $smsMessage = SmsManager::createMessage([
                'SENDER_ID' => $senderId,
                'MESSAGE_TO' => $phone,
                'MESSAGE_BODY' => sprintf('ПГК: %s - код для входа на портал', $code),
            ]);

            $result = $smsMessage->send();
            if ($result->isSuccess()) {
                $res = SmsCodeTable::add([
                    'USER_ID' => $this->userID,
                    'CODE' => $code,
                    'IP' => $_SERVER['REMOTE_ADDR']
                ]);

                if (!$res->isSuccess()) {
                    throw new InvalidOperationException('Ошибка добавления смс кода');
                }
            } else {
                throw new InvalidOperationException('Не удалось отправить СМС, либо СМС шлюз не доступен.');
            }
        } catch (\Throwable $exception) {
            throw new InvalidOperationException('Не удалось отправить СМС, либо СМС шлюз не доступен.');
        }


        return true;
    }
}
