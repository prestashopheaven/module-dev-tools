# prestashopheaven/module-dev-tools

Ontwikkeltools voor PrestaShopHeaven-modules. Installeer als `require-dev`; alles loopt via
één commando: `vendor/bin/ps-module` (in composer-scripts gewoon `ps-module`).

| Commando | Wat |
|---|---|
| `ps-module verify` | De volledige definition of done, in volgorde; stopt bij de eerste fout |
| `ps-module check` | `composer checks` (lint, cs, PHPStan, compat, conventies, tests) + stempel |
| `ps-module conventions` | Docblocks overal + schone hoofdklasse (DOC-01, MOD-01) |
| `ps-module phpstan [versies]` | PHPStan tegen elke PS-versie uit `tests/phpstan/config.json` |
| `ps-module validate [dir\|zip]` | Validator-precheck (regels live van validator.prestashop.com) |
| `ps-module build` | `../dist/<module>.zip`: no-dev vendor, gescopete packages, `index.php` overal |
| `ps-module validate-zip` | Validator-precheck van de release-zip |
| `ps-module validator-api` | Zip naar validator.prestashop.com (`PRESTASHOP_VALIDATOR_API_KEY` in `.env`) |
| `ps-module shops <cmd>` | Testwinkels in Docker: `doctor`, `run`, `up`, `down`, `integration`, `behat`, `upgrade` |
| `ps-module status` | Welke stappen op de huidige code geslaagd zijn |
| `ps-module skip <stap> "<reden>"` | Stap die echt niet kan draaien (wordt gemeld) |

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

## Gedeelde configuratie

- php-cs-fixer: `.php-cs-fixer.dist.php` →
  `return (require __DIR__ . '/vendor/prestashopheaven/module-dev-tools/resources/php-cs-fixer.php')(__DIR__);`
- Docker: `resources/docker/compose.yml` (MySQL 8, PrestaShop met dev-modus)
- Validatorregels (fallback): `resources/validator-rules.json`
