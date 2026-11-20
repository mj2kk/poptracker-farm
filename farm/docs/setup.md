# Развёртывание и подключение

### Установленная система Vuka

Файлы Farm расположены в `/PopTracker Farm`. Действующий Laravel PopTracker работает в `/var/www/projects/dataroom-agents-lab`. Для Farm используется отдельный сервис PHP 8.3 FPM и отдельная база `poptracker_farm`. Единственная интеграция с действующим сайтом — включение префикса /api/device/v1/ через Nginx на HTTPS-домене `7s-01d.online`. Приложение использует системную проверку TLS. Секрет для подключения к MySQL заменён аутентификацией локального пользователя `popfarm` через Unix-сокет.

### Действия оператора на VPS

```sh
cd '/PopTracker Farm/server'
runuser -u popfarm -- php bin/farm.php issue 'Test Android'
runuser -u popfarm -- php bin/farm.php list
runuser -u popfarm -- php bin/farm.php revoke UUID
```

Команда `issue` печатает UUID и одноразовый код. Запишите UUID для восстановления и введите код на телефоне в течение 10 минут. В операторском списке время UTC. Команда `revoke` также отменяет ещё не погашенный код. Кнопка «Забыть устройство» удаляет локальный токен с телефона. Для вывода телефона из эксплуатации отзовите UUID на сервере.

### Сборка и установка

Подтверждённый набор инструментов — Gradle Wrapper 9.6.0, Android Gradle Plugin 9.5.1 со встроенным Kotlin, Android SDK 37.0 и JBR 25 из Android Studio. Минимальная версия для установки — API 23, физически испытанный телефон использует API 33. Выполните из каталога проекта на Mac:

```sh
cd android
export ANDROID_HOME="$HOME/Library/Android/sdk"
export JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home"
./gradlew :app:testDebugUnitTest :app:assembleDebug
shasum -a 256 app/build/outputs/apk/debug/app-debug.apk
"$ANDROID_HOME/platform-tools/adb" devices -l
"$ANDROID_HOME/platform-tools/adb" -s SERIAL install -r app/build/outputs/apk/debug/app-debug.apk
```

Откройте PopTracker Farm. Убедитесь, что указан `https://7s-01d.online`, введите код, отсоедините USB и нажмите «Подключить». Время последнего heartbeat на экране показывает время приёма сервером. Для проверки обрыва отключите Wi-Fi и мобильные данные на 3 минуты, затем восстановите сеть и оставьте агент открытым. В таблице истории появится разрыв между сообщениями, а статус вернётся в online. Для смены адреса сервера нужна новая регистрация.

### Развёртывание на совместимом Ubuntu-сервере

Скопируйте каталог `server` в `/PopTracker Farm`. Нужны PHP CLI и FPM 8.3 с `pdo_mysql`, MySQL 8 с активным плагином `auth_socket`, Nginx, cron и существующий сайт HTTPS с доверенным сертификатом. Команды ниже предназначены для первоначальной установки в свободные имена базы и пользователя.

```sh
cd '/PopTracker Farm/server'
useradd --system --user-group --no-create-home --shell /usr/sbin/nologin popfarm
mysql -e "CREATE DATABASE poptracker_farm CHARACTER SET utf8mb4; CREATE USER 'popfarm'@'localhost' IDENTIFIED WITH auth_socket; GRANT SELECT,INSERT,UPDATE,DELETE ON poptracker_farm.* TO 'popfarm'@'localhost';"
mysql poptracker_farm < database/up.sql
install -m 0644 deploy/fpm.conf /etc/php/8.3/fpm/pool.d/poptracker-farm.conf
install -m 0644 deploy/limits.conf /etc/nginx/conf.d/poptracker-farm-limits.conf
install -m 0644 deploy/locations.conf /etc/nginx/snippets/poptracker-farm-locations.conf
install -m 0644 deploy/prune.cron /etc/cron.d/poptracker-farm
```

