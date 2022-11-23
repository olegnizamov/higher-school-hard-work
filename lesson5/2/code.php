<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

/**
 *
 *
 * Класс, используемый для сохранения кодовой фразы.
 * Это альтернатива номеру мобильного телефона, если по каким-то непреодолимым причинам пользователь не можете его указать,
 * но хочет входить на портал не из корпоративной сети.
 * Задать кодовую фразу можно в личном профиле, в верхнем правом углу по кнопке МОБИЛЬНЫЙ ДОСТУП.
 * Придумать кодовую фразу: несколько слов на латинице или кириллице в сочетании со специальными символами (пробел, подчёркивание, разделители).
 * Фразу важно запомнить. Она не хранится в исходном коде и сразу шифруется. Поэтому восстановить ее не получитсся.
 * Любой, у кого есть  кодовая фраза, сможет совершать на портале действия от пользовательского имени.
 * Менять  кодовую фразу можно любое количество раз. Верной будет та, что вы задали самой последней.
 * Если пользователь забыл кодовую фразу, то пусть идет в личный профиль из внутренней сети и задает новую, введя её в поле Новая кодовая раза.
*/


use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Korus\Authorization\Entity\KeywordTable;

Loc::loadMessages(__FILE__);

class CIntranetUserProfilePasswordComponent extends \CBitrixComponent implements \Bitrix\Main\Engine\Contract\Controllerable, \Bitrix\Main\Errorable
{
    protected $errorCollection;

    public function onPrepareComponentParams($params)
    {
        $this->errorCollection = new ErrorCollection();

        return $params;
    }

    protected function listKeysSignedParameters()
    {
        return array(
            'USER_ID'
        );
    }

    public function getErrorByCode($code)
    {
        return $this->errorCollection->getErrorByCode($code);
    }

    public function configureActions()
    {
        return array();
    }

    public function getErrors()
    {
        return $this->errorCollection->toArray();
    }

    protected function getPermissions()
    {
        static $cache = false;

        if (
            $cache === false
            && Loader::includeModule('socialnetwork')
        ) {
            global $USER;

            $currentUserPerms = \CSocNetUserPerms::initUserPerms(
                $USER->getId(),
                $this->arParams["USER_ID"],
                \CSocNetUser::isCurrentUserModuleAdmin(SITE_ID, false)
            );

            $result = [
                'edit' => (
                    $currentUserPerms["IsCurrentUser"]
                    || (
                        $currentUserPerms["Operations"]["modifyuser"]
                        && $currentUserPerms["Operations"]["modifyuser_main"]
                    )
                )
            ];

            if (
                !ModuleManager::isModuleInstalled("bitrix24")
                && $USER->isAdmin()
                && !$currentUserPerms["IsCurrentUser"]
            ) {
                $result['edit'] = (
                    $result['edit']
                    && \CSocNetUser::isCurrentUserModuleAdmin(SITE_ID, false)
                );
            }

            $cache = $result;
        } else {
            $result = $cache;
        }

        return $result;
    }

    public function saveAction(array $data)
    {
        global $USER;

        if (empty($data) || ($data['PASSWORD'] == '' && $data['KEYWORD'] == '')) {
            $this->errorCollection[] = new Error(Loc::getMessage('INTRANET_USER_PROFILE_NOTHING_TO_SAVE'));
            return null;
        }

        $this->arResult['Permissions'] = $this->getPermissions();
        if (!$this->arResult['Permissions']['edit']) {
            $this->errorCollection[] = new Error(Loc::getMessage('INTRANET_USER_PROFILE_ACCESS_DENIED'));
            return null;
        }

        if (!empty($data['PASSWORD'])) {
            $fields = array(
                'PASSWORD' => $data["PASSWORD"],
                'CONFIRM_PASSWORD' => $data["CONFIRM_PASSWORD"]
            );

            if (!$USER->Update($this->arParams['USER_ID'], $fields)) {
                $this->errorCollection[] = new Error($USER->LAST_ERROR);
            }
        }

        if (!empty($data['KEYWORD'])) {
            try {
                $this->saveKeyword($data['KEYWORD']);
            } catch (\Exception $e) {
                $this->errorCollection[] = new Error($e->getMessage());
            }
        }

        if (!$this->errorCollection->isEmpty()) {
            return null;
        }

        return true;

    }

    private function saveKeyword(string $keyword)
    {
        Loader::includeModule('korus.authorization');

        $userId = $this->arParams['USER_ID'];
        $exists = KeywordTable::getCount(['=USER_ID' => $userId]);

        if ($exists) {
            $result = KeywordTable::update($userId, ['KEYWORD' => $keyword]);
        } else {
            $result = KeywordTable::add([
                'USER_ID' => $userId,
                'KEYWORD' => $keyword
            ]);
        }

        if (!$result->isSuccess()) {
            throw new Exception(implode(PHP_EOL, $result->getErrorMessages()));
        }
    }

    public function getFieldInfo()
    {
        $passwordPolicy = CUser::GetGroupPolicy($this->arParams["USER_ID"]);
        $passDesc = is_array($passwordPolicy) && isset($passwordPolicy["PASSWORD_REQUIREMENTS"]) ? $passwordPolicy["PASSWORD_REQUIREMENTS"] : "";

        $fields = array(
            array(
                "title" => Loc::getMessage("INTRANET_USER_PROFILE_FIELD_PASSWORD"),
                "name" => "PASSWORD",
                "type" => "password",
                "editable" => true,
                "data" => array(
                    "desc" => $passDesc
                )
            ),
            array(
                "title" => Loc::getMessage("INTRANET_USER_PROFILE_FIELD_CONFIRM_PASSWORD"),
                "name" => "CONFIRM_PASSWORD",
                "type" => "password",
                "editable" => true,
                "visibilityPolicy" => 'edit',
            ),
            array(
                "title" => "Новая кодовая фраза",
                "name" => "KEYWORD",
                "type" => "text",
                "editable" => true,
                "data" => array(
                    "desc" => "Внимание: никому не сообщайте кодовое слово. Оно используется для доступа к вашему аккаунту при невозможности отправить СМС с кодом авторизации.",
                )
            )
        );

        return $fields;
    }

    public function getConfig()
    {
        $formConfig = array(
            array(
                'name' => 'keyword',
                'title' => 'Смена кодовой фразы',
                'type' => 'section',
                'elements' => array(
                    array('name' => 'KEYWORD')
                ),
                'data' => array('isChangeable' => false, 'isRemovable' => false)
            )
        );

        return $formConfig;
    }

    public function executeComponent()
    {
        global $USER;

        \CJSCore::Init("loader");

        $permissions = $this->getPermissions();
        if (!$permissions['edit'] && $USER->GetID() != $this->arParams["USER_ID"]) {
            return;
        }

        $this->arResult["FormFields"] = $this->getFieldInfo();
        $this->arResult["FormConfig"] = $this->getConfig();

        $this->includeComponentTemplate();
    }
}

?>
