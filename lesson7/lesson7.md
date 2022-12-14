### Истинное наследование

Код кейса [1](/code1.php)
Код кейса [2](/code2.php)

### Отчет
+ Упрощает добавление операций, работающих со сложными структурами объектов.
+ Объединяет родственные операции в одном классе.
+ Посетитель может накапливать состояние при обходе структуры элементов.
+ Проще подмешивать дополнительный функционал

- Паттерн не оправдан, если иерархия элементов часто меняется.
- Может привести к нарушению инкапсуляции элементов.
- Менее нагляден - имхо

При реализации опирался на статью "https://refactoring.guru/ru/design-patterns/visitor".
