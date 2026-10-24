<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * A fake PrestaShop webservice for the publish shop tests: router script of PHP's built-in server
 * (php -S 127.0.0.1:<port> fake-webservice.php), state in the JSON file of FAKE_WS_STATE, read and written on every
 * request. It answers like PrestaShop 8/9 where publish shop depends on it:
 *   - GET products/<id> as XML with every field, the languages and the associations;
 *   - PUT replaces the whole product: a plain field the request lacks is emptied (NULL in low_stock_threshold, ''
 *     sent there is stored as 0), a read-only field
 *     (manufacturer_name, new, quantity) is refused (HTTP 400), position_in_category 0 is refused (HTTP 500),
 *     id_default_image makes that image the cover, the images and stock_availables associations are ignored, an
 *     association that is not sent stays, a product_bundle list (sent for a product that is no pack) is refused with
 *     HTTP 500 after the fields were saved (PrestaShop 8.1);
 *   - an uploaded image becomes the cover when the product has none; a deleted cover leaves no cover; with state
 *     delete_500 a DELETE of an image deletes it and then answers HTTP 500 without a body (PrestaShop 8.1).
 * With state image_languages_module the shop has the resource heavenlangimages_images (module Language-Specific Product
 * Images): a PUT stores the languages and captions of an image in state image_languages.
 * Failures on request: state fail.upload_at (the n-th upload of the run fails), fail.put (every PUT is refused),
 * fail.image_languages_at (the n-th PUT of heavenlangimages_images is refused).
 * Every request is logged in state log as "METHOD path".
 */

declare(strict_types=1);

