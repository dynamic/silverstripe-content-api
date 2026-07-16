<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestMultiRelationalPolyObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestPolyObject;
use Dynamic\ContentApi\Write\WriteApplicator;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

class WriteApplicatorTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        ApiTestPolyObject::class,
        ApiTestCascadeObject::class,
        ApiTestMultiRelationalPolyObject::class,
    ];

    public function testTrustedChannelMaySetPolymorphicClassColumnDirectly(): void
    {
        // #25's "reject a direct {Name}Class payload key" rule exists to
        // stop untrusted request input from setting an arbitrary raw class
        // string with no ClassRegistry validation — it must not also block
        // the trusted $internalFields channel (the same one that already
        // writes ParentID/Sort), which by definition never carries
        // request-derived values.
        $applicator = WriteApplicator::create();
        $poly = ApiTestPolyObject::create(['Title' => 'Trusted Class write']);

        $applicator->applyFields($poly, ['Title' => 'Trusted Class write'], [
            'OwnerClass' => ApiTestCascadeObject::class,
        ]);

        $this->assertSame(ApiTestCascadeObject::class, $poly->OwnerClass);
    }

    /**
     * Regression for #34 code review: polymorphicRelationColumnRelation()
     * detects a multirelational has_one's synthetic {Name}Relation
     * companion column by string-stripping the 'Relation' suffix — it must
     * not false-positive when the stripped name coincidentally matches a
     * genuinely distinct, differently-typed has_one relation that happens
     * to literally be named "{Owner}Relation".
     */
    public function testRelationColumnDetectionDoesNotShadowAGenuinelyNamedDistinctRelation(): void
    {
        $applicator = WriteApplicator::create();

        // Shape of DataObject::hasOne() for a class declaring both a
        // multirelational polymorphic 'Owner' (normalized to DataObject::class
        // by the framework, per #34) AND an unrelated, real has_one literally
        // named 'OwnerRelation' pointing at a concrete class.
        $hasOne = [
            'Owner' => DataObject::class,
            'OwnerRelation' => ApiTestCascadeObject::class,
        ];

        // The genuine 'OwnerRelation' has_one always wins — the className
        // argument is irrelevant here since the collision guard must return
        // null before ever needing to check multirelational-ness.
        $this->assertNull(
            $applicator->polymorphicRelationColumnRelation('OwnerRelation', 'SomeClassNeverConsulted', $hasOne)
        );
    }

    /**
     * The companion-column detector must still correctly recognise the
     * synthetic {Name}Relation column when there's no colliding relation
     * name — using the real, schema-registered multirelational stub so
     * hasOneComponentHandlesMultipleRelations() is exercised for real.
     */
    public function testRelationColumnDetectionRecognisesTheSyntheticCompanionColumn(): void
    {
        $applicator = WriteApplicator::create();
        $hasOne = (array) ApiTestMultiRelationalPolyObject::create()->hasOne();

        $this->assertSame(
            'Owner',
            $applicator->polymorphicRelationColumnRelation(
                'OwnerRelation',
                ApiTestMultiRelationalPolyObject::class,
                $hasOne
            )
        );
    }
}
