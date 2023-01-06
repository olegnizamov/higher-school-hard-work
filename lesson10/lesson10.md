### Божественная линия кода :)

Новогодние праздники - извиняюсь, что припоздал с выполнением задания.

### case 1

было:

```php
Banners::showUser(
    $this->getTypeBanner(),
    $this->viewedBannersToUser($USER->GetID()),
    Application::getInstance());
```

стало:

```php
$bannerTypes = $this->getTypeBanner();
$viewedBanners = $this->viewedBannersToUser($USER->GetID());
$requestUri = Application::getInstance()->getContext()->getServer()->getRequestUri();

 if (
            isset($viewedBanners[$banner->getData()['ID']])
            && $banner->getData()['BANNER_TYPE'] == $bannerTypes['banner']
            && Timezone::getUserTimezone()
            && (new \DateTime("@".$viewedBanners[$banner->getData()['ID']], Timezone::getUserTimezone())) > (Timezone::getUserCurrentTime())->setTime(0, 0, 0)
        ) {
            return false;
        }
```

### case 2

было:

```php
if(!empty(userCollection.getGroups())&&function($userID){return $digit % 2 == 0;}){}
```

стало:

```php
    $usersGroups=userCollection.getGroups();
    $evenUserID = function($userID) {
        return $userID % 2 == 0;
    };
    if(!empty($usersGroups)&&$evenUserID){}
```

### case 3

было:

```php
return is_null($this->siteid)
    ?Application::getInstance()->getContext()->getServer()->get("site_id")()
    :Application::getInstance()->getContext()->getServer()->getRequestUri();
```

стало:

```php

$siteId = $this->siteid;
$initSiteId = Application::getInstance()->getContext()->getServer()->get("site_id");
$urlRequest = Application::getInstance()->getContext()->getServer()->getRequestUri();


return is_null($siteId)?$initSiteId:$urlRequest;
```

### case 4

было:

```php
AddMessage2Log((strpos($text, '<?php')) ? 'Ошибка' : '', "my_module_id");
```

стало:

```php
$pos=strpos($text, '<?php');
if ($pos === false) {
   AddMessage2Log( 'Ошибка', "my_module_id");
}
```

### case 5

было:

```php

        if (!empty(Option::get('main', 'task_stop_words_in_title')) && strrpos(trim(str_replace(['Re:', 'Fwd:'], '', $fields['SUBJECT'])), $taskStopWord)) {
            $fields['ACTIVITY_ERROR'] = ActivityEventsError::HAS_TITLE_STOP_WORDS;
            return true;
        }

```

стало:

```php

$taskStopWord = Option::get('kt.main', 'task_stop_words_in_title');
        $taskName = trim(str_replace(['Re:', 'Fwd:'], '', $fields['SUBJECT']));
        if (!empty($taskStopWord) && strrpos($taskName, $taskStopWord)) {
            $fields['ACTIVITY_ERROR'] = ActivityEventsError::HAS_TITLE_STOP_WORDS;

            return true;
        }
```