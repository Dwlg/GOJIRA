<?php

namespace Gojira\Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * encrypt()/decrypt() com AES-256-GCM e a descontinuação de encrypt_str()/decrypt_str() (mcrypt).
 */
final class SecurityCryptoTest extends TestCase
{
    /** @var \Tool */
    private $tools;

    private const KEY = 'chave-secreta-de-teste';

    /** @var string[] */
    private $deprecations = [];

    protected function setUp(): void
    {
        $this->tools        = new \Tool();
        $this->deprecations = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->deprecations[] = $errstr;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        foreach (['', 'texto simples', "acentuação, \0 byte nulo e emoji \u{1F600}", str_repeat('x', 10000)] as $plain) {
            $cipher = $this->tools->encrypt(self::KEY, $plain);

            self::assertNotSame($plain, $cipher);
            self::assertSame($plain, $this->tools->decrypt(self::KEY, $cipher));
        }
    }

    public function testCiphertextIsBase64WithIvAndTag(): void
    {
        $raw = base64_decode($this->tools->encrypt(self::KEY, 'abc'), true);

        self::assertNotFalse($raw);
        self::assertSame(12 + 16 + 3, strlen($raw));
    }

    public function testSameInputProducesDifferentCiphertexts(): void
    {
        self::assertNotSame($this->tools->encrypt(self::KEY, 'valor'), $this->tools->encrypt(self::KEY, 'valor'));
    }

    public function testWrongKeyReturnsFalse(): void
    {
        self::assertFalse($this->tools->decrypt('outra-chave-de-teste', $this->tools->encrypt(self::KEY, 'valor')));
    }

    public function testTamperedCiphertextReturnsFalse(): void
    {
        $raw          = base64_decode($this->tools->encrypt(self::KEY, 'valor'), true);
        $last         = strlen($raw) - 1;
        $raw[$last]   = chr(ord($raw[$last]) ^ 1);

        self::assertFalse($this->tools->decrypt(self::KEY, base64_encode($raw)));
    }

    public function testMalformedInputReturnsFalse(): void
    {
        self::assertFalse($this->tools->decrypt(self::KEY, 'não é base64!'));
        self::assertFalse($this->tools->decrypt(self::KEY, base64_encode('curto')));
    }

    /**
     * @runInSeparateProcess
     */
    public function testEncryptStrIsDeprecatedAndFailsClearlyWithoutMcrypt(): void
    {
        if (function_exists('mcrypt_encrypt')) {
            self::markTestSkipped('Extensão mcrypt instalada; o caminho legado continua ativo.');
        }

        foreach (['encrypt_str', 'encrypt_str', 'decrypt_str'] as $function) {
            try {
                $this->tools->$function('chave', 'valor');
                self::fail($function . ' deveria lançar exceção sem mcrypt');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('mcrypt', $e->getMessage());
            }
        }

        // Um aviso por função e por requisição.
        self::assertCount(2, $this->deprecations);
        self::assertStringContainsString('encrypt_str', $this->deprecations[0]);
        self::assertStringContainsString('decrypt_str', $this->deprecations[1]);
    }

    public function testAadIsAuthenticated(): void
    {
        $cipher = $this->tools->encrypt(self::KEY, 'valor', 'usuario:42');

        self::assertSame('valor', $this->tools->decrypt(self::KEY, $cipher, 'usuario:42'));
        self::assertFalse($this->tools->decrypt(self::KEY, $cipher, 'usuario:43'));
        self::assertFalse($this->tools->decrypt(self::KEY, $cipher));
    }

    public function testCiphertextWithoutAadDoesNotDecryptWithAad(): void
    {
        self::assertFalse($this->tools->decrypt(self::KEY, $this->tools->encrypt(self::KEY, 'valor'), 'contexto'));
    }

    /**
     * @dataProvider weakKeys
     *
     * @param mixed $key
     */
    public function testEncryptRejectsEmptyOrShortKeys($key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tools->encrypt($key, 'valor');
    }

    /**
     * @dataProvider weakKeys
     *
     * @param mixed $key
     */
    public function testDecryptRejectsEmptyOrShortKeys($key): void
    {
        $cipher = $this->tools->encrypt(self::KEY, 'valor');

        $this->expectException(\InvalidArgumentException::class);

        $this->tools->decrypt($key, $cipher);
    }

    public function weakKeys(): array
    {
        return [
            'vazia'    => [''],
            'nula'     => [null],
            '15 bytes' => [str_repeat('a', 15)],
        ];
    }

    public function testSixteenByteKeyIsAccepted(): void
    {
        $key = str_repeat('a', 16);

        self::assertSame('valor', $this->tools->decrypt($key, $this->tools->encrypt($key, 'valor')));
    }
}
