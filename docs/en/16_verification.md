# Verification

Four endpoints exist specifically to make a production content restructure boring rather than a
~200-scratch-script, 8-hand-rolled-BuildTask affair: a fingerprint before you touch anything, a
dry run of the batch that touches it, an atomic apply with rollback verification if it fails, and
a parity check + a reachability check afterward. None of these are new write surfaces — they're
read/simulate primitives layered on top of `POST batch` and `POST records/$ClassRef/$ID/publish`.

## The workflow

```
1. GET fingerprint                       — snapshot before
2. POST batch { "dryRun": true }         — preview what the real batch would do
3. POST batch                            — apply (atomic: true recommended)
4. GET records/$ClassRef/$ID/parity      — did each touched record's owned tree actually land?
5. GET fingerprint                       — snapshot after; diff against step 1
```

Steps 1 and 5 use the *same* endpoint — a fingerprint's value is almost entirely in the diff, not
any single snapshot.

## Fingerprint

`GET fingerprint` ([endpoint reference](05_endpoint-reference.md#get-fingerprint)) builds a
deterministic, path-keyed snapshot of the site's content via
`Dynamic\ContentApi\Verify\FingerprintService`:

- **Pages** are keyed by URL path (`/section/page`), not id. Ids churn across a rebuild, a
  re-seed, or a different environment; a page's tree path doesn't. The path is built from an
  in-memory `ParentID` walk, forced to `DRAFT` explicitly regardless of the ambient reading mode —
  the whole design is "enumerate the full draft superset, then mark each row live-or-not"
  separately, so a query that silently narrowed to Live-only rows would make every draft-only page
  vanish from the snapshot entirely, not just report it as not-live.
- **`related`** sections are project-configured (`FingerprintService.related_classes`, e.g.
  `'HeroSlide' => 'PageID'` for a hero-image relation keyed by its owning page's direct FK column)
  — a single direct-FK hop, not a multi-level relation walk. A related record whose owner id
  doesn't resolve to a known page is counted in `unresolved`, never individually identified by a
  raw id (unless `includeIds=true`) — the prototype this generalizes leaked exactly that id into
  its own output, reintroducing the environment-dependent value the whole snapshot exists to
  avoid.
- **`violations`** is the reachability invariant this issue is named for: every live page (or live
  related record) whose path runs through a non-live ancestor. `SiteTree::get_by_link()` resolves
  a URL one path segment at a time in the current stage, so a single draft-only ancestor 404s
  every live page below it — regardless of that page's own live status. The prototype's own
  fingerprint output already contained both contradicting lines (a live page directly below a
  draft-only parent) that caused a real production 404; nothing ever checked them against each
  other. `violations` computes exactly that check, walking each candidate's full ancestor chain
  (not just its immediate parent), so a live related record under a live owner page whose *own*
  parent is draft-only is caught too.
- **Determinism is the whole point.** Every collection is sorted (by path); ids are excluded from
  `data` unless `includeIds=true`; `meta.skipped` (classes unreadable by this token, or a section
  that doesn't apply — e.g. no `SiteTree` at all) is separate from `data` so two callers with
  different permissions still produce a like-for-like diff over what they can both see.

`classes=` restricts which sections appear in the *response* — narrowing it to a `related` ref
without `pages` still resolves owner paths through the same internal index a full scan would use;
only the `pages` section itself is omitted from the output.

## Dry-run batch

`POST batch { "dryRun": true }` (see [Batch operations](07_batch-operations.md#dry-run)) actually
executes the batch inside a transaction — model validation and relation resolution only surface
inside `$record->write()` — then forces a rollback and remaps the outcome through
`would`-prefixed statuses. It runs the same authorization pass as a real batch and, since #127
landed first, verifies its own rollback the same way an atomic real run does. `dryRun` is a
`POST batch` feature only; every other write endpoint rejects it outright rather than silently
ignoring it.

**Sharp edge:** a rolled-back `create` still consumes an `AUTO_INCREMENT` value. A dry run's own
returned ids are ephemeral and must never be diffed against — exactly the reason `GET fingerprint`
is path-keyed rather than id-keyed; an id-based fingerprint would register a dry run as drift that
never actually happened.

## Atomic apply + rollback verification

`POST batch { "atomic": true }` (see
[Batch operations](07_batch-operations.md#atomicity)) rolls the whole batch back on the first
failing operation and *re-checks* the claim: every `created`/`deleted` result is re-read by id,
and — since #127 — every `updated` result's declared `fields` are snapshotted before the write and
compared afterward. A batch whose claimed rollback doesn't match reality reports
`ROLLBACK_UNVERIFIED` rather than a false `rolledBack: true`. Known gaps (documented, not silent):
relation changes aren't covered by the per-column check, and verification reads `DRAFT` only — an
`update` with `publish` set also wrote `LIVE`, unverified independently.

## Draft/live parity

`GET records/$ClassRef/$ID/parity` (see
[Publishing & stages](10_publishing-and-stages.md#draftlive-parity)) answers "does this specific
record, and everything it `$owns`, actually match between draft and live" — narrower and more
detailed than a fingerprint diff, and the right tool once a fingerprint diff (or a batch result)
tells you *which* record to look at.

## Putting it together

A fingerprint diff and a parity check answer different questions and are meant to be used
together, not as substitutes: the fingerprint catches reachability breaks and shows you the shape
of what changed across the *whole* site; parity confirms one record's owned tree is internally
consistent. A dry run tells you what a batch *would* do before it touches anything; atomic
rollback verification tells you a failed batch actually left nothing behind. None of the four
replace code review or the test suite — they replace the ~200-event scratch-script rehearsal a
real production restructure fell back to when none of this existed.
