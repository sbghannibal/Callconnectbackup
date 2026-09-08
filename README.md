# Callconnectbackup

Tool om in te loggen op **callconnect.proximus.be**, alle gegevens op te halen en in een **MySQL**-database te bewaren.
In het eigen **PHP-portaal** kan je de data bekijken, parameters wijzigen, aanduiden wat terug moet en die selectie
terugduwen naar CallConnect. Elke login op CallConnect (gelukt of mislukt) en elke push worden gelogd.

## Wat zit erin

| Onderdeel | Bestand |
| --- | --- |
| MySQL-schema (records, parameters, login_log, push_log, discovery_*) | `database/schema.sql` |
| Configuratie via omgevingsvariabelen / `.env` | `src/Config.php` |
| Databaseverbinding + migratie | `src/Database.php` |
| Alle queries | `src/Repository.php` |
| CallConnect-client (login, ophalen, pushen via cURL) | `src/HttpCallConnectClient.php` |
| Import (login → ophalen → wegschrijven) | `src/ImportService.php` |
| Ontdekking (pagina's crawlen via GET) | `src/DiscoveryService.php` |
| HTML-parser (secties, labels, waarden, formuliervelden) | `src/PageParser.php` |
| Push (login → aangeduide parameters terugzetten) | `src/PushService.php` |
| Portaal (data, parameters wijzigen, aanduiden, pushen) | `public/index.php` |
| Ontdekte pagina's en velden bekijken | `public/discovery.php` |
| Logboek (loginpogingen en pushes) | `public/logs.php` |
| CLI-scripts | `bin/migrate.php`, `bin/import.php`, `bin/push.php`, `bin/discover.php` |

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
4. **Ontdekken**: `php bin/discover.php` of de knop *Pagina's zoeken in CallConnect* op `public/discovery.php`.
   Zie [Ontdekking](#ontdekking) hieronder.
5. **Logboek**: `public/logs.php` toont elke loginpoging (tijdstip, gebruiker, resultaat, HTTP-status, boodschap) en
   elke push met de verstuurde payload. Het portaal toont bovenaan altijd de laatste loginstatus.

## Ontdekking

Omdat CallConnect geen API heeft, kan de tool de portaalpagina's zelf aflopen. Na de login worden de ingestelde
startpagina's via **GET** opgehaald en wordt de HTML geparsed met `DOMDocument`/`DOMXPath`. Interne links, tabbladen
en GET-formulieren worden gevolgd, zolang ze binnen `CALLCONNECT_BASE_URL` en de toegelaten paden vallen.

Per pagina wordt bewaard:

* URL en pad, titel/hoofding en de diepte in de crawl
* de gevonden tabbladen/secties, links en formulieren
* alle label/waarde-paren uit detailkaarten (`dl`), tabellen en lijsten
* alle formuliervelden met naam, id, type, waarde, aangevinkte staat en keuzemogelijkheden
* de volledige ruwe HTML, zodat velden die (nog) niet herkend worden later toch te bekijken zijn

Wachtwoordvelden worden nooit bewaard. De resultaten staan in `discovery_runs`, `discovery_pages` en
`discovery_fields` en zijn te bekijken via *Ontdekking* in het portaal, zodat je nadien kan beslissen welke velden
je effectief wil importeren.

Instellingen in `.env`:

```
CALLCONNECT_DISCOVERY_SEEDS=/portal/users   # startpagina's, komma- of regelgescheiden (leeg = CALLCONNECT_DATA_PATH)
CALLCONNECT_DISCOVERY_ALLOW=/portal/*       # toegelaten paden, "*" is een joker (leeg = alles binnen de basis-URL)
CALLCONNECT_DISCOVERY_MAX_DEPTH=2           # hoe diep links gevolgd worden
CALLCONNECT_DISCOVERY_MAX_PAGES=50          # maximum aantal pagina's per ronde
```

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

De nabootsing serveert naast de JSON-endpoints ook HTML-pagina's (`/portal/users`, `/portal/users/A1`), zodat je de
ontdekking kan uitproberen met `CALLCONNECT_DISCOVERY_SEEDS=/portal/users` en `CALLCONNECT_DISCOVERY_ALLOW=/portal/*`.
