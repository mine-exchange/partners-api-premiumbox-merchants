
# Решение для движка PremiumBox (версий 2.0 — 2.2 и 2.3), позволяющее взаимодействовать с MINE REST API (включает в себя пеймерчант и мерчант)

## Установка и настройка:

### ⚙️ Базовая установка
- Загрузить мерчант и пеймерчант в соответствующие директории. Также требуется загрузить директорию "includes", содержащую общий код для мерчанта и пеймерчанта в корневую директорию PremiumBox;
- Установить ключ авторизации и ключ подписи, взяв его из личного кабинета Mine Partners API;
<hr />

### 🔄 Выбор способа получения обновлений из Mine Partners API  
Вначале требуется **установить случайный хеш для status url** (в мерчанте или пеймерчанте). Хеш позволяет замаскировать адрес скрипта, чтобы им не воспользовались злоумышленники.
  
Варианты получения обновлений указаны в нижней части настраиваемого мерчанта/пеймерчанта. Доступны 2 варианта:

**a). WebHook (рекомендуемый способ)**: Данный URL необходимо добавить в личном кабинете MINE Partners API в поле "URL обратного вызова". 
> ⚠ По умолчанию URL всегда берётся из настроек мерчанта. Если вы деактивируете мерчант, не забудьте поменять адрес в личном кабинете MINE Partners API, взяв его из настроек пеймерчанта. 

> ⚠ **Если вы используете Cloudflare или любой другой продукт, имеющий Firewall, [требуется добавить ваш URL приёма вебхуков в исключения firewall](cloudflare-firewall-exceptions.md).**

**b). CRON (не рекомендуется но поддерживается на крайний случай)**: Требуется добавить запрос адреса CRON скрипта в ваш CRONTAB по аналогии с иными мерчантами. 
> ⚠ По умолчанию хеш, который дописывается в адрес CRON скрипта всегда берётся из настроек мерчанта. Если вы деактивируете мерчант, не забудьте поменять в вашем CRONTAB ссылку, взяв её из настроек пеймерчанта;
<hr />





### 💱 Валюты:
1. Создать необходимые валюты средствами административного интерфейса PremiumExchanger, если они не созданы ранее;
2. В случае если для валюты используется пеймерчант MINE Partners, выбрать из какой валюты внутреннего счёта использовать резерв;
<hr />

### 🎯 Направления обмена:
1. Создать направления, если это не было сделано ранее;
2. Мерчант и пеймерчант из коробки умеют определять, к какой валюте внутреннего счёта относится валюта партнёра, поэтому достаточно в направлении обмена указать мерчант и/или пеймерчант MINE Partners;
<hr />

### 💰 Неточность соответствия сумм:

> **⚠ Мы строго рекомендуем настроить этот параметр хотя бы на мизерное значение (можно просто не изменять), т.к. копейки могут отличаться из за особенностей рассчёта сумм в premiumbox.**

Опционально можно настроить параметр "допустимый % расхождения для пересчёта": при создании заявки может быть незначительно изменена сумма нашим API. При превышении расхождения сумм в данной настройке, сумма не будет изменена но после прохождения средств заявка будет переведена в статус "на проверке":
- В случае с мерчантом: Вы сможете поменять статус с "На проверке" на "Оплачена" и заявка продолжит выполнение;
- В случае с пеймерчантом: Деньги не будут отправлены, вы сможете найти данный заказ в личном кабинете Mine Partners и при необходимости, подтвердить его вручную. После этого статус в PremiumBox поменяется автоматически;
<hr />

