# prestashopheaven/module-dev-tools

Ontwikkeltools voor PrestaShopHeaven-modules. Installeer als `require-dev`; alles loopt via
één commando: `vendor/bin/ps-module` (in composer-scripts gewoon `ps-module`).

| Commando | Wat |
|---|---|
| `ps-module verify` | De volledige definition of done, in volgorde; stopt bij de eerste fout |
| `ps-module check` | `composer checks` (lint, cs, PHPStan, compat, conventies, tests) + stempel |
| `ps-module conventions` | Docblocks overal + schone hoofdklasse (DOC-01, MOD-01) + de DDD/CQRS-architectuur (ARCH-01..15) + tests (TST-04..07: elke klasse een eigen test, elke methode aangeroepen, elke exception verwacht, elke command/query in een integratietest) |
| `ps-module coverage` | De unit-tests voeren elke regel van `src/Domain`, `src/Application` en `src/Presenter` uit (TST-08; PCOV of Xdebug, Clover in `.cache/coverage/`). Draait ook in `ps-module check` |
| `ps-module phpstan [versies]` | PHPStan tegen elke PS-versie uit `tests/phpstan/config.json` |
| `ps-module validate [dir\|zip]` | Validator-precheck (regels live van validator.prestashop.com) |
| `ps-module build` | `../dist/<module>.zip`: no-dev vendor, gescopete packages, `index.php` overal |
| `ps-module validate-zip` | Validator-precheck van de release-zip |
| `ps-module validator-api` | Zip naar validator.prestashop.com (`PRESTASHOP_VALIDATOR_API_KEY` in `.env`) |
| `ps-module shops <cmd>` | Testwinkels in Docker: `doctor`, `run`, `up`, `down`, `integration`, `behat`, `upgrade`, `multistore` |
| `ps-module translations <cmd>` | Teksten van de module in elke taal uit `<repo>/i18n.json`: `extract`, `status`, `todo`, `apply`, `fill`, `auto` (DeepL), `accept`, `prune`, `mails-ok`, `build` (XLF), `check` |
| `ps-module context [--no-notes\|--generate\|--status]` | `<repo>/CONTEXT.md`: Notes (door Claude) + gegenereerd codeoverzicht; geïmporteerd door de `CLAUDE.md` van de repo |
| `ps-module git <cmd>` | Git-werkwijze (GIT-01..07): `status [--remote]`, `start <naam> [--fix]`, `pr` (push + PR via `gh` of GitLab-MR), `finish` (na de merge opruimen), `later` |
| `ps-module index` | `index.php` van de module in elke map zonder (niet in `vendor/`, volgt geen symlinks) |
| `ps-module docs <cmd>` | Handleidingen (HTML + PDF), marketingteksten (Addons) en screenshots per taal in `<repo>/docs/`: `init`, `status`, `check`, `screenshots`, `frames`, `manual`, `marketing`, `build`, `mark-translated`, `preview`, `doctor` |
| `ps-module status` | Welke stappen op de huidige code geslaagd zijn |
| `ps-module skip <stap> "<reden>"` | Stap die echt niet kan draaien (wordt gemeld) |
| `ps-module assets [status\|check\|build]` | JavaScript van de module (`<module>/_dev/`, `views/js/`): `build` = `npm ci` + `npm run build` (typecheck, lint, tests met 100 % dekking, esbuild bij TypeScript), `check` = bronnen, tests en `views/js` horen bij de laatste geslaagde run (zonder Node) + stempel |

Geslaagde stappen leggen een stempel vast in `<repo>/.cache/dod/` met een vingerafdruk van de
broncode. De Stop-hook van het project `C:/_GIT/_PrestaShopHeaven/MODULES` gebruikt die om de
definition of done af te dwingen. Een stempel met de hand zetten kan bewust niet.

## Scoping in de release-zip

`ps-module build` leest uit de composer.json van de module:

```json
"extra": {
  "prestashopheaven": {
    "scope-prefix": "PrestaShopHeaven\\Foo\\Vendor",
    "scope": ["PrestaShopHeaven\\ModuleKit", "Vanengers\\PrestaShopLibrary"]
  }
}
```

en zet die prefix voor de genoemde namespaces, in de module-code en in `vendor/`. Daarna draait
`composer dump-autoload`. Zo laadt elke module zijn eigen versie van gedeelde packages.
`ps-module shops run` installeert ook de echte zip in de testwinkels.

## JavaScript: tests en TypeScript (`ps-module assets`)

