<?php

use Bitrix\Main\Application;
use Bitrix\Crm\Integrity\DuplicateCommunicationCriterion;

class KtAgreement
{
    /**
     * Метод делает проверку дополнительных полей договора
     *
     * @param $arFields
     */
    static function checkProjectCodeField(&$arFields)
    {
        $dealList = [];
        $request = Application::getInstance()->getContext()->getRequest();
        $serverUrl = ($request->isHttps() ? "https" : "http") . "://" . $request->getHttpHost();
        $arrProperty = [];
        $propertyRes = CIBlockElement::GetProperty(
            $arFields['IBLOCK_ID'],
            $arFields['ID'],
            "sort",
            "asc"
        );

        while ($property = $propertyRes->Fetch()) {
            //SWITCH удален и изменен на массив данных
            $arrProperty[$property['CODE']] = $property['VALUE'];
        }

        //HARD_WORK вынесен в отдельный метод
        $dealList = self::getDeals($dealList);
        $arrProperty['PROJECT_CODE_FOR_INVOICE'] = empty($arrProperty['PROJECT_CODE_FOR_INVOICE']) ? $arrProperty['PROJECT_CODE'] : $arrProperty['PROJECT_CODE_FOR_INVOICE'];
        $arrProperty['PROJECT_CODE_FOR_INVOICE'] = strlen($arrProperty['PROJECT_CODE_FOR_INVOICE']) > 6 ? substr(
            $arrProperty['PROJECT_CODE_FOR_INVOICE'],
            0,
            6
        ) : $arrProperty['PROJECT_CODE_FOR_INVOICE'];
        $arrProperty['DS_NUMBER_FOR_INVOICE'] = (strlen($arrProperty['DS_NUMBER_FOR_INVOICE']) > 2) ? substr(
            $arrProperty['DS_NUMBER_FOR_INVOICE'],
            0,
            2
        ) : $arrProperty['DS_NUMBER_FOR_INVOICE'];
        $detailingUrl = $serverUrl . "/detail/?contract=" . $arFields['ID'] . "&jql=" . urlencode(
                "project = " . $arrProperty['PROJECT_CODE']
            );

        CIBlockElement::SetPropertyValuesEx($arFields['ID'], $arFields['IBLOCK_ID'], [
            'PROJECT_CODE_FOR_INVOICE' => $arrProperty['PROJECT_CODE_FOR_INVOICE'],
            'DS_NUMBER_FOR_INVOICE'    => $arrProperty['DS_NUMBER_FOR_INVOICE'],
            AGREEMENT_DETAIL_LINK      => "<a href='$detailingUrl' target='_blank'>Ссылка</a>",
            AGREEMENT_DEALS_FIELD_CODE => empty($dealList) ? null : $dealList,
        ]);
    }


    /**
     * Получение строки с номером договора или допсоглашения для документов
     *
     * @param array $contract
     * @param string $currency
     * @return string
     */
    public static function getAgreementNumString($contract = [], $currency = '')
    {
        //HARD_WORK удалена $agreementString ибо она не решает никакой задачи
        if (empty($contract)) {
            return '';
        }
        $isEnRequisite = in_array($currency, ['USD', 'EUR']);
        $months = ($isEnRequisite) ? self::$enMonths : self::$months;
        $time = strtotime($contract["PROPERTY_AGREEMENT_DATE_VALUE"]);
        $agreementDate = $isEnRequisite
            ? $months[intval(date('m', $time))] . ' ' . date('d', $time) . ', ' . date('Y', $time)
            : date('d.m.Y', $time);

        // Удален else
        if (!empty($contract["PROPERTY_PARENT_PROJECT_VALUE"])) {
            //удален else
            return $isEnRequisite ?
                " to the contract #{$contract['NAME']} dated {$contract['PROPERTY_AGREEMENT_DATE_VALUE']}" :
                " договору №{$contract['NAME']} от {$contract['PROPERTY_AGREEMENT_DATE_VALUE']}";
        }

        $parentAgreement = \CIBlockElement::GetList([],
            ['ID' => $contract["PROPERTY_PARENT_PROJECT_VALUE"]],
            false,
            false,
            [
                "ID",
                "NAME",
                "PROPERTY_CONTACT",
                "PROPERTY_AGREEMENT_DATE",
            ])->Fetch();

        if (empty($parentAgreement)) {
            return '';
        }


        $time = strtotime($parentAgreement['PROPERTY_AGREEMENT_DATE_VALUE']);
        $parentAgreementDate = $isEnRequisite
            ? $months[intval(date('m', $time))] . ' ' . date('d', $time) . ', ' . date('Y', $time)
            : date('d.m.Y', $time);

        return $isEnRequisite ?
            "the additional agreement #{$contract['NAME']} dated {$agreementDate}" .
            "to the contract #{$parentAgreement['NAME']} dated {$parentAgreementDate}" :
            "доп. соглашению №{$contract['NAME']} от {$agreementDate}"
            . " к договору №{$parentAgreement['NAME']} от {$parentAgreementDate}";
    }

    /**
     * Проверка на существование сделки
     *
     * @param array $dealIds
     * @return array|null
     */
    public static function updateDealsProperty($dealIds = [])
    {
        \CModule::IncludeModule('crm');

        //HARD_WORK изменен с типа null на []
        $checkedDeals = [];
        if (empty($dealIds)) {
            return $checkedDeals;
        }

        $dealsRes = \CCrmDeal::GetList([], ['ID' => $dealIds]);
        while ($deal = $dealsRes->Fetch()) {
            //HARD_WORK удален if, т.к в запросе выбираются корректные данные, попадающие под этот запрос
            $checkedDeals[] = $deal['ID'];
        }
        return $checkedDeals;
    }


}