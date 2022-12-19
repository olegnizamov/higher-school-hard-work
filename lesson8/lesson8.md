### Истинное наследование

### Отчет

- Материал изучен
- Язык программирования PHP:

В целом это можно реализовать программно или начиная с PHP 5.4 (актуальная версия 8.0-8.1) появился механизм, который
позволяет реализовать данный подход. Это механизм trait. (https://www.php.net/manual/ru/language.oop5.traits.php)

Рассмотрим trait. Есть класс мебель, от которой наследуем класс Стол, Диван.

```php

abstract class Furniture {
    protected $width;
    protected $height;
    protected $length;
    public function getDimension() {
        return [$this->width, $this->height, $this->length];
    }
 
    // requirement 01
    public function getVolume() {
        return $this->width * $this->height * $this->length;
    }
 
    // requirement 02
    protected $color;
    protected $material;
    public function getColor() {
        return $this->color;
    }
    public function getMaterial() {
        return $this->material;
    }
}
 
class Table extends Furniture {
    protected $square;
    public function getSquare() {
        return $this->square;
    }
    protected $maxSquare;
    public function getMaxSquare() {
        return $this->maxSquare;
    }
}
 

class Couch extends Furniture {
    protected $maxSquare;
    public function getMaxSquare() {
        return $this->maxSquare;
    }
}

```


Для удаления дублирующего кода мы можем использовать trait

```php
trait MaxSquare {
    protected $maxSquare;
    public function getMaxSquare() {
        return $this->maxSquare;
    }
}

```

```php
class Table extends Furniture {
    use MaxSquare;
    protected $square;
    public function getSquare() {
        return $this->square;
    }
}
class Couch extends Furniture {
    use MaxSquare;
}
```

В плане работы trait - они подключаются аналогично include в php (во время выполнения кода).

Если мы откопали артефакт более ранней версии, то в целом такое можно через магию php реализовать.

```php
lass Mixin {
    private $mixed = array();
 
    public function __get($name){       
        foreach($this->mixed as $object){
            if(property_exists($object,$name))
                return $object->$name;
        }
 
        throw new Exception("Property $name is not defined.");
    }
 
    public function __set($name,$value){
        foreach($this->mixed as $object){
            if(property_exists($object,$name))
                return $object->$name=$value;
        }   
 
        throw new Exception("Property $name is not defined.");
    }
 
    public function __isset($name){
        foreach($this->mixed as $object){
            if(property_exists($object,$name) && isset($this->$name))
                return true;
        }
 
        return false;
    }
 
    public function __unset($name){
        foreach($this->mixed as $object){
            if(property_exists($object,$name))
                $object->$name = null;
        }
    }
 
    public function __call($name,$parameters){
        foreach($this->mixed as $object){
            if(method_exists($object,$name))
                return call_user_func_array(array($object,$name),$parameters);
        }
 
        throw new Exception("Method $name is not defined.");
    }
 
    public function mix($name, $class){
        return $this->mixed[$name]=new $class();
    }   
}

class A extends Mixin {
 
}
 
class B {
    public $foo = "barn";
 
    function test(){
        echo "Success!\n";
    }
}
 
$a = new A();
$a->mix('b', 'B');
$a->test();
echo $a->foo;

```
однако есть стокое ощущение, что это будет работать медленнее и за это побьют по рукам)
