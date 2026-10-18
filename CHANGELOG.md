# Changelog

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
