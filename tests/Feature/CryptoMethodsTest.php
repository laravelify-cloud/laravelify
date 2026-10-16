<?php

namespace Tests\Feature;

use App\Livewire\Database\Index as DatabaseIndex;
use App\Models\CryptoMethod;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CryptoMethods;
use App\Services\SiteCommand;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CryptoMethodsTest extends TestCase
{
    use RefreshDatabase;

    /** The ffkristal Crypto: AES-256-CBC with an IV from the key, so the same text always encrypts the same. */
    private const ENCRYPT = "return openssl_encrypt(\$text, 'AES-256-CBC', 'site-secret', 0, substr(hash('sha256', 'site-secret'), 0, 16));";

    private const DECRYPT = "return openssl_decrypt(\$text, 'AES-256-CBC', 'site-secret', 0, substr(hash('sha256', 'site-secret'), 0, 16));";

    /** @var list<string> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The site sandbox, here: the same command run by this machine's PHP.
        $this->mock(SiteCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturnUsing(function (Website $website, string $command): CommandResult {
                $this->commands[] = $command;
                $local = (string) preg_replace('/^php[0-9.]*/', escapeshellarg(PHP_BINARY), $command);
                $process = Process::fromShellCommandline($local);
                $process->run();

                return new CommandResult($process->isSuccessful(), false, [$command], $process->getOutput().$process->getErrorOutput(), (int) $process->getExitCode());
            });
        });
    }

    private function method(array $attributes = []): CryptoMethod
    {
        return CryptoMethod::factory()->create([
            'website_id' => Website::factory()->create(['type' => 'laravel', 'php_version' => '8.4'])->id,
            'boot_laravel' => false,
            'encrypt_code' => self::ENCRYPT,
            'decrypt_code' => self::DECRYPT,
            ...$attributes,
        ]);
    }

    public function test_a_method_encrypts_and_decrypts_in_the_site_with_its_own_code(): void
    {
        $method = $this->method();
        $crypto = app(CryptoMethods::class);
        $expected = openssl_encrypt('AZE1234567', 'AES-256-CBC', 'site-secret', 0, substr(hash('sha256', 'site-secret'), 0, 16));

        $this->assertSame($expected, $crypto->encrypt($method, 'AZE1234567'));
        $this->assertSame(['AZE1234567', null], $crypto->decrypt($method, [$expected, 'not-encrypted']));
        $this->assertStringStartsWith('php8.4 -r ', $this->commands[0], 'It runs with the site\'s PHP version.');
        $this->assertStringNotContainsString('site-secret', $this->commands[0].'', 'The code travels base64-encoded, not as shell text.');
    }

    public function test_a_failing_method_says_why(): void
    {
        $method = $this->method(['encrypt_code' => "throw new RuntimeException('no key set');"]);

        $this->expectExceptionMessage('The method failed: no key set');
        app(CryptoMethods::class)->encrypt($method, 'x');
    }

    public function test_methods_are_managed_and_tried_on_the_encryption_tab(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
        $site = Website::factory()->create(['type' => 'laravel', 'name' => 'ffkristal-backend']);
        Website::factory()->create(['type' => 'nextjs', 'name' => 'front']);

        Livewire::test(DatabaseIndex::class)
            ->set('tab', 'encryption')
            ->call('newCryptoMethod')
            ->set('cryptoName', 'ffkristal passport')
            ->set('cryptoWebsite', (string) $site->id)
            ->set('cryptoBoot', false)
            ->set('cryptoEncrypt', self::ENCRYPT)
            ->set('cryptoDecrypt', self::DECRYPT)
            ->call('saveCryptoMethod')
            ->assertHasNoErrors()
            ->assertSee('ffkristal passport')
            ->set('cryptoTryText', 'AZE1234567')
            ->call('tryCryptoMethod')
            ->assertSet('cryptoTried.decrypted', 'AZE1234567');

        $saved = CryptoMethod::query()->sole();
        $this->assertSame(self::ENCRYPT, $saved->encrypt_code);
        $this->assertNotSame(self::ENCRYPT, $saved->getRawOriginal('encrypt_code'), 'The code is encrypted at rest.');

        Livewire::test(DatabaseIndex::class)
            ->set('tab', 'encryption')
            ->call('newCryptoMethod')
            ->set('cryptoName', 'front')
            ->set('cryptoWebsite', (string) Website::query()->where('name', 'front')->value('id'))
            ->call('saveCryptoMethod')
            ->assertHasErrors(['cryptoWebsite']);
    }
}
