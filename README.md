На сайте порядка 1млн постов. Данный сервис был сделан для генерации списка постов по параметрам с учетом типа сортировки и пагинации.

- getPostCount используется для определения общего количества постов по критериям для управления работы пагинацией
- getList возвращает массив постов, в основном используется для постраничного вывода постов.
- getFeed возвращает посты по играм, на которые подписан пользователь. 

Остальные методы являются закрытыми и обеспечивают работу первых трех публичных методов.
