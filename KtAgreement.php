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
        $codeForInvoice = '';
        $dsNumber = '';
        $projectCode = '';
        $dealList = [];
        $request = Application::getInstance()->getContext()->getRequest();
        $serverUrl = ($request->isHttps() ? "https" : "http") . "://" . $request->getHttpHost();
        $propertyRes = CIBlockElement::GetProperty(
            $arFields['IBLOCK_ID'],
            $arFields['ID'],
            "sort",
            "asc"
        );
        $propertyResList = [];
        while ($property = $propertyRes->Fetch()) {
            switch ($property['CODE']) {
                case  'PROJECT_CODE_FOR_INVOICE':
                    $codeForInvoice = $property['VALUE'];
                    break;
                case 'DS_NUMBER_FOR_INVOICE':
                    $dsNumber = $property['VALUE'];
                    break;
                case 'PROJECT_CODE':
                    $projectCode = $property['VALUE'];
                    break;
                case AGREEMENT_DEALS_FIELD_CODE:
                    $dealList[] = $property['VALUE'];
                    break;
                default:
                    $propertyResList["PROPERTY_{$property['CODE']}_VALUE"] = $property['VALUE'];
                    break;
            }
        }

        $checkedDeals = null;
        if (empty($dealList)) {
            return $checkedDeals;
        }
        $dealsRes = \CCrmDeal::GetList([], ['ID' => $dealList]);
        while ($deal = $dealsRes->Fetch()) {
            if (in_array($deal['ID'], $dealList)) {
                $checkedDeals[] = $deal['ID'];
            }
        }
        $dealList = $checkedDeals;

        $codeForInvoice = empty($codeForInvoice) ? $projectCode : $codeForInvoice;
        $codeForInvoice = strlen($codeForInvoice) > 6 ? substr($codeForInvoice, 0, 6) : $codeForInvoice;
        $dsNumber = (strlen($dsNumber) > 2) ? substr($dsNumber, 0, 2) : $dsNumber;
        $detailingUrl = $serverUrl . "/detail/?contract=" . $arFields['ID'] . "&jql=" . urlencode(
                "project = " . $projectCode
            );

        CIBlockElement::SetPropertyValuesEx($arFields['ID'], $arFields['IBLOCK_ID'], [
            'PROJECT_CODE_FOR_INVOICE' => $codeForInvoice,
            'DS_NUMBER_FOR_INVOICE'    => $dsNumber,
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
        $agreementString = '';
        if (empty($contract)) {
            return $agreementString;
        }
        $isEnRequisite = in_array($currency, ['USD', 'EUR']);
        $months = ($isEnRequisite) ? self::$enMonths : self::$months;
        $time = strtotime($contract["PROPERTY_AGREEMENT_DATE_VALUE"]);
        $agreementDate = $isEnRequisite
            ? $months[intval(date('m', $time))] . ' ' . date('d', $time) . ', ' . date('Y', $time)
            : date('d.m.Y', $time);
        if (!empty($contract["PROPERTY_PARENT_PROJECT_VALUE"])) {
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
            if (!empty($parentAgreement)) {
                $time = strtotime($parentAgreement['PROPERTY_AGREEMENT_DATE_VALUE']);
                $parentAgreementDate = $isEnRequisite
                    ? $months[intval(date('m', $time))] . ' ' . date('d', $time) . ', ' . date('Y', $time)
                    : date('d.m.Y', $time);
                if ($isEnRequisite) {
                    return "the additional agreement #{$contract['NAME']} dated {$agreementDate}"
                        . "  to the contract #{$parentAgreement['NAME']} dated {$parentAgreementDate}";;
                } else {
                    return "доп. соглашению №{$contract['NAME']} от {$agreementDate}"
                        . " к договору №{$parentAgreement['NAME']} от {$parentAgreementDate}";
                }
            }
        } else {
            if ($isEnRequisite) {
                return " to the contract #{$contract['NAME']} dated {$contract['PROPERTY_AGREEMENT_DATE_VALUE']}";
            } else {
                return " договору №{$contract['NAME']} от {$contract['PROPERTY_AGREEMENT_DATE_VALUE']}";
            }
        }
    }


}