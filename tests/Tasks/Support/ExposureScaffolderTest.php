<?php

namespace Dynamic\ContentApi\Tests\Tasks\Support;

use DNADesign\Elemental\Models\BaseElement;
use Dynamic\ContentApi\Security\ContentApiGrantExtension;
use Dynamic\ContentApi\Tasks\Support\ExposureScaffolder;
use Dynamic\ContentApi\Tests\Stub\ApiTestElement;
use Dynamic\ContentApi\Write\WriteGuardExtension;
use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
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
     * A typo'd --exclude must fail loudly, the same as a typo'd --root —
     * silently ignoring it would leave the class it was meant to keep out
     * fully exposed in the output with no signal at all, undermining the
     * "a human reviews this diff" safety model the whole tool relies on.
     */
    public function testRejectsAnUnknownExcludeClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExposureScaffolder::create()->generate([ApiTestElement::class], ['Totally\\Not\\A\\Real\\Class']);
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
     * #198: no HTTP method maps to the 'action' (publish/unpublish/archive)
     * verb — a generated 'GET,POST,PUT' string looks like full CRUD but
     * silently has no way to ever publish a write through this API. The
     * generator itself had this exact bug (found auditing the file, not
     * previously filed against the generator specifically) — proven here
     * against the actual generated api_access line, not just presence of
     * the substring "action" anywhere in the output.
     */
    public function testGeneratedApiAccessIncludesTheActionVerb(): void
    {
        $yaml = ExposureScaffolder::create()->generate([ApiTestElement::class]);

        $this->assertMatchesRegularExpression(
            "/api_access: 'GET,POST,PUT,action'/",
            $yaml,
            'a generated api_access missing the action token silently blocks publish forever (#198)'
        );
    }

    /**
     * The extensions block includes WriteGuardExtension, which protects
     * writes on colymba's generic /api surface — but colymba only ever
     * routes to a class listed in its OWN, separate
     * DefaultQueryHandler.models config; this module's ClassRegistry.models
     * (already documented in the output) doesn't cover it. Confirmed live:
     * a class configured everywhere else but missing this second
     * registration has content_schema_class report a complete, writable
     * schema while every actual /api/$Model request 404s or is silently
     * invisible.
     */
    public function testDocumentsTheSeparateColymbaRegistryEntry(): void
    {
        $yaml = ExposureScaffolder::create()->generate([ApiTestElement::class]);

        $this->assertStringContainsString(
            'Colymba\RESTfulAPI\QueryHandlers\DefaultQueryHandler:',
            $yaml
        );
        $this->assertMatchesRegularExpression(
            '/DefaultQueryHandler:\s*\n#\s*models:\s*\n#\s*\S+: ' . preg_quote(ApiTestElement::class, '/') . '/',
            $yaml
        );
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
     * `writableFields()` diffs the class's own `$db` against
     * `WriteApplicator`'s `protected_fields` — a regression there (the list
     * silently shrinking, or the diff being dropped) would leak a field
     * like `Password` into scaffolded, paste-ready YAML with nothing else
     * catching it before a human pastes it straight into project config.
     * `ID`/`ClassName`/`Created`/`LastEdited` are NOT a meaningful case for
     * this: they're framework `$fixed_fields`, never present in `db`
     * config at all, so they'd be absent from the output regardless of
     * whether this filtering works — a real `db`-declared protected name
     * (temporarily merged onto the fixture below) is what actually
     * exercises the diff.
     */
    public function testNeverIncludesProtectedFields(): void
    {
        Config::modify()->merge(ApiTestElement::class, 'db', ['Password' => 'Varchar']);

        $yaml = ExposureScaffolder::create()->generate([ApiTestElement::class]);

        $this->assertStringNotContainsString('    - Password', $yaml);
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
