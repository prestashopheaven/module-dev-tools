# Commands

Every command of `prestashopheaven/module-dev-tools`, with all parameters, options and defaults. Run them in the
module folder (`modules/<repo>/<module>/`) as `vendor/bin/ps-module <command>`, or from a checkout of this package
as `php <path>/bin/ps-module <command>`. Composer scripts can call `ps-module` directly.

Contents:
- [Options for every command](#options-for-every-command)
- [Definition of done](#definition-of-done): `verify`, `check`, `coverage`, `conventions`, `phpstan`, `validate`, `build`, `validate-zip`, `validator-api`, `status`, `skip`, `fingerprint`
- [JavaScript](#javascript-assets): `assets`
- [Test shops](#test-shops-shops): `shops`
- [Translations](#translations): `translations`
- [Docs and marketing](#docs-and-marketing-docs): `docs`
- [Publishing](#publishing-publish): `publish`
- [The demo shop](#the-demo-shop-demo-site): `demo-site`
- [Git workflow](#git-workflow-git): `git`
- [Other](#other): `context`, `index`, `link`, `unlink`, `help`
- [Server scripts](#server-scripts): `demo-site.sh`, `demo-install.php`, `demo-settings.php`, `demo-server-check.sh`
- [Local demo shop](#local-demo-shop-demosh): `demo-site/demo.sh` (workspace)
- [Settings](#settings-env-and-environment): `.env` and environment variables
- [Exit codes](#exit-codes)

Notation: `<x>` is required, `[x]` is optional, `a|b` means one of them, `...` means repeatable.

## Options for every command

| Option | What it does |
|---|---|
| `-v`, `--verbose` | Anywhere on the line, e.g. `ps-module -v publish demo` or `ps-module shops run -v`. The same as `PS_MODULE_VERBOSE=1` (also `true`, `yes`, `on`). Reports every step on **STDERR** with a time stamp (`[v 12:34:56] ...`): the settings read, every program started (command line, folder, exit code, time), files written, HTTP requests (URL, status, time), and for server commands the SSH login, every upload, remote command and delete. On the demo server, `demo-site.sh` and its helpers report their steps too. Secrets show as `***`. Normal output (STDOUT) and exit codes stay the same, so `--json` output can still be piped. |

## Shared resources: one at a time

Several modules can be worked on at the same time (other terminals, other Claude sessions). Commands that use
something the modules share wait for each other instead of getting in each other's way:

| Lock | Taken by |
|---|---|
| `demo-server` | `publish docs`, `publish demo`, `publish vps-snapshot`, every `demo-site` command (the docs site, the demo shop and the VPS are one server). With `--env=<file>` (another server, e.g. the local Docker demo shop) the lock has a name of its own. |
| `sales-shop` | `publish shop` (also `--update`) |
| `shops-<module>` | every `shops` command except `doctor`, and the screenshots of `docs` (the same Docker shops) |
| `docs-image` | building the docs-tools Docker image |

A dry run (`--dry-run`) does not wait. A waiting command says every minute who holds the lock (command, module,
process, since when) and goes on as soon as it is free; after `PS_MODULE_LOCK_TIMEOUT` seconds (default 3600) it
stops with exit 1. The locks are files in `MODULES/.cache/locks/` (the nearest folder upwards with
`libraries/module-dev-tools` or a `.env`; otherwise the system temp folder; `PS_MODULE_LOCK_DIR` sets another one).
The operating system releases a lock when its process ends, also after a crash, so nothing is ever left behind. A
command started by a command that holds a lock (e.g. the `shops` commands of `docs build`) does not wait for it.
Different modules have their own test shops, ports and files: their `shops run`, `verify` and `docs build` run side
by side. `shops up` stops with a clear message when another container already uses the port of the shop.

Commands marked *(module)* must run inside a module (a folder with `<name>.php` and `composer.json`) and exit 2
elsewhere. Commands marked *(repo)* need a module repository (`module.spec.json`).

## Definition of done

### `ps-module verify` *(module)*
The full definition of done in order; stops at the first failure.
1. `assets check`, when the module has JavaScript.
2. `check`.
3. `validate`.
4. `docs build`, when the module has docs.
5. `build`.
6. `validate-zip`.
7. `shops run`, when `testing.json` exists.
8. `validator-api`, when a key is set.
9. `status`.

Refuses (exit 1) while local libraries are linked (`ps-module unlink` first). No options.

### `ps-module check` *(module)*
Runs `composer run-script checks` (lint, code style, PHPStan, PHP compatibility, conventions, translations, unit
tests), then `coverage`. When both pass, it records the stamp `check`. No options.

### `ps-module coverage` *(module)*
The unit tests must execute every line of `src/Domain/`, `src/Application/` and `src/Presenter/` (TST-08).
- **Runs:** `phpunit --testsuite unit` with a Clover report at `.cache/coverage/clover.xml`.
- **Needs:** `vendor/bin/phpunit`, `tests/Unit/`, and the PCOV or Xdebug extension.
- **Exit codes:** 0 complete; 1 lines not run, tests failing or no `tests/Unit`; 2 no coverage driver or no phpunit.

### `ps-module conventions [--with-tests]`
Docblocks everywhere and a clean main class (DOC-01, MOD-01), the DDD + CQRS architecture (ARCH), and the tests rules
(TST-04..07). Works outside a module too (checks the current folder).
- `--with-tests`: also check `tests/` (skipped by default).
- Always skipped: `vendor`, `node_modules`, `.cache`, `dist`, `.git`, `index.php`, `.php-cs-fixer*`.
- Exit codes: 0 ok, 1 violations, 2 not a folder.

### `ps-module phpstan [<ps-version>...]`
PHPStan against each PrestaShop version.
- **Versions:** default all `versions` of `tests/phpstan/config.json`. With the environment variable `_PS_ROOT_DIR_`,
  it runs once against that installation and ignores version arguments.
- **PrestaShop installs:** in `PS_CACHE_DIR` (environment) or `cache_dir` (config.json); fetch one with
  `php .claude/skills/ps-module/scripts/fetch-prestashop.php <version>`.
- **Paths:** config.json `paths`, else those that exist of `<module>.php src controllers classes upgrade override
  tests/Unit tests/Integration`.
- **Config:** `tests/phpstan/phpstan.neon`; result cache per version in `.cache/phpstan/<version>/`.
- **Exit code:** 1 when any version fails or a PrestaShop install is missing.

### `ps-module validate [<dir|zip>]` *(module)*
Validator pre-check with the live rules of validator.prestashop.com (cached; bundled rules as fallback).
- `<dir|zip>`: default the module folder. Only the first argument is used.
- Stamps `validate-src` for the source folder, and `validate-zip` for a zip with a fingerprint (`<zip>.json`).
- `--json` works only when calling the script directly: `php src/validate.php <target> --json`.
- Exit codes: 0 no errors (warnings allowed), 1 errors, 2 usage error or unreadable zip.

### `ps-module build` *(module)*
Builds `<repo>/dist/<module>.zip`: no-dev vendor, scoped packages, `index.php` in every folder, the manual PDF(s).
- **Writes:** the zip, plus `<zip>.json` (fingerprint, version, build time). Prints the zip path.
- **Manuals:** `docs/docs.json > zip_manuals`. Default: English when `docs/manual/en/readme_en.pdf` exists, else the
  source language of `i18n.json`.
- **Scoping:** `composer.json > extra.prestashopheaven.scope` and `scope-prefix`.
- **Exit code:** 1 when Composer fails.

### `ps-module validate-zip` *(module)*
The same as `validate <repo>/dist/<module>.zip`. No options.

### `ps-module validator-api [<dir|zip> [--compat=<majors>] [--json]]` *(module)*
Uploads the zip to validator.prestashop.com and reports the result.
- `<dir|zip>`: default `<repo>/dist/<module>.zip`. A folder is built first. Options only work after an explicit
  target (`ps-module validator-api ../dist/x.zip --json`).
- `--compat=8,9`: PrestaShop majors. Default: from `ps_versions_compliancy` (min..max within 8..9; 9 without a min).
- `--json`: also print the raw answer.
- **Settings:** `PRESTASHOP_VALIDATOR_API_KEY` (required, environment or `.env`), `PRESTASHOP_VALIDATOR_URL` (default
  `https://validator.prestashop.com`).
- **Upload:** a copy without the PDFs in `docs/`, because the validator takes at most 30 MB.
- **Writes:** `<zip without .zip>.validator.json`, and the stamp `validator-api` when the zip has a fingerprint.
- **Exit codes:** 0 no errors, 1 errors, 2 request or setup failed.

### `ps-module status [--json]` *(repo)*
Which definition-of-done steps passed on the current code, plus whether `CONTEXT.md` is current.
- `--json`: machine-readable.
- Exit codes: 0 complete, 1 not complete.

### `ps-module skip <step> "<reason>"` *(repo)*
Marks a step that cannot run (reported to the user). Steps: `assets`, `check`, `validate-src`, `validate-zip`,
`shop-tests`, `docs`, `validator-api`. Exit 2 without step or reason.

### `ps-module fingerprint` *(repo)*
Prints the fingerprint of the module source (what the stamps compare against).

## JavaScript (`assets`)

### `ps-module assets [status|check|build]` *(module)*
JavaScript of the module (`<module>/_dev/`, `views/js/`). Default `status`.
- `status`: what is there and whether it matches the last passing build. Always exit 0.
- `check`: sources, tests and `views/js` match the last passing run (no Node needed). Records the stamp `assets`;
  exit 1 on problems.
- `build`: runs these in `_dev/`, then checks and records the stamp `assets`:
  1. `npm install --no-audit --no-fund` when there is no `package-lock.json`.
  2. `npm ci --no-audit --no-fund` when `node_modules` is older than the lock file.
  3. `npm run build`: typecheck, lint, tests with 100 % coverage, and the bundle when the module uses TypeScript.

  Needs Node.js 22.13+ and npm.
- No JavaScript at all: every command prints "nothing" and exits 0.

## Test shops (`shops`)

`ps-module shops <command> ...` *(module)* runs PrestaShop test shops in Docker, using the settings in
`<repo>/testing.json`.
- **Versions:** `<version>` is `X.Y.Z`.
- **Port:** `base_port` (default 8000) + major×100 + minor×10 + patch. For example, 9.2.0 runs on
  http://localhost:8920.
- **Back office:** `/admin-dev/`, login `demo@prestashop.com` / `prestashop_demo`.
- **Compose project:** `<module>-ps<version without dots>`.
- **Flags:** unknown `--` flags are ignored.

| Command | What it does |
|---|---|
| `doctor` | Checks the docker CLI, the Docker daemon and `docker compose`. |
| `run [<version>...] [--keep] [--skip-upgrade] [--skip-behat] [--skip-integration] [--multistore\|--skip-multistore]` | The full shop test (below). |
| `up <version>` | Starts the shop (or reuses a running one), deploys the current code and installs the module. Prints the shop and admin URL. |
| `deploy <version>` | In a running shop: uninstall, copy the current code, install. |
| `integration <version>` | PHPUnit `--testsuite integration` inside the running shop. |
| `behat <version>` | Behat from `<repo>/tests/behat` against the running shop. Runs `composer install` there first when needed. |
| `upgrade <version>` | Installs each released version (tags `vX.Y.Z` below the current version) and upgrades it to the current code. `upgrade_from: previous` uses only the last tag. |
| `multistore <version> [--skip-upgrade] [--skip-integration]` | Only the multistore round. |
| `down [<version>\|all]` | Stops and removes the shop(s) with their data. Default `all` (every version in `testing.json`). |
| `help` | This list. |

**`run` in detail:**
- **Versions:** default all `versions` of `testing.json`. The release zip is built first.
- **Steps per version:**
  1. Up, deploy, install, check the installed state, smoke test the front and back office.
  2. Integration tests (`suites.integration`, unless `--skip-integration`).
  3. Behat (`suites.behat`, unless `--skip-behat`).
  4. Uninstall, check nothing is left, reinstall and compare.
  5. The same with the release zip.
  6. Upgrades (`upgrade_from` not `none`, unless `--skip-upgrade`).
  7. The multistore round (`"multistore": true` or `--multistore`, unless `--skip-multistore`).
  8. `down`, unless `--keep`.
- **Stamp:** `shop-tests`, only when all versions ran without a skip option and everything passed.

**`testing.json`:**

| Key | Values (default) |
|---|---|
| `module` | the module name |
| `versions` | the PrestaShop versions to test |
| `suites.integration` | `true` / `false` |
| `suites.behat` | `true` / `false` |
| `upgrade_from` | `all`, `previous` or `none` |
| `db_image` | Docker image of the database (`mysql:8.0`) |
| `base_port` | first port (`8000`) |
| `multistore` | `true` / `false` |

Scripts that run inside a test shop (started by `shops`, not by hand):
- **`ps-probe.php <module> <CONFIG_PREFIX>`:** prints the installed state of the module as JSON: hooks, configuration,
  tabs, overrides, tables.
- **`ps-multistore-shop.php <command> ...`:** commands `setup`, `isolation <module>`, `reset <module>`,
  `keep-check <module>`, `delete-shop <module>`, `find-value <module> <value>`, `enable <module> <id_shop>` and
  `disable <module> <id_shop>`. It prints one JSON line with `ok` and `problems`.

## Translations

`ps-module translations <command> [args]` *(module)*: the texts of the module in every language of `<repo>/i18n.json`.
- **Options:** `--name` or `--name=value`.
- **`<iso>...`:** target languages from `i18n.json`. None, or `all`, means every target language; an unknown language
  exits 2.

| Command | What it does |
|---|---|
| `extract [--json]` | The texts per domain; calls PrestaShop cannot extract are errors (exit 1). |
| `status [--json]` | Per language: what is missing, unreviewed (machine) or broken. |
| `todo [<iso>...] [--with-machine] [--out=<file>]` | JSON skeleton of the missing texts, to fill in and `apply` (stdout, or `--out`, relative to the module folder). `--with-machine`: also the unreviewed machine translations. |
| `apply <file\|-> [--machine[=<engine>]]` | Merges a filled-in todo file into the catalogues (`-` = stdin), then `build`. `--machine`: mark them as machine translations (unreviewed). Exit 1 when a text is rejected, 2 on bad input. |
| `fill` | Fills missing texts from the shared glossary, then `build`. |
| `auto [<iso>...]` | Fills missing texts with DeepL, marked "machine". Needs `DEEPL_API_KEY`; `DEEPL_API_URL` overrides the endpoint. |
| `accept <iso>...\|all` | Marks machine translations as reviewed. |
| `prune` | Removes catalogue texts that are no longer in the code. |
| `mails-ok <iso>...\|all` | Confirms `mails/<iso>/` is translated from the current `mails/en/`. |
| `build` | Writes `translations/<locale>/<Domain>.<locale>.xlf` (what PrestaShop 8 and 9 load) + `index.php`. |
| `check` | Fails (exit 1) on missing, broken or unbuilt translations (part of `composer checks`). |
| `help` | This list (also without a command). |

## Docs and marketing (`docs`)

`ps-module docs <command>` *(module)*: manuals (HTML + PDF), Addons marketing texts, screenshots, framed screenshots
and the box cover in `<repo>/docs/`.
- **Arguments:** words starting with `--` are options; others are positional. Unknown options are ignored.
- **`docs/docs.json`:** every command except `init`, `doctor` and `help` needs it.
- **Languages:** `<iso>...` are languages of `i18n.json`; others are dropped. Default: all.

| Command | What it does |
|---|---|
| `init` | Creates `docs/` with `docs.json`, content for every language and the frame template. Keeps existing files. |
| `status [--json]` | What is complete per language. Exit 1 on content errors. |
| `check` | Validates content and settings. Exit 1 on errors (warnings only: 0). |
| `screenshots [--keep] [--only=<id,...>]` | Takes the screenshots in a Docker shop. `--only`: these screenshot ids. `--keep`: leave the shop running. |
| `manual [<iso>...] [--no-pdf]` | Manuals as HTML and PDF. `--no-pdf`: HTML only. |
| `frames [<iso>...] [--only=<id,...>] [--force] [--preview]` | Screenshots in the frame template (`docs/frame/`). `--force`: render unchanged frames too. `--preview`: a quick preview of the source language in `.cache/docs/frame-preview/`. |
| `marketing [<iso>...]` | Addons marketing texts. |
| `cover [--force] [--name=<text>] [--logo=<file>] [--logo-area=x0,y0,x1,y1] [--bar=fit\|full] [--stripes=under\|none\|over] [--out=<file>]` | The box cover from the box template. Defaults below the table. Any override implies `--force`. |
| `logo <file>` | Makes the chosen logo (SVG or PNG; relative to `docs/` or the repo) the module logo `<module>/logo.png`. |
| `build [--no-screenshots] [--screenshots] [--keep]` | Everything: screenshots (only when missing or out of date; `--screenshots` forces a retake, `--no-screenshots` skips them), frames, manuals, PDFs, marketing. Records the stamp `docs`. |
| `mark-translated <iso\|all> [manual\|marketing\|screenshots]` | Records that a translation matches the current English text. Default: all three kinds. `all` = every language except the source. |
| `preview [<iso>] [--pages=<first>-<last>]` | PNG previews of PDF pages. Default: the source language, pages `1-3`. |
| `doctor` | Which PDF and image engines are available. |
| `help` | This list. |

**`cover` defaults:**
- **Name:** the technical name without "heaven", in capitals.
- **Logo:** `content/logo.svg|png`, else the first logo suggestion.
- **Other:** `--bar=fit`, `--stripes=under`, the logo area from the template, output `docs/marketing/cover.png`.

**Settings:**
- **Environment:** `PS_DOCS_CHROME` (path to Chrome/Edge), `PS_DOCS_BOX` (path to a `box.json` for the cover).
- **`docs.json`:**
  - `pdf.engines` (default `docker`, `chrome`, `wkhtmltopdf`, `dompdf`)
  - `frame.engines` (default `docker`, `chrome`)
  - `shop.version`, `install_languages`, `configuration`, `setup`, `hide`
  - `zip_manuals`

## Publishing (`publish`)

`ps-module publish <command> [options]` *(repo)*: the release checklist per version (REL-08). `docs` and `demo` only
publish a released version: the tag `v<version>` must exist, unless it runs in CI or with `--force`.

| Command | What it does |
|---|---|
| `status [--check]` | The checklist of the current version: Addons upload, docs site, demo shop, sales shop. `--check`: also ask the docs site which version it shows. Exit 0 when all are done. |
| `site` | Builds `dist/docs-site/<module>/`: `index.php` + `readme_<iso>.pdf` of every language (from `docs build`) + `flag-<country>.svg`, the flag shown next to each language. |
| `docs [--dry-run] [--force] [--no-snapshot]` | Builds the site and uploads it by SFTP to `<DOCS_SITE_SFTP_PATH>/<module>/`, removes stale files there, then checks `<DOCS_SITE_URL>/<module>/index.php`. With `DEMO_SITE_SSH_HOST` set it then rewrites the intro page of the demo shop (`settings intro`), which links the docs site of every module. Afterwards a TransIP VPS snapshot, unless `--no-snapshot`. |
| `demo [--dry-run] [--force] [--no-snapshot]` | Puts `dist/<module>.zip` in the demo shop (below). Needs the checked zip of the current code (`build` + `validate-zip`), except in CI. Afterwards a VPS snapshot, unless `--no-snapshot`. |
| `vps-snapshot [<description>] [--dry-run]` | A TransIP snapshot of the whole VPS. `--dry-run` logs in read-only and lists the VPS and its snapshots. |
| `shop [--dry-run] [--force]` | Creates the module as a product in your own sales shop through the PrestaShop webservice (below). Only on command, never in CI. |
| `shop --update [--dry-run]` | New version: replaces the texts and images of the existing product (found by its reference) and makes sure of its features; price, tax rule, categories, active state, stock, the virtual file, visibility and reference stay (below). Exit 1 when the shop has no product with the module's reference. |
| `done addons\|shop` | Records a manual step: the zip of this version is uploaded to PrestaShop Addons (Addons has no upload API), or put in the sales shop product by hand. |
| `undo <step>` | Forgets a recorded step (`addons`, `docs`, `demo`, `shop`) of the current version. |
| `help` | This list. |

**`demo` steps on the server:**
1. Copy the module folder and the database aside (for a rollback).
2. Install or upgrade the module in the shop as it is. There is no restore: the other files, modules and settings
   stay as they are.
3. Rewrite the intro page (its list of modules) and run the smoke test. The test also runs before the install; only
   pages that broke since then count, so problems the shop already had (settings changed by hand) do not stop it.
4. Make a new snapshot, so the nightly reset keeps the module. The snapshot holds the shop as it is at that moment.

On a failure, only the module folder and the database go back to how they were before the deploy.

Afterwards (on the local side) the module's static files (`logo.png`, `views/**`) are purged from the Cloudflare cache
when `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` are set; otherwise their addresses are printed. They keep their
address in every version, and the server lets a CDN cache them for years. `publish docs` does the same for the PDFs.

**`shop`: the product in the sales shop.**
- **Once per module.** Without `--update`, a product whose reference is the module's technical name is never
  changed: the command says it exists and stops. For a new version: `--update` (below), the zip in the product by
  hand, then `publish done shop`.
- **No duplicates.** A product with the same name (any language) but another reference, e.g. made by hand, stops the
  command too: set that product's reference to the module's technical name, or use `--force` to create a new one.
- **Always inactive.** The product is created with `active=0` and read back; you activate it yourself. A shop whose
  webservice does not know `active` gets no product at all.
- **What goes in:**
  - name and short description: `display_name` and `description` of `module.spec.json`, translated from
    `i18n/<iso>.json`;
  - description: `docs/content/<iso>/marketing.json` (`description` as paragraphs, then `features` as a list);
  - meta title, meta description and friendly URL (Latin scripts transliterated, other scripts get the English URL);
  - price without tax: `docs.json > marketing > price`;
  - virtual product, category `SALES_SHOP_CATEGORY_ID` (2), tax rule `SALES_SHOP_TAX_RULES_GROUP_ID` (none);
  - images: `docs/marketing/cover-1000.png` (the cover), then `docs/framed/en/<id>.png` in the order of
    `docs.json > screenshots`;
  - features from `resources/publish/shop-features.json` (Multistore: Yes); an existing feature or value with the same
    name in any language is reused.
- **Languages:** every language installed in the shop. It gets the module's texts in that language (matched on
  PrestaShop's iso code, `si`/`vn` too, else on the language code: `pt-br` gets `pt`), otherwise English.
- **By hand afterwards:** the zip in the Virtual product tab (PrestaShop has no API for virtual product files), the tax
  rule, stock, and activating the product.
- **`--update`: a new version of an existing product.** Finds the product by its reference (none: exit 1, run
  without `--update` first) and replaces what comes from the module repository; nothing else changes.
  - Texts: name, short description, description, meta title, meta description and friendly URL in every shop
    language, built exactly as on creation. A changed name gives a new friendly URL (no redirect from the old one).
  - Images: the new set is uploaded first (cover, then the screenshots in `docs.json` order), then the texts are
    saved, then the old images are removed, and then the first new image is made the cover. A failed upload or a
    refused save removes the images of that run again: the product stays as it was. An old image that cannot be
    removed is listed as a manual step.
  - Features: as on creation (Multistore: Yes); another value of the same feature on the product is replaced, other
    features stay.
  - Kept as they are: price, tax rule, categories, active state, stock, the virtual product file, visibility and
    reference. A webservice PUT replaces the whole product, so the command reads the full product, changes only the
    texts, features and cover, leaves out the fields the shop refuses on PUT (`manufacturer_name`, `quantity`, `new`,
    `position_in_category` and every field its synopsis marks read-only) and the empty ones (they stay NULL instead of
    becoming 0), and sends the rest back as it was. Of the associations only `product_features` is sent: the shop
    leaves categories, combinations, images and stock alone when they are not in the PUT. Afterwards it reads the
    product again and warns when one of those fields differs.
  - Before anything is written it checks that the key has `products` PUT and `images` POST + DELETE (exit 1 when not).
  - Prints per text field in how many languages it changes, the images and the manual steps (the zip, checking,
    activating), and records the step `shop`. `--dry-run` only reads from the shop and shows what would change.
- **Images per language** (create and `--update`): when the shop has the module Language-Specific Product Images
  (`heavenlangimages`, webservice resource `heavenlangimages_images` with GET + PUT for the key), every screenshot comes
  as one frame per shop language: `docs/framed/<iso>/<id>.png` of the module language of that shop language, English
  for a shop language without its own frame. After the upload each image gets its shop languages and its caption (the
  frame title of `docs/content/<iso>/screenshots.json`; for the cover the product name, every language) through that
  resource. A refused language PUT leaves that image for every language and is listed as a manual step. Without the
  resource (or without the permission) the images are the cover and the English frames for every language, as before;
  the plan says why.
- **Webservice key** (Advanced Parameters > Webservice), permissions: `languages` GET; `products` GET + POST;
  `images` POST; `product_features` and `product_feature_values` GET + POST. `--update` also needs `products` PUT and
  `images` DELETE. Images per language: `heavenlangimages_images` GET + PUT.

Options for all `publish` and `demo-site` commands:
- `--env=<file>`: read the settings from this file first (e.g. `demo-site/local.env`). Server settings
  (`DEMO_SITE_*`, `DOCS_SITE_*`, `TRANSIP_*`, `CLOUDFLARE_*`, `SALES_SHOP_*`) then come only from that file, never
  from `.env`.
- `--dry-run`: show what would happen; nothing changes.
- `--force`: publish a version without a release tag.

Recorded steps: `<repo>/.cache/publish/<version>.json`.

## The demo shop (`demo-site`)

`ps-module demo-site <command> [args] [--env=<file>] [--dry-run]`: the demo shop itself (one shop for all modules).
- **Where:** also works outside a module, e.g. in `MODULES/demo-site`.
- **Target:** without `--env`, the live server from `MODULES/.env`; `./demo.sh` uses the local copy.
- **How it runs:** most commands upload the current server scripts, then run that command of `demo-site.sh` over SSH.

| Command | What it does | Changes the shop |
|---|---|---|
| `check` | What the server has and lacks: commands, PHP version + extensions, web server, shop folder, database settings, whether the server reaches its own site, what PHP sees behind the web server. | no |
| `install-prestashop [<version>]` | Installs PrestaShop once. Default version: `DEMO_SITE_PS_VERSION` or 9.2.0. Uses the local cache (`.cache/prestashop/<version>`), the database from `.db_credentials` and the language packs of the local demo, and installs no demo products. Refuses when PrestaShop is already installed; a failed attempt can run again. | yes |
| `setup` | Uploads the tools, writes `demo-site.conf`, then: demo mode on (unless `DEMO_SITE_DEMO_MODE=off`), settings, first snapshot, cron job, test, status. | yes |
| `config` | Rewrites `demo-site.conf` from the settings and applies it (settings + cron), without a snapshot. | yes (no snapshot) |
| `status` | Latest snapshot, number of snapshots, last test, cron line. | no |
| `snapshot [<note>]` | A snapshot of the files and the database now. It becomes "latest", which the nightly reset restores; the newest `KEEP` are kept. | snapshots only |
| `restore [<snapshot>]` | Puts a snapshot back (default `latest`). | yes |
| `test` | Smoke test: home page per language, `TEST_PATHS`, intro page, back-office login. | no |
| `nightly` | `restore latest` + `test` (what the cron job runs). | yes |
| `demo-mode on\|off` | PrestaShop's demo mode (`_PS_MODE_DEMO_`). Keep it **on** on the live demo. | yes |
| `settings [intro]` | The fixed demo settings again (e-mail off, demo employee, login filled in, brand, intro page). With `intro`: only the intro page (its list of modules), the rest of the shop stays as it is. | yes |
| `push <snapshot-dir>` | Uploads a snapshot made elsewhere (local: `demo-site/server/snapshots/latest`) and makes it the server's latest: restore + test, the previous one back on a failure. Then a VPS snapshot. | yes |
| `apply <dir> [<note>] [--no-snapshot]` | Uploads a folder with `apply.php` (e.g. the test data of all modules: `MODULES/test-site`, `php test-site/test-site.php demo`) and runs it in the shop as it is: test before and after, the database back on a failure, else a new demo snapshot. Then a VPS snapshot (unless `--no-snapshot`). | yes |
| `vps-snapshot [<description>]` | The same as `publish vps-snapshot`. | VPS snapshot |

## Git workflow (`git`)

`ps-module git <command>` *(module)*: feature branches, commits, pull/merge requests, merging, cleaning up (GIT-01..07).

| Command | What it does |
|---|---|
| `status [--remote] [--json]` | Default. The state of the repo and the next question to ask. `--remote`: also look at the remote. |
| `start <slug> [--fix]` | New branch `feature/<slug>` (`fix/<slug>` with `--fix`); keeps uncommitted work. The slug comes first. |
| `pr` | Pushes the branch and opens a pull request (`gh`) or GitLab merge request. |
| `finish` | After the merge: back to the default branch, pull, delete the branch. |
| `later` | The user said "not now": don't ask again until the state changes. |

Exit 2 when not in a git repository or with an unknown command.

## Other

| Command | What it does |
|---|---|
| `ps-module context` *(module)* | Regenerates the overview in `<repo>/CONTEXT.md` and records that the Notes match the code. Exit 1 when the code changed but the Notes did not. |
| `ps-module context --no-notes` | The same, without that check. |
| `ps-module context --generate` | Regenerate only. |
| `ps-module context --status` | Exit 0 when `CONTEXT.md` exists and is current. Precedence: `--status` > `--generate` > `--no-notes`. |
| `ps-module index` *(module)* | Adds the module's `index.php` to every folder without one (not `vendor/`, never through symlinks). |
| `ps-module link` *(module)* | Uses the workspace's `libraries/*` (symlinked) instead of Packagist. Run `unlink` before you commit. |
| `ps-module unlink` *(module)* | Back to the released packages from Packagist. |
| `ps-module help` | The short overview. |

## Server scripts

These run on the demo server, in `DEMO_SITE_TOOLS_DIR` (default `~/demo-site`). The `ps-module` commands upload and
run them, but they can also be run by hand over SSH.

### `demo-site.sh [-v|--verbose] <command> [args]`

| Command | What it does |
|---|---|
| `snapshot [<note>]` | Files + database to `snapshots/<time>/`; `snapshots/latest` points to it; keeps the newest `KEEP`. |
| `restore [<snapshot>]` | Default `latest`. Puts back the files (rsync, or a folder swap without rsync) and the database (all tables dropped first). Keeps the server's own `parameters.php`. Sets the domain from `URL`, regenerates `.htaccess`, clears and warms the cache, applies the demo settings. |
| `activate <snapshot>` | Makes an uploaded snapshot the latest: restore + test, the previous latest back on a failure. |
| `deploy <zip> <module>` | Restore latest → install or upgrade (`demo-install.php`) → settings → test → new snapshot. Back to the snapshot of before on a failure. Prints `DEMO-OK <version>`. |
| `apply <dir> [<note>]` | Test before → `php <dir>/apply.php` in the shop root (gets `DEMO_SITE_URL` and `DEMO_SITE_ADMIN_DIR`; must print `APPLY-OK`) → intro page → test → new snapshot. On a failure (no `APPLY-OK`, or pages that broke since the test before) only the database goes back. Prints `APPLY-OK`. |
| `demo-mode on\|off` | Sets `_PS_MODE_DEMO_` in `config/defines.inc.php`. |
| `settings [intro]` | Runs `demo-settings.php` (`intro`: only the intro page). |
| `test` | Smoke test; result in `snapshots/last-test.json`. |
| `nightly` | `restore latest` + `test`. |
| `install-cron` | Cron line for `nightly` at `CRON_TIME`. With `CRON_INSTALL=no` it removes the line and prints what to schedule in the control panel instead. |
| `status` | Latest snapshot, snapshots, last test, cron. |
| `paths` | `SNAPSHOT_DIR` and `PS_ROOT`. |
| `help` | This list. |

Commands that change the shop wait for each other (`flock`, up to an hour).
- **Verbose** (`-v` or `VERBOSE=1`): settings, free disk, every step with sizes and times, rsync statistics,
  cache-warmup output, and each tested page with its HTTP code. Database names, users and passwords are never printed.

`demo-site.conf` (written by `ps-module demo-site setup` / `config`; shell syntax):

| Key | Meaning (default) |
|---|---|
| `PS_ROOT` | root of the PrestaShop (required) |
| `URL` | public address of the shop (required for `test`) |
| `LANGUAGES` | languages of the demo, e.g. `en nl` |
| `TEST_PATHS` | extra pages for the test |
| `ADMIN_DIR` | back-office folder (found automatically) |
| `SNAPSHOT_DIR` | snapshot folder (`snapshots/` next to the script) |
| `KEEP` | snapshots to keep (`5`) |
| `PHP` | PHP binary (`php`) |
| `CRON_TIME` | time of the nightly reset (`30 3 * * *`) |
| `CRON_INSTALL` | `no`: schedule it in the control panel instead (`yes`) |
| `CONNECT_TO` | curl `--connect-to` for the test (empty: found per test) |
| `KEEP_PATHS` | folders in `PS_ROOT` that are not part of the demo (`.well-known` and docs folders with `.docs-site` always) |
| `DEMO_EMAIL`, `DEMO_PASSWORD` | the public demo login (`demo@demo.com` / `demodemo`) |
| `INTRO_PATH` | intro page folder (`start`) |
| `DOCS_URL` | docs site, linked from the intro page as `<DOCS_URL>/<module>/index.php`. Empty: the shop's own domain, for each module whose `<module>/index.php` is in the shop root |

### `php demo-install.php <zip> <module> [<php>]`
Run in the root of the shop. Installs or upgrades a module from a zip:
1. The old files are kept aside until the install succeeds.
2. Runs `bin/console prestashop:module install|upgrade`.
3. Rights of the limited demo profile(s): every profile other than SuperAdmin that has a right on the module (PrestaShop
   gives those to the profiles that may manage modules) gets every right on the module's back-office pages (its tabs,
   which PrestaShop only gives to SuperAdmin) and loses the right to uninstall the module. Again on every upgrade.
   `php demo-install.php --rights <module>` does only this.
4. Clears the cache and prints `DEMO-OK <installed version>`.

On a failure the previous version comes back.
- **Exit codes:** 2 with the reason (zip not found, bad module name, not a shop root), 1 when the install failed.
- **Verbose:** `VERBOSE=1`.

### `php demo-settings.php <url> <email> <password> <intro-path> <docs-url> <reset-time> [<languages>]`
Run in the root of the shop.
- **Languages:** the demo languages active, others off.
- **Demo employee:** one SuperAdmin with the demo login; e-mail off.
- **Login filled in:** an `.htaccess` rule in the admin folder.
- **HTTPS behind a proxy:** an `.htaccess` rule in the shop root.
- **Brand logos:** shop, login page and back office.
- **Intro page:** `/<intro-path>/`.

Prints `DEMO-SETTINGS-OK <admin folder> (...)`. Verbose: `VERBOSE=1`.

### `bash demo-server-check.sh <ps-root> <shop-url>`
The checks of `ps-module demo-site check`. Changes nothing.
- **Settings:** `DEMO_SITE_PHP` picks the PHP binary.
- **Verbose:** `VERBOSE=1` adds DNS and timing details (never IP addresses).

## Local demo shop (`demo.sh`)

`MODULES/demo-site/demo.sh` (workspace, Git Bash on Windows) runs the local Docker copy of the demo shop on
http://localhost:8090. Settings: `demo-site/local.env`. `-v` / `--verbose` anywhere on the line turns on verbose
output.

| Command | What it does |
|---|---|
| `./demo.sh up` | Builds and starts the containers. The first start installs PrestaShop into `shop/`. |
| `./demo.sh init` | Once after the first start: `demo-site setup` against the local copy. |
| `./demo.sh deploy <module-repo>` | Puts a module in the local demo like `publish demo` does on the server (`--force --no-snapshot`). Builds nothing: run `ps-module build` first. |
| `./demo.sh push-to-server` | Asks first, then makes the latest local snapshot the live server's demo (`demo-site push`, settings from `MODULES/.env`). |
| `./demo.sh down` | Stops the containers; `shop/` and the database volume stay. |
| `./demo.sh <command> [args]` | Any `ps-module demo-site` command against the local copy: `status`, `snapshot "<note>"`, `restore [<snapshot>]`, `test`, `nightly`, `demo-mode on\|off`, ... |
| `./demo.sh help` | This list. |

## Settings (`.env` and environment)

Read from the environment, else from the nearest `.env` upwards (`MODULES/.env`; never in git, never shown).
Credentials are always masked in output.

| Setting | Used by | Meaning (default) |
|---|---|---|
| `PS_MODULE_VERBOSE` | all | `1` = verbose (as `-v`) |
| `PS_MODULE_LOCK_TIMEOUT`, `PS_MODULE_LOCK_DIR` | `publish`, `demo-site`, `shops`, `docs` | how long to wait for a shared resource (3600 s), folder of the lock files |
| `PRESTASHOP_VALIDATOR_API_KEY`, `PRESTASHOP_VALIDATOR_URL` | `validator-api`, `status` | online validator |
| `DEEPL_API_KEY`, `DEEPL_API_URL` | `translations auto` | DeepL |
| `PACKAGIST_USERNAME`, `PACKAGIST_TOKEN` | library releases | Packagist |
| `PS_CACHE_DIR`, `_PS_ROOT_DIR_` | `phpstan` | PrestaShop installs |
| `PS_DOCS_CHROME`, `PS_DOCS_BOX` | `docs` | Chrome/Edge path, box template |
| `DOCS_SITE_URL` | `publish docs` | public address of the docs site |
| `DOCS_SITE_SFTP_HOST`, `_PORT` (22), `_USER`, `_PASSWORD` or `_KEY` (+ `_KEY_PASSPHRASE`), `_PATH` | `publish docs` | SFTP login and the folder that is the root of `DOCS_SITE_URL` |
| `DEMO_SITE_SSH_HOST`, `_PORT` (22), `_USER`, `_PASSWORD` or `_KEY` (+ `_KEY_PASSPHRASE`) | `publish demo`, `demo-site` | SSH login (an unreadable key falls back to the password) |
| `DEMO_SITE_TOOLS_DIR` | `publish demo`, `demo-site` | folder of the server scripts (`demo-site`) |
| `DEMO_SITE_PS_ROOT`, `DEMO_SITE_URL` | `demo-site` | shop root and address (required for `setup`) |
| `DEMO_SITE_LANGUAGES`, `DEMO_SITE_TEST_PATHS`, `DEMO_SITE_ADMIN_DIR` (`admin-dev` for `install-prestashop`), `DEMO_SITE_SNAPSHOT_DIR`, `DEMO_SITE_KEEP` (5), `DEMO_SITE_PHP` (php), `DEMO_SITE_CRON` (`30 3 * * *`), `DEMO_SITE_CRON_INSTALL` (yes), `DEMO_SITE_CONNECT_TO`, `DEMO_SITE_KEEP_PATHS`, `DEMO_SITE_EMAIL` (demo@demo.com), `DEMO_SITE_PASSWORD` (demodemo), `DEMO_SITE_INTRO_PATH` (start), `DEMO_SITE_DEMO_MODE` (on) | `demo-site setup`/`config` | written to `demo-site.conf` (table above) |
| `DEMO_SITE_PS_VERSION` (9.2.0), `DEMO_SITE_TIMEZONE` (Europe/Amsterdam), `DEMO_SITE_COUNTRY` (nl), `DEMO_SITE_SHOP_NAME` (PrestaHaven demo), `DEMO_SITE_FIXTURES` (0) | `demo-site install-prestashop` | the installation |
| `DEMO_SITE_DB_CREDENTIALS` | `demo-site install-prestashop` | another database file instead of the nearest `.db_credentials` |
| `TRANSIP_LOGIN`, `TRANSIP_PRIVATE_KEY` (file or key text), `TRANSIP_VPS_NAME`, `TRANSIP_SNAPSHOT_KEEP` (2, at least 2: the oldest snapshot is always kept as the starting point; before a new one the most recent goes), `TRANSIP_GLOBAL_KEY` (true) | `vps-snapshot`, after `publish docs/demo` | TransIP VPS snapshots (never on the VPS itself) |
| `CLOUDFLARE_API_TOKEN` (permission Zone > Cache Purge), `CLOUDFLARE_ZONE_ID` | after `publish docs/demo` | Purges the module's static files (logo, `views/`) and the docs PDFs from the Cloudflare cache. Without them the addresses are printed for a purge by hand. |
| `SALES_SHOP_URL`, `SALES_SHOP_WEBSERVICE_KEY`, `SALES_SHOP_CATEGORY_ID` (2), `SALES_SHOP_TAX_RULES_GROUP_ID` (none) | `publish shop` | Your own sales shop: its address (as the shop answers it, https and www included) and a webservice key with the permissions listed under `shop` above. |

`.db_credentials` (next to `.env`): `DB_HOST` (localhost), `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, optional
`DB_PREFIX` (must be `ps_`). `key: value`, `export KEY=value` and PHP `define()` lines work too. It is only used by
`demo-site install-prestashop`.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | done / passed |
| 1 | the check or action failed (details above the exit) |
| 2 | usage error: wrong arguments, or not run inside a module |
