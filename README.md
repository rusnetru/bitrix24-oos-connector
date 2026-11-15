# msp.oos — Office Online Server для Диска Битрикс24 (коробка)

Модуль открывает файлы Диска в Word, Excel и PowerPoint Online с вашей фермы Office Online Server (OOS),
той же, что обслуживает SharePoint. Битрикс24 выступает WOPI-хостом: OOS читает файл с портала
и сохраняет изменения новой версией файла на Диске.

Статус: **v0.1, не проверялся на живом портале.** Сначала ставить на тестовый портал.

## Что внутри

| Файл | Назначение |
| --- | --- |
| `lib/wopiserver.php` | WOPI-эндпоинты: CheckFileInfo, GetFile, PutFile, LOCK / UNLOCK / REFRESH_LOCK / GET_LOCK / UNLOCK_AND_RELOCK |
| `lib/hostpage.php` | Страница-хост: выдаёт токен, открывает редактор OOS на всю страницу |
| `lib/discovery.php` | Читает `https://<oos>/hosting/discovery`, кэширует на 12 часов |
| `lib/token.php` | Токен доступа (HMAC-SHA256: пользователь, файл, право записи, срок) |
| `lib/proofkey.php` | Проверка подписи запросов OOS (X-WOPI-Proof) |
| `lib/lockmanager.php`, `lib/locktable.php` | Блокировки WOPI в таблице `b_msp_oos_lock` |
| `lib/diskfile.php` | Работа с Диском: права, содержимое, `uploadVersion` |
| `install/tools/wopi.php` | Точка входа `/wopi/files/...` (через правило urlrewrite) |
| `install/tools/edit.php` | `/bitrix/tools/msp_oos/edit.php?id=<ID файла>` — открыть файл |
| `options.php` | Настройки модуля |

Права проверяются на каждом запросе OOS по правам Диска того пользователя, который открыл файл.
Если доступ отозвали, редактор перестанет читать и сохранять файл.

## Требования

- Коробочный Битрикс24 с модулем «Диск», PHP 8.1+, расширение `openssl`.
- Ферма OOS с включённым редактированием (отдельный Windows Server, не сервер Битрикса — см. ниже).
- Сеть: OOS обращается к порталу по HTTPS (`https://portal/wopi/files/...`), браузеры сотрудников открывают OOS.
- OOS доверяет сертификату портала (цепочка до корпоративного CA есть на серверах OOS).

Подробно про OOS — в следующем разделе.

## Требования к Office Online Server

### Где ставить

**На сервер Битрикс24 OOS поставить нельзя.**
- Коробка обычно работает на Linux (BitrixVM, Bitrix Environment), а OOS — только на Windows Server.
- Даже если портал на Windows, Microsoft требует для OOS выделенный сервер: без SharePoint, Exchange,
  SQL Server и без других сайтов IIS. OOS сам занимает порты 80/443 и 809.

Есть два варианта.

| Вариант | Когда подходит | Минусы |
| --- | --- | --- |
| **A. Существующая ферма SharePoint** | Ферма уже есть и доступна по сети из портала и браузеров. Достаточно `New-OfficeWebAppsHost -Domain <портал>` | Нагрузка от Битрикса ложится на ферму SharePoint; изменения на ферме затрагивают оба сервиса |
| **B. Отдельный экземпляр OOS для Битрикса** | Нужно развести сервисы, ферма SharePoint в другом сегменте сети или её нельзя трогать | Ещё одна Windows-машина, свой сертификат, своя установка |

Для варианта B — отдельная виртуальная машина рядом с порталом (та же сеть или DMZ), а не сервер Битрикса.

### Сервер (вариант B)

- **ОС:** Windows Server 2016 или 2019 (64-бит). Поддержку 2022 проверить по документации Microsoft для вашей сборки OOS.
- **Домен:** сервер должен быть в домене Active Directory — это требование установщика OOS.
- **Ресурсы:** как для SharePoint Server 2016 — от 4 vCPU, 12–16 ГБ ОЗУ, 80 ГБ диска.
  Для пилота хватит одного сервера; для большой нагрузки ферма из нескольких серверов за балансировщиком.