Voor elke module met eigen JavaScript in `views/js/` of een `<module>/_dev/package.json` (skill: optioneel deel
`js-tests`, recept `typescript`). `_dev/` bevat `package.json`, `build.mjs`, `vitest.config.mjs` en de tests in
`_dev/tests/`; met TypeScript ook de bronnen (`_dev/front/*.ts`, `_dev/back/*.ts`), die `build.mjs` met esbuild bundelt
naar een leesbare `views/js/<entry>.js` met de licentieheader. Zonder TypeScript zijn de scripts in `views/js/` met de
hand geschreven. `views/js/` staat in git, zodat de module zonder Node vanuit git werkt. Bibliotheken van derden in
`views/js/vendor/<bibliotheek>/` vallen erbuiten (geen tests, lint of dekking); `ps-module conventions` eist wel per
bibliotheek een eigen map met het licentiebestand (`LICENSE`, `LICENCE` of `COPYING`).

- `assets build`: `npm install` (zonder lock) of `npm ci` (lock nieuwer dan `node_modules`), dan `npm run build`:
  typecheck (`tsc`, ook de tests), lint (ESLint), tests met Vitest + jsdom en dekking (Istanbul) van **100 % per bestand**
  voor regels, statements, functies en branches, de bundel, en pas dan `_dev/build-manifest.json`. Vereist Node.js
  22.13+ en npm; zonder Node stopt het met een melding en de tip `ps-module skip assets "<reden>"`.
- `assets check`: vergelijkt `_dev/build-manifest.json` (hash van alles in `_dev/` zonder `node_modules` en `.cache`,
  plus `.license-header.txt`, een hash per script in `views/js/` en de geslaagde testrun; CRLF telt als LF) met de
  bestanden, in PHP. Exit 1 bij gewijzigde bronnen, tests of scripts na de laatste geslaagde run, bij een script in
  `views/js/` dat niet in die run zat, en bij JavaScript in `views/js/` zonder `_dev/package.json` (ongetest). Zit in
  `composer check` (`@assets`) en is de DoD-stap `assets`; `ps-module verify` draait hem als eerste.
- `ps-module build` laat `_dev/`, `node_modules/`, `package*.json`, `*.ts`, `tsconfig*` en build-configs uit de zip;
  `ps-module validate` en `ps-module index` slaan `_dev/` over.

## Vertalingen (`ps-module translations`)

- Talen: `<repo>/i18n.json` (`source: en` + `languages`); alle 33 beschikbare talen en hun
  PrestaShop-locale staan in `resources/languages.json`.
- Catalogus per taal: `<repo>/i18n/<iso>.json` (domein → Engelse tekst → vertaling; een vertaling
  als `{text, machine}` is nog niet nagekeken). Alleen de catalogus bewerken.
- `build` maakt de XLF-bestanden die PrestaShop 8 en 9 laden:
  `<module>/translations/<locale>/<DomeinZonderPunten>.<locale>.xlf`.
- `check` (in `composer checks`) faalt bij ontbrekende vertalingen, afwijkende placeholders,
  niet-letterlijke teksten, ontbrekende mailvertalingen of verouderde XLF.
- Generieke teksten met vertalingen in alle talen: `resources/translations/glossary.json` (`fill`).
- `auto` vult aan via DeepL als `DEEPL_API_KEY` in de omgeving of een `.env` staat; anders vertaalt
  Claude via `todo` + `apply`.

## Context voor Claude (`ps-module context`)

`<repo>/CONTEXT.md` wordt via `@CONTEXT.md` in de `CLAUDE.md` van de repo in elke sessie geladen, zodat
Claude minder hoeft te zoeken. Bovenaan de **Notes** (door Claude bijgehouden: doel, architectuur,
beslissingen, valkuilen, open punten, recente wijzigingen), onderaan een **gegenereerd overzicht**:
identiteit, hooks met hun implementatie, configuratie, tabs, codekaart (eerste docblock-zin + publieke
methodes), routes, services, bestanden, database, vertalingen en docs.

- `ps-module context`: overzicht verversen en vastleggen dat de Notes bij de huidige code horen.
  Weigert als de code veranderde maar de Notes niet (`--no-notes` als er echt niets te melden is).
- `--generate`: alleen het overzicht verversen (dat doet de Stop-hook van het project ook).
- `--status`: is alles actueel? (`ps-module status` toont het ook.)
- `context_workspace()` maakt het overzicht van een werkmap met meerdere modules (`MODULES/CONTEXT.md`).

## Documentatie en marketing (`ps-module docs`)

