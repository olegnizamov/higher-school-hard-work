<?php

/**
 *
 * Класс бронирования рабочих мест
 * По умолчанию для сотрудника открывается «его» рабочий кабинет (поле в карточке пользователя, заполняется через импорт
 * файла формата .CSV). Сотрудник из списка кабинетов может выбрать только разрешенные ему для бронирования кабинеты
 * (поле в карточке пользователя, заполняется через импорт файла формата .CSV).
 * График занятости рабочего места – редактируемое поле в виде таймлайна занятого времени,
 * по умолчанию отображается выбранные дата и время (значения полей «дата начала брони» и «дата окончания брони»).
 * Если на выбранный интервал времени место занято, то оно отображается в виде красного прямоугольника и недоступно для
 * бронирования (кнопка «Забронировать» будет некликабельна). При наведении на красную область должно выходить информационное
 * окно с указанием о сотруднике, забронировавшем место.
 */


namespace Korus\Workplaces;

use \Bitrix\Iblock\Model\Section;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\FileTable;
use Korus\Workplaces\Entity\PlaceItem;
use Korus\Workplaces\Helpers\Iblock;
use Korus\Workplaces\Helpers\Install\PlaceIblock;
use Korus\Workplaces\Util\SingletonTrait;

class PlaceManager
{
    use SingletonTrait;

    const IBLOCK_ID_OPTION_NAME = PlaceIblock::IBLOCK_ID_OPTION_NAME;
    private $sectionEntity;

    public static function getIblock()
    {
        static $iblock;
        if (!$iblock) {
            $iblock = Iblock::getIblockByCode(KORUS_WORKPLACES_PLACES_IBLOCK_CODE, KORUS_WORKPLACES_IBLOCK_TYPE_ID);
        }

        return $iblock;
    }

    public static function getIblockId()
    {
        $id = Option::get('korus.workplaces', static::IBLOCK_ID_OPTION_NAME);
        if ($id) {
            return $id;
        }

        return static::getIblock()['ID'];
    }

    public function getPlace($id)
    {
        $res = \CIBlockElement::GetList(
            [],
            [
                'ACTIVE' => 'Y',
                'ID' => $id,
                'IBLOCK_ID' => static::getIblock()
            ],
            false,
            false,
            [
                'ID',
                'CODE',
                'IBLOCK_SECTION_ID',
                'NAME',
                'PROPERTY_WORKPLACE_TYPE'
            ]
        );

        $place = $res->Fetch();
        if (!$place) {
            throw new \Exception('Место не найдено');
        }

        return new PlaceItem(
            $place['ID'],
            $place['NAME'],
            PlaceTypeManager::getInstance()->getTypeById($place['PROPERTY_WORKPLACE_TYPE_VALUE']),
            $place['CODE']
        );
    }

    public function getPlaces($cabinetId)
    {
        $places = [];

        $currentUser = UserManager::getInstance()->getCurrent();

        if (!in_array($cabinetId, $currentUser->getAvailableCabinets())) {
            return [];
        }

        $res = \CIBlockElement::GetList(
            [],
            [
                'ACTIVE' => 'Y',
                'IBLOCK_SECTION_ID' => $cabinetId ?? $currentUser->getAvailableCabinets(),
                'IBLOCK_ID' => static::getIblockId()
            ],
            false,
            false,
            [
                'ID',
                'CODE',
                'IBLOCK_SECTION_ID',
                'NAME',
                'PROPERTY_WORKPLACE_TYPE'
            ]
        );

        while ($place = $res->Fetch()) {
            $places[$place['CODE']] = new PlaceItem(
                $place['ID'],
                $place['NAME'],
                PlaceTypeManager::getInstance()->getTypeById($place['PROPERTY_WORKPLACE_TYPE_VALUE']),
                $place['CODE']
            );
        }

        return $places;
    }

    public function getCabinets($floorId)
    {
        $res = [];
        $map = [];

        $cabinets = $this->getList(null, 3, $floorId);

        foreach ($cabinets as $cabinet) {
            $res[$cabinet['ENTITY_IBLOCK_SECTION_ID']][] = [
                'name' => $cabinet['ENTITY_NAME'],
                'value' => $cabinet['ENTITY_ID'],
            ];
            $map[$cabinet['ENTITY_ID']] = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/' . $cabinet['mapUrl']);
        }

        return [$res, $map];
    }

    public function getFloors($officeId)
    {
        $res = [];

        $floors = $this->getList('FLOOR_REF.', 2, $officeId);

        foreach ($floors as $floor) {
            $res[$floor['ENTITY_IBLOCK_SECTION_ID']][] = [
                'name' => $floor['ENTITY_NAME'],
                'value' => $floor['ENTITY_ID'],
            ];
        }

        return $res;
    }

    public function getOffices()
    {
        $res = [];

        $offices = $this->getList('OFFICE_REF.', 1, null);

        foreach ($offices as $office) {
            $res[] = [
                'name' => $office['ENTITY_NAME'],
                'value' => $office['ENTITY_ID']
            ];
        }

        return $res;
    }

    private function getList($select, int $depth, ?int $parentId)
    {
        $currentUser = UserManager::getInstance()->getCurrent();

        if (count($currentUser->getAvailableCabinets()) == 0) {
            return [];
        }

        $entity = $this->getEntity();

        $arSelect = [
            'ENTITY_' => $select ? $select . '*' : '*',
        ];

        if (!$select) {
            $arSelect[] = new ExpressionField(
                'mapUrl',
                'concat_ws("/", %s, %s)',
                [
                    'MAP.SUBDIR',
                    'MAP.FILE_NAME'
                ]
            );
        }

        $res = $entity::getList([
            'select' => $arSelect,
            'filter' => [
                '@ID' => $currentUser->getAvailableCabinets(),
                '=IBLOCK_ID' => static::getIblockId(),
                $select . 'DEPTH_LEVEL' => $depth,
                $parentId ? [$select . 'IBLOCK_SECTION_ID' => $parentId] : null
            ],
            'runtime' => $this->getReferences(),
            'group' => 'ENTITY_ID',
            'order' => $select . 'SORT'
        ]);

        return $res;
    }

    private function getReferences()
    {
        $entity = $this->getEntity();

        $references = [
            new ReferenceField(
                'FLOOR_REF',
                $entity,
                [
                    'this.IBLOCK_SECTION_ID' => 'ref.ID',
                ],
                [
                    'join_type' => 'inner'
                ]
            ),
            new ReferenceField(
                'OFFICE_REF',
                $entity,
                [
                    'this.FLOOR_REF.IBLOCK_SECTION_ID' => 'ref.ID',
                ],
                [
                    'join_type' => 'inner'
                ]
            ),
            new ReferenceField(
                'MAP',
                FileTable::class,
                [
                    'this.UF_SVG_MAP' => 'ref.ID',
                ],
                [
                    'join_type' => 'left'
                ]
            )
        ];

        return $references;
    }

    private function getEntity()
    {
        if (!$this->sectionEntity) {
            $this->sectionEntity = Section::compileEntityByIblock(static::getIblockId());
        }

        return $this->sectionEntity;
    }
}
