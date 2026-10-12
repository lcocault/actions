<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Infrastructure\Config;
use Actions\Infrastructure\EnvFile;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testEnvFileParsing(): void
    {
        $parsed = EnvFile::parse("# commentaire\nA=1\nB=\"deux mots\"\nexport C='x' # note\nD=val # coupé\n\nbad line\n");
        self::assertSame(['A' => '1', 'B' => 'deux mots', 'C' => 'x', 'D' => 'val'], $parsed);
    }

    public function testFromArrayAppliesDefaults(): void
    {
        $c = Config::fromArray(['DB_NAME' => 'n', 'DB_USER' => 'u']);
        self::assertSame('production', $c->appEnv);
        self::assertSame('127.0.0.1', $c->dbHost);
        self::assertSame(5432, $c->dbPort);
        self::assertSame([], $c->mcpAllowedOrigins);
        self::assertSame(['127.0.0.1', 'localhost', '[::1]'], $c->portalAllowedHosts);
        self::assertSame(['127.0.0.1', 'localhost', '[::1]'], $c->mcpAllowedHosts);
    }

    public function testMcpAllowedHostsAreSplitAndLowercasedAndIndependentOfPortal(): void
    {
        $c = Config::fromArray(['DB_NAME' => 'n', 'DB_USER' => 'u', 'MCP_ALLOWED_HOSTS' => ' Actions.Local , ,127.0.0.1']);
        self::assertSame(['actions.local', '127.0.0.1'], $c->mcpAllowedHosts);
        self::assertSame(Config::DEFAULT_PORTAL_HOSTS, $c->portalAllowedHosts);
        $c = Config::fromArray(['DB_NAME' => 'n', 'DB_USER' => 'u', 'PORTAL_ALLOWED_HOSTS' => 'portail.local']);
        self::assertSame(Config::DEFAULT_PORTAL_HOSTS, $c->mcpAllowedHosts);
    }

    public function testEmptyMcpAllowedHostsFallBackToDefault(): void
    {
        foreach (['', ' , '] as $value) {
            $c = Config::fromArray(['DB_NAME' => 'n', 'DB_USER' => 'u', 'MCP_ALLOWED_HOSTS' => $value]);
            self::assertSame(Config::DEFAULT_MCP_HOSTS, $c->mcpAllowedHosts);
        }
    }

    public function testPortalAllowedHostsAreSplitAndLowercased(): void
    {
        $c = Config::fromArray(['DB_NAME' => 'n', 'DB_USER' => 'u', 'PORTAL_ALLOWED_HOSTS' => ' Actions.Local , ,127.0.0.1']);
        self::assertSame(['actions.local', '127.0.0.1'], $c->portalAllowedHosts);
    }

    public function testEmptyPortalAllowedHostsFallBackToDefault(): void
    {
        foreach (['', ' , '] as $value) {
            $c = Config::fromArray(['DB_NAME' => 'n', 'DB_USER' => 'u', 'PORTAL_ALLOWED_HOSTS' => $value]);
            self::assertSame(Config::DEFAULT_PORTAL_HOSTS, $c->portalAllowedHosts);
        }
    }

    public function testOriginsAreSplit(): void
    {
        $c = Config::fromArray(['DB_NAME' => 'n', 'DB_USER' => 'u', 'MCP_ALLOWED_ORIGINS' => ' http://a , ,http://b']);
        self::assertSame(['http://a', 'http://b'], $c->mcpAllowedOrigins);
    }

    public function testMissingRequiredVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::fromArray(['DB_USER' => 'u']);
    }

    public function testInvalidEnvAndPort(): void
    {
        foreach ([['APP_ENV' => 'prod'], ['DB_PORT' => 'abc'], ['DB_PORT' => '70000']] as $bad) {
            try {
                Config::fromArray($bad + ['DB_NAME' => 'n', 'DB_USER' => 'u']);
                self::fail('Exception attendue');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEnvironmentTakesPrecedenceOverFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "DB_NAME=fichier\nDB_USER=fu\n");
        try {
            $c = Config::load($file, ['DB_NAME' => 'env']);
            self::assertSame('env', $c->dbName);
            self::assertSame('fu', $c->dbUser);
        } finally {
            unlink($file);
        }
    }

    private const BASE = ['DB_NAME' => 'n', 'DB_USER' => 'u'];

    public function testAppBasePathDefaultsToNoPrefix(): void
    {
        self::assertSame('', Config::fromArray(self::BASE)->appBasePath);
        self::assertSame('', Config::fromArray(self::BASE + ['APP_BASE_PATH' => ''])->appBasePath);
        self::assertSame('', Config::fromArray(self::BASE + ['APP_BASE_PATH' => '/'])->appBasePath);
        self::assertSame('', (new Config('test', 'h', 1, 'n', 'u', 'p'))->appBasePath);
    }

    public function testAppBasePathIsNormalised(): void
    {
        foreach ([['/actions', '/actions'], ['/actions/', '/actions'], ['/a/b', '/a/b'], ['/A.b_c~d-1', '/A.b_c~d-1']] as [$in, $out]) {
            self::assertSame($out, Config::fromArray(self::BASE + ['APP_BASE_PATH' => $in])->appBasePath, $in);
        }
    }

    public function testInvalidAppBasePathFailsLoudly(): void
    {
        foreach (['actions', '//x', '/a//b', '/a b', '/a/../b', '/..', '/.', '/a/./b', '/a?x', '/a#b', '/%2e', '/actions//', '/é', "/a\n"] as $bad) {
            try {
                Config::fromArray(self::BASE + ['APP_BASE_PATH' => $bad]);
                self::fail("Exception attendue pour " . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('APP_BASE_PATH invalide.', $e->getMessage());
            }
        }
    }
}
