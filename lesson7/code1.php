<?php

class Document
{
    public function __construct()
    {
        echo 'Document';
    }

    public function print()
    {
        echo 'printDocument';
    }
}

class XMLDocument
{
    public function __construct()
    {
        echo 'XMLDocument';
    }

    public function print()
    {
        echo 'printXMLDocument';
    }
}

class JsonDocument
{
    public function __construct()
    {
        echo 'JsonDocument';
    }

    public function print()
    {
        echo 'printJsonDocument';
    }
}


class NotCorrectCase
{
    public function execute()
    {
        $objDocument = new Document();
        $objJson = new JsonDocument();
        $objXml = new XmlDocument();
        $objDocument->print();
        $objJson->print();
        $objXml->print();
    }
}

