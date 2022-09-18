<?php

namespace Kt\Tests;

use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Currency\Integration\IblockMoneyProperty;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;
use CCrmActivityPriority;
use CCrmActivityType;
use CCrmCompany;
use CCrmContact;
use CCrmDeal;
use CCrmLead;
use CCrmOwnerType;
use CIBlockElement;
use CTaskItem;
use CTimeZone;
use CUser;
use Exception;
use Faker\Factory;
use Faker\Generator;
use Faker\Provider\en_US\Company;
use Kt\Crm\Company\CompanyTable;
use Kt\Crm\Contact\Contact;
use Kt\Crm\Lead\LeadTable;
use Kt\Iblock\Lists\Agreements\ElementAgreementsTable;
use Kt\Socialnetwork\WorkgroupSubjectTable;
use Kt\Socialnetwork\WorkgroupTable;
use Kt\Sprints\SprintTable;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Класс для тестирования.
 *
 * @internal
 * @coversNothing
 */
class BitrixTestCase extends TestCase
{
    /**
     * @var Generator
     */
    protected static $faker;

    /**
     * @var bool
     */
    protected $backupGlobals = false;

    /**
     * этот метод phpUnit вызывает после исполнения текущего теста.
     *
     * {@inheritdoc}
     */
    public function tearDown(): void
    {
        // без этого вызова Mockery не будет работать
        Mockery::close();
    }

    /**
     * Получить Faker для создания тестовых данных.
     */
    public static function getFaker(): Generator
    {
        if (!self::$faker) {
            self::$faker = Factory::create();
        }

        return self::$faker;
    }

    /**
     * Метод получения еmail. Заплатка.
     */
    public static function getEmail(): string
    {
        $arEmail = explode('@', self::getFaker()->safeEmail);
        $arEmail[0] = substr($arEmail[0], 0, 10);
        $arEmail[0] = trim($arEmail[0], '.');
        $arEmail[1] = substr($arEmail[1], 0, 15);

        return implode('@', $arEmail);
    }

    /**
     * Метод получения email большего заданной длины Contact::MAX_EMAIL_LENGTH. Заплатка.
     */
    public static function getLongEmail(): string
    {
        $email = '';
        while (strlen($email) < Contact::MAX_EMAIL_LENGTH) {
            $email = str_replace(' ', '', self::getFaker()->text(Contact::MAX_EMAIL_LENGTH)) .
                self::getEmail();
        }

        return $email;
    }

    /**
     * Метод получения уникального названия Проекта, которого нет в системе.
     */
    public static function getUniqueProjectName(): string
    {
        $projectName = self::getFaker()->unique()->text(25);

        while ($workgroupObj = WorkgroupTable::query()
            ->where('NAME', $projectName)
            ->fetchObject()) {
            $projectName = self::getFaker()->unique()->text(25);
        }

        return $projectName;
    }

    /**
     * Создать группу соцсети.
     *
     * @param array $fields Поля для создания группы
     *
     * @throws ArgumentException
     * @throws LoaderException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function createWorkgroup(array $fields = []): int
    {
        Loader::includeModule('socialnetwork');
        $faker = Factory::create();

        return \CSocNetGroup::Add(array_merge([
            'SITE_ID' => SITE_ID,
            'NAME' => $faker->unique()->text(10),
            'DESCRIPTION' => $faker->text,
            'VISIBLE' => 'Y',
            'DATE_CREATE' => new DateTime(),
            'DATE_UPDATE' => new DateTime(),
            'DATE_ACTIVITY' => new DateTime(),
            'OPENED' => 'Y',
            'SUBJECT_ID' => WorkgroupSubjectTable::getWorkgroupSubject()->requireId(),
            'KEYWORDS' => '',
            'SPAM_PERMS' => SONET_ROLES_USER,
            'INITIATE_PERMS' => SONET_ROLES_USER,
            'PROJECT' => 'N',
            'UF_PROJECT_NAME' => 'RANDOM_TEST_PROJECT',
            'OWNER_ID' => 1,
        ], $fields));
    }

    /**
     * Создать спринт
     *
     * @param array $fields Поля для создания спринта
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws LoaderException|SystemException
     */
    public static function createSprint(array $fields = []): int
    {
        Loader::includeModule('kt.sprints');

        $faker = Factory::create();

        $element = new CIBlockElement();

        return $element->add(array_replace_recursive([
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => SprintTable::getEntity()->getIblock()->getId(),
            'NAME' => $faker->unique()->text(10),
            'PROPERTY_VALUES' => [
                'PROJECT_ID' => WorkgroupTable::query()->setCacheTtl(3600)->fetchObject()->requireId(),
                'DATE_START' => ['VALUE' => Date::createFromPhp((new \DateTime()))->format('d.m.Y')],
                'DATE_END' => ['VALUE' => Date::createFromPhp((new \DateTime())->modify('+1 week'))
                    ->format('d.m.Y'), ],
                'RESOURCES' => $faker->randomNumber(),
            ],
        ], $fields));
    }