$stateFile = (string) getenv('FAKE_WS_STATE');
$state = json_decode((string) file_get_contents($stateFile), true);
$path = trim((string) preg_replace('#^/api#', '', (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$method = (string) $_SERVER['REQUEST_METHOD'];
$state['log'][] = "$method $path";
$segments = explode('/', $path);

try {
    fake_ws_route($state, $method, $segments);
} finally {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Answers one request.
 *
 * @param array<string, mixed> $state
 * @param string[] $segments
 */
function fake_ws_route(array &$state, string $method, array $segments): void
{
    $resource = $segments[0] ?? '';
    if ($resource === '' && $method === 'GET') {
        // The resource list with the permissions of the key (state denied: ["images delete", ...]).
        $xml = '<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><api shopName="Fake">';
        $resources = ['languages', 'products', 'images', 'product_features', 'product_feature_values'];
        if (!empty($state['image_languages_module'])) {
            // The module Language-Specific Product Images is installed (state image_languages_module).
            $resources[] = 'heavenlangimages_images';
        }
        foreach ($resources as $name) {
            $xml .= "<$name";
            foreach (['get', 'put', 'post', 'patch', 'delete', 'head'] as $verb) {
                $xml .= " $verb=\"" . (in_array("$name $verb", $state['denied'] ?? [], true) ? 'false' : 'true') . '"';
            }
            $xml .= "></$name>";
        }
        fake_ws_xml($xml . '</api></prestashop>');

        return;
    }
    if ($resource === 'languages' && $method === 'GET') {
        fake_ws_json(['languages' => array_values($state['languages'])]);

        return;
    }
    if ($resource === 'products' && !isset($segments[1]) && $method === 'GET') {
        if (($_GET['schema'] ?? '') === 'synopsis') {
            fake_ws_xml('<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><product><id></id><manufacturer_name read_only="true"></manufacturer_name><new read_only="true"></new><price required="true"></price><name></name><associations></associations></product></prestashop>');

            return;
        }
        $rows = [];
        foreach ($state['products'] as $product) {
            $reference = trim((string) ($_GET['filter']['reference'] ?? ''), '[]');
            if ($reference === '' || $product['fields']['reference'] === $reference) {
                $rows[] = ['id' => $product['fields']['id'], 'active' => $product['fields']['active'], 'reference' => $product['fields']['reference'], 'name' => array_map(static fn ($id, $v): array => ['id' => (string) $id, 'value' => $v], array_keys($product['lang']['name']), $product['lang']['name'])];
            }
        }
        fake_ws_json($rows === [] ? [] : ['products' => $rows]);

        return;
    }
    if ($resource === 'products' && isset($segments[1])) {
        $id = (string) (int) $segments[1];
        if (!isset($state['products'][$id])) {
            fake_ws_error(404, 'Invalid ID');

            return;
        }
        if ($method === 'GET') {
            fake_ws_xml(fake_ws_product_xml($state, $id));

            return;
        }
        if ($method === 'PUT') {
            fake_ws_put($state, $id, (string) file_get_contents('php://input'));

            return;
        }
    }
    if ($resource === 'images' && ($segments[1] ?? '') === 'products') {
        $productId = (string) (int) ($segments[2] ?? 0);
        if ($method === 'POST') {
            $state['uploads'] = ($state['uploads'] ?? 0) + 1;
            if (($state['fail']['upload_at'] ?? 0) === $state['uploads']) {
                fake_ws_error(500, 'Unable to save this image');

                return;
            }
            $imageId = (string) ++$state['next_image'];
            $hasCover = false;
            foreach ($state['images'] as $image) {
                $hasCover = $hasCover || ($image['product'] === $productId && $image['cover']);
            }
            $state['images'][$imageId] = ['product' => $productId, 'file' => (string) ($_FILES['image']['name'] ?? ''), 'cover' => !$hasCover];
            fake_ws_xml("<prestashop><image><id>$imageId</id></image></prestashop>");

            return;
        }
        if ($method === 'DELETE') {
            unset($state['images'][(string) (int) ($segments[3] ?? 0)]);
            if (!empty($state['delete_500'])) {
                http_response_code(500);

                return;
            }
            fake_ws_json([]);

            return;
        }
    }
    if ($resource === 'heavenlangimages_images' && $method === 'PUT' && !empty($state['image_languages_module'])) {
        // The resource of heavenlangimages: languages by iso code (none = every language) and captions per iso code.
        $imageId = (string) (int) ($segments[1] ?? 0);
        $state['image_put'] = ($state['image_put'] ?? 0) + 1;
        if (!isset($state['images'][$imageId]) || ($state['fail']['image_languages_at'] ?? 0) === $state['image_put']) {
            fake_ws_error(400, 'The image does not exist.');

            return;
        }
        $xml = simplexml_load_string((string) file_get_contents('php://input'));
        $languages = [];
        foreach ($xml->heavenlangimages_image->languages->language ?? [] as $language) {
            $languages[] = (string) $language->iso_code;
        }
        $legends = [];
        foreach ($xml->heavenlangimages_image->legends->legend ?? [] as $legend) {
            $legends[(string) $legend->iso_code] = (string) $legend->value;
        }
        $state['image_languages'][$imageId] = ['languages' => $languages, 'legends' => $legends];
        fake_ws_xml('<prestashop><heavenlangimages_image><id>' . $imageId . '</id></heavenlangimages_image></prestashop>');

        return;
    }
    if ($resource === 'product_features' && $method === 'GET') {
        fake_ws_json(['product_features' => array_values($state['features'])]);

        return;
    }
    if ($resource === 'product_feature_values' && $method === 'GET') {
        $feature = trim((string) ($_GET['filter']['id_feature'] ?? ''), '[]');
        fake_ws_json(['product_feature_values' => array_values(array_filter($state['feature_values'], static fn (array $v): bool => $v['id_feature'] === $feature))]);

        return;
    }
    if (in_array($resource, ['product_features', 'product_feature_values'], true) && $method === 'POST') {
        $xml = simplexml_load_string((string) file_get_contents('php://input'));
        $node = $resource === 'product_features' ? $xml->product_feature : $xml->product_feature_value;
        $field = $resource === 'product_features' ? 'name' : 'value';
        $newId = (string) ++$state['next_feature'];
        $row = ['id' => $newId, $field => []];
        foreach ($node->$field->language as $language) {
            $row[$field][] = ['id' => (string) $language['id'], 'value' => (string) $language];
        }
        if ($resource === 'product_features') {
            $state['features'][$newId] = $row;
        } else {
            $state['feature_values'][$newId] = $row + ['id_feature' => (string) $node->id_feature, 'custom' => '0'];
        }
        fake_ws_json([$resource === 'product_features' ? 'product_feature' : 'product_feature_value' => ['id' => (int) $newId]]);

        return;
    }
    fake_ws_error(405, "$method $resource is not supported by the fake");
}

/**
 * The product as PrestaShop's webservice gives it in XML.
 *
 * @param array<string, mixed> $state
 */
function fake_ws_product_xml(array $state, string $id): string
{
    $product = $state['products'][$id];
    $doc = new DOMDocument('1.0', 'UTF-8');
    $root = $doc->appendChild($doc->createElement('prestashop'));
    $node = $root->appendChild($doc->createElement('product'));
    $cover = '';
    $images = [];
    foreach ($state['images'] as $imageId => $image) {
        if ($image['product'] === $id) {
            $images[] = (string) $imageId;
            $cover = $image['cover'] ? (string) $imageId : $cover;
        }
    }
    $fields = $product['fields'] + ['id_default_image' => $cover, 'manufacturer_name' => '', 'new' => '0', 'position_in_category' => '0'];
    foreach ($fields as $name => $value) {
        $node->appendChild($doc->createElement($name))->appendChild($doc->createCDATASection((string) $value));
    }
    foreach ($product['lang'] as $name => $values) {
        $field = $node->appendChild($doc->createElement($name));
        foreach ($values as $languageId => $value) {
            $language = $field->appendChild($doc->createElement('language'));
            $language->setAttribute('id', (string) $languageId);
            $language->appendChild($doc->createCDATASection((string) $value));
        }
    }
    $associations = $node->appendChild($doc->createElement('associations'));
    $lists = [
        'categories' => ['category', array_map(static fn ($c): array => ['id' => $c], $product['categories'])],
        'images' => ['image', array_map(static fn ($i): array => ['id' => $i], $images)],
        'product_features' => ['product_feature', $product['features']],
        'stock_availables' => ['stock_available', $product['stock']],
        'product_bundle' => ['product', []],
    ];
    foreach ($lists as $name => [$itemName, $items]) {
        $list = $associations->appendChild($doc->createElement($name));
        foreach ($items as $item) {
            $itemNode = $list->appendChild($doc->createElement($itemName));
            foreach ($item as $field => $value) {
                $itemNode->appendChild($doc->createElement($field))->appendChild($doc->createCDATASection((string) $value));
            }
        }
    }

    return (string) $doc->saveXML();
}

/**
 * A PUT of a product, with PrestaShop's rules (see the file comment).
 *
 * @param array<string, mixed> $state
 */
function fake_ws_put(array &$state, string $id, string $body): void
{
    if (!empty($state['fail']['put'])) {
        fake_ws_error(400, 'Validation error: "name"');

        return;
    }
    $xml = simplexml_load_string($body);
    $node = $xml !== false ? $xml->product : null;
    if ($node === null || (string) $node->id !== $id) {
        fake_ws_error(400, 'id is required when modifying a resource');

        return;
    }
    foreach (['manufacturer_name', 'new', 'quantity'] as $readOnly) {
        if (isset($node->$readOnly)) {
            fake_ws_error(400, "parameter \"$readOnly\" not writable. Please remove this attribute of this XML");

            return;
        }
    }
    if (isset($node->position_in_category) && (int) $node->position_in_category <= 0) {
        fake_ws_error(500, 'You cannot set 0 or a negative position, the minimum is 1.');

        return;
    }
    $product = &$state['products'][$id];
    foreach (array_keys($product['fields']) as $field) {
        // Like ObjectModel: a missing field is null (NULL in a nullable column, else ''), '' in a nullable number is 0.
        $nullable = in_array($field, ['low_stock_threshold'], true);
        if (!isset($node->$field)) {
            $product['fields'][$field] = $nullable ? null : '';
        } else {
            $product['fields'][$field] = $nullable && (string) $node->$field === '' ? '0' : (string) $node->$field;
        }
    }
    foreach (array_keys($product['lang']) as $field) {
        foreach ($node->$field->language ?? [] as $language) {
            $product['lang'][$field][(string) $language['id']] = (string) $language;
        }
    }
    if (isset($node->id_default_image) && (string) $node->id_default_image !== '') {
        foreach ($state['images'] as $imageId => $image) {
            if ($image['product'] === $id) {
                $state['images'][$imageId]['cover'] = (string) $imageId === (string) $node->id_default_image;
            }
        }
    }
    if (isset($node->associations->categories)) {
        $product['categories'] = [];
        foreach ($node->associations->categories->category as $category) {
            $product['categories'][] = (string) $category->id;
        }
    }
    if (isset($node->associations->product_features)) {
        $product['features'] = [];
        foreach ($node->associations->product_features->product_feature as $feature) {
            $product['features'][] = ['id' => (string) $feature->id, 'id_feature_value' => (string) $feature->id_feature_value];
        }
    }
    $state['puts'][] = $body;
    if (isset($node->associations->product_bundle)) {
        fake_ws_error(500, 'Error occurred while setting the product_bundle value');

        return;
    }
    fake_ws_json(['product' => ['id' => (int) $id]]);
}

/**
 * Sends a JSON answer.
 *
 * @param array<mixed> $data
 */
function fake_ws_json(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data === [] ? new stdClass() : $data);
}

/**
 * Sends an XML answer.
 */
function fake_ws_xml(string $xml): void
{
    header('Content-Type: text/xml');
    echo $xml;
}

/**
 * Sends a webservice error.
 */
function fake_ws_error(int $status, string $message): void
{
    http_response_code($status);
    fake_ws_json(['errors' => [['code' => 0, 'message' => $message]]]);
}
