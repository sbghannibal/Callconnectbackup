# Callconnectbackup

Tool om in te loggen op **callconnect.proximus.be**, alle gegevens op te halen en in een **MySQL**-database te bewaren.
In het eigen **PHP-portaal** kan je de data bekijken, parameters wijzigen, aanduiden wat terug moet en die selectie
terugduwen naar CallConnect. Elke login op CallConnect (gelukt of mislukt) en elke push worden gelogd.

## Wat zit erin

| Onderdeel | Bestand |
| --- | --- |
| MySQL-schema (records, parameters, login_log, push_log) | `database/schema.sql` |
| Configuratie via omgevingsvariabelen / `.env` | `src/Config.php` |
| Databaseverbinding + migratie | `src/Database.php` |
| Alle queries | `src/Repository.php` |
| CallConnect-client (login, ophalen, pushen via cURL) | `src/HttpCallConnectClient.php` |
| Import (login → ophalen → wegschrijven) | `src/ImportService.php` |
| Push (login → aangeduide parameters terugzetten) | `src/PushService.php` |
| Portaal (data, parameters wijzigen, aanduiden, pushen) | `public/index.php` |
| Logboek (loginpogingen en pushes) | `public/logs.php` |
| CLI-scripts | `bin/migrate.php`, `bin/import.php`, `bin/push.php` |

Vereisten: PHP 8.1+ met `pdo_mysql`, `curl` en `json`, en een MySQL/MariaDB-server. Composer is niet nodig.

## Installatie

```bash
cp .env.example .env      # vul MySQL- en CallConnect-gegevens in
php bin/migrate.php       # maakt de database en tabellen aan
php -S 127.0.0.1:8080 -t public   # of wijs je webserver naar public/
```

De CallConnect-login wordt nooit in de database bewaard; enkel het resultaat van de poging.

## Gebruik

1. **Ophalen**: `php bin/import.php` of de knop *Ophalen uit CallConnect* in het portaal.
   Er wordt ingelogd, alle records worden opgehaald en per record worden de velden als parameters bewaard.
   Een herimport ververst de CallConnect-waarde maar behoudt wijzigingen die nog niet gepusht zijn.
2. **Wijzigen en aanduiden**: pas in het portaal de kolom *Waarde in portaal* aan en vink *Push* aan bij de
   parameters die terug moeten. Bewaren doe je met *Opslaan in database*.
3. **Terugzetten**: `php bin/push.php` of de knop *Opslaan en aangeduide naar CallConnect pushen*.
   Per record wordt één update verstuurd met de aangeduide parameters. Lukt het, dan wordt de status *gepusht* en de
   CallConnect-waarde bijgewerkt; lukt het niet, dan blijft de parameter aangeduid met status *push mislukt*.
4. **Logboek**: `public/logs.php` toont elke loginpoging (tijdstip, gebruiker, resultaat, HTTP-status, boodschap) en
   elke push met de verstuurde payload. Het portaal toont bovenaan altijd de laatste loginstatus.

## Endpoints instellen

De paden en veldnamen van CallConnect zijn instelbaar in `.env`, zodat de tool niet moet wijzigen als het portaal
andere endpoints gebruikt:

```
CALLCONNECT_LOGIN_PATH=/api/login       # loginendpoint
CALLCONNECT_LOGIN_FORMAT=json           # json of form (formulier-post)
CALLCONNECT_DATA_PATH=/api/subscribers  # lijst met records
CALLCONNECT_DATA_KEY=items              # sleutel in het JSON-antwoord met de lijst
CALLCONNECT_PUSH_PATH=/api/subscribers/{id}
CALLCONNECT_PUSH_METHOD=PUT
CALLCONNECT_ID_FIELD=id                 # veld met de unieke sleutel
CALLCONNECT_LABEL_FIELD=name            # veld dat als titel getoond wordt
```

## Testen

De tests draaien tegen een echte MySQL-server en tegen een lokale CallConnect-nabootsing
(`tests/fixtures/mock_server.php`). Ze worden overgeslagen als er geen MySQL bereikbaar is.

```bash
TEST_DB_HOST=127.0.0.1 TEST_DB_USER=root TEST_DB_PASSWORD=root TEST_DB_NAME=callconnect_test phpunit
```

Wil je de tool uitproberen zonder CallConnect, start dan de nabootsing en zet
`CALLCONNECT_BASE_URL=http://127.0.0.1:8081` met gebruiker `tester` / `secret`:

```bash
php -S 127.0.0.1:8081 tests/fixtures/mock_server.php
```
