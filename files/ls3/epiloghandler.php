<?php

namespace Kt\Crm\Handlers;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use CCrmOwnerTypeAbbr;
use CJSCore;
use CUtil;

/**
 * Класс, содержащий общие обработчики событий onEpilog.
 */
class EpilogHandler
{
    /**
     * В лидах, сделках, контактах, компаниях комментарии должны быть по умолчанию раскрыты.
     */
    public static function onEpilogActionExpandComment()
    {
        $arVariables = [];
        \CComponentEngine::parseComponentPath(
            '/',
            [
                substr(Option::get('crm', 'path_to_company_details'), 1),
                substr(Option::get('crm', 'path_to_deal_details'), 1),
                substr(Option::get('crm', 'path_to_lead_details'), 1),
                substr(Option::get('crm', 'path_to_contact_details'), 1),
            ],
            $arVariables
        );

        if (!empty($arVariables)) {
            CJSCore::RegisterExt('commentExpand', [
                'js' => '/local/js/crm/commentExpand.js',
            ]);
            CUtil::InitJSCore(['commentExpand']);
        }
    }

    /**
     * Добавление кнопки на страницу сделки.
     *
     * @throws \Bitrix\Main\SystemException
     */
    public static function onEpilogActionAddButtonForDealsPage()
    {
        \CComponentEngine::parseComponentPath(
            '/',
            [
                substr(Option::get('crm', 'path_to_deal_details'), 1),
            ],
            $arVariables
        );
        if (!empty($arVariables)) {
            \KtDeals::addButtonForDealsPage();
        }
    }

    /**
     * Добавление в исходящих письмах при ответе из сущности crm
     * в теме [D#ID_Сделки#] и изменение ответственного.
     *
     * @throws \Bitrix\Main\SystemException
     */
    public static function onEpilogEditTitleAndSender()
    {
        $arVariables = [];
        \CComponentEngine::parseComponentPath(
            '/',
            [
                substr(Option::get('crm', 'path_to_company_details'), 1),
                substr(Option::get('crm', 'path_to_deal_details'), 1),
                substr(Option::get('crm', 'path_to_lead_details'), 1),
                substr(Option::get('crm', 'path_to_contact_details'), 1),
            ],
            $arVariables
        );

        /** Подключение компонента для установки кук с email пользователя
         * и детальной страницы, на которой открыта форма.
         */
        if (!empty($arVariables)) {
            $entityType = key($arVariables);
            $entityId = reset($arVariables);

            switch (key($arVariables)) {
                case 'company_id':
                    $entityType = CCrmOwnerTypeAbbr::Company;

                    break;
                case 'deal_id':
                    $entityType = CCrmOwnerTypeAbbr::Deal;

                    break;
                case 'lead_id':
                    $entityType = CCrmOwnerTypeAbbr::Lead;

                    break;
                case 'contact_id':
                    $entityType = CCrmOwnerTypeAbbr::Contact;

                    break;
            }


            global $APPLICATION;
            $APPLICATION->IncludeComponent(
                'kt:crm.edit.email',
                '',
                [
                    'USER_EMAIL' => CurrentUser::get()->getEmail(),
                    'USER_ID' => CurrentUser::get()->getId(),
                    'ENTITY_ID' => $entityId,
                    'ENTITY_TYPE' => $entityType,
                ]
            );
        }

        /** Подключение изменения формы отправки email
         * Данные получаются из компонента через куки.
         */
        $url = Context::getCurrent()->getRequest()->getRequestUri();
        if (false !== strpos($url, '/crm.activity.planner/slider.php')) {
            CJSCore::RegisterExt('changeEmailForm', [
                'js' => '/local/js/crm/changeEmailForm.js',
            ]);
            CUtil::InitJSCore(['changeEmailForm']);
        }
    }
}
