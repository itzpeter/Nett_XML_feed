# TODO – VPS deploy + cron (NEM lokálisan állítandó be)

Ezeket a VPS-en kell elvégezni, nem a fejlesztői gépen.

## 1. Fájlok kihelyezése

```bash
sudo mkdir -p /var/www/nett-feed
sudo cp generate-feed.php /var/www/nett-feed/
# a kimeneti webgyökér általában már létezik: /var/www/html
```

## 2. Első kézi futtatás (smoke test a VPS-en)

```bash
/usr/bin/php /var/www/nett-feed/generate-feed.php
ls -l /var/www/html/arukereso-feed-soudal-mapei.xml
# Ellenőrzés böngészőből: https://nett.hu/arukereso-feed-soudal-mapei.xml
```

A scriptnek írási joga kell legyen a `/var/www/html` mappához (a cront futtató
felhasználó nevében). Ha a webszerver felhasználója `www-data`, célszerű az ő
nevében futtatni a cront, vagy megfelelő jogosultságot adni.

## 3. Cron – napi 1 futtatás (hajnali 3:10)

`crontab -e` (vagy `/etc/cron.d/nett-feed`):

```cron
10 3 * * * /usr/bin/php /var/www/nett-feed/generate-feed.php >> /var/log/nett-arukereso-xml-feed.log 2>&1
```

A `>> … 2>&1` a STDOUT (INFO) és STDERR (ERROR) sorokat is a logba írja.
A script nem-nulla kilépési kóddal jelez hibát, és ilyenkor a régi XML-t
nem írja felül.

## 4. (Opcionális) logrotate a feed loghoz

`/etc/logrotate.d/nett-arukereso-xml-feed`:

```
/var/log/nett-arukereso-xml-feed.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
```
