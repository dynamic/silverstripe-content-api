<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;

/**
 * Runs only where dynamic/silverstripe-essentials-tools is installed (e.g.
 * the essentials-ss6 testbed) — module-standalone CI skips it.
 */
class ColorTokenTest extends ContentApiTestCase
{
    private const PROVIDER = 'Dynamic\\Essentials\\Service\\ColorConfigurationProvider';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(ColorTokenTest::PROVIDER)) {
            $this->markTestSkipped('essentials-tools not installed');
        }

        $provider = ColorTokenTest::PROVIDER;

        if (empty($provider::getBackgroundColors())) {
            $this->markTestSkipped('no background_colors configured');
        }

        $this->adminToken = $this->mintTokenFor('adminUser');
    }

    public function testPaletteTokenResolvesOnWrite(): void
    {
        $provider = ColorTokenTest::PROVIDER;
        $expected = array_values($provider::getBackgroundColors())[0];

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestElement',
                    'externalId' => 'color-e1',
                    'fields' => ['Title' => 'Colored', 'BackgroundColor' => '$palette(0)'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame('created', $body['data']['results'][0]['status']);

        $element = ApiTestElement::get()->filter('FixtureIdentifier', 'color-e1')->first();
        $this->assertSame($expected, $element->BackgroundColor, 'token resolved, not stored as literal');
    }

    public function testOutOfRangePaletteFailsTheWrite(): void
    {
        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestElement',
                    'externalId' => 'color-e2',
                    'fields' => ['Title' => 'Bad color', 'BackgroundColor' => '$palette(99)'],
                ],
            ],
        ], $this->adminToken));

        $this->assertSame('TOKEN_RESOLUTION_FAILED', $body['data']['results'][0]['error']['code']);
        $this->assertNull(
            ApiTestElement::get()->filter('FixtureIdentifier', 'color-e2')->first(),
            'no white-on-white literals persisted'
        );
    }

    public function testButtonTokenResolvesToJsonBlob(): void
    {
        $provider = ColorTokenTest::PROVIDER;

        if (empty($provider::getButtonColorCombinations())) {
            $this->markTestSkipped('no button_colors configured');
        }

        $body = $this->decode($this->apiPost('batch', [
            'operations' => [
                [
                    'op' => 'upsert',
                    'class' => 'ApiTestElement',
                    'externalId' => 'color-e3',
                    'fields' => ['Title' => 'Buttoned', 'ButtonColor' => '$button(0, Primary)'],
                ],
            ],
        ], $this->adminToken));

        $this->assertNull($body['error']);
        $this->assertSame('created', $body['data']['results'][0]['status']);

        $element = ApiTestElement::get()->filter('FixtureIdentifier', 'color-e3')->first();
        $decoded = json_decode((string) $element->ButtonColor, true);
        $this->assertIsArray($decoded, 'ButtonColor stores the resolved JSON combo blob');
    }
}
