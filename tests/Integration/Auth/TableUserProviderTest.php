<?php

namespace Gojira\Tests\Integration\Auth;

use Gojira\Tests\Support\AuthFixtures;
use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Gojira_TableUserProvider contra banco real: busca por login, e-mail, id e
 * tipo, detecção do esquema e gravação do hash.
 */
final class TableUserProviderTest extends IntegrationTestCase
{
    use AuthFixtures;

    /** @var array<string, int> */
    private $ids = [];

    private function provider(bool $useEmail = true): \Gojira_TableUserProvider
    {
        return new \Gojira_TableUserProvider(self::$connection, $this->userTable, $this->typeTable, $useEmail);
    }

    private function standardUsers(): void
    {
        $this->createAuthTables();
        $this->ids['ana']    = $this->addUser('ana', 'segredo', 1, 2);
        $this->ids['carlos'] = $this->addUser('carlos', 'segredo', 2, 2);
        $this->ids['bia']    = $this->addUser('bia', 'segredo', 1, 1);
        $this->ids['quote']  = $this->addUser('o"neil', 'segredo', 1, 2);
    }

    public function testFindByLoginReturnsTheUserWithItsFields(): void
    {
        $this->standardUsers();

        $user = $this->provider()->findByLogin('ana', ['ADM']);

        self::assertInstanceOf(\Gojira_GenericUser::class, $user);
        self::assertEquals($this->ids['ana'], $user->getAuthId());
        self::assertSame('ana', $user->getAuthLogin());
        self::assertSame('ana@example.test', $user->getAuthEmail());
        self::assertEquals(1, $user->getAuthType());
        self::assertSame(['ADM'], $user->getRoles());
        self::assertSame('Nome ana', $user->getName());
        self::assertTrue($user->isActive());
        self::assertSame($this->storedHash($this->ids['ana']), $user->getAuthPasswordHash());
    }

    public function testFindByLoginAcceptsTheEmail(): void
    {
        $this->standardUsers();

        self::assertEquals($this->ids['ana'], $this->provider()->findByLogin('ana@example.test', ['ADM'])->getAuthId());
    }

    public function testFindByLoginIgnoresTheEmailWhenDisabled(): void
    {
        $this->standardUsers();

        self::assertNull($this->provider(false)->findByLogin('ana@example.test', ['ADM']));
        self::assertNotNull($this->provider(false)->findByLogin('ana', ['ADM']));
    }

    /** @return array<string, array{string}> */
    public function typeSpellings(): array
    {
        return ['shortcode' => ['ADM'], 'id' => ['1'], 'nome' => ['admin']];
    }

    /** @dataProvider typeSpellings */
    public function testTypeCanBeGivenAsShortcodeIdOrName(string $type): void
    {
        $this->standardUsers();

        self::assertNotNull($this->provider()->findByLogin('ana', [$type]));
    }

    public function testUserOfAnotherTypeIsNotFound(): void
    {
        $this->standardUsers();

        self::assertNull($this->provider()->findByLogin('carlos', ['ADM']));
        self::assertNotNull($this->provider()->findByLogin('carlos', ['ADM', 'CLI']));
    }

    public function testUnknownTypeFindsNobody(): void
    {
        $this->standardUsers();

        self::assertNull($this->provider()->findByLogin('ana', ['XYZ']));
    }

    public function testEmptyTypeListDoesNotFilter(): void
    {
        $this->standardUsers();

        self::assertNotNull($this->provider()->findByLogin('carlos', []));
    }

    public function testQuotesInLoginAreData(): void
    {
        $this->standardUsers();

        self::assertEquals($this->ids['quote'], $this->provider()->findByLogin('o"neil', ['ADM'])->getAuthId());
        self::assertNull($this->provider()->findByLogin('ana" OR "1"="1', ['ADM']));
        self::assertNull($this->provider()->findByLogin("ana' OR '1'='1", ['ADM']));
    }

    public function testQuotesInTypeAreData(): void
    {
        $this->standardUsers();

        self::assertNull($this->provider()->findByLogin('ana', ['ADM" OR "1"="1']));
    }

    public function testFindByIdHonoursTheType(): void
    {
        $this->standardUsers();

        self::assertSame('ana', $this->provider()->findById($this->ids['ana'], ['ADM'])->getAuthLogin());
        self::assertNull($this->provider()->findById($this->ids['ana'], ['CLI']));
        self::assertNull($this->provider()->findById(999999, ['ADM']));
    }

    public function testInactiveUserIsFoundButNotActive(): void
    {
        $this->standardUsers();

        $user = $this->provider()->findByLogin('bia', ['ADM']);

        self::assertFalse($user->isActive());
        self::assertEquals(1, $user->getRawStatus());
    }

    public function testActiveColumnSchemaIsDetected(): void
    {
        $this->createAuthTables(true, 'active');
        $this->addUser('ana', 'segredo', 1, 1);
        $this->addUser('bia', 'segredo', 1, -1);

        $provider = $this->provider();

        self::assertSame(['PENDING' => -1, 'INACTIVE' => 0, 'ACTIVE' => 1], $provider->getStatusList());
        self::assertTrue($provider->findByLogin('ana', ['ADM'])->isActive());
        self::assertFalse($provider->findByLogin('bia', ['ADM'])->isActive());
    }

    public function testTableWithoutNameColumnGivesEmptyName(): void
    {
        $this->createAuthTables(true, 'status', false);
        $this->addUser('ana', 'segredo');

        self::assertSame('', $this->provider()->findByLogin('ana', ['ADM'])->getName());
    }

    public function testWithoutTypeTableTheTypeColumnHoldsTheCode(): void
    {
        $this->createAuthTables(false);
        $id = $this->addUser('ana', 'segredo', 'ADM');
        $this->addUser('carlos', 'segredo', 'CLI');

        $user = $this->provider()->findByLogin('ana', ['ADM']);

        self::assertEquals($id, $user->getAuthId());
        self::assertSame(['ADM'], $user->getRoles());
        self::assertSame('ADM', $user->getAuthType());
        self::assertNull($this->provider()->findByLogin('carlos', ['ADM']));
        self::assertEquals($id, $this->provider()->findById($id, ['ADM'])->getAuthId());
    }

    public function testUpdatePasswordHashWritesOnlyThatUser(): void
    {
        $this->standardUsers();
        $before = $this->storedHash($this->ids['carlos']);

        self::assertTrue($this->provider()->updatePasswordHash($this->ids['ana'], 'novo-hash'));

        self::assertSame('novo-hash', $this->storedHash($this->ids['ana']));
        self::assertSame($before, $this->storedHash($this->ids['carlos']));
    }

    public function testUpdatePasswordHashWithExpectedOldHash(): void
    {
        $this->standardUsers();
        $old = $this->storedHash($this->ids['ana']);

        self::assertFalse($this->provider()->updatePasswordHash($this->ids['ana'], 'novo-hash', 'outro-hash'));
        self::assertSame($old, $this->storedHash($this->ids['ana']), 'hash trocado por outra requisição não é sobrescrito');

        self::assertTrue($this->provider()->updatePasswordHash($this->ids['ana'], 'novo-hash', $old));
        self::assertSame('novo-hash', $this->storedHash($this->ids['ana']));
    }
}
