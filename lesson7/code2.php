<?php


/**
 * Интерфейс Компонента объявляет метод принятия объектов-посетителей.
 *
 * В этом методе Конкретный Компонент вызывает конкретный метод Посетителя, с
 * тем же типом параметра, что и у компонента.
 */
interface Entity
{
    public function accept(Visitor $visitor): string;
}



class Document implements Entity
{
    public $name ='Document';

    public function __construct()
    {
        echo 'Document';
    }


    public function accept(Visitor $visitor): string
    {
        return $visitor->visitDocument($this);
    }
}

class XMLDocument implements Entity
{
    public $name ='XMLDocument';

    public function __construct()
    {
        echo 'XMLDocument';
    }

    public function accept(Visitor $visitor): string
    {
        return $visitor->visitXMLDocument($this);
    }


}

class JsonDocument implements Entity
{
    public $name ='JsonDocument';

    public function __construct()
    {
        echo 'JsonDocument';
    }

    public function accept(Visitor $visitor): string
    {
        return $visitor->visitJsonDocument($this);
    }
}

interface Visitor
{
    public function visitDocument(Document $obj): string;

    public function visitXMLDocument(XMLDocument $obj): string;

    public function visitJsonDocument(JsonDocument $obj): string;
}



class CorrectCase implements Visitor
{
    public function visitDocument(Document $obj): string
    {
        return $obj->name;
    }

    public function visitXMLDocument(XMLDocument $obj): string
    {
        return $obj->name;
    }

    public function visitJsonDocument(JsonDocument $obj): string
    {
        return $obj->name;
    }
}


