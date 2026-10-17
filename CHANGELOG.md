# Changelog

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