- **На сервере ничего лишнего:** ни SharePoint, ни Exchange, ни SQL, ни других сайтов IIS, ни Office.

### Предварительные компоненты

- Роли и компоненты Windows (PowerShell от администратора, Windows Server 2016/2019):

  ```powershell
  Install-WindowsFeature Web-Server, Web-Mgmt-Tools, Web-Mgmt-Console, Web-WebServer, Web-Common-Http, `
    Web-Default-Doc, Web-Static-Content, Web-Performance, Web-Stat-Compression, Web-Dyn-Compression, `
    Web-Security, Web-Filtering, Web-Windows-Auth, Web-App-Dev, Web-Net-Ext45, Web-Asp-Net45, `
    Web-ISAPI-Ext, Web-ISAPI-Filter, Web-Includes, NET-Framework-Features, NET-Framework-45-Features, `
    NET-Framework-45-Core, NET-Framework-45-ASPNET, NET-WCF-HTTP-Activation45, NET-WCF-Pipe-Activation45, `
    NET-WCF-TCP-Activation45, Server-Media-Foundation, Windows-Identity-Foundation
  ```

- .NET Framework 4.7.2 или новее.
- Visual C++ Redistributable 2013 и 2015–2022 (x64).
- Языковые пакеты OOS для русского интерфейса (ставятся после OOS).

Точный список зависит от версии Windows и сборки OOS — сверить со страницей
«Plan Office Online Server» / «Deploy Office Online Server» на Microsoft Learn.

### Дистрибутив и лицензии

- Дистрибутив OOS — в Volume Licensing Service Center (VLSC) организации, вместе с последним накопительным обновлением.
  **Скачать заранее:** после окончания поддержки (31.12.2026) он может пропасть из VLSC.
- Просмотр документов бесплатен. Для **редактирования** у пользователей должна быть лицензия Office,
  дающая право на редактирование в OOS (корпоративная Office Professional Plus / Standard или подписка
  Microsoft 365 с правами on-premises). Если SharePoint уже редактирует через OOS, эти лицензии, скорее всего, есть.

### Сертификат и сеть

- Сертификат для имени OOS (например `oos.company.local`) от корпоративного CA, в хранилище «Локальный компьютер → Личное»,
  с понятным Friendly Name — он указывается при создании фермы.
- DNS-имя OOS разрешается и с сервера портала, и с компьютеров сотрудников.

| Откуда → куда | Порт | Зачем |
| --- | --- | --- |
| Браузеры сотрудников → OOS | 443 | Окно редактора |
| Портал → OOS | 443 | Чтение discovery |
| OOS → портал | 443 | Чтение и сохранение файлов (`/wopi/files/...`) |
| Серверы OOS ↔ серверы OOS | 809 | Только для фермы из нескольких серверов |

На серверах OOS должен быть корневой сертификат CA, выпустившего сертификат портала.

### Создание фермы (вариант B)

```powershell
New-OfficeWebAppsFarm -InternalURL "https://oos.company.local" `
  -CertificateName "OOS" -EditingEnabled
# ограничить, каким хостам OOS отдаёт редактор
New-OfficeWebAppsHost -Domain "portal.company.local"
```

Проверка: `https://oos.company.local/hosting/discovery` открывается в браузере и отдаёт XML.

## Порядок тестирования

Модуль работает только в **коробке**. В облачном Битрикс24 свои PHP-модули ставить нельзя
(там доступны только REST-приложения), поэтому проверить его в облаке не получится.

Порядок:

1. Поднять тестовый портал (ниже).
2. Разрешить тестовый портал на ферме OOS (ниже).
3. Установить и настроить модуль — шаги 0–3 этого README.
4. Проверить, что модуль отвечает, ещё без OOS (ниже).
5. Прогнать WOPI Validator от Microsoft (ниже).
6. Проверить редактирование через OOS — шаг 4 этого README.
7. Включить проверку подписи запросов и повторить шаг 6.

### Тестовый портал