Сделайте резервную копию выбранного HTTPS-vhost. Внутри его блока `server` добавьте `include /etc/nginx/snippets/poptracker-farm-locations.conf;`. Проверьте конфигурацию и примените её:

```sh
php-fpm8.3 -t && nginx -t && systemctl reload php8.3-fpm && systemctl reload nginx
runuser -u popfarm -- php bin/farm.php list
curl -i https://7s-01d.online/api/device/v1/me
```

Проверка без токена должна вернуть HTTP 401. Для другого домена укажите его в запросе и на экране телефона. Журнал `/var/log/nginx/poptracker-farm.log` содержит только время, метод, имя маршрута и HTTP-статус. При использовании прокси лимит частоты действует по адресу, который видит Nginx.

Для отключения Farm удалите включение маршрутов из vhost, проверьте конфигурацию и перечитайте Nginx. Отдельно можно отключить пул PHP и задание cron. Обратная миграция `database/down.sql` удаляет данные трёх таблиц в выбранной БД и предназначена только для явного отката после резервного копирования. На рабочей базе она не нужна.

### Серверные тесты

Используйте отдельную базу с префиксом `poptracker_farm_test_`. Например, на машине с локальной root-аутентификацией MySQL:

```sh
cd '/PopTracker Farm/server'
mysql -e 'CREATE DATABASE poptracker_farm_test_local CHARACTER SET utf8mb4'
mysql poptracker_farm_test_local < database/up.sql
FARM_DB_USER=root FARM_DSN='mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=poptracker_farm_test_local;charset=utf8mb4' php tests/api.php
```

Для отката тестовой схемы примените `database/down.sql` только к созданной тестовой базе. Проверять физический телефон следует отдельно от этих тестов.

### Этап 2 · Очередь заданий

Для новой установки после `database/up.sql` примените `mysql poptracker_farm < database/002_tasks_up.sql`. При обновлении действующей установки сначала сохраните согласованный дамп Farm DB и текущие файлы сервиса. Миграция добавляет две таблицы в той же базе. Разверните обновлённые `farm.php`, `tasks.php`, роутер, CLI и `deploy/limits.conf`. Для обновления Android используйте `adb -s SERIAL install -r app/build/outputs/apk/debug/app-debug.apk` с тем же ключом подписи. Установщик сохраняет действующую регистрацию.

Команды на Vuka:

```sh
cd '/PopTracker Farm/server'
runuser -u popfarm -- php bin/farm.php list
runuser -u popfarm -- php bin/farm.php task:create DEVICE_UUID PING
runuser -u popfarm -- php bin/farm.php task:create DEVICE_UUID GET_DEVICE_STATUS
runuser -u popfarm -- php bin/farm.php task:create DEVICE_UUID EXECUTE_TEST_TASK '{"duration_ms":1000}'
runuser -u popfarm -- php bin/farm.php task:list DEVICE_UUID
runuser -u popfarm -- php bin/farm.php task:show TASK_UUID
runuser -u popfarm -- php bin/farm.php task:cancel TASK_UUID
```

`task:list` без UUID выводит до 100 последних заданий всего парка. `task:show` показывает время UTC, итог и попытки. Поле `replay_count` считает подтверждённые повторы того же результата, `late_results` — опоздания. Отменить можно задание в статусе queued. При окончании 120-секундного lease статус обновляется при чтении очереди, операторском просмотре, обработке результата или ежедневной очистке. Выбран максимум одна попытка. Оператор может проверить причину и явно создать новое задание. Ежедневный `prune` удаляет завершённые, отменённые и истёкшие задания старше 30 дней вместе с попытками. Ожидающее задание живёт до исполнения или отмены оператором.

Для тестирования разверните обе миграции в одноразовой БД с префиксом `poptracker_farm_test_` и экспортируйте `FARM_DSN` и `FARM_DB_USER` как в примере выше. Запустите `php tests/api.php`, `php tests/tasks.php` и `php tests/task_http.php`. Для проверки отката примените к этой тестовой БД `database/002_tasks_down.sql` и повторите `php tests/api.php`. Обратная миграция удаляет историю заданий. Для эксплуатационного отката сначала верните файлы API, конфигурацию и совместимый клиент, оставив добавленные таблицы для разбора. Восстановление всей БД из дампа вернёт её к моменту копирования.

