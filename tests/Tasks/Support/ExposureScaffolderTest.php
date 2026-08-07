<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use DNADesign\Elemental\Models\BaseElement;
use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use Dynamic\ContentApi\Tasks\Support\ExposureScaffolder;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Write\WriteGuardExtension;
use InvalidArgumentException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

class ExposureScaffolderTest extends SapphireTest
{
    public function testRequiresAtLeastOneRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExposureScaffolder::create()->generate([]);
    }

    public function testRejectsAnUnknownRootClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExposureScaffolder::create()->generate(['Totally\\Not\\A\\Real\\Class']);
    }

    /**
     * ApiTestElement (see the stub) has its own `Intro` db field, a `Photo`
     * has_one (Image) and `Cta` has_one (Link) — neither ElementalArea-typed
     * — and two has_many children. Scaffolding it directly (a concrete leaf,
     * not a shared ancestor of anything else in this single-class root set)
     * must produce a full block including BaseElement's own inherited
     * fields (Title/ShowTitle/Sort/ExtraClass/Style) alongside Intro, both
     * has_ones by their bare relation name, and both has_many relations —
     * a "contains" check on each, not an exact list, since the enclosing
     * testbed project's own config chain legitimately hoists more fields
     * onto BaseElement than this module's own fixtures know about (see
     * local-ci's nested-vendor-checkout routing: this suite genuinely runs
     * against the full enclosing project's config manifest, matching how
     * every other test in this suite already runs).
     */
    public function testScaffoldsFieldsHasOneAndHasManyForAConcreteLeaf(): void
    {
        $yaml = ExposureScaffolder::create()->generate([ApiTestElement::class]);

        $this->assertStringContainsString(ApiTestElement::class . ':', $yaml);

        foreach (['Title', 'ShowTitle', 'Sort', 'ExtraClass', 'Style', 'Intro', 'Photo', 'Cta'] as $field) {
            $this->assertStringContainsString(
                sprintf('    - %s', $field),
                $yaml,
                sprintf('expected "%s" in the generated api_writable_fields', $field)
            );
        }

        $this->assertMatchesRegularExpression(
            '/api_writable_relations: \[[^\]]*\bItems\b[^\]]*\]/',
            $yaml
        );
        $this->assertMatchesRegularExpression(
            '/api_writable_relations: \[[^\]]*\bPlainItems\b[^\]]*\]/',
            $yaml
        );
        $this->assertStringContainsString(WriteGuardExtension::class, $yaml);
        $this->assertStringContainsString(ContentApiGrantExtension::class, $yaml);
    }

    /**
     * BaseElement's own `Parent` has_one targets `ElementalArea` — the
     * framework auto-provisions this FK, and WriteApplicator::
     * isFieldWritable() blocks it from direct client writes for every
     * relation except a BaseElement's own "Parent" (a narrow, placement-
     * policy-checked exception this generator deliberately does not
     * default into an allowlist — see the class docblock). It must never
     * appear as a bare "- Parent" line in generated output.
     */
    public function testNeverIncludesTheElementalAreaParentRelation(): void
    {
        $yaml = ExposureScaffolder::create()->generate([ApiTestElement::class]);

        $this->assertStringNotContainsString('    - Parent', $yaml);
    }

    /**
     * `--root=BaseElement` walks in BaseElement itself (concrete) alongside
     * every concrete subclass, e.g. ApiTestElement. BaseElement is a
     * shared ancestor of another class in this SAME result set, so it must
     * never get its own api_writable_fields block — hoisting one there
     * would silently allowlist every field on every subclass too (#27).
     */
    public function testNeverEmitsApiWritableFieldsOnASharedAncestorInTheSameRun(): void
    {
        $yaml = ExposureScaffolder::create()->generate([BaseElement::class]);

        $this->assertMatchesRegularExpression(
            '/' . preg_quote(ApiTestElement::class, '/') . ':\s*\n\s*api_access/',
            $yaml,
            'a genuine concrete subclass in the walk must still get a full block'
        );

        // BaseElement itself must appear only in the "skipped ancestor" note,
        // never as its own "ClassName:\n  api_writable_fields:" block.
        $this->assertDoesNotMatchRegularExpression(
            '/^' . preg_quote(BaseElement::class, '/') . ':\s*\n\s*api_access.*\n\s*api_writable_fields/ms',
            $yaml
        );
        $this->assertStringContainsString(BaseElement::class, $yaml, 'still named, in the skipped-ancestor note');
    }

    /**
     * Member/Group/etc. are excluded from generated output the same way
     * they're excluded from auto-discovery — reusing
     * ClassRegistry::discoveryDenylist() rather than re-deriving a second
     * list that could drift from the first.
     */
    public function testReusesTheClassRegistryDenylist(): void
    {
        $yaml = ExposureScaffolder::create()->generate([Member::class]);

        $this->assertStringNotContainsString(Member::class . ':', $yaml);
    }

    public function testExcludeOptionSkipsAGivenSubtree(): void
    {
        $yaml = ExposureScaffolder::create()->generate([BaseElement::class], [ApiTestElement::class]);

        $this->assertStringNotContainsString(ApiTestElement::class . ':', $yaml);
    }

    public function testEmptyResultStillCarriesTheBanner(): void
    {
        $yaml = ExposureScaffolder::create()->generate([BaseElement::class], [BaseElement::class]);

        $this->assertStringContainsString('AUTO-GENERATED', $yaml);
        $this->assertStringContainsString('No concrete', $yaml);
    }
}
