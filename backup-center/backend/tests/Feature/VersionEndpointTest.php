<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * /api/version 端点测试。
 * 该端点读取构建时写入的 backend/VERSION，供页脚展示真实部署版本，
 * 避免"部署了新版但界面仍显示硬编码旧版本号"的回潮问题。
 */
class VersionEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('VERSION');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    protected function tearDown(): void
    {
        $path = base_path('VERSION');
        if (is_file($path)) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_version_endpoint_returns_expected_keys(): void
    {
        $response = $this->getJson('/api/version');

        $response->assertStatus(200)
            ->assertJsonStructure(['version', 'commit', 'commit_short', 'build_date', 'channel']);
    }

    public function test_version_endpoint_parses_version_file(): void
    {
        file_put_contents(base_path('VERSION'), implode("\n", [
            'BC_VERSION=v1.2.0',
            'BC_COMMIT=abcdef1234567890abcdef1234567890abcdef12',
            'BC_COMMIT_SHORT=abcdef1',
            'BC_BUILD_DATE=2026-10-15T14:40:00Z',
            'BC_CHANNEL=release',
        ]) . "\n");

        $response = $this->getJson('/api/version');

        $response->assertStatus(200)
            ->assertJson([
                'version'      => 'v1.2.0',
                'commit'       => 'abcdef1234567890abcdef1234567890abcdef12',
                'commit_short' => 'abcdef1',
                'build_date'   => '2026-10-15T14:40:00Z',
                'channel'      => 'release',
            ]);
    }

    public function test_version_endpoint_returns_nulls_when_no_file(): void
    {
        $response = $this->getJson('/api/version');

        $response->assertStatus(200)
            ->assertJson([
                'version' => null,
                'channel' => null,
            ]);
    }
}