    /**
     * Создать задачу.
     *
     * @param array $fields          Поля задачи
     * @param int   $executiveUserId Пользователь, от которого создавать задачу
     * @param array $params          Дополнительные параметры
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws \CTaskAssertException
     * @throws \TasksException
     */
    public static function createTask(array $fields = [], $executiveUserId = 1, $params = []): CTaskItem
    {
        $faker = Factory::create();
        $fields = array_merge(
            [
                'TITLE' => $faker->realText(10),
                'DESCRIPTION' => $faker->text,
                'CREATED_BY' => 1,
                'RESPONSIBLE_ID' => 1,
                'GROUP_ID' => WorkgroupTable::query()->setCacheTtl(3600)->fetchObject()->requireId(),
            ],
            $fields
        );

        return CTaskItem::add($fields, $executiveUserId, $params);
    }

    /**
     * Создать компанию.
     *
     * @param array $fields          Поля компании
     * @param int   $executiveUserId Пользователь от которого создавать компанию
     * @param array $params          Дополнительные параметры
     *
     * @throws LoaderException
     *
     * @return bool|int
     */
    public static function createCompany(array $fields = [], $executiveUserId = 1, $params = []): int
    {
        Loader::includeModule('crm');

        $faker = self::getFaker();
        $faker->addProvider(new Company($faker));

        $fields = array_merge(
            [
                'TITLE' => $faker->company(),
                'OPENED' => 'Y',
                'COMPANY_TYPE' => 'CUSTOMER',
                'ASSIGNED_BY_ID' => $executiveUserId,
                'FM' => [
                    'PHONE' => [
                        'n0' => [
                            'VALUE_TYPE' => 'WORK',
                            'VALUE' => $faker->phoneNumber,
                        ],
                    ],
                    'EMAIL' => [
                        'n0' => [
                            'VALUE_TYPE' => 'WORK',
                            'VALUE' => self::getEmail(),
                        ],
                    ],
                ],
                CompanyTable::UF_SERVICE_TYPE => $faker->randomNumber(1),
            ],
            $fields
        );

        $company = new CCrmCompany(false);
        $result = $company->Add($fields, true, $params);

        // Если возникла ошибка, выкенем исключение
        if (!$result) {
            throw new Exception($company->LAST_ERROR . 'с параметрами' . print_r($fields, true));
        }

        return $result;
    }

    /**
     * Создать сделку.
     *
     * @param array $fields          Поля сделки
     * @param int   $executiveUserId Пользователь от которого создавать сделку
     * @param array $params          Дополнительные параметры
     *
     * @throws LoaderException
     *
     * @return bool|int
     */
    public static function createDeal(array $fields = [], int $executiveUserId = 1, $params = []): int
    {
        Loader::includeModule('crm');

        $faker = Factory::create();

        $fields = array_merge(
            [
                'TITLE' => 'Сделка ' . $faker->text(20),
                'STAGE_ID' => DEAL_STAGE_NEW,
                'SOURCE_ID' => 'SELF',
                'CURRENCY_ID' => 'RUB',
                'ASSIGNED_BY_ID' => $executiveUserId,
            ],
            $fields
        );

        $deal = new CCrmDeal();
        $result = $deal->Add($fields, true, $params);

        // Если возникла ошибка, выкенем исключение
        if (!$result) {
            throw new Exception($deal->LAST_ERROR . 'с параметрами' . print_r($fields, true));
        }

        return $result;
    }

    /**
     * Создать договор
     *
     * @param array $fields          Поля договора
     * @param int   $executiveUserId Пользователь от которого договор
     *
     * @throws LoaderException
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function createAgreement(array $fields = [], int $executiveUserId = 1): int
    {
        Loader::includeModule('iblock');

        $faker = Factory::create();

        $fields = array_replace_recursive(
            [
                'IBLOCK_ID' => ElementAgreementsTable::getEntity()->getIblock()->getId(),
                'AUTHOR' => $executiveUserId,
                'IBLOCK_SECTION_ID' => false,
                'NAME' => $faker->text(20),
                'PROPERTY_VALUES' => [
                    'ACTIVE' => 'Y',
                    'OVERALL_SUMM' => 0 . IblockMoneyProperty::SEPARATOR . \CCrmCurrency::GetDefaultCurrencyID(),
                    'OVERALL_SUMM_PAID' => 0 . IblockMoneyProperty::SEPARATOR . \CCrmCurrency::GetDefaultCurrencyID(),
                ],
            ],
            $fields
        );

        $agreement = new CIBlockElement();
        $result = $agreement->Add($fields);

        // Если возникла ошибка, выкенем исключение
        if (!$result) {
            throw new Exception($agreement->LAST_ERROR . 'с параметрами' . print_r($fields, true));
        }

        return $result;
    }

    /**
     * Метод создания контакта.
     *
     * @param array $arFields      Поля контакта
     * @param bool  $bUpdateSearch Флаг обновления поиска
     * @param array $options       Дополнительные параметры
     *
     * @throws LoaderException
     */
    public static function createContact(array $arFields = [], bool $bUpdateSearch = true, array $options = []): int
    {
        Loader::includeModule('crm');

        $faker = self::getFaker();

        $arFields = array_merge(
            [
                'NAME' => $faker->unique()->name,
                'LAST_NAME' => $faker->unique()->lastName,
                'SECOND_NAME' => $faker->unique()->name,
                'ADDRESS' => $faker->address,
                'FM' => [
                    'PHONE' => [
                        'n0' => ['VALUE' => $faker->unique()->phoneNumber, 'VALUE_TYPE' => 'WORK'],
                    ],
                    'EMAIL' => [
                        'n0' => ['VALUE' => self::getEmail(), 'VALUE_TYPE' => 'WORK'],
                    ],
                ],
                'HAS_PHONE' => 'Y',
                'HAS_EMAIL' => 'Y',
                'HAS_IMOL' => 'N',
            ],
            $arFields
        );

        $crmContactObject = new CCrmContact();
        $result = $crmContactObject->Add($arFields, $bUpdateSearch, $options);

        // Если возникла ошибка, выкенем исключение
        if (!$result) {
            throw new Exception(
                $crmContactObject->LAST_ERROR . 'с параметрами' . print_r($arFields, true)
            );
        }

        return $result;
    }

