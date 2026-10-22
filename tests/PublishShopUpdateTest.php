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

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * `ps-module publish shop --update` against a fake PrestaShop webservice (tests/Support/fake-webservice.php, run by
 * PHP's built-in server): texts in every language, images replaced with the cover first, nothing else changed, and
 * the failure paths.
 */
final class PublishShopUpdateTest extends TestCase
{
    /** @var resource|null the built-in server */
    private static $server;

    private static string $url = '';

    private static string $stateFile = '';

    private string $repo = '';

    /**
     * Starts the fake webservice once for all tests.
     */
    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$stateFile = sys_get_temp_dir() . '/ps-module-fake-ws-' . getmypid() . '.json';
        file_put_contents(self::$stateFile, '{}');
        $env = getenv() + ['FAKE_WS_STATE' => self::$stateFile];
        $env['FAKE_WS_STATE'] = self::$stateFile;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/Support/fake-webservice.php'], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, __DIR__ . '/Support', $env);
        self::assertIsResource($server);
        self::$server = $server;
        self::$url = "http://127.0.0.1:$port";
        for ($i = 0; $i < 100; ++$i) {
            $probe = @fsockopen('127.0.0.1', $port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50000);
        }
        self::fail('the fake webservice did not start');
    }

    /**
     * Stops the fake webservice.
     */
    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$stateFile);
    }

    /**
     * A module repository with texts in en and nl, a cover and two framed screenshots; a shop with product #12.
     */
    protected function setUp(): void
    {
        $this->repo = str_replace('\\', '/', sys_get_temp_dir()) . '/ps-module-shop-test-' . bin2hex(random_bytes(4));
        $files = [
            'module.spec.json' => json_encode(['display_name' => 'Price Drop Alerts', 'description' => 'Emails when a price drops.']),
            'i18n.json' => json_encode(['source' => 'en', 'languages' => ['nl']]),
            'i18n/nl.json' => json_encode(['strings' => ['ModulesHeavenfooAdmin' => ['Price Drop Alerts' => 'Prijsdalingsmeldingen', 'Emails when a price drops.' => ['text' => 'Mails als een prijs daalt.']]]]),
            'docs/docs.json' => json_encode(['marketing' => ['price' => 69.99], 'screenshots' => [['id' => 'front'], ['id' => 'configure']]]),
            'docs/content/en/marketing.json' => json_encode(['description' => "New **version**.\n\nSecond paragraph.", 'features' => ['Double opt-in']]),
            'docs/content/nl/marketing.json' => json_encode(['description' => 'Nieuwe versie.', 'features' => ['Dubbele opt-in']]),
            'docs/marketing/cover-1000.png' => 'png',
            'docs/framed/en/front.png' => 'png',
            'docs/framed/en/configure.png' => 'png',
            'heavenfoo/heavenfoo.php' => '<?php $this->version = \'1.1.0\';',
        ];
        foreach ($files as $file => $content) {
            @mkdir(dirname("$this->repo/$file"), 0777, true);
            file_put_contents("$this->repo/$file", (string) $content);
        }
        putenv('SALES_SHOP_URL=' . self::$url);
        putenv('SALES_SHOP_WEBSERVICE_KEY=TESTKEY0123456789');
        $this->writeState($this->shopState());
    }

    /**
     * Removes the module repository and the settings.
     */
    protected function tearDown(): void
    {
        putenv('SALES_SHOP_URL');
        putenv('SALES_SHOP_WEBSERVICE_KEY');
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->repo);
    }

    /**
     * Every shop language gets the module text in its language, or English when the module has none.
     */
    public function testUpdateReplacesTheTextsInEveryShopLanguage(): void
    {
        [$code] = $this->runUpdate();

        $product = $this->state()['products']['12'];
        self::assertSame(0, $code);
        self::assertSame(['1' => 'Price Drop Alerts', '2' => 'Prijsdalingsmeldingen', '3' => 'Price Drop Alerts'], $this->texts($product['lang']['name']));
        self::assertSame(['1' => '<p>Emails when a price drops.</p>', '2' => '<p>Mails als een prijs daalt.</p>', '3' => '<p>Emails when a price drops.</p>'], $this->texts($product['lang']['description_short']));
        self::assertSame("<p>New <strong>version</strong>.</p>\n<p>Second paragraph.</p>\n<ul>\n<li>Double opt-in</li>\n</ul>\n", $product['lang']['description']['1']);
        self::assertSame("<p>Nieuwe versie.</p>\n<ul>\n<li>Dubbele opt-in</li>\n</ul>\n", $product['lang']['description']['2']);
        self::assertSame(['1' => 'price-drop-alerts', '2' => 'prijsdalingsmeldingen', '3' => 'price-drop-alerts'], $this->texts($product['lang']['link_rewrite']));
        self::assertSame('Prijsdalingsmeldingen', $product['lang']['meta_title']['2']);
        self::assertSame('Mails als een prijs daalt.', $product['lang']['meta_description']['2']);
    }

    /**
     * The new images replace the old ones in docs order, the cover first; old images go only after the texts are saved.
     */
    public function testUpdateReplacesTheImagesWithTheCoverFirst(): void
    {
        $this->runUpdate();

        $state = $this->state();
        $images = array_filter($state['images'], static fn (array $i): bool => $i['product'] === '12');
        self::assertSame(['cover-1000.png', 'front.png', 'configure.png'], array_column(array_values($images), 'file'));
        self::assertSame([102, 103, 104], array_keys($images), 'the old images #100 and #101 are removed');
        self::assertTrue($images[102]['cover']);
        self::assertFalse($images[103]['cover'] || $images[104]['cover']);
        $order = array_values(array_filter($state['log'], static fn (string $l): bool => !str_starts_with($l, 'GET')));
        self::assertSame(['POST images/products/12', 'POST images/products/12', 'POST images/products/12', 'PUT products/12', 'DELETE images/products/12/100', 'DELETE images/products/12/101', 'PUT products/12'], $order, 'upload, texts, remove the old images, then the cover');
    }

    /**
     * Price, tax rule, category, active state, stock and the other fields come back as they were; read-only fields are never sent.
     */
    public function testUpdateChangesNothingElse(): void
    {
        $before = $this->state()['products']['12'];

        $this->runUpdate();

        $after = $this->state()['products']['12'];
        self::assertSame($before['fields'], $after['fields'], 'price, tax rule, category, active state, visibility, reference ... as they were');
        self::assertSame($before['categories'], $after['categories']);
        self::assertSame($before['stock'], $after['stock']);
        self::assertSame([['id' => '9', 'id_feature_value' => '90'], ['id' => '5', 'id_feature_value' => '50']], $after['features'], 'other features stay, Multistore: No becomes Yes');
        foreach ($this->state()['puts'] as $put) {
            self::assertStringNotContainsString('<manufacturer_name', $put);
            self::assertStringNotContainsString('<position_in_category', $put);
            self::assertStringNotContainsString('<new>', $put);
            self::assertStringNotContainsString('<categories', $put, 'associations other than the features are left alone');
            self::assertStringNotContainsString('<product_bundle', $put);
            self::assertStringNotContainsString('<stock_availables', $put);
        }
    }

    /**
     * The step shop is recorded for the version, and the manual steps are printed.
     */
    public function testUpdateRecordsTheStep(): void
    {
        [, $output] = $this->runUpdate();

        $recorded = json_decode((string) file_get_contents("$this->repo/.cache/publish/1.1.0.json"), true);
        self::assertStringContainsString('product #12 updated', $recorded['shop']['note']);
        self::assertStringContainsString('Virtual product tab: replace the file', $output);
        self::assertStringContainsString('Activate the product when it is ready', $output);
    }

    /**
     * Without a product with the module reference nothing is written and the exit code is 1.
     */
    public function testUpdateWithoutProductStopsWithExitOne(): void
    {
        $state = $this->shopState();
        $state['products'] = [];
        $this->writeState($state);

        [$code, $output] = $this->runUpdate();

        self::assertSame(1, $code);
        self::assertStringContainsString('no product with reference heavenfoo', $output);
        self::assertSame([], $this->writes());
    }

    /**
     * A key without products PUT or images DELETE stops the update before anything is written.
     */
    public function testAMissingPermissionStopsBeforeAnyWrite(): void
    {
        $state = $this->shopState();
        $state['denied'] = ['images delete', 'products put'];
        $this->writeState($state);

        [$code, $output] = $this->runUpdate();

        self::assertSame(1, $code);
        self::assertStringContainsString('The webservice key lacks: products PUT, images DELETE', $output);
        self::assertSame([], $this->writes());
    }

    /**
     * A dry run only reads: it shows what would change and records nothing.
     */
    public function testDryRunChangesNothing(): void
    {
        $before = $this->state();

        [$code, $output] = $this->runUpdate(true);

        $after = $this->state();
        self::assertSame(0, $code);
        self::assertSame([], $this->writes());
        self::assertSame($before['products'], $after['products']);
        self::assertSame($before['images'], $after['images']);
        self::assertStringContainsString('new text in 3 of 3 language(s)', $output);
        self::assertStringContainsString('replace the 2 current one(s): #100 #101', $output);
        self::assertStringContainsString('Dry run: nothing changed.', $output);
        self::assertFileDoesNotExist("$this->repo/.cache/publish/1.1.0.json");
    }

    /**
     * A failed upload removes the uploads of the run again and keeps the old images, the cover and the texts.
     */
    public function testAFailedImageUploadKeepsTheOldImagesAndTexts(): void
    {
        $state = $this->shopState();
        $state['fail']['upload_at'] = 2;
        $this->writeState($state);

        try {
            $this->runUpdate();
            self::fail('a failed upload must stop the update');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('image front.png not uploaded', $e->getMessage());
            self::assertStringContainsString('Product #12 is unchanged', $e->getMessage());
        }

        $after = $this->state();
        self::assertSame([100, 101], array_keys($after['images']), 'old images kept, the upload of this run removed again');
        self::assertTrue($after['images'][100]['cover']);
        self::assertSame('Old name', $after['products']['12']['lang']['name']['1']);
        self::assertNotContains('PUT products/12', $after['log']);
        self::assertNotContains('DELETE images/products/12/100', $after['log']);
    }

    /**
     * A PUT the shop refuses leaves the product exactly as it was.
     */
    public function testARefusedPutRemovesTheNewImagesAgain(): void
    {
        $state = $this->shopState();
        $state['fail']['put'] = true;
        $this->writeState($state);

        try {
            $this->runUpdate();
            self::fail('a refused PUT must stop the update');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('the shop refused the new texts', $e->getMessage());
        }

        $after = $this->state();
        self::assertSame([100, 101], array_keys($after['images']));
        self::assertTrue($after['images'][100]['cover']);
        self::assertSame($this->shopState()['products']['12'], $after['products']['12']);
    }

    /**
     * The PUT body: read-only fields out, only the given languages changed, the feature value replaced, the cover set or left out.
     */
    public function testUpdateXmlLeavesOutReadOnlyFieldsAndSetsTheCover(): void
    {
        $doc = new DOMDocument();
        $doc->loadXML('<prestashop><product><id>7</id><manufacturer_name>X</manufacturer_name><price>5.000000</price><id_default_image>3</id_default_image><name><language id="1">Old</language><language id="2">Oud</language></name><associations><product_features><product_feature><id>5</id><id_feature_value>51</id_feature_value></product_feature></product_features></associations></product></prestashop>');

        $xml = publish_shop_update_xml($doc, ['name' => [1 => 'New']], [['feature' => 5, 'value' => 50, 'label' => 'Multistore: Yes']], 9, ['manufacturer_name']);
        $withoutCover = publish_shop_update_xml($doc, [], [], 0, []);

        self::assertStringNotContainsString('manufacturer_name', $xml);
        self::assertStringContainsString('<price>5.000000</price>', $xml);
        self::assertStringContainsString('<language id="1">New</language><language id="2">Oud</language>', $xml);
        self::assertStringContainsString('<id_default_image>9</id_default_image>', $xml);
        self::assertStringContainsString('<product_feature><id>5</id><id_feature_value>50</id_feature_value></product_feature>', $xml);
        self::assertStringNotContainsString('<id_feature_value>51', $xml);
        self::assertStringNotContainsString('id_default_image', $withoutCover);
        self::assertStringNotContainsString('<associations', $withoutCover, 'without features no association is sent: the shop keeps them all');
    }

    /**
     * Creating still sends every text field (the texts are shared with --update).
     */
    public function testCreateXmlStillHasEveryText(): void
    {
        $plan = ['module' => 'heavenfoo', 'source' => 'en', 'name' => ['en' => 'Foo'], 'short' => ['en' => 'Short'], 'description' => ['en' => '<p>Long</p>'], 'price' => 10.0];
        $schema = ['fields' => ['reference', 'active', 'price', 'name', 'description_short', 'description', 'link_rewrite', 'meta_title', 'meta_description'], 'associations' => []];

        $xml = publish_shop_product_xml($plan, [1 => ['iso' => 'en', 'name' => 'English', 'module' => 'en']], $schema, [], 2, 0);

        foreach (['<name><language id="1">Foo</language>', '<link_rewrite><language id="1">foo</language>', '<meta_description><language id="1">Short</language>', '<active>0</active>'] as $part) {
            self::assertStringContainsString($part, $xml);
        }
    }

    /**
     * Runs publish shop --update on the test repository; returns the exit code and the output.
     *
     * @return array{0: int, 1: string}
     */
    private function runUpdate(bool $dryRun = false): array
    {
        $ctx = publish_context($this->repo, $this->repo . '/heavenfoo');
        ob_start();
        try {
            $code = publish_cmd_shop($ctx, $dryRun, false, true);
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$code, $output];
    }

    /**
     * The shop: languages en, nl and de (no module texts: English), product #12 with two images (#100 the cover), the
     * feature Multistore (#5) with values Yes (#50) and No (#51, on the product) and another feature (#9).
     *
     * @return array<string, mixed>
     */
    private function shopState(): array
    {
        $old = static fn (string $text): array => ['1' => $text, '2' => $text, '3' => $text];

        return [
            'languages' => [
                ['id' => 1, 'iso_code' => 'en', 'language_code' => 'en-us', 'name' => 'English'],
                ['id' => 2, 'iso_code' => 'nl', 'language_code' => 'nl-nl', 'name' => 'Nederlands'],
                ['id' => 3, 'iso_code' => 'de', 'language_code' => 'de-de', 'name' => 'Deutsch'],
            ],
            'products' => ['12' => [
                'fields' => ['id' => '12', 'id_category_default' => '7', 'id_tax_rules_group' => '1', 'reference' => 'heavenfoo', 'price' => '59.990000', 'wholesale_price' => '0.000000', 'active' => '0', 'state' => '1', 'type' => 'virtual', 'is_virtual' => '1', 'product_type' => 'virtual', 'visibility' => 'both', 'available_for_order' => '1', 'show_price' => '1', 'condition' => 'new', 'minimal_quantity' => '1', 'low_stock_threshold' => null, 'ean13' => '', 'date_upd' => '2026-10-01 10:00:00'],
                'lang' => ['name' => $old('Old name'), 'description_short' => $old('<p>Old</p>'), 'description' => $old('<p>Old</p>'), 'link_rewrite' => $old('old-name'), 'meta_title' => $old('Old name'), 'meta_description' => $old('Old'), 'available_now' => $old('In stock')],
                'categories' => ['2', '7'],
                'features' => [['id' => '9', 'id_feature_value' => '90'], ['id' => '5', 'id_feature_value' => '51']],
                'stock' => [['id' => '33', 'id_product_attribute' => '0']],
            ]],
            'images' => ['100' => ['product' => '12', 'file' => 'old-cover.png', 'cover' => true], '101' => ['product' => '12', 'file' => 'old.png', 'cover' => false]],
            'next_image' => 101,
            'features' => ['5' => ['id' => '5', 'name' => [['id' => '1', 'value' => 'Multistore']]], '9' => ['id' => '9', 'name' => [['id' => '1', 'value' => 'Compatibility']]]],
            'feature_values' => [
                '50' => ['id' => '50', 'id_feature' => '5', 'custom' => '0', 'value' => [['id' => '1', 'value' => 'Yes']]],
                '51' => ['id' => '51', 'id_feature' => '5', 'custom' => '0', 'value' => [['id' => '1', 'value' => 'No']]],
            ],
            'next_feature' => 90,
            'delete_500' => true,
            'log' => [],
            'puts' => [],
        ];
    }

    /**
     * Writes the shop state for the fake webservice.
     *
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        file_put_contents(self::$stateFile, json_encode($state));
    }

    /**
     * The shop state after the requests.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return json_decode((string) file_get_contents(self::$stateFile), true);
    }

    /**
     * The requests that changed something (everything but GET).
     *
     * @return string[]
     */
    private function writes(): array
    {
        return array_values(array_filter($this->state()['log'], static fn (string $l): bool => !str_starts_with($l, 'GET')));
    }

    /**
     * Texts per language id with string keys, for comparisons.
     *
     * @param array<int|string, string> $texts
     *
     * @return array<string, string>
     */
    private function texts(array $texts): array
    {
        $out = [];
        foreach ($texts as $id => $text) {
            $out[(string) $id] = $text;
        }

        return $out;
    }
}
