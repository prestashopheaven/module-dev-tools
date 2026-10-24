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

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/update-sign.php';

/**
 * `ps-module sign` and the Update-* trailers: the signature format the update server and the updater verify.
 */
final class UpdateSignTest extends TestCase
{
    private static function zip(string $module = 'heavenexample', string $version = '1.2.3', bool $twoRoots = false): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString("$module/$module.php", "<?php\nclass X { public function __construct() { \$this->version = '$version'; } }\n");
        $zip->addFromString("$module/src/A.php", '<?php');
        if ($twoRoots) {
            $zip->addFromString('other/readme.txt', 'x');
        }
        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }

    private static function keyPair(): string
    {
        return sodium_crypto_sign_seed_keypair(str_repeat('k', 32));
    }

    public function testSignatureVerifiesAndDescribesExactlyThisZip(): void
    {
        $zip = self::zip();
        $keyPair = self::keyPair();
        $signature = update_sign_zip($zip, sodium_crypto_sign_secretkey($keyPair), ['mode' => 'accept', 'prestashop' => ['8.1-8.2, 9.2'], 'php' => ['8.1-8.3'], 'rollout' => 50]);
        $envelope = json_decode($signature, true);

        self::assertSame(1, $envelope['format']);
        self::assertSame(substr(hash('sha256', sodium_crypto_sign_publickey($keyPair)), 0, 16), $envelope['key_id']);
        $statementBytes = base64_decode($envelope['statement'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($envelope['signature'], true), $statementBytes, sodium_crypto_sign_publickey($keyPair)));

        $statement = json_decode($statementBytes, true);
        self::assertSame('heavenexample', $statement['module']);
        self::assertSame('1.2.3', $statement['version']);
        self::assertSame(hash('sha256', $zip), $statement['sha256']);
        self::assertSame(strlen($zip), $statement['size']);
        self::assertSame(['mode' => 'accept', 'prestashop' => ['8.1', '8.2', '9.2'], 'php' => ['8.1', '8.2', '8.3'], 'channel' => 'stable', 'rollout' => 50, 'min_updater' => null], $statement['publish']);
    }

    public function testDefaultsArePending(): void
    {
        $statement = update_statement_of(update_sign_zip(self::zip(), sodium_crypto_sign_secretkey(self::keyPair())));
        self::assertSame('pending', $statement['publish']['mode']);
        self::assertSame([], $statement['publish']['prestashop']);
        self::assertSame(100, $statement['publish']['rollout']);
    }

    public function testTrailersOfAReleaseCommit(): void
    {
        $publish = update_publish_from_trailers(<<<'MSG'
            Release 1.2.3 - Fix the price check

            Some text.

            Update-Publish: accept
            Update-PrestaShop: 8.1, 8.2, 9.0, 9.1, 9.2
            Update-PHP: 8.1-8.5
            Update-Channel: beta
            Update-Rollout: 25%
            Update-Min-Updater: 1.1.0
            Co-Authored-By: Someone <x@example.com>
            MSG);

        self::assertSame(['mode' => 'accept', 'prestashop' => ['8.1, 8.2, 9.0, 9.1, 9.2'], 'php' => ['8.1-8.5'], 'channel' => 'beta', 'rollout' => 25, 'min_updater' => '1.1.0'], $publish);
        $normalised = update_normalise_publish($publish);
        self::assertSame(['8.1', '8.2', '9.0', '9.1', '9.2'], $normalised['prestashop']);
        self::assertSame(['8.1', '8.2', '8.3', '8.4', '8.5'], $normalised['php']);
    }

    public function testOptionsWinOverTrailers(): void
    {
        $publish = array_merge(update_publish_from_trailers("Update-Publish: accept\nUpdate-Rollout: 10"), update_publish_from_options(['rollout' => '100', 'publish' => 'pending']));
        self::assertSame('pending', $publish['mode']);
        self::assertSame('100', $publish['rollout']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPublish(): iterable
    {
        yield 'accept without lines' => [['mode' => 'accept'], 'needs Update-PrestaShop'];
        yield 'bad mode' => [['mode' => 'yes'], 'accept or pending'];
        yield 'bad channel' => [['channel' => 'nightly'], 'stable or beta'];
        yield 'rollout too high' => [['rollout' => 150], '0 to 100'];
        yield 'bad min updater' => [['min_updater' => '1.0'], '1.2.3'];
        yield 'bad line' => [['prestashop' => ['nine']], 'not a major.minor'];
        yield 'range over majors' => [['php' => ['7.4-8.1']], 'one major version'];
        yield 'range down' => [['php' => ['8.5-8.1']], 'go up'];
    }

    /** @param array<string, mixed> $publish */
    #[DataProvider('invalidPublish')]
    public function testInvalidPublishInstructions(array $publish, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        update_normalise_publish($publish);
    }

    public function testUnknownTrailerIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown trailer Update-Foo');
        update_publish_from_trailers('Update-Foo: bar');
    }

    public function testZipMustHaveOneModuleFolderAndAVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly one folder');
        update_sign_zip(self::zip(twoRoots: true), sodium_crypto_sign_secretkey(self::keyPair()));
    }

    public function testInvalidKeyIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        update_sign_zip(self::zip(), 'short');
    }

    public function testSigningKeyFromEnvironment(): void
    {
        $secret = sodium_crypto_sign_secretkey(self::keyPair());
        putenv('UPDATE_SIGNING_KEY=' . base64_encode($secret));
        try {
            self::assertSame($secret, update_signing_key());
        } finally {
            putenv('UPDATE_SIGNING_KEY');
        }
    }

    public function testSignCommandWritesSigNextToTheZip(): void
    {
        $file = sys_get_temp_dir() . '/heavenexample-' . uniqid() . '.zip';
        file_put_contents($file, self::zip());
        putenv('UPDATE_SIGNING_KEY=' . base64_encode(sodium_crypto_sign_secretkey(self::keyPair())));
        try {
            ob_start();
            $code = update_sign_cli([$file, '--publish=accept', '--prestashop=9.2', '--php=8.3']);
            $output = (string) ob_get_clean();
            self::assertSame(0, $code, $output);
            self::assertFileExists($file . '.sig');
            self::assertSame('accept', update_statement_of((string) file_get_contents($file . '.sig'))['publish']['mode']);
            self::assertStringNotContainsString(base64_encode(sodium_crypto_sign_secretkey(self::keyPair())), $output, 'the key is never printed');
        } finally {
            putenv('UPDATE_SIGNING_KEY');
            @unlink($file);
            @unlink($file . '.sig');
        }
    }
}