Bron (geschreven door Claude, per taal uit `i18n.json`): `docs/content/<iso>/manual.md` (Markdown met
front matter) en `docs/content/<iso>/marketing.json` (de velden van het Addons-productblad), plus
`docs/docs.json` (screenshots, categorie, prijs, voordelen, multistore). `ps-module docs build`
controleert alles (TODO's, lengtes, categorie/voordelen uit `resources/addons/`, versie gelijk aan de
module, vertalingen bijgewerkt na een wijziging in het Engels), maakt screenshots in de Docker-testwinkel
als de code veranderd is, en genereert `docs/manual/<iso>/manual.html` + PDF, `docs/marketing/<iso>/`
en `docs/index.html`. Daarna staat de stap `docs` in de definition of done.

- Demodata voor de screenshots: `docs/docs.json > shop.setup` noemt een PHP-script in `docs/` (bijv. `"setup": "shop-setup.php"`).
  `docs build` draait het in de testwinkel nadat de module is geïnstalleerd (en de talen en `shop.configuration`
  klaarstaan), vóór de screenshots, met PrestaShop en de Symfony-kernel geladen en `$languages` (iso => id, active,
  locale). Het script mag een korte melding teruggeven. Faalt het, dan stopt de build. Een gewijzigd script maakt de
  screenshots verouderd.
- Screenshots en PDF draaien in de image `prestashopheaven/docs-tools:<hash>` (Playwright/Chromium met
  Noto-fonts voor alle schriften), die bij het eerste gebruik lokaal gebouwd wordt uit
  `resources/docs/Dockerfile`.
- PDF-keten: Docker → lokale Chrome/Edge (headless) → wkhtmltopdf → dompdf (optioneel, alleen
  Latijn/Cyrillisch/Grieks). Lukt niets, dan blijft de printklare HTML staan en meldt `build` dat.
- `ps-module docs status` toont per taal precies welk bestand of veld ontbreekt of verouderd is;
  `ps-module docs preview <iso>` zet PDF-pagina's om naar PNG om het resultaat te bekijken.

### Screenshots in een frame (`ps-module docs frames`)

Elke screenshot wordt in het frame van de module gezet: een kader met het bijschrift in de taal erop
gedrukt, de screenshot in een browservenster, telefoon of tablet, en onderaan logo, naam en `1 / 4`.
Resultaat: `docs/framed/<iso>/<id>.png` (standaard 1600 x 1200, de productafbeeldingen voor Addons). De
handleidingen, de marketingbladen en `docs/index.html` gebruiken ze ook.

- **Sjabloon per module: `docs/frame/frame.html` + `frame.css`** (plus eigen afbeeldingen en fonts in
  `docs/frame/`, gebruikt met `url("x.png")` / `src="x.png"`; bij het renderen ingebed). `docs init` maakt
  het één keer aan uit `resources/docs/frame/` met een beginstijl uit `styles.json` (gekozen op de
  modulenaam). Daarna is het van de module: je past het met de hand aan, geen enkele tool schrijft nog in
  `docs/frame/`, en elke `docs build` / `docs frames` gebruikt het. Een nieuwere library verandert een
  bestaand sjabloon dus ook niet.
- Uitproberen: `ps-module docs frames --preview [<iso>] [--only=<id>]` schrijft pagina's in
  `.cache/docs/frame-preview/` die de echte `frame.css` laden: open er een in Chrome, pas `frame.css` aan,
  ververs. De echte afbeeldingen: `ps-module docs frames`.
- Instellingen in `docs/docs.json > frame`: `width`, `height`, `caption` (top/bottom, als body-class
  `caption-top|bottom`), optioneel `address`, `logo`, `counter`, `manual`, `enabled`, `engines`.
- Controle: het sjabloon heeft `{{css}}`, `{{image}}`, `{{title}}` en het script dat `dataset.ready` zet;
  geen externe bestanden; afbeeldingen moeten in `docs/frame/` staan; waarschuwing als `--text` op
  `--frame` minder dan 4.5:1 contrast heeft. Een wijziging aan het sjabloon maakt de docs-stempel ongeldig.
- Bijschriften: `docs/content/<iso>/screenshots.json`, `{"<id>": {"title": "..", "text": ".."}}`, vertaald
  zoals de handleiding (`docs mark-translated <iso> screenshots`).
- Rendert in de docs-tools-image, anders met een lokale Chrome/Edge; alleen wat veranderd is
  (`docs/framed/manifest.json`), `--force` voor alles.

## Gedeelde configuratie

- php-cs-fixer: `.php-cs-fixer.dist.php` →
  `return (require __DIR__ . '/vendor/prestashopheaven/module-dev-tools/resources/php-cs-fixer.php')(__DIR__);`
- Docker: `resources/docker/compose.yml` (MySQL 8, PrestaShop met dev-modus)
- Validatorregels (fallback): `resources/validator-rules.json`
