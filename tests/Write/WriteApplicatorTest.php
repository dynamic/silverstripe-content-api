<?php

namespace Dynamic\ContentApi\Tests\Write;

use Dynamic\ContentApi\Tests\Stub\ApiTestCascadeObject;
use Dynamic\ContentApi\Tests\Stub\ApiTestMultiRelationalPolyCollidingDbFieldObject;
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
        ApiTestMultiRelationalPolyCollidingDbFieldObject::class,
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

    /**
     * Regression for #34 code review (mirror of
     * testRelationColumnDetectionDoesNotShadowAGenuinelyNamedDistinctRelation
     * above, for the 'Class' suffix): the shared
     * polymorphicCompanionColumnRelation() helper's collision guard applies
     * to both companion-column detectors it backs. Before this PR's refactor
     * unified the two into one helper, polymorphicClassColumnRelation() had
     * no such guard at all — this pins the fix for that suffix too, not just
     * the new 'Relation' one.
     */
    public function testClassColumnDetectionDoesNotShadowAGenuinelyNamedDistinctRelation(): void
    {
        $applicator = WriteApplicator::create();

        $hasOne = [
            'Owner' => DataObject::class,
            'OwnerClass' => ApiTestCascadeObject::class,
        ];

        $this->assertNull(
            $applicator->polymorphicClassColumnRelation('OwnerClass', $hasOne)
        );
    }

    /**
     * The companion-column detector must still correctly recognise the
     * synthetic {Name}Class column when there's no colliding relation name —
     * the plain-string polymorphic form, unaffected by this PR's changes.
     */
    public function testClassColumnDetectionRecognisesTheSyntheticCompanionColumn(): void
    {
        $applicator = WriteApplicator::create();
        $hasOne = (array) ApiTestPolyObject::create()->hasOne();

        $this->assertSame('Owner', $applicator->polymorphicClassColumnRelation('OwnerClass', $hasOne));
    }

    /**
     * Regression for #34 code review: polymorphicRelationColumnRelation()
     * must not false-positive on a *non*-multirelational polymorphic
     * has_one — a bare {Name}Relation-suffixed column name must only match
     * when hasOneComponentHandlesMultipleRelations() actually confirms it,
     * not merely because the stripped relation name resolves to
     * DataObject::class.
     */
    public function testRelationColumnDetectionReturnsNullForAPlainPolymorphicHasOne(): void
    {
        $applicator = WriteApplicator::create();
        $hasOne = (array) ApiTestPolyObject::create()->hasOne();

        $this->assertNull(
            $applicator->polymorphicRelationColumnRelation('OwnerRelation', ApiTestPolyObject::class, $hasOne)
        );
    }

    /**
     * Regression for #34 code review: `polymorphicCompanionColumnRelation()`'s
     * docblock claims a plain `$db` field sharing a companion column's exact
     * name is a non-issue because `DataObjectSchema::databaseFields()`
     * merges has_one-derived composite columns *after* plain `$db` fields,
     * so the synthetic column always wins in the schema itself. This is a
     * claim about upstream framework merge order this repo doesn't control —
     * pin it with a real regression test so a future SilverStripe release
     * that changed that order would be caught here, not discovered as a
     * silent security regression in production.
     */
    public function testFrameworkCompositeColumnWinsOverAPlainDbFieldOfTheSameName(): void
    {
        $spec = DataObject::getSchema()->fieldSpec(
            ApiTestMultiRelationalPolyCollidingDbFieldObject::class,
            'OwnerRelation'
        );

        // ApiTestMultiRelationalPolyCollidingDbFieldObject declares a plain
        // 'OwnerRelation' => 'Varchar' $db field AND a multirelational
        // 'Owner' has_one (whose synthetic companion column is also named
        // 'OwnerRelation') — if the plain field ever won, this would report
        // something other than the composite's own 'Varchar' spec (they
        // happen to share a base type, so the real assertion is the
        // detector below, not this type string alone).
        $this->assertSame('Varchar', $spec);

        $applicator = WriteApplicator::create();
        $hasOne = (array) ApiTestMultiRelationalPolyCollidingDbFieldObject::create()->hasOne();

        // The detector must still recognise 'OwnerRelation' as Owner's
        // synthetic companion column — proving the framework's own merge
        // resolved the collision in favour of the composite field, exactly
        // as polymorphicCompanionColumnRelation()'s docblock claims.
        $this->assertSame(
            'Owner',
            $applicator->polymorphicRelationColumnRelation(
                'OwnerRelation',
                ApiTestMultiRelationalPolyCollidingDbFieldObject::class,
                $hasOne
            )
        );
    }
}