Для зарегистрированного устройства команда создания PING:

```sh
runuser -u popfarm -- php "/PopTracker Farm/server/bin/farm.php" task:create DEVICE_UUID PING
```

Команда возвращает UUID задания. Передайте его в `task:show`, чтобы увидеть состояние и результат.

### Этап 3A · Тест собственной Activity

После миграций первого и второго этапов примените `mysql poptracker_farm < database/003_ui_up.sql`. Она расширяет ENUM в device_tasks. На действующем сервере предварительно сохраните согласованный дамп базы и исходные файлы. Установите `ui_scenario.php` перед обновлением `tasks.php`. После публикации сервера установите подписанный тем же debug-ключом агент через `adb install -r`. Установщик сохраняет действующую регистрацию.

В приложении откройте настройки специальных возможностей и вручную включите «PopTracker Farm · Automation Test». На OPPO список служб может находиться в подразделе загруженных приложений. Вернитесь в Farm, нажмите верхнюю кнопку «Открыть тестовый экран» и оставьте экран открытым. После установки USB можно отключить. Кнопка «Стоп» прерывает текущий сценарий, «Назад» закрывает тест. Кнопка «Сброс» очищает счётчик и возвращает список в начало между запусками. Службу можно выключить там же в системных настройках.

Команда для тестового устройства:

```sh
cd '/PopTracker Farm/server'
runuser -u popfarm -- php bin/farm.php task:create DEVICE_UUID UI_TEST_SCENARIO '{"scenario":"OWN_APP_BUTTON_AND_SCROLL_V1"}'
runuser -u popfarm -- php bin/farm.php task:show TASK_UUID
```

