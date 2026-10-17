# Changelog

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