| Вариант | Комментарий |
| --- | --- |
| **Копия рабочего портала** (лучший) | Восстановить резервную копию на отдельную виртуальную машину. Та же версия модуля «Диск», те же права и данные. На копии отключить почту (отправку и сбор писем) и агенты, чтобы она не писала партнёрам |
| Чистая пробная коробка | «1С-Битрикс24: Корпоративный портал», бесплатно на 30 дней с 1c-bitrix.ru, на BitrixVM или Bitrix Environment (Linux) |
| Неофициальные Docker-образы | Только для быстрой пробы |

Тестовому порталу нужны своё DNS-имя (например `portal-test.company.local`)
и HTTPS-сертификат от корпоративного CA. Дальше в примерах — это имя.

### OOS для теста

Отдельный сервер не нужен: можно временно разрешить тестовый портал на нынешней ферме OOS (той, что у SharePoint).

```powershell
New-OfficeWebAppsHost -Domain "portal-test.company.local"
# после теста
Remove-OfficeWebAppsHost -Domain "portal-test.company.local"
```

SharePoint это не затрагивает. Если `Get-OfficeWebAppsHost` возвращает пустой список,
ферма уже принимает любые хосты, и добавлять ничего не надо.

### Модуль отвечает (без OOS)

```bash
curl -sk -o /dev/null -w "%{http_code}\n" "https://portal-test.company.local/wopi/files/1?access_token=x"
```

| Код | Что значит |
| --- | --- |
| **401** | Модуль установлен, запрос до него доходит, неверный токен отклонён |
| 404 | Не срабатывает правило urlrewrite (см. «Если не открывается») |
| 500 / 503 | Ошибка PHP или модуль не установлен — журнал ошибок PHP |

### WOPI Validator