Для чтения счётчика и поиска строки служба запрашивает доступ к содержимому активного окна. События отфильтрованы по пакету Farm. В коде дополнительно проверяются владелец окна, ресурс, видимость и пакет целевого узла. Службу связывает Android через `BIND_ACCESSIBILITY_SERVICE`. В конфигурации не включены жесты, получение скриншотов, обработка клавиш и перечисление окон. [Документация Android о конфигурации службы](https://developer.android.com/guide/topics/ui/accessibility/service)

Для регрессии в одноразовой тестовой БД после трёх миграций выполните `php tests/api.php`, `php tests/tasks.php`, `php tests/task_http.php` и `php tests/ui_scenario.php`. Физические инструментальные тесты собраны отдельно через `:app:assembleDebugAndroidTest`. Их можно установить отдельно от агента. `AutomationScreenTest` запускается до включения службы, `EnabledServiceTest` — после ручного включения. Так данные установленного OPPO сохраняются. Если Android пометил службу как отключившуюся после смены процесса, включите её вручную заново после старта тестового раннера. После завершения инструментальных тестов проверьте статус службы в обычном агенте.

Для отката остановите создание UI-задач, отмените ожидающие и дождитесь окончания уже взятых попыток. Удаление возможности из APK останавливает дальнейшую выдачу UI-сценариев этому клиенту. Возврат старого PHP требует согласованного возврата клиента: старый сервер отклоняет дополнительное поле capabilities. Расширенный ENUM можно оставить для сохранения аудита. `003_ui_down.sql` в строгом режиме откажется сузить поле, пока существуют строки UI_TEST_SCENARIO. Архивирование истории и восстановление рабочей базы — отдельные операторские действия.

### Этап 3B.1 · Доставка отчёта после длительного обрыва

Остановите выкладку перед сменой API, если резервная копия не восстанавливается в отдельную базу. После миграций 001–003 выполните `mysql poptracker_farm < database/004_result_delivery_up.sql` из каталога сервера. Опубликуйте `tasks.php`, затем установите подписанный тем же ключом APK 0.3.2 через `adb -s SERIAL install -r app/build/outputs/apk/debug/app-debug.apk`. При обновлении сохраняются локальный журнал и регистрация. В новой версии в CLI у попытки выводятся `delivery_phase`, `deliver_until`, `reported_start_ms` и `reported_finish_ms`.

В одноразовой БД с четырьмя миграциями запустите `php tests/api.php`, `php tests/tasks.php`, `php tests/task_http.php`, `php tests/ui_scenario.php` и `php tests/result_delivery.php`. Для Android выполните `./gradlew :app:testDebugUnitTest :app:assembleDebug` в каталоге android. [Политика доставки и порядок отката](step-03b1-lease-protocol.md)

### Этап 3B.2. Приём пакета

Перед обновлением сделайте дамп Farm и восстановите его в отдельную тестовую БД. Примените к копии миграцию `database/005_collection_up.sql` после 001–004. Проверьте чистый откат через `005_collection_down.sql`, затем примените 005 повторно и запустите шесть PHP-наборов, включая `tests/collection.php`. Обратная миграция разрешена только при отсутствии заданий сбора. Завершённые пакеты и строки удаляются вместе с заданиями по действующей политике хранения 30 дней.

Для первой установки операторского доступа выполните под root на VPS. При обновлении существующей инсталляции сохраните её токен.

```sh
install -d -m 0750 -o root -g popfarm /etc/poptracker-farm
(
  set -eC
  umask 077
  openssl rand -hex 32 > /etc/poptracker-farm/operator.token
  tr -d '\n' < /etc/poptracker-farm/operator.token | sha256sum | cut -d' ' -f1 > /etc/poptracker-farm/operator.sha256
  chown root:popfarm /etc/poptracker-farm/operator.sha256
  chmod 0640 /etc/poptracker-farm/operator.sha256
)
```

После проверки примените 005 к `poptracker_farm`, разместите `collection.php`, `tasks.php`, `farm.php`, CLI и роутер. Установите `deploy/locations.conf` в `/etc/nginx/snippets/poptracker-farm-locations.conf`. Выполните `nginx -t` и `php-fpm8.3 -t` перед перечитыванием конфигураций. Обновите Android с сохранением регистрации через `adb install -r`. Откройте Farm, чтобы агент начал получать задания. В каталоге `/PopTracker Farm/server` на VPS:

```sh
runuser -u popfarm -- php bin/farm.php task:create DEVICE_UUID COLLECT_TEST_DATA '{"dataset":"synthetic_videos","record_count":10}'
runuser -u popfarm -- php bin/farm.php task:show TASK_UUID
```

### Step 4. Второй Android и общая очередь

Сохраните дамп `poptracker_farm` и текущие серверные файлы. На копии с миграциями 001–005 примените `database/006_orchestration_up.sql`. Проверьте пустой откат через `006_orchestration_down.sql`, повторите прямую миграцию и выполните прежние PHP-наборы и `tests/orchestration.php` с тестовым DSN. После приёмки копии примените прямую миграцию к рабочей Farm, разместите `collection.php`, `tasks.php`, `farm.php` и перечитайте пул FPM. Откат защищён от удаления истории назначения any и связей одноимённых клиентских пакетов.

Для нового Android установите тот же подписанный APK, адресуя ADB по serial. Выпустите персональный код через `bin/farm.php issue 'Название устройства'`, введите его в Farm на новом телефоне и оставьте приложение открытым с интернетом. При настройке Xiaomi подтвердите установку в MIUI. Службу «PopTracker Farm · Automation Test» включайте на самом телефоне для сценариев тестового экрана. Для заполнения аппаратного профиля выдайте GET_DEVICE_STATUS. Операторские команды на Vuka выполняются из `/PopTracker Farm/server`.

```sh
runuser -u popfarm -- php bin/farm.php list
runuser -u popfarm -- php bin/farm.php task:create DEVICE_UUID GET_DEVICE_STATUS
runuser -u popfarm -- php bin/farm.php task:create any PING
runuser -u popfarm -- php bin/farm.php task:show TASK_UUID
```