    /**
     * Метод создания активити.
     *
     * @param array $arFields   Поля активити
     * @param bool  $checkPerms Проверка на доступ
     * @param bool  $regEvent   Проверка на события
     * @param array $options    Дополнительные параметры
     */
    public static function createActivity(
        array $arFields = [],
        bool $checkPerms = true,
        bool $regEvent = true,
        array $options = []
    ): int {
        $faker = self::getFaker();

        $now = ConvertTimeStamp(time() + CTimeZone::GetOffset(), 'FULL', 's1');
        $arFields = array_merge(
            [
                'SUBJECT' => $faker->word,
                'DESCRIPTION' => $faker->text,
                'TYPE_ID' => CCrmActivityType::Email,
                'PROVIDER_ID' => 'CRM_EMAIL',
                'PROVIDER_TYPE_ID' => 'EMAIL',
                'RESPONSIBLE_ID' => 1,
                'COMPLETED' => 'N',
                'AUTHOR_ID' => 1,
                'START_TIME' => $now,
                'END_TIME' => $now,
                'OWNER_TYPE_ID' => CCrmOwnerType::Contact,
                'PRIORITY' => CCrmActivityPriority::Medium,
                'BINDINGS' => [],
                'ENTITY_ID' => CCrmOwnerType::Lead,
                'SETTINGS' => [
                    'EMAIL_META' => [
                        'from' => '<' . self::getEmail() . '>',
                        'replyTo' => '<' . self::getEmail() . '>',
                        'to' => '<' . self::getEmail() . '>',
                        '__email' => '<' . self::getEmail() . '>',
                    ],
                ],
            ],
            $arFields
        );

        return CCrmActivity::Add($arFields, $checkPerms, $regEvent, $options);
    }

    /**
     * Метод создания активити Timeline.
     *
     * @param array $arFields Поля активити Timeline
     *
     * @throws Exception
     */
    public static function createTimelineActivity(array $arFields = []): int
    {
        $arFields = array_merge(
            [
                'TYPE_ID' => TimelineType::ACTIVITY,
                'CREATED' => new DateTime(),
                'AUTHOR_ID' => 1,
                'ASSOCIATED_ENTITY_TYPE_ID' => CCrmOwnerType::Activity,
            ],
            $arFields
        );

        $result = TimelineTable::add($arFields);

        return $result->getId();
    }

    /**
     * Создания записи в таблице b_crm_timeline_bind.
     *
     * @param array $data Массив столбцов таблицы
     *
     * @throws ArgumentException
     */
    public static function createTimeLineBinding(array $data): void
    {
        TimelineBindingTable::upsert($data);
    }

    /**
     * Создания лида.
     *
     * @param array $fields Поля Лида
     */
    public static function createLead(array $fields = []): int
    {
        $faker = self::getFaker();

        $lead = new CCrmLead(false);
        $fields = array_merge(
            [
                'TITLE' => $faker->word,
                LeadTable::UF_SERVICE_TYPE => $faker->randomNumber(1),
            ],
            $fields
        );

        return $lead->Add($fields, false, ['CURRENT_USER' => 1]);
    }

    /**
     * Создания пользователя.
     *
     * @param array $fields Поля пользователя
     */
    public static function createUser(array $fields): int
    {
        $faker = self::getFaker();
        $user = new CUser();
        $pass = md5(time());
        $fields = array_merge(
            [
                'LOGIN' => md5((string) microtime()),
                'EMAIL' => self::getEmail(),
                'PASSWORD' => $pass,
                'NAME' => $faker->word,
                'LAST_NAME' => $faker->word,
                'CONFIRM_PASSWORD' => $pass,
            ],
            $fields
        );

        $userId = $user->Add($fields);

        if (!$userId) {
            throw new Exception($user->LAST_ERROR . 'с параметрами' . print_r($fields, true));
        }

        return $userId;
    }
}
