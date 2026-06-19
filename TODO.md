# TODO – saját VPS deploy + cron (alternatíva a GitHub Actions helyett)

Csak akkor kell, ha saját szerverről akarod kiszolgálni a feedet. Ezeket a VPS-en
kell elvégezni, nem a fejlesztői gépen. (Az ajánlott út a GitHub Actions — lásd `README.md`.)

## 1. Fájlok kihelyezése

```bash
sudo mkdir -p /var/www/feed-generator
sudo cp generate-feed.php /var/www/feed-generator/
sudo mkdir -p /var/www/feed            # ebbe kerül a publikus XML
```

## 2. Első kézi futtatás (smoke test a VPS-en)

A forrás URL-t környezeti változóból adjuk (ne legyen a parancssorban/processz-listában szem előtt):

```bash
export FEED_SOURCE_URL="https://<forrás-feed-url>"
/usr/bin/php /var/www/feed-generator/generate-feed.php \
  --output=/var/www/feed/arukereso-feed-soudal-mapei.xml
ls -l /var/www/feed/arukereso-feed-soudal-mapei.xml
```

Az nginx szolgálja ki a `/var/www/feed` mappát egy saját (al)domainen
(pl. `https://feed.sajatdomain.hu/...`). A scriptnek írási joga kell legyen a kimeneti
mappához (a cront futtató felhasználó nevében).

## 3. Cron – napi 1 futtatás (hajnali 3:10)

A forrás URL-t a cron környezetében add meg (pl. a crontab elején vagy egy külön env-fájlban):

```cron
FEED_SOURCE_URL=https://<forrás-feed-url>
10 3 * * * /usr/bin/php /var/www/feed-generator/generate-feed.php --output=/var/www/feed/arukereso-feed-soudal-mapei.xml >> /var/log/arukereso-xml-feed.log 2>&1
```

A `>> … 2>&1` a STDOUT (INFO) és STDERR (ERROR) sorokat is a logba írja.
A script nem-nulla kilépési kóddal jelez hibát, és ilyenkor a régi XML-t nem írja felül.

## 4. (Opcionális) logrotate

`/etc/logrotate.d/arukereso-xml-feed`:

```
/var/log/arukereso-xml-feed.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
```
