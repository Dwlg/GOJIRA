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
            $cipher = $this->tools->encrypt('chave-secreta', $plain);

            self::assertNotSame($plain, $cipher);
            self::assertSame($plain, $this->tools->decrypt('chave-secreta', $cipher));
        }
    }

    public function testCiphertextIsBase64WithIvAndTag(): void
    {
        $raw = base64_decode($this->tools->encrypt('k', 'abc'), true);

        self::assertNotFalse($raw);
        self::assertSame(12 + 16 + 3, strlen($raw));
    }

    public function testSameInputProducesDifferentCiphertexts(): void
    {
        self::assertNotSame($this->tools->encrypt('k', 'valor'), $this->tools->encrypt('k', 'valor'));
    }

    public function testWrongKeyReturnsFalse(): void
    {
        self::assertFalse($this->tools->decrypt('outra', $this->tools->encrypt('chave', 'valor')));
    }

    public function testTamperedCiphertextReturnsFalse(): void
    {
        $raw          = base64_decode($this->tools->encrypt('chave', 'valor'), true);
        $last         = strlen($raw) - 1;
        $raw[$last]   = chr(ord($raw[$last]) ^ 1);

        self::assertFalse($this->tools->decrypt('chave', base64_encode($raw)));
    }

    public function testMalformedInputReturnsFalse(): void
    {
        self::assertFalse($this->tools->decrypt('chave', 'não é base64!'));
        self::assertFalse($this->tools->decrypt('chave', base64_encode('curto')));
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
}
