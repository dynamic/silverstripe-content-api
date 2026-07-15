<?php

namespace Dynamic\ContentApi\Tests\Control;

use Dynamic\ContentApi\Tests\ContentApiTestCase;
use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;

class RecordSerializerTest extends ContentApiTestCase
{
    private string $token;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->mintTokenFor('adminUser');

        $this->logHandler = new TestHandler();
        Injector::inst()->registerService(
            new Logger('test', [$this->logHandler]),
            LoggerInterface::class
        );
    }

    public function testUnreadableRelationIsLoggedNotSilentlySwallowed(): void
    {
        // A has_many entry whose target class doesn't exist can't be read —
        // RecordSerializer must log it, not just return null (#22).
        Config::modify()->merge(ApiTestObject::class, 'has_many', [
            'Broken' => 'Dynamic\\ContentApi\\Tests\\Stub\\NonExistentTarget',
        ]);

        $record = $this->objFromFixture(ApiTestObject::class, 'one');

        $body = $this->decode($this->apiGet("records/ApiTest/{$record->ID}", $this->token));

        $this->assertArrayHasKey('Broken', $body['data']['relations']);
        $this->assertNull($body['data']['relations']['Broken']);
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('Broken'),
            'expected a warning logged for the unreadable relation'
        );
    }

    public function testPolymorphicRelationWithUnregisteredClassEmitsFqcnNotNull(): void
    {
        $owner = ApiTestCascadeObject::create(['Title' => 'Owner']);
        $owner->write();

        $poly = ApiTestPolyObject::create(['Title' => 'Orphaned ref']);
        $poly->setField('OwnerID', $owner->ID);
        // ApiTestCascadeObject is deliberately not in the test ClassRegistry
        // models map (see ContentApiTestCase::setUp()) — refFor() returns
        // null for it.
        $poly->setField('OwnerClass', ApiTestCascadeObject::class);
        $poly->write();

        $body = $this->decode($this->apiGet("records/ApiTestPoly/{$poly->ID}", $this->token));

        $owner = $body['data']['relations']['Owner'];
        $this->assertSame(ApiTestCascadeObject::class, $owner['class'], 'falls back to the FQCN, not null');
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('unregistered'),
            'expected a warning logged for the unregistered polymorphic target class'
        );
    }
}