[WOPI Validator](https://github.com/microsoft/wopi-validator-core) — открытая утилита Microsoft.
Она прогоняет набор тестов протокола (чтение, сохранение, блокировки) прямо по адресам портала, без OOS,
и показывает по каждому тесту «пройден / не пройден» с причиной.

1. **Подготовка.**
   - В настройках модуля флажок «Проверять подпись запросов» **выключен**: валидатор запросы не подписывает.
   - На машине, откуда запускается валидатор, установлен .NET SDK (версия — по README репозитория валидатора),
     и с неё открывается `https://portal-test.company.local`.
2. **Тестовый файл.** Создать на Диске пустой файл `test.wopitest` (0 байт) и узнать его ID:

   ```sql
   SELECT ID, NAME, SIZE FROM b_disk_object WHERE TYPE = 3 AND NAME = 'test.wopitest';
   ```

   Файл должен быть пустым: валидатор проверяет, что запись в пустой файл разрешена без блокировки.
   Перед каждым повторным прогоном файл заново делать пустым (или создавать новый).
3. **Токен.** Страница `edit.php` файлы `.wopitest` не открывает (их нет в discovery OOS),
   поэтому токен выдаётся из консоли на сервере портала. Подставить корень сайта, ID пользователя (1 — администратор)
   и ID файла:

   ```bash
   php -r '
   $_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
   define("NO_KEEP_STATISTIC", true); define("NOT_CHECK_PERMISSIONS", true); define("NO_AGENT_CHECK", true);
   require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
   \Bitrix\Main\Loader::includeModule("msp.oos");
   $t = \Msp\Oos\Token::issue(1, 12345, true, "");
   echo "TOKEN=", $t["token"], PHP_EOL, "TTL=", $t["ttl"], PHP_EOL;
   '
   ```

   Пользователь должен иметь право на изменение файла. Токен действует столько, сколько указано
   в настройке «Время жизни токена» (по умолчанию 10 часов).
4. **Запуск.**

   ```bash
   git clone https://github.com/microsoft/wopi-validator-core.git
   cd wopi-validator-core
   dotnet build
   ```

   Затем запустить собранную утилиту `Microsoft.Office.WopiValidator`
   (путь к сборке и точные ключи — в README валидатора):

   ```bash
   dotnet <путь>/Microsoft.Office.WopiValidator.dll \
     -w "https://portal-test.company.local/wopi/files/12345" \
     -t "<TOKEN>" -l 0 -e OfficeOnline
   ```

5. **Как читать результат.**
   - Должны проходить группы основных операций: CheckFileInfo, GetFile, PutFile, блокировки
     (Lock, Unlock, RefreshLock, GetLock, UnlockAndRelock).
   - Тесты переименования, «Сохранить как» (PutRelativeFile) и удаления в v0.1 не поддерживаются:
     модуль сообщает об этом в CheckFileInfo (`UserCanNotWriteRelative`, `SupportsRename = false`),
     и валидатор такие тесты пропускает или помечает как неприменимые.
   - Остальные проваленные тесты — это ошибки модуля. Прислать вывод валидатора и записи журнала событий `MSP_OOS_*`.

### Редактирование через OOS

Шаг 4 этого README: открыть файл через `edit.php?id=…`, правка → новая версия на Диске,
режим просмотра, права пользователей, одновременная работа двух пользователей.
Затем включить проверку подписи запросов и повторить.

## 0. Проверки перед установкой

На сервере портала:

```bash
# версия PHP и openssl
php -v && php -m | grep -i openssl

# портал видит OOS: в ответе должен быть XML с <wopi-discovery>
curl -sk https://oos.company.local/hosting/discovery | head -c 300
```

На сервере OOS (PowerShell):

```powershell
# редактирование включено: должно быть True
(Get-OfficeWebAppsFarm).EditingEnabled
```

## 1. Установка

1. Скопировать `local/modules/msp.oos` в `/local/modules/msp.oos` на портале.
2. Проверить синтаксис (модуль ещё не запускался, шаг обязательный):

   ```bash
   find /path/to/site/local/modules/msp.oos -name '*.php' -exec php -l {} \;
   ```

   У каждого файла должно быть `No syntax errors`.
3. Админка → `/bitrix/admin/partner_modules.php` (Marketplace → Установленные решения) →
   «Office Online Server для Диска» → «Установить».
4. Убедиться, что установка прошла:
   - есть папка `/bitrix/tools/msp_oos/` с `wopi.php` и `edit.php`;
   - в `/urlrewrite.php` появилось правило `#^/wopi/files/#` → `/bitrix/tools/msp_oos/wopi.php`;
   - в базе есть таблица `b_msp_oos_lock`.

## 2. Настройка модуля

`/bitrix/admin/settings.php?mid=msp.oos`:

- **Адрес OOS** — например `https://oos.company.local`;
- **Адрес портала** — тот, по которому портал открывается и с сервера OOS, и у сотрудников, например `https://portal.company.local`;
- **Зона discovery** — `internal-https`;
- **Проверять подпись запросов** — пока не включать.

Нажать «Сохранить и проверить OOS». Ожидается зелёное сообщение
«OOS отвечает… Редактирование: docx, xlsx, pptx…». При ошибке — журнал событий, тип `MSP_OOS_DISCOVERY`.

## 3. Настройка на стороне OOS

```powershell
Get-OfficeWebAppsHost
```

Если в списке уже есть домены (например, домен SharePoint), добавить портал — иначе OOS откажется с ним работать:

```powershell
New-OfficeWebAppsHost -Domain "portal.company.local"
```

Проверить, что OOS достаёт до портала:

```powershell
Invoke-WebRequest "https://portal.company.local/wopi/files/1?access_token=x" -UseBasicParsing
```

| Ответ | Что значит |
| --- | --- |
| **401** | Всё верно: запрос дошёл до модуля, неверный токен отклонён |
| 404 со страницей портала | Не срабатывает правило urlrewrite. Для nginx + php-fpm несуществующие пути должны уходить в `/bitrix/urlrewrite.php` (в BitrixVM обычно уже так) |
| Ошибка сертификата | На сервере OOS нет корневого сертификата корпоративного CA |

## 4. Проверка редактирования

1. Положить на Диск тестовый `test.docx` и узнать его ID:

   ```sql
   SELECT ID, NAME FROM b_disk_object WHERE TYPE = 3 AND NAME LIKE 'test%' ORDER BY ID DESC;
   ```

   (`TYPE = 3` — файл.) Также ID обычно виден во внутренней ссылке на файл (`objectId=...`).
2. Открыть `https://portal.company.local/bitrix/tools/msp_oos/edit.php?id=<ID>` — должен открыться Word Online на всю страницу.
3. Внести правку, подождать 30–60 секунд автосохранения. В истории версий файла на Диске появится новая версия от вашего имени.
4. Режим просмотра: `...edit.php?id=<ID>&action=view` — редактирование недоступно.
5. Права:
   - пользователь без доступа к файлу видит ошибку, а не документ;
   - пользователь только с правом чтения попадает в режим просмотра.
6. Совместная работа:
   - `.xlsx` двумя пользователями одновременно — правки видны друг другу;
   - то же для `.docx`: работает ли совместная правка в Word с этим хостом, заранее неизвестно.
7. Когда всё работает — включить «Проверять подпись запросов» и повторить шаги 2–3.

Ошибки пишутся в журнал событий портала (Настройки → Инструменты → Журнал событий), тип `MSP_OOS_*`.

### Если не открывается

| Симптом | Где смотреть |
| --- | --- |
| «Office Online Server недоступен» | Адрес OOS, сертификат OOS, флажок «Проверять SSL», журнал `MSP_OOS_DISCOVERY` |
| Редактор пишет, что не может открыть файл | OOS не достучался до `https://portal/wopi/files/<ID>`: DNS, сертификат портала на OOS, `New-OfficeWebAppsHost`, правило urlrewrite (см. шаг 3) |
| `/wopi/files/<ID>?access_token=x` отдаёт 404 страницы портала, а не 401 | Не сработало правило urlrewrite: проверить `/urlrewrite.php`, а для nginx — что несуществующие пути уходят в `/bitrix/urlrewrite.php` |
| Изменения не сохраняются | Журнал `MSP_OOS_SAVE`; проактивная защита/WAF могут резать бинарные POST на `/wopi/` — добавить `/wopi/` в исключения |
| Включили проверку подписи, и всё перестало работать | «Адрес портала» должен в точности совпадать с адресом, по которому ходит OOS (схема, хост, порт) |
| Белая страница / 500 | Журнал ошибок PHP на сервере портала; прислать текст ошибки разработчику |

## 5. Откат

`/bitrix/admin/partner_modules.php` → «Удалить». Удаление убирает правило urlrewrite,
папку `/bitrix/tools/msp_oos/` и таблицу блокировок. Уже сохранённые версии файлов на Диске остаются.

## Ограничения v0.1

- **Встраивание в интерфейс Диска пока не сделано.** Файл открывается по ссылке `edit.php?id=...`.
  Пункт «Открыть в Office Online» в меню Диска делается следующим шагом через собственный обработчик документов Диска.
  Для этого нужны исходники модуля `disk` вашей версии (см. ниже).
- **Версии.** OOS автосохраняет часто, и каждое сохранение — новая версия на Диске.
  Стоит задать лимит версий в настройках Диска.
- **Старые форматы** (`.doc`, `.xls`, `.ppt`) — только просмотр: OOS редактирует OOXML.
- **«Сохранить как», переименование** из редактора не поддерживаются.
- **Совместное редактирование в Word** с хостом не на SharePoint зависит от OOS. Проверить на пилоте.
- **OOS снят с поддержки Microsoft 31.12.2026.** Модуль от этого не перестанет работать,
  но обновлений безопасности для OOS не будет.

## Следующий шаг: кнопка в Диске

Скопируйте с портала в папку проекта `bitrix-src/disk/`:

- `/bitrix/modules/disk/install/version.php`
- `/bitrix/modules/disk/lib/document/` (вся папка)
- `/bitrix/modules/disk/lib/controller/` (если есть) и `/bitrix/modules/disk/lib/internals/` — по возможности

По ним будет видно, как в вашей версии регистрируются обработчики документов
(как встроены OnlyOffice и Битрикс24.Docs). Тогда «Office Online» можно добавить
в выбор способа работы с документами и в меню файлов, без правки ядра.
