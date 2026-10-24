# Changelog

## [1.21.0] - 2026-10-24

### Toegevoegd
- Docs-site (`publish site`/`publish docs`): elke taal op de pagina met alle handleidingen heeft zijn vlag, voor de
  naam in de lijst en in de grote PDF-knop (bij rechts-naar-linkstalen rechts). De vlaggen staan als
  `flag-<land>.svg` naast `index.php` (flag-icons 7.6.0, MIT, in `resources/publish/flags/`); welk land bij welke taal
  hoort staat als `flag` in `resources/languages.json`. Een taal zonder vlag krijgt alleen zijn naam.
- Eigen tests: `PublishSiteTest` (elke taal heeft een vlag, de site krijgt de vlaggen en toont ze, een onbekende of
  vreemde vlagwaarde wordt niet gekopieerd).

## [1.20.1] - 2026-10-23

### Gewijzigd
- Onderhoud: teksten van de introductiepagina van de demo en de standaardnaam van de demowinkel bijgewerkt, metadata
  en bestandsheaders bijgewerkt.

## [1.20.0] - 2026-10-23

### Toegevoegd
- `ps-module demo-site apply <dir> [note] [--no-snapshot] [--dry-run]`: uploadt een map met `apply.php` naar de
  demoserver en draait `demo-site.sh apply`: `apply.php` in de winkel zoals hij is (met `DEMO_SITE_URL` en
  `DEMO_SITE_ADMIN_DIR`), met hetzelfde vangnet als `deploy`: test ervoor en erna, bij een fout (geen `APPLY-OK`, of
  pagina's die sindsdien kapot zijn) gaat de database terug, anders een nieuwe demo-snapshot en een VPS-snapshot. Voor
  de testgegevens van alle modules in de demo (`MODULES/test-site`, `php test-site/test-site.php demo`).
- Introductiepagina van de demo (`demo-settings.php`): per module een link "Things to try" naar `/test-site/#<module>`
  als de testgegevens van die module in de winkel staan, en `data-module`/`data-version` op elke module (leesbaar voor
  tools).
- Eigen tests: `PublishDemoApplyTest` (bestanden van de map, weigeren zonder `apply.php`, dry run, het serverscript).

## [1.19.0] - 2026-10-22

### Toegevoegd
- Meerdere modules tegelijk (andere terminals, andere Claude-sessies): commando's die iets gedeelds gebruiken wachten
  op elkaar met een lock (`src/lock.php`) in plaats van elkaar in de weg te zitten. `demo-server`: `publish docs`,
  `publish demo`, `publish vps-snapshot` en elk `demo-site`-commando (met `--env=<file>` een eigen lock per server);
  `sales-shop`: `publish shop`; `shops-<module>`: elk `shops`-commando en de screenshots van `docs`; `docs-image`: het
  bouwen van de docs-image. Een wachtend commando meldt elke minuut wie het lock heeft (commando, module, proces,
  sinds) en gaat verder zodra het vrij is; na `PS_MODULE_LOCK_TIMEOUT` seconden (3600) stopt het. De locks staan in
  `MODULES/.cache/locks/`; het besturingssysteem geeft ze vrij als het proces stopt, ook na een crash. Een commando dat
  door een lockhouder is gestart (de `shops`-commando's van `docs build`) wacht niet op zichzelf. `--dry-run` wacht niet.
- `shops up`/`run`: stopt met een duidelijke melding als een container van een ander project (of een ander programma)
  de poort van de winkel al gebruikt, in plaats van tegen de verkeerde winkel te testen.
- `demo-install.php` (`publish demo`): na de installatie of upgrade krijgt elk profiel behalve SuperAdmin dat rechten
  op de module heeft (de profielen die modules mogen beheren, zoals het beperkte demo-account) alle rechten op de
  back-office-pagina's van de module (PrestaShop geeft die alleen aan SuperAdmin), en verliest het het recht om de
  module te verwijderen. Bij elke upgrade opnieuw (nieuwe tabs). `php demo-install.php --rights <module>` doet alleen dit.
  Getest op PrestaShop 9.2.0.

### Gewijzigd
- `shops behat`/`run`: is `tests/behat/composer.json` gewijzigd sinds de laatste installatie (bijv. een hernoemde
  namespace), dan worden de Behat-dependencies en de autoloader eerst bijgewerkt; anders vond Behat de contexts niet
  ("FeatureContext context class not found"). Vastgelegd met een hash van `composer.json` in `tests/behat/vendor/`.
- `validate`: de cache van de validatorregels (in de temp-map, gedeeld door alle modules) wordt atomair geschreven.
- Eigen tests: `LockTest` (wachten, melding, timeout, vrijgave na een crash, kindprocessen).

## [1.18.0] - 2026-10-22

### Toegevoegd
- `ps-module publish shop --update [--dry-run]`: werkt het bestaande product in de verkoopwinkel bij voor een nieuwe
  versie (gevonden op de referentie; zonder product stopt het met exitcode 1). Naam, korte en lange omschrijving,
  meta-teksten en vriendelijke URL komen in elke taal van de winkel opnieuw uit de module, zoals bij het aanmaken; de
  afbeeldingen worden vervangen: eerst de nieuwe uploaden (cover, dan de screenshots in de volgorde van `docs.json`),
  dan de teksten opslaan, dan de oude afbeeldingen weg en dan de eerste nieuwe als cover. Een mislukte upload of een
  geweigerde opslag haalt de nieuwe afbeeldingen weer weg: het product blijft zoals het was. De features
  (Multistore: Ja) staan er daarna zeker op. Prijs, belastingregel, categorieën, actief/inactief, voorraad, het bestand
  van het virtuele product, zichtbaarheid en referentie blijven staan: de webservice-PUT krijgt het volledige product
  terug, zonder de velden die de winkel bij een PUT weigert (`manufacturer_name`, `quantity`, `new`,
  `position_in_category` en wat de synopsis read-only noemt), zonder lege velden (anders wordt NULL 0) en van de
  associaties alleen `product_features` (PrestaShop laat de andere dan staan; 8.1 weigert een lege `product_bundle`);
  na afloop wordt vergeleken. Een DELETE van een afbeelding wordt gecontroleerd door het product terug te lezen
  (PrestaShop 8.1 verwijdert en antwoordt dan HTTP 500). Toont per tekstveld in hoeveel talen het verandert en legt de
  stap `shop` vast. Extra webservicerechten: `products` PUT en `images` DELETE; die worden vooraf gecontroleerd.
  Getest op PrestaShop 8.1.0 en 9.2.0.
- Eigen tests van het package (`vendor/bin/phpunit`, ook in CI): `publish shop --update` tegen een nep-webservice die
  PrestaShops PUT-regels nadoet.

## [1.17.1] - 2026-10-22

### Gewijzigd
- `publish shop` maakt geen dubbel product meer: heeft de winkel al een product met dezelfde naam (in een van de
  talen, bijv. met de hand gemaakt met een andere referentie), dan stopt het en zegt het welk product; zet daar de
  referentie op de technische modulenaam, dan herkent `publish shop` het voortaan. `--force` maakt toch een nieuw
  product.

## [1.17.0] - 2026-10-22

### Toegevoegd
- `ps-module publish shop [--dry-run]`: de module als product in de eigen verkoopwinkel, via de PrestaShop-webservice
  (`SALES_SHOP_URL` + `SALES_SHOP_WEBSERVICE_KEY`, optioneel `SALES_SHOP_CATEGORY_ID` en
  `SALES_SHOP_TAX_RULES_GROUP_ID`). Alleen op commando. Het product komt er één keer, altijd **inactief** (teruggelezen
  ter controle), als virtueel product met naam, korte en lange omschrijving, meta-teksten en vriendelijke URL in elke
  taal van de winkel (teksten uit `module.spec.json`, `i18n/<iso>.json` en `docs/content/<iso>/marketing.json`; een
  taal zonder teksten krijgt Engels), de prijs uit `docs.json > marketing`, de cover en de Engelse framed screenshots
  als afbeeldingen en de feature Multistore: Ja (`resources/publish/shop-features.json`, bestaande wordt hergebruikt).
  Een bestaand product (zelfde referentie) wordt nooit aangepast. De zip blijft handwerk: PrestaShop heeft geen API
  voor bestanden van virtuele producten. Getest op PrestaShop 8.1.0 en 9.2.0.
- Publicatie-checklist: stap `shop` (Verkoopwinkel); `publish done shop` legt vast dat de zip van een nieuwe versie met
  de hand in het product staat.

## [1.16.3] - 2026-10-21

### Gewijzigd
- Demowinkel, introductiepagina `/start/`: elke module krijgt een link naar zijn docs-site
  (`<DOCS_SITE_URL>/<module>/index.php`). Zonder `DOCS_SITE_URL` is dat het eigen domein van de winkel, en dan alleen
  voor modules waarvan `<module>/index.php` in de winkelroot staat (geen dode links).
- `publish docs` schrijft daarna de introductiepagina van de demowinkel opnieuw (`demo-site.sh settings intro`, als
  `DEMO_SITE_SSH_HOST` gezet is), zodat de link meteen verschijnt en niet pas na de nachtelijke reset. Lukt dat niet,
  dan is het een waarschuwing.

## [1.16.2] - 2026-10-21

### Opgelost
- Multistore-ronde, upgrade-test "keeps shop values" (MS-03): een configuratiesleutel die de nieuwe versie bewust
  verwijdert (niet meer in `ModuleDefinition`, en na de upgrade in geen enkele winkel, groep of globaal meer aanwezig)
  telt niet meer als verloren winkelwaarde. Blijven er toch rijen van zo'n sleutel achter, dan meldt de check dat
  (INS-04). Gevonden bij `heavenproductlistorder` 1.1.0, dat `..._STOCK_FIRST` vervangt door `..._GROUP_ORDER`.

## [1.16.1] - 2026-10-21

### Gewijzigd
- VPS-snapshots (TransIP): de oudste snapshot blijft altijd (het beginpunt); vóór een nieuwe gaat de meest recente
  weg. `TRANSIP_SNAPSHOT_KEEP` is standaard 2 (minimaal 2): de oudste + de nieuwste. Na het verwijderen wacht het tot
  de VPS vrij is.

### Opgelost
- `TRANSIP_PRIVATE_KEY` (en andere sleutels) in `.env` over meerdere regels, tussen aanhalingstekens, met `\n` of met
  verloren regeleinden (gekopieerd van een webpagina) wordt nu gelezen; lukt het niet, dan zegt de melding waarom
  (zonder de sleutel te tonen).
- Een verkeerde `TRANSIP_VPS_NAME` (de omschrijving in plaats van de technische naam) noemt de VPS'en van het account.

## [1.16.0] - 2026-10-21

### Toegevoegd
- `-v` / `--verbose` voor elk commando (overal op de regel, of `PS_MODULE_VERBOSE=1`): elke stap, elk gestart programma
  met map, exitcode en tijd, gelezen instellingen, en bij `publish`/`demo-site` de SSH-login, elke upload, elk
  servercommando en elk HTTP-verzoek; `demo-site.sh`, `demo-install.php`, `demo-settings.php` en
  `demo-server-check.sh` melden op de server dan ook elke stap (`VERBOSE=1`). Op STDERR, geheimen als `***`
  (`src/verbose.php`, `src/publish-sftp.php`).
- `COMMANDS.md`: alle commando's met al hun parameters, opties en standaardwaarden, ook die van `demo-site.sh` op de
  server.
- `demo-site check`: controleert de demoserver zonder iets te wijzigen (commando's, PHP-versie en -extensies, webserver,
  winkelmap, databasevariabelen via de eigen `parameters.php`, wat PHP achter de webserver ziet, of de server de eigen
  site bereikt).
- `demo-site install-prestashop [<versie>]`: installeert PrestaShop op de demoserver vanuit de lokale cache, met de
  database uit `.db_credentials` (alleen via een privébestand, nooit op een opdrachtregel), de taalpakketten van de
  lokale demo en zonder demoproducten; een mislukte poging kan opnieuw.
- Alle uitvoer van `ps-module publish`/`demo-site` wordt gemaskeerd: host, gebruiker, wachtwoorden, sleutels en de
  database-instellingen verschijnen als `***`.
- Demowinkel: mappen die niet bij de demo horen (docs-mappen met `.docs-site`, `.well-known`, `DEMO_SITE_KEEP_PATHS`)
  zitten niet in snapshots en blijven bij een reset staan; `publish docs` zet de `.docs-site`-markering.
- Demowinkel: de rooktest zoekt zelf een route als de server de eigen site niet bereikt (127.0.0.1, eigen adressen).
- Demowinkel: HTTPS achter een proxy (Cloudflare "Flexible", load balancer): `HTTPS=on` uit `X-Forwarded-Proto` /
  `CF-Visitor` bovenaan de `.htaccess`, en de inlog-redirect gebruikt het https-adres van de winkel.
- `DEMO_SITE_CRON_INSTALL=no`: de nachtelijke taak niet in crontab (bij Hestia/Plesk in het paneel inplannen).
- `demo-site vps-snapshot --dry-run` logt echt in bij TransIP (alleen-lezen) en toont de VPS en snapshots.

- Cloudflare: na `publish docs` en `publish demo` worden de PDF's en de statische bestanden van de module (`logo.png`,
  `views/**`) uit de Cloudflare-cache gehaald (`CLOUDFLARE_API_TOKEN` met Cache Purge + `CLOUDFLARE_ZONE_ID`); zonder die
  instellingen staan de adressen in de uitvoer om met de hand te purgen. Ze houden in elke versie hetzelfde adres, dus
  Cloudflare bleef het oude logo tonen.

### Gewijzigd
- Rooktest op de server: de route naar de eigen site wordt gezocht met `robots.txt` (statisch) en 30 s in plaats van
  de homepage en 10 s. Vlak na het legen van de cache duurde de homepage langer, waardoor geen route gevonden werd,
  de test via Cloudflare HTTP 000 gaf en een geslaagde deploy werd teruggedraaid.
- `demo-settings.php` schrijft elk bestand via een tijdelijk bestand + rename en stopt als dat niet lukt. Op de server
  hoorde `start/index.html` bij root (een commando als root); de introductiepagina bleef zo stil op een oude versie staan
  terwijl de uitvoer "geschreven" meldde. Nieuw: `demo-site settings intro` (alleen de introductiepagina).
- `demo-site.sh deploy` test de winkel ook vóór de install en draait alleen terug op pagina's die daarna stuk zijn:
  instellingen die met de hand veranderd zijn (bijv. vriendelijke URL's of demomodus uit) houden een module niet meer
  tegen; ze staan wel in de uitvoer.
- `publish demo` (`demo-site.sh deploy`) installeert of upgradet alleen die module in de winkel zoals hij is: geen
  restore van de laatste snapshot meer vooraf (die zette de hele winkel terug en overschreef wijzigingen op de server),
  en alleen de introductiepagina wordt herschreven (`DEMO_SETTINGS_ONLY=intro`), niet de talen, medewerker, logo's en
  inlogregels. Faalt de install of de rooktest, dan komen alleen de modulemap en de database van vlak daarvoor terug.
  Daarna nog steeds een nieuwe snapshot, zodat de nachtelijke reset de module houdt.
- SSH: is `*_KEY` geen leesbare sleutel en staat er een wachtwoord, dan wordt met het wachtwoord ingelogd.
- Foutmeldingen en droge runs noemen geen hostnamen of gebruikers meer.
- `demo-site.sh` gebruikt `mariadb`/`mariadb-dump` als die er zijn, en logt de databasenaam niet meer.

## [1.15.1] - 2026-10-20

### Opgelost
- `publish`/`demo-site`: alle inloggegevens (ook die van `.db_credentials`) worden vóór de eerste uitvoerregel gelezen,
  zodat het masker ze vanaf het begin kent; `localhost` en loopbackadressen worden niet meer gemaskeerd.
- `demo-install.php` zegt bij een fout welk deel niet klopt (zip niet gevonden, modulenaam, geen winkelmap).
- `publish demo`: de installatie vond de zip niet (relatief pad, terwijl het installatiescript in de winkelmap draait);
  `demo-site.sh deploy` maakt het pad nu absoluut.
- Demowinkel: het legen van `var/cache` faalde ("Directory not empty") als een bezoeker tegelijk de cache vulde, en
  brak dan een herstel halverwege af. De map wordt nu eerst in één stap opzij gezet en daarna verwijderd; kan dat niet
  (een koppelpunt, zoals het Docker-volume van de lokale demo: "Device or resource busy"), dan wordt hij ter plekke
  geleegd.
- `publish demo`: de foutmelding zei altijd dat de server de vorige snapshot had teruggezet, ook als dat niet zo was;
  nu verwijst hij naar de serveruitvoer, waarin `demo-site.sh` dat zelf meldt.
- Demowinkel: inloggen in de backoffice gaf een fout (Doctrine `NonUniqueResultException`): `demo-settings.php` maakte
  bij een tweede run een tweede medewerker met `demo@demo.com` (`Employee::employeeExists()` geeft in PS 9 een bool, geen
  id). Nu op id opgezocht; dubbele medewerkers worden verwijderd (ook na het terugzetten van een oude snapshot).

### Toegevoegd
- Demowinkel: de inlog staat altijd ingevuld, ook bij het rechtstreeks openen van de admin-URL (blok bovenaan
  `<admin>/.htaccess`, Apache). Het merk uit `branding/` van de werkruimte (`publish demo`, `demo-site setup|config`
  sturen `logo.svg` en `brand.json > logo_png` mee): winkellogo, logo in de backoffice-kop en op het inlogscherm, en
  de introductiepagina in de merkkleuren met het logo.

## [1.15.0] - 2026-10-20

### Toegevoegd
- `ps-module docs logo <bestand>`: het gekozen logo (MKT-07) wordt het logo van de module: `docs/content/logo.svg`
  (of `.png`; de doos-cover gebruikt het) en `<module>/logo.png`, 512 x 512 met een transparante achtergrond, gerenderd
  via Docker of Chrome. Bedoeld voor ná de keuze van de gebruiker en vóór `docs build`.

## [1.14.0] - 2026-10-20

### Toegevoegd
- `ps-module docs cover` (ook in `docs build`): de productdoos van het merk in perspectief met het logo en de naam van
  de module (MKT-08): `docs/marketing/cover.png` (transparant) + `cover-1000.png`. De doos komt uit de werkruimte
  (`branding/box/box.json`: sjablonen, naambalken, logovlak, gemeten hoekpunten); logo en naam worden met een CSS
  `matrix3d` op de echte perspectieffoto gezet, zodat licht en schaduw van de doos blijven. Opties per module in
  `docs.json > cover`: `name`, `logo`, `logo_area`, `bar` (`fit`|`full`), `stripes` (`under`|`none`|`over`), `enabled`.
- `docs init` neemt het hoofdframe van de werkruimte over (`branding/frame/`: merkkleuren en fonts), met de
  gebruikte fonts en afbeeldingen in `docs/frame/assets/`.
- De frames-renderer kan transparante PNG's maken (Docker: `omitBackground`, Chrome: transparante achtergrond).

## [1.13.1] - 2026-10-20

### Opgelost
- `ps-module docs` zag de map `docs/content/logo-suggestions/` aan voor een taal die niet in `i18n.json` staat
  (waarschuwing bij elke module met logo-suggesties).

## [1.13.0] - 2026-10-20

### Toegevoegd
- `ps-module publish`: de release-checklist per versie (REL-08). `status [--check]` toont welke stappen open staan;
  `docs` bouwt de docs-site (`dist/docs-site/<module>/`: `index.php` met taalkeuze + de PDF-handleiding van elke taal) en
  zet die via SFTP op de server (`DOCS_SITE_*`), met een controle van de live pagina; `demo` installeert of upgradet de
  gecontroleerde zip in de demowinkel via SSH (`DEMO_SITE_*`: `bin/console prestashop:module install|upgrade`,
  `cache:clear`, terug naar de vorige versie bij een fout); `done addons` vinkt de handmatige Addons-upload af; `site`
  bouwt alleen de map. `docs` en `demo` publiceren alleen een versie met tag (of in CI, of met `--force`).
- `ps-module demo-site`: de demowinkel (één PrestaShop met alle modules, ook buiten een module-repo):
  `setup` (gereedschap uploaden, `demo-site.conf`, demomodus, eerste snapshot, nachtelijke cron), `status`, `snapshot`,
  `restore`, `test`, `nightly`, `demo-mode on|off`, `push <snapshot>` (een lokaal gemaakte snapshot wordt die van de
  server) en `vps-snapshot`. Op de server: `resources/publish/demo-site.sh` (snapshots van bestanden + database,
  elke nacht terug + rooktest, deploy = schone winkel → install/upgrade → test → nieuwe snapshot, terug bij een fout;
  de server houdt zijn eigen `parameters.php`, domein en `.htaccess` volgen de URL), `demo-install.php` en
  `demo-settings.php` (e-mail uit, demo-medewerker, introductiepagina met links naar winkel en backoffice met de
  inlog ingevuld en de modules met hun handleiding).
- `ps-module publish vps-snapshot`: TransIP-snapshot van de hele VPS via de REST-API v6 (`TRANSIP_*`), automatisch na
  `publish docs`/`demo`/`demo-site push` (behalve met `--no-snapshot`). De sleutel staat nooit op de VPS.
- `--env=<bestand>`: instellingen van een ander doel (de lokale Docker-demo). `DEMO_SITE_*`, `DOCS_SITE_*` en
  `TRANSIP_*` komen dan alleen uit dat bestand.
- Logo's (MKT-07): `docs/content/logo-suggestions/*.svg` (meerdere suggesties) komen als PNG op het marketingblad;
  `docs check` waarschuwt bij minder dan drie suggesties of zonder `docs/content/logo-prompt.md`. Het oude losse
  `logo-suggestion.svg` werkt nog.
- `resources/languages.json`: `native`, de naam van elke taal in die taal (voor taalkeuzes).
- Nieuwe dependency `phpseclib/phpseclib` ^3.0 (SFTP/SSH in pure PHP, met wachtwoord of key).

### Gewijzigd
- `ps-module link` zoekt de map `libraries/` zelf (ook vanuit `modules/<repo>/<module>`: `../../../libraries/*`);
  `unlink` en `verify` herkennen elk aantal `../`. Het werkruimte-overzicht (`context`) leest de module-repo's in
  `modules/` en, voor de oude indeling, direct in de werkruimte.
- `ps-module build` zet alleen de Engelse handleiding in de zip (`docs/readme_en.pdf`; ander keuze via
  `docs/docs.json > zip_manuals`). Met alle talen werd de zip te groot voor uploads (33 talen: 45 MB); de handleidingen
  van alle talen staan op de docs-site (`ps-module publish docs`).

## [1.12.1] - 2026-10-20

### Opgelost
- `ps-module validator-api`: validator.prestashop.com neemt maximaal 30 MB aan (daarboven HTTP 413). Een zip met veel talen
  kwam daarboven door de handleiding-PDF's (33 talen: 45 MB). De validator krijgt nu een tijdelijke kopie zonder
  `docs/*.pdf` (geen code); de release-zip zelf houdt de PDF's. Is ook die kopie te groot, dan stopt het commando met een
  duidelijke melding in plaats van de HTML van de 413.

## [1.12.0] - 2026-10-19

### Toegevoegd
- `docs/docs.json > shop.setup`: een PHP-script in `docs/` dat `ps-module docs build` in de testwinkel draait nadat de
  module is geïnstalleerd en vóór de screenshots (met PrestaShop en de Symfony-kernel geladen, en `$languages`). Zo
  tonen de screenshots demodata die de module nodig heeft (bijv. een changelog bij een demoproduct): `docs build` zet de
  module voor de screenshots opnieuw neer, dus data die je vooraf in de winkel zet, verdwijnt. Een gewijzigd script
  maakt de screenshots verouderd; een fout in het script stopt de build. Nieuw commando `setup <bestand>` in
  `resources/docs/shop-probe.php`.

### Opgelost
- Screenshots in het Sloveens en Vietnamees: de testwinkel kreeg de code uit `i18n.json` (`sl`, `vi`) in plaats van
  PrestaShops eigen code (`si`, `vn`). De taal werd dan niet geïnstalleerd en de screenshots kwamen uit de brontaal.
  Installeren, de taal van de medewerker, `id_lang` en de taal in de URL gebruiken nu `ps_iso` uit `languages.json`.
- Een taal installeren in een testwinkel met 30+ talen liep vast op de geheugenlimiet van 512M (PrestaShop laadt de
  catalogi van alle talen). `resources/docs/shop-probe.php` draait nu met 2048M.

## [1.11.0] - 2026-10-19

### Toegevoegd
- Uitzondering voor JavaScript van derden (TST-27): scripts in `views/js/vendor/<bibliotheek>/` hoeven geen tests en
  tellen niet mee voor `ps-module assets` en de DoD-stap `assets`. `ps-module conventions` eist per bibliotheek een
  eigen map met het licentiebestand (`LICENSE`, `LICENCE` of `COPYING`). De `_dev/`-template van de skill (build.mjs,
  vitest.config.mjs, eslint.config.mjs) slaat de map over; werk `_dev/` van bestaande modules daarmee bij.
  JavaScript in een andere `lib/`-, `libs/`-, `vendor/`- of `vendors/`-map is een fout (de validator slaat daar de
  licentiecheck over, dus eigen code hoort er niet).

### Gewijzigd
- `ps-module validate` volgt de online validator voor JavaScript (getest met twee testzips op validator.prestashop.com):
  - geen licentieheader en geen structuurcheck in mappen `lib`, `libs`, `vendor` en `vendors` (ook `views/js/vendor/`,
    dat eerder helemaal werd overgeslagen);
  - `eval(` in JavaScript is een fout, ook in libraries van derden en comments;
  - een library die PrestaShop meelevert is een fout (was waarschuwing), alleen op exacte bestandsnaam met `included`;
  - JavaScript zonder licentieheader en JavaScript buiten `views/js/` zijn fouten (waren waarschuwingen).

## [1.10.0] - 2026-10-19

### Toegevoegd
- Testcontrole in `ps-module conventions` (`src/test-check.php`, conventies TST-04..07): elke klasse, trait en enum in
  `src/`, `controllers/` en `classes/` heeft een eigen gespiegeld testbestand, elke publieke/protected methode wordt
  erin aangeroepen, elke exception die de klasse gooit wordt verwacht (`expectException`), en elke command/query wordt in
  een integratietest gedispatcht. Alleen voor modules.
- `ps-module coverage` (TST-08): de unit-tests voeren elke regel van `src/Domain`, `src/Application` en
  `src/Presenter` uit (PCOV of Xdebug, Clover). Draait ook in `ps-module check`.
- JavaScript/TypeScript-tests (TST-20..26): `ps-module assets` draait lint, typecheck, Vitest met 100 % dekking per
  bestand en de build; `assets check` faalt bij gewijzigde bronnen, tests of `views/js`, en bij JS zonder `_dev/`-tests.
  De DoD-stap `assets` geldt voor elke module met eigen JS. Node 22.13+.

### Gewijzigd
- `ps-module phpstan` analyseert standaard ook `tests/Unit` en `tests/Integration`.

## [1.9.0] - 2026-10-18

### Toegevoegd
- Architectuurcontrole DDD + CQRS in `ps-module conventions` (`src/architecture-check.php`, conventies
  ARCH-01..15): de lagen in `src/` (Domain, Application, Infrastructure, presentatie), wie wat mag gebruiken
  (geen PrestaShop in Domain/Application, geen `\Db`/`\Configuration`/repositories in hooks en controllers),
  commands/queries/results final en immutable, handlers met één `handle()`, query-handlers schrijven niet, elke
  handler geregistreerd in `config/*.yml` en elke tag geldig, de bus aanwezig, en een unit-test per handler.
  Alleen voor modules (niet voor de libraries).
- `ps-module assets build|check|status`: TypeScript/npm-build in `<module>/_dev/` (esbuild), met een
  versheidscontrole (hash van bronnen en uitvoer) die ook zonder Node werkt; DoD-stap `assets` als de module
  `_dev/package.json` heeft; `verify` draait eerst `assets check`.

### Gewijzigd
- PHPStan draait per PrestaShop-versie met een eigen resultaatcache (`.cache/phpstan/<versie>`): voorheen hergebruikte
  de tweede versie de resultaten van de eerste voor ongewijzigde bestanden.
- Release-zip laat ook TypeScript- en build-configuratie weg (`*.ts`, `*.map`, `tsconfig*.json`, `build.mjs`,
  eslint/prettier-config, lockfiles van yarn/pnpm); `validate` en `index` slaan `_dev/` over.

## [1.8.0] - 2026-10-18

### Gewijzigd
- Framesjabloon per module: `docs/frame/frame.html` + `frame.css` (plus eigen afbeeldingen en fonts). `docs
  init` maakt het één keer aan met een beginstijl; daarna wordt het met de hand aangepast en schrijft geen
  tool er nog in. Elke frame-render gebruikt het. De kleuren staan nu in `frame.css` (`:root`), niet meer
  in `docs.json > frame`; `docs init` verhuist ze (en een `docs/content/frame.html`/`.css` uit 1.7).
- `docs.json > frame` houdt alleen instellingen: `width`, `height`, `caption`, `address`, `logo`,
  `counter`, `manual`, `enabled`, `engines`.

### Toegevoegd
- `ps-module docs frames --preview`: voorbeeldpagina's die de echte `frame.css` laden (bewerken, verversen).
- Het docs-stempel (definition of done) hoort ook bij `docs/frame/`: een aangepast sjabloon vraagt een
  nieuwe `docs build`.

## [1.7.0] - 2026-10-18

### Toegevoegd
- Screenshots in een frame: `ps-module docs frames` zet elke screenshot in het frame van de module, met het
  bijschrift in de taal op het frame (`docs/framed/<iso>/<id>.png`). Sjabloon `resources/docs/frame/`,
  stijl per module in `docs/docs.json > frame` (beginstijl uit `styles.json` via `docs init`), eigen CSS of
  sjabloon in `docs/content/frame.css` / `frame.html`, bijschriften in `docs/content/<iso>/screenshots.json`.
- `docs build` maakt de frames na de screenshots; handleidingen, marketingbladen (sectie
  "Product images") en `docs/index.html` gebruiken ze. `docs status` en de validatie controleren stijl,
  contrast, bijschriften en vertalingen; `docs mark-translated <iso> screenshots`.

## [1.6.0] - 2026-10-17

### Toegevoegd
- `ps-module git`: `status [--remote]`, `start <naam> [--fix]` (branch vanaf de bijgewerkte default
  branch, neemt wijzigingen mee), `pr` (push + pull request via `gh`, of merge request op GitLab via
  push-opties), `finish` (terug naar de default branch, pullen, branch alleen verwijderen als hij echt
  gemerged is, ook na squash), `later`.
- `gitflow_state()` / `gitflow_advice()` / `gitflow_signature()` voor de hooks van het project: welke vraag
  (branch, commit, push + PR, merge, opruimen) aan de beurt is, één keer per stand van de repo.

## [1.5.1] - 2026-10-17

### Opgelost
- Handleidingen en marketingbladen gebruiken per taal de vertaalde modulenaam (uit `i18n/<iso>.json`).
- `ps-module status` meldt als de online validator niet geconfigureerd is (geen API-sleutel gevonden),
  in plaats van stil "Complete" te geven.
- `ps-module context`: YAML-comments in `routes.yml` komen niet meer in het overzicht.

## [1.5.0] - 2026-10-17

### Toegevoegd
- `ps-module context`: `<repo>/CONTEXT.md` met Notes (door Claude) en een uit de code gegenereerd
  overzicht (identiteit, hooks met implementatie, configuratie, tabs, codekaart, routes, services,
  bestanden, database, vertalingen, docs). Weigert als de code veranderde maar de Notes niet.
  `--generate` en `--status`; `ps-module status` toont de contextstatus.
- `context_workspace()`: overzicht van alle modules en packages in een werkmap.

## [1.4.1] - 2026-10-17

### Opgelost
- Multistore-ronde: configuratievelden met een getal (NumberType/MoneyType renderen `type="text"`)
  krijgen numerieke testwaarden; veldfouten van het UI-kit-formulierthema worden gemeld.
- Testwinkels: Windows-junctions (`ps-module link`) worden ook gekopieerd als `is_dir()` ze niet herkent.
- `ps-module link`/`unlink` op Windows: geeft het `composer update`-commando om zelf te draaien, omdat
  Composer module-dev-tools niet kan vervangen terwijl `ps-module` draait.

## [1.4.0] - 2026-10-17

### Toegevoegd
- `ps-module translations`: teksten extraheren (PHP, Smarty, Twig), catalogus per taal in
  `<repo>/i18n/<iso>.json`, XLF voor PS 8 en 9, mails per taal, glossary met 33 talen, DeepL optioneel.
  `translations check` hoort bij `composer checks`.
- `ps-module docs`: handleidingen (HTML + PDF), Addons-marketingbladen en screenshots per taal in
  `<repo>/docs/`; definition-of-done-stap `docs` (verloopt bij code- en contentwijzigingen).
- Multistore-ronde in `ps-module shops run` (`testing.json: "multistore": true`) en
  `ps-module shops multistore <versie>`; probe met `module_shops`, `hook_shops`, `configuration_scopes`.
- `ps-module index`: `index.php` in elke map zonder, zonder `vendor/` of symlinks te volgen.
- `resources/languages.json` (33 talen) en `resources/addons/` (categorieën, voordelen, multistore).
- `ps-module validate`: `ps_versions_compliancy` max mag geen `_PS_VERSION_` zijn (MOD-02), de constructor
  moet `multistoreCompatibility = MULTISTORE_COMPATIBILITY_YES` zetten (MS-01), overrides geven een
  waarschuwing voor `own-clients`.
- `ps-module build`: handleiding-PDF's als `docs/readme_<iso>.pdf` in de zip; gescopete imports worden
  opnieuw gesorteerd (online validator: `ordered_imports`).

### Gewijzigd
- `ps-module verify`: check, validate, docs, build, validate-zip, shops, validator-api.
- De testwinkels kopiëren Windows-junctions (`ps-module link`) correct.
- Twig-licentieheaders in de stijl van header-stamp (`{# # …`) worden geaccepteerd.

## [1.3.0] - 2026-10-17

### Toegevoegd
- `ps-module link` / `unlink`: tijdelijk de lokale `../../libraries/*` gebruiken in plaats van Packagist.
- `ps-module verify` weigert zolang libraries gekoppeld zijn.

### Gewijzigd
- De testwinkels volgen gekoppelde (symlink/junction) mappen bij het kopiëren van de module.

## [1.2.0] - 2026-10-17

### Toegevoegd
- `ps-module validate`: het bestandscommentaar van elk PHP-bestand moet een `/**`-docblock zijn
  (de online validator keurde `/*` 246 keer af in een echte module).

### Gewijzigd
- `index.php`-bestanden krijgen ook de licentiecontrole, net als bij de online validator.

## [1.1.0] - 2026-10-17

### Toegevoegd
- Back-office rooktest in `ps-module shops run` (login, Module Manager, configuratiepagina, menu-links, productpagina).
- `ps-module shops deploy`; release-zip wordt in de testwinkels geïnstalleerd; overrides in de opruimcontrole.
- Validator: leest het echte rapportformaat van validator.prestashop.com, eist `.htaccess`, Addons-regel voor overrides.
- Build zonder `composer.lock` installeert zonder `require-dev`.
- CI- en release-workflows (Packagist-update bij elke release).

### Gewijzigd
- Cache van de testwinkel wordt na elke module-actie geleegd (PS 8.1-container).
- Jaarbereik in de licentieheader is een waarschuwing (de online validator meldt het niet).
- Docblocks op alle functies (DOC-01).

## [1.0.0] - 2026-10-17

### Toegevoegd
- Eerste versie.
