<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaShopHeaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * `ps-module publish shop`: the module as a product in the own sales shop, through the PrestaShop webservice
 * (loaded by publish.php). Runs only on command, never in CI. It creates the product once and never changes an
 * existing one; the product is always created INACTIVE (the user activates it by hand) and read back to make sure.
 *
 * What goes in, all from the module repository:
 *   - name               module.spec.json display_name, translated from i18n/<iso>.json (the module's own catalogue)
 *   - short description  module.spec.json description, translated the same way
 *   - description        docs/content/<iso>/marketing.json: description (paragraphs) + features (a list)
 *   - meta title/description, friendly URL (Latin scripts transliterated; other scripts get the English one)
 *   - price              docs.json > marketing > price, without tax
 *   - images             docs/marketing/cover-1000.png first (the cover), then docs/framed/en/<id>.png in the order
 *                        of docs.json > screenshots
 *   - features           resources/publish/shop-features.json (Multistore: Yes), reused when the shop has them
 *   - reference          the technical module name: how the command finds the product again
 * Every language installed in the shop gets its texts; a shop language the module has no texts for gets English.
 * The zip of the virtual product stays a manual step: PrestaShop has no API for virtual product files.
 *
 * Settings (environment or .env): SALES_SHOP_URL (the shop's address, e.g. https://www.example.com),
 * SALES_SHOP_WEBSERVICE_KEY (Advanced Parameters > Webservice; permissions below), optional
 * SALES_SHOP_CATEGORY_ID (default 2, Home) and SALES_SHOP_TAX_RULES_GROUP_ID (default: none, set it by hand).
 * Webservice permissions: languages GET; products GET + POST; images POST; product_features and
 * product_feature_values GET + POST.
 */

declare(strict_types=1);

/**
 * Creates the product of the module in the sales shop, inactive, with texts in every shop language, images and
 * features; prints the manual steps that are left. A product with the module's reference is never changed; when a
 * product with the same name exists (made by hand), nothing is created unless $force.
 *
 * @param array<string, mixed> $ctx
 */
function publish_cmd_shop(array $ctx, bool $dryRun, bool $force = false): int
{
    $plan = publish_shop_plan($ctx);
    $url = rtrim(publish_env($ctx, 'SALES_SHOP_URL'), '/');
    $key = publish_env($ctx, 'SALES_SHOP_WEBSERVICE_KEY');
    $category = (int) (publish_env($ctx, 'SALES_SHOP_CATEGORY_ID') ?: 2);
    $tax = (int) publish_env($ctx, 'SALES_SHOP_TAX_RULES_GROUP_ID');
    if ($url === '' || $key === '') {
        if (!$dryRun) {
            throw new RuntimeException('publish shop needs SALES_SHOP_URL and SALES_SHOP_WEBSERVICE_KEY in .env (see example.env).');
        }
        publish_shop_print_plan($ctx, $plan, null, $category, $tax);
        echo "Dry run without a shop (SALES_SHOP_URL / SALES_SHOP_WEBSERVICE_KEY not set): nothing sent.\n";

        return 0;
    }
    $ws = ['api' => $url . '/api', 'key' => $key];
    $languages = publish_shop_languages($ws, $ctx);
    $existing = publish_shop_find_product($ws, $ctx['module']);
    if ($existing !== null) {
        echo "{$ctx['module']} is already in the sales shop: product #{$existing['id']} (" . ($existing['active'] ? 'active' : 'inactive') . "). Nothing changed.\n";
        echo "New version? Replace the file in its Virtual product tab with ../dist/{$ctx['module']}.zip, then: vendor/bin/ps-module publish done shop\n";

        return 0;
    }
    $fields = publish_shop_schema($ws);
    publish_shop_print_plan($ctx, $plan, $languages, $category, $tax);
    $sameName = publish_shop_find_by_name($ws, $plan['name']);
    if ($sameName !== []) {
        $list = implode(', ', array_map(static fn (array $p): string => "#{$p['id']} (reference '" . $p['reference'] . "', " . ($p['active'] ? 'active' : 'inactive') . ')', $sameName));
        echo "The shop already has a product with this name: $list.\n"
            . "Is it this module? Set its reference to {$ctx['module']} in the back office: then publish shop knows it and never creates it again.\n";
        if (!$force) {
            echo $dryRun ? "Dry run: nothing created (a real run stops here; --force creates a new product anyway).\n" : "Nothing created, to avoid a duplicate. --force creates a new product anyway.\n";

            return $dryRun ? 0 : 1;
        }
        echo "--force: creating a new product anyway.\n";
    }
    if ($dryRun) {
        echo "Dry run: nothing created.\n";

        return 0;
    }

    $features = publish_shop_features($ws, $plan['features'], $languages);
    $xml = publish_shop_product_xml($plan, $languages, $fields, $features, $category, $tax);
    $created = publish_shop_ws($ws, 'POST', 'products', [], $xml);
    $id = (int) ($created['body']['product']['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('the shop answered without a product id: ' . substr($created['raw'], 0, 300));
    }
    $check = publish_shop_ws($ws, 'GET', "products/$id", ['display' => '[id,active]']);
    $active = (string) ($check['body']['product']['active'] ?? $check['body']['products'][0]['active'] ?? '');
    if ($active !== '0') {
        throw new RuntimeException("product #$id was created but the shop reports it as active ('$active'): deactivate it by hand NOW (Catalog > Products).");
    }
    echo "Created product #$id \"{$plan['name'][$plan['source']]}\" (inactive).\n";

    $uploaded = 0;
    foreach ($plan['images'] as $image) {
        try {
            publish_shop_ws($ws, 'POST', "images/products/$id", [], null, ['image' => new CURLFile($image, 'image/png', basename($image))]);
            ++$uploaded;
            echo '  image ' . $uploaded . '/' . count($plan['images']) . ': ' . publish_shop_relative($ctx, $image) . ($uploaded === 1 && str_contains($image, '/docs/marketing/cover') ? ' (cover)' : '') . "\n";
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'ps-module publish shop: image ' . basename($image) . ' not uploaded: ' . publish_mask($e->getMessage()) . "\n");
        }
    }

    $zip = $ctx['repo'] . '/dist/' . $ctx['module'] . '.zip';
    echo "\nLeft to do by hand in the back office (Catalog > Products > #$id):\n";
    echo '  1. Virtual product tab: add the file ../dist/' . $ctx['module'] . '.zip'
        . (is_file($zip) ? ' (' . publish_size((int) filesize($zip)) . ', ' . date('Y-m-d H:i', (int) filemtime($zip)) . ')' : ' (not built yet: vendor/bin/ps-module build)') . "\n";
    echo '  2. Check price, tax rule' . ($tax === 0 ? ' (none set)' : '') . ", category, stock and the texts\n";
    echo "  3. Activate the product\n";
    if ($uploaded < count($plan['images'])) {
        echo '  ' . (count($plan['images']) - $uploaded) . " image(s) failed (see above): add them by hand\n";
    }
    publish_record($ctx, 'shop', "product #$id (inactive), $uploaded image(s); zip by hand");
    echo "Recorded step 'shop' for {$ctx['version']}.\n";

    return 0;
}

/**
 * Everything the product gets, from the module repository: texts per module language, price, images, features.
 *
 * @param array<string, mixed> $ctx
 *
 * @return array{module: string, source: string, name: array<string, string>, short: array<string, string>, description: array<string, string>, price: float, images: string[], features: array<int, array{name: array<string, string>, value: array<string, string>}>, fallback: string[]}
 *
 * @throws RuntimeException when the docs (cover, framed screenshots, marketing texts) are missing
 */
function publish_shop_plan(array $ctx): array
{
    $repo = $ctx['repo'];
    $source = $ctx['languages'][0] ?? 'en';
    $docs = publish_json("$repo/docs/docs.json");
    $spec = $ctx['spec'];
    $displayName = (string) ($spec['display_name'] ?? $ctx['module']);
    $summary = (string) ($spec['description'] ?? '');
    $plan = ['module' => $ctx['module'], 'source' => $source, 'name' => [], 'short' => [], 'description' => [], 'fallback' => []];
    foreach ($ctx['languages'] as $iso) {
        $marketing = publish_json("$repo/docs/content/$iso/marketing.json");
        if ($marketing === []) {
            if ($iso === $source) {
                throw new RuntimeException("docs/content/$iso/marketing.json is missing: run vendor/bin/ps-module docs status.");
            }
            $plan['fallback'][] = $iso;
            continue;
        }
        $catalogue = $iso === $source ? [] : publish_json("$repo/i18n/$iso.json");
        $plan['name'][$iso] = publish_shop_translate($catalogue, $displayName);
        $plan['short'][$iso] = publish_shop_translate($catalogue, $summary);
        $plan['description'][$iso] = publish_shop_html((string) ($marketing['description'] ?? ''), array_map('strval', (array) ($marketing['features'] ?? [])));
    }

    $images = [];
    if (($docs['cover']['enabled'] ?? true) !== false) {
        $cover = is_file("$repo/docs/marketing/cover-1000.png") ? "$repo/docs/marketing/cover-1000.png" : "$repo/docs/marketing/cover.png";
        if (!is_file($cover)) {
            throw new RuntimeException('docs/marketing/cover-1000.png is missing: run vendor/bin/ps-module docs build.');
        }
        $images[] = $cover;
    }
    foreach ((array) ($docs['screenshots'] ?? []) as $shot) {
        $file = "$repo/docs/framed/en/" . (string) ($shot['id'] ?? '') . '.png';
        if (is_file($file)) {
            $images[] = $file;
        }
    }
    if (count($images) < 2) {
        throw new RuntimeException('no framed screenshots in docs/framed/en/: run vendor/bin/ps-module docs build.');
    }
    $plan['images'] = $images;
    $plan['price'] = round((float) ($docs['marketing']['price'] ?? 0), 6);
    $plan['features'] = array_values(array_filter((array) (publish_json(__DIR__ . '/../resources/publish/shop-features.json')['features'] ?? []), 'is_array'));

    return $plan;
}

/**
 * The translation of an English text in a module catalogue (i18n/<iso>.json: domain -> English -> translation or
 * {text, machine}); the English text itself when the catalogue does not have it.
 *
 * @param array<string, mixed> $catalogue
 */
function publish_shop_translate(array $catalogue, string $english): string
{
    foreach ((array) ($catalogue['strings'] ?? []) as $strings) {
        $found = is_array($strings) ? ($strings[$english] ?? null) : null;
        $text = is_array($found) ? (string) ($found['text'] ?? '') : (string) $found;
        if ($text !== '') {
            return $text;
        }
    }

    return $english;
}

/**
 * The product description as HTML: the marketing text in paragraphs (**bold** kept), then the features as a list.
 *
 * @param string[] $features
 */
function publish_shop_html(string $text, array $features): string
{
    $inline = static fn (string $s): string => (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $html = '';
    foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $paragraph) {
        if (trim($paragraph) !== '') {
            $html .= '<p>' . str_replace("\n", '<br>', $inline(trim($paragraph))) . "</p>\n";
        }
    }
    $features = array_filter(array_map('trim', $features), static fn (string $f): bool => $f !== '');
    if ($features !== []) {
        $html .= "<ul>\n" . implode('', array_map(static fn (string $f): string => '<li>' . $inline($f) . "</li>\n", $features)) . "</ul>\n";
    }

    return $html;
}

/**
 * A friendly URL part: Latin scripts transliterated to ASCII; '' for other scripts (the caller uses English then).
 */
function publish_shop_slug(string $text): string
{
    if (preg_match('/[^\p{Latin}\P{L}]/u', $text) === 1) {
        return '';
    }
    $ascii = function_exists('transliterator_transliterate') ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) : strtolower($text);

    return trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-'), 0, 128), '-');
}

/**
 * Cuts a text to at most $max characters, at a word boundary when possible.
 */
function publish_shop_cut(string $text, int $max): string
{
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');

    return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-");
}

/**
 * The languages installed in the shop, each with the module language whose texts it gets (null: none, English then).
 * Matched on PrestaShop's iso code (si, vn ...), else on the first part of the language code (pt-br -> pt).
 *
 * @param array{api: string, key: string} $ws
 * @param array<string, mixed> $ctx
 *
 * @return array<int, array{iso: string, name: string, module: string|null}>
 */
function publish_shop_languages(array $ws, array $ctx): array
{
    $rows = publish_shop_ws($ws, 'GET', 'languages', ['display' => '[id,iso_code,language_code,name]'])['body']['languages'] ?? [];
    $languages = [];
    foreach ((array) $rows as $row) {
        $iso = strtolower((string) ($row['iso_code'] ?? ''));
        $languages[(int) $row['id']] = ['iso' => $iso, 'name' => (string) ($row['name'] ?? $iso), 'module' => publish_shop_module_language($ctx, $iso, (string) ($row['language_code'] ?? ''))];
    }
    if ($languages === []) {
        throw new RuntimeException('the shop lists no languages (does the webservice key have GET on languages?).');
    }

    return $languages;
}

/**
 * The module language whose texts a shop language gets: by PrestaShop's iso code (si, vn ...), else by the first part
 * of its language code (pt-br -> pt, en-gb -> en); null when the module has no texts in it.
 *
 * @param array<string, mixed> $ctx
 */
function publish_shop_module_language(array $ctx, string $psIso, string $languageCode): ?string
{
    $psIso = strtolower($psIso);
    $prefix = strtolower(explode('-', $languageCode)[0]);
    $module = null;
    foreach ($ctx['catalog'] as $key => $info) {
        if ((string) ($info['ps_iso'] ?? $key) === $psIso) {
            $module = (string) $key;
        }
    }
    $module ??= isset($ctx['catalog'][$prefix]) ? $prefix : null;

    return $module !== null && in_array($module, $ctx['languages'], true) ? $module : null;
}

/**
 * The product with the module's reference in the shop, or null.
 *
 * @param array{api: string, key: string} $ws
 *
 * @return array{id: int, active: bool}|null
 */
function publish_shop_find_product(array $ws, string $module): ?array
{
    $rows = publish_shop_ws($ws, 'GET', 'products', ['display' => '[id,active,reference]', 'filter[reference]' => "[$module]"])['body']['products'] ?? [];
    foreach ((array) $rows as $row) {
        if ((string) ($row['reference'] ?? '') === $module) {
            return ['id' => (int) $row['id'], 'active' => (string) ($row['active'] ?? '0') === '1'];
        }
    }

    return null;
}

/**
 * Products whose name equals one of the module's names (any language, case ignored): a product made by hand before,
 * without the module's reference. Creating another one would be a duplicate.
 *
 * @param array{api: string, key: string} $ws
 * @param array<string, string> $names module language => product name
 *
 * @return array<int, array{id: int, reference: string, active: bool}>
 */
function publish_shop_find_by_name(array $ws, array $names): array
{
    $rows = (array) (publish_shop_ws($ws, 'GET', 'products', ['display' => '[id,name,reference,active]'])['body']['products'] ?? []);
    $found = [];
    foreach ($rows as $row) {
        if (is_array($row) && publish_shop_match([$row], 'name', $names) !== null) {
            $found[] = ['id' => (int) $row['id'], 'reference' => (string) ($row['reference'] ?? ''), 'active' => (string) ($row['active'] ?? '0') === '1'];
        }
    }

    return $found;
}

/**
 * The field and association names of a product in this shop's webservice (its blank schema), so only fields the
 * PrestaShop version knows are sent.
 *
 * @param array{api: string, key: string} $ws
 *
 * @return array{fields: string[], associations: string[]}
 */
function publish_shop_schema(array $ws): array
{
    // XML: the JSON form of a blank schema leaves the empty fields out.
    $raw = publish_shop_ws($ws, 'GET', 'products', ['schema' => 'blank', 'output_format' => 'XML'])['raw'];
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_use_internal_errors($previous);
    $product = $xml !== false ? $xml->product : null;
    if ($product === null || $product->count() === 0) {
        throw new RuntimeException('the shop did not return the product schema.');
    }
    $fields = [];
    foreach ($product->children() as $name => $node) {
        $fields[] = (string) $name;
    }
    $associations = [];
    foreach ($product->associations->children() as $name => $node) {
        $associations[] = (string) $name;
    }
    // Without these the product could end up active (PrestaShop's default) or not be found again: refuse.
    $missing = array_diff(['active', 'reference', 'price', 'name'], $fields);
    if ($missing !== []) {
        throw new RuntimeException('the product schema of the shop lacks ' . implode(', ', $missing) . ': nothing created.');
    }

    return ['fields' => $fields, 'associations' => $associations];
}

/**
 * Finds or creates every feature and its value in the shop; returns the pairs for the product.
 *
 * @param array{api: string, key: string} $ws
 * @param array<int, array{name: array<string, string>, value: array<string, string>}> $features
 * @param array<int, array{iso: string, name: string, module: string|null}> $languages
 *
 * @return array<int, array{feature: int, value: int, label: string}>
 */
function publish_shop_features(array $ws, array $features, array $languages): array
{
    $existing = (array) (publish_shop_ws($ws, 'GET', 'product_features', ['display' => 'full'])['body']['product_features'] ?? []);
    $pairs = [];
    foreach ($features as $feature) {
        $label = ($feature['name']['en'] ?? '?') . ': ' . ($feature['value']['en'] ?? '?');
        $featureId = publish_shop_match($existing, 'name', $feature['name']);
        if ($featureId === null) {
            $xml = publish_shop_xml('product_feature', [], ['name' => publish_shop_lang_values($feature['name'], $languages, 128)]);
            $featureId = (int) (publish_shop_ws($ws, 'POST', 'product_features', [], $xml)['body']['product_feature']['id'] ?? 0);
            echo "  feature created: {$feature['name']['en']} (#$featureId)\n";
        }
        $values = (array) (publish_shop_ws($ws, 'GET', 'product_feature_values', ['display' => 'full', 'filter[id_feature]' => "[$featureId]"])['body']['product_feature_values'] ?? []);
        $values = array_filter($values, static fn ($v): bool => is_array($v) && (string) ($v['custom'] ?? '0') === '0');
        $valueId = publish_shop_match($values, 'value', $feature['value']);
        if ($valueId === null) {
            $xml = publish_shop_xml('product_feature_value', ['id_feature' => (string) $featureId, 'custom' => '0'], ['value' => publish_shop_lang_values($feature['value'], $languages, 255)]);
            $valueId = (int) (publish_shop_ws($ws, 'POST', 'product_feature_values', [], $xml)['body']['product_feature_value']['id'] ?? 0);
            echo "  feature value created: $label (#$valueId)\n";
        }
        if ($featureId > 0 && $valueId > 0) {
            $pairs[] = ['feature' => $featureId, 'value' => $valueId, 'label' => $label];
        }
    }

    return $pairs;
}

/**
 * The id of the first row whose multilingual field has one of the given texts in any language (case ignored).
 *
 * @param array<int|string, mixed> $rows
 * @param array<string, string> $texts
 */
function publish_shop_match(array $rows, string $field, array $texts): ?int
{
    $wanted = array_map(static fn (string $t): string => mb_strtolower(trim($t)), array_values($texts));
    foreach ($rows as $row) {
        $values = is_array($row) ? ($row[$field] ?? '') : '';
        $values = is_array($values) ? array_map(static fn ($v): string => is_array($v) ? (string) ($v['value'] ?? '') : (string) $v, $values) : [(string) $values];
        foreach ($values as $value) {
            if ($value !== '' && in_array(mb_strtolower(trim($value)), $wanted, true)) {
                return (int) $row['id'];
            }
        }
    }

    return null;
}

/**
 * A text per shop language id: the module language's text, else the source language's.
 *
 * @param array<string, string> $texts module language => text
 * @param array<int, array{iso: string, name: string, module: string|null}> $languages
 *
 * @return array<int, string>
 */
function publish_shop_lang_values(array $texts, array $languages, int $max = 0, string $source = 'en'): array
{
    $values = [];
    foreach ($languages as $id => $language) {
        $text = $texts[$language['module'] ?? $source] ?? $texts[$source] ?? '';
        $values[$id] = $max > 0 ? publish_shop_cut($text, $max) : $text;
    }

    return $values;
}

/**
 * The XML of the new product: only fields this shop knows, inactive, virtual, every language filled in.
 *
 * @param array<string, mixed> $plan
 * @param array<int, array{iso: string, name: string, module: string|null}> $languages
 * @param array{fields: string[], associations: string[]} $schema
 * @param array<int, array{feature: int, value: int, label: string}> $features
 */
function publish_shop_product_xml(array $plan, array $languages, array $schema, array $features, int $category, int $tax): string
{
    $source = $plan['source'];
    $slugs = [];
    foreach ($plan['name'] as $iso => $name) {
        $slugs[$iso] = publish_shop_slug($name) ?: publish_shop_slug($plan['name'][$source]);
    }
    $fields = [
        'reference' => $plan['module'] ?? '',
        'active' => '0',
        'state' => '1',
        'type' => 'virtual',
        'is_virtual' => '1',
        'product_type' => 'virtual',
        'price' => sprintf('%.6f', $plan['price']),
        'id_category_default' => (string) $category,
        'available_for_order' => '1',
        'show_price' => '1',
        'visibility' => 'both',
        'condition' => 'new',
        'minimal_quantity' => '1',
    ];
    if ($tax > 0) {
        $fields['id_tax_rules_group'] = (string) $tax;
    }
    $multilang = [
        'name' => publish_shop_lang_values($plan['name'], $languages, 128, $source),
        'description_short' => publish_shop_lang_values(array_map(static fn (string $s): string => '<p>' . htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>', $plan['short']), $languages, 0, $source),
        'description' => publish_shop_lang_values($plan['description'], $languages, 0, $source),
        'link_rewrite' => publish_shop_lang_values($slugs, $languages, 128, $source),
        'meta_title' => publish_shop_lang_values($plan['name'], $languages, 128, $source),
        'meta_description' => publish_shop_lang_values($plan['short'], $languages, 255, $source),
    ];
    foreach (array_keys($fields + $multilang) as $field) {
        if (!in_array($field, $schema['fields'], true)) {
            ps_vlog("product field '$field' is not in this shop's webservice: left out");
            unset($fields[$field], $multilang[$field]);
        }
    }
    $associations = [];
    if (in_array('categories', $schema['associations'], true)) {
        $associations['categories'] = [['category', ['id' => (string) $category]]];
    }
    if ($features !== [] && in_array('product_features', $schema['associations'], true)) {
        $associations['product_features'] = array_map(static fn (array $f): array => ['product_feature', ['id' => (string) $f['feature'], 'id_feature_value' => (string) $f['value']]], $features);
    }

    return publish_shop_xml('product', $fields, $multilang, $associations);
}

/**
 * Webservice XML for one object: plain fields, multilingual fields (language id => text) and associations
 * (name => list of [node name, fields]).
 *
 * @param array<string, string> $fields
 * @param array<string, array<int, string>> $multilang
 * @param array<string, array<int, array{0: string, 1: array<string, string>}>> $associations
 */
function publish_shop_xml(string $node, array $fields, array $multilang, array $associations = []): string
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $root = $doc->appendChild($doc->createElement('prestashop'));
    $object = $root->appendChild($doc->createElement($node));
    foreach ($fields as $name => $value) {
        $object->appendChild($doc->createElement($name))->appendChild($doc->createTextNode($value));
    }
    foreach ($multilang as $name => $values) {
        $field = $object->appendChild($doc->createElement($name));
        foreach ($values as $id => $value) {
            $language = $field->appendChild($doc->createElement('language'));
            if ($language instanceof DOMElement) {
                $language->setAttribute('id', (string) $id);
            }
            $language->appendChild($doc->createTextNode($value));
        }
    }
    if ($associations !== []) {
        $list = $object->appendChild($doc->createElement('associations'));
        foreach ($associations as $name => $items) {
            $group = $list->appendChild($doc->createElement($name));
            foreach ($items as [$itemName, $itemFields]) {
                $item = $group->appendChild($doc->createElement($itemName));
                foreach ($itemFields as $fieldName => $value) {
                    $item->appendChild($doc->createElement($fieldName))->appendChild($doc->createTextNode($value));
                }
            }
        }
    }

    return (string) $doc->saveXML();
}

/**
 * Prints what the product will get: name, price, category, tax rule, features, images and the language mapping.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $plan
 * @param array<int, array{iso: string, name: string, module: string|null}>|null $languages null: shop not asked
 */
function publish_shop_print_plan(array $ctx, array $plan, ?array $languages, int $category, int $tax): void
{
    $source = $plan['source'];
    echo "Sales shop product for {$ctx['module']} {$ctx['version']}:\n";
    printf("  %-13s %s (the command finds the product by it)\n", 'Reference', $ctx['module']);
    printf("  %-13s %s\n", 'Name', $plan['name'][$source]);
    printf("  %-13s %.2f excl. tax, tax rule %s\n", 'Price', $plan['price'], $tax > 0 ? "#$tax" : 'none (set it by hand)');
    printf("  %-13s #%d\n", 'Category', $category);
    printf("  %-13s %s\n", 'Features', implode(', ', array_map(static fn (array $f): string => ($f['name']['en'] ?? '?') . ': ' . ($f['value']['en'] ?? '?'), $plan['features'])));
    printf("  %-13s %s\n", 'Images', implode(', ', array_map(static fn (string $f): string => publish_shop_relative($ctx, $f), $plan['images'])) . ' (first = cover)');
    printf("  %-13s %s\n", 'Status', 'INACTIVE (you activate it), virtual product; the zip is added by hand');
    if ($plan['fallback'] !== []) {
        printf("  %-13s %s\n", 'No texts', implode(' ', $plan['fallback']) . ' (no docs/content/<iso>/marketing.json: English)');
    }
    if ($languages === null) {
        printf("  %-13s %s\n", 'Languages', implode(' ', array_keys($plan['name'])) . ' (module); the shop decides which are used');

        return;
    }
    $parts = [];
    foreach ($languages as $language) {
        $parts[] = $language['iso'] . ($language['module'] === null ? '=English' : ($language['module'] !== $language['iso'] ? '=' . $language['module'] : ''));
    }
    printf("  %-13s %s\n", 'Languages', implode(' ', $parts) . ' (' . count($languages) . ' in the shop)');
}

/**
 * A path relative to the repository, for messages.
 *
 * @param array<string, mixed> $ctx
 */
function publish_shop_relative(array $ctx, string $file): string
{
    return str_starts_with($file, $ctx['repo'] . '/') ? substr($file, strlen($ctx['repo']) + 1) : $file;
}

/**
 * One webservice request (JSON answers; XML or a file upload as body). The key goes as HTTP Basic login, or as the
 * ws_key parameter when the server drops that header. Throws with the shop's own error messages.
 *
 * @param array{api: string, key: string} $ws
 * @param array<string, string> $query
 * @param array<string, CURLFile>|null $upload multipart fields
 *
 * @return array{status: int, body: array<string, mixed>, raw: string}
 *
 * @throws RuntimeException on a network error or an HTTP error
 */
function publish_shop_ws(array $ws, string $method, string $path, array $query = [], ?string $xml = null, ?array $upload = null): array
{
    static $keyInQuery = false;
    if (!function_exists('curl_init')) {
        throw new RuntimeException('the PHP curl extension is needed for publish shop.');
    }
    $query += ['output_format' => 'JSON'];
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $params = $keyInQuery ? $query + ['ws_key' => $ws['key']] : $query;
        $url = $ws['api'] . '/' . $path . '?' . http_build_query($params);
        $ch = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERAGENT => 'ps-module publish shop'];
        if (!$keyInQuery) {
            $options[CURLOPT_USERPWD] = $ws['key'] . ':';
        }
        if ($xml !== null) {
            $options[CURLOPT_POSTFIELDS] = $xml;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: text/xml; charset=utf-8'];
        } elseif ($upload !== null) {
            $options[CURLOPT_POSTFIELDS] = $upload;
        }
        curl_setopt_array($ch, $options);
        $start = microtime(true);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        unset($ch);
        ps_vlog(sprintf('%s %s: HTTP %d, %s after %.1fs', $method, $ws['api'] . '/' . $path, $status, is_string($raw) ? publish_size(strlen($raw)) : 'no answer', microtime(true) - $start));
        if (!is_string($raw)) {
            throw new RuntimeException("$method /api/$path: no answer from the shop ($error).");
        }
        if ($status === 401 && !$keyInQuery) {
            // Some servers (PHP as CGI/FPM without the rewrite rule) drop the Authorization header: try ws_key.
            ps_vlog('HTTP 401 with Basic login: trying the ws_key parameter');
            $keyInQuery = true;
            continue;
        }
        break;
    }
    $body = json_decode((string) $raw, true);
    $body = is_array($body) ? $body : [];
    if ($status >= 300 && $status < 400) {
        throw new RuntimeException("$method /api/$path: the shop redirects (HTTP $status) to " . strtok($location, '?') . ': use that address in SALES_SHOP_URL.');
    }
    if ($status === 401) {
        throw new RuntimeException("$method /api/$path: the shop refuses the key (HTTP 401): check SALES_SHOP_WEBSERVICE_KEY and that the webservice is on (Advanced Parameters > Webservice).");
    }
    if ($status >= 400 || $status === 0) {
        $messages = array_map(static fn ($e): string => is_array($e) ? (string) ($e['message'] ?? '') : (string) $e, (array) ($body['errors'] ?? []));
        $hint = $status === 403 || $status === 405 ? ' (does the webservice key have this permission?)' : '';
        throw new RuntimeException("$method /api/$path: HTTP $status$hint " . ($messages !== [] ? implode('; ', $messages) : substr(strip_tags($raw), 0, 300)));
    }

    return ['status' => $status, 'body' => $body, 'raw' => $raw];
}
