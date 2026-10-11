<?php

namespace Gojira\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;

/**
 * Tool_Network::sendMail com o PHPMailer carregado pelo autoload do Composer.
 *
 * Rodam em processo separado porque incluir o network.class.php registra o
 * autoload de system/vendor, e os demais testes carregam o framework sem ele.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NetworkMailerTest extends TestCase
{
    private function network(): \Tool_Network
    {
        require_once GOJIRA_SYSTEM_PATH . 'class/tool/network.class.php';

        return new \Tool_Network();
    }

    public function testPhpMailerIsResolvedFromComposerVendor(): void
    {
        $this->network();

        self::assertTrue(class_exists(\PHPMailer\PHPMailer\PHPMailer::class));
        self::assertTrue(version_compare(\PHPMailer\PHPMailer\PHPMailer::VERSION, '6.9.0', '>='));

        $file = (string) (new \ReflectionClass(\PHPMailer\PHPMailer\PHPMailer::class))->getFileName();
        self::assertStringEndsWith('system/vendor/phpmailer/phpmailer/src/PHPMailer.php', str_replace('\\', '/', $file));
    }

    public function testLoadingTheToolDoesNotLoadPhpMailerEagerly(): void
    {
        $this->network();

        self::assertFalse(class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false));
    }

    public function testMissingFromReturnsFalseWithoutDebug(): void
    {
        self::assertFalse($this->network()->sendMail('dest@example.com', 'Assunto', '<p>x</p>', []));
    }

    public function testMissingFromThrowsPhpMailerExceptionInDebug(): void
    {
        $network = $this->network();

        $this->expectException(\PHPMailer\PHPMailer\Exception::class);
        $this->expectExceptionMessage('From is required in config array');

        $network->sendMail('dest@example.com', 'Assunto', '<p>x</p>', ['debug' => true]);
    }

    public function testInvalidRecipientIsRejectedByPhpMailerBeforeSending(): void
    {
        $network = $this->network();

        $this->expectException(\PHPMailer\PHPMailer\Exception::class);

        $network->sendMail('nao-e-email', 'Assunto', '<p>x</p>', ['from' => 'origem@example.com', 'debug' => true]);
    }
}
