### Увидеть ясную структуру дизайна

1. Изучите материал из СильныхИдей "Три уровня рассуждений о программной системе - 3". - DONE
2. Выберите в вашем рабочем проекте некоторый "кусок кода" (несколько сотен строк), и сформулируйте словесно его логический дизайн.
   Насколько существующий код ему реально соответствует?
   Может быть, теперь даже напрашивается совершенно другой код?
3. Реализуйте этот дизайн (перепишите существующий код) ...
   -- в схеме "целиком или ничего", или (лучше)
   -- в декларативном виде, насколько возможно.

Старайтесь при этом достигать максимальной ясности и соответствия кода 1:1 с дизайном.

*1:1 означает, напомню, что код не просто прямо реализует дизайн, но и не возможен никакой другой код, который это делает как-то по другому. То есть и дизайн напрямую следует из кода.*

Но ни в коем случае не усложняйте! :) А то идея "декларативщины" подчас провоцирует на ужасные "универсальные оптимизированные" конструкции.

4. Повторите пункты 2 и 3 ещё 2-3 раза с другим кодом.

В решении отправляете (по каждой итерации) исходный код, рефлексию п.2 и новую версию п.3. Сколько времени занимает каждая такая итерация?

Разбор кейса [1](1/readme.md), код кейса [1](1/code.php), код результата [1](1/result_code.php)

Разбор кейса [2](2/readme.md), код кейса [2](2/code.php), код результата [2](2/result_code.php)

Разбор кейса [3](3/readme.md), код кейса [3](3/code.php), код результата [3](3/result_code.php)