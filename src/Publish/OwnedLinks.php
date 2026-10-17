<?php

namespace Dynamic\ContentApi\Publish;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * The silverstripe/linkfield records a record owns through its has_one
 * relations (#242). LinkTransformer writes a link draft-only, with Owner
 * fields naming the record and relation, as part of the owner's payload; a
 * link is then part of its owner's aggregate. PublishOrchestrator publishes
 * a record's pending links with the record, gates them on the owner's class
 * verb (governingClass()), and archives the links a type change left behind.
 *
 * A link counts as owned only when its Owner fields name the record and the
 * relation, the way linkfield's own Link::Owner() decides it, so a link some
 * other record points at is never published or archived on this one's
 * behalf. Every method is a no-op without linkfield.
 */
class OwnedLinks
{
    use Injectable;

    public const LINK_CLASS = 'SilverStripe\\LinkField\\Models\\Link';

    /**
     * Links the record points at on draft and owns, whose draft differs
     * from live (never published, or edited since).
     *
     * @return DataObject[]
     */
    public function pending(DataObject $owner): array
    {
        $pending = [];

        foreach ($this->linkRelations($owner) as $relation) {
            $id = (int) $owner->getField($relation . 'ID');

            if ($id <= 0) {
                continue;
            }

            $link = $this->onStage(Versioned::DRAFT, fn () => DataObject::get_by_id(OwnedLinks::LINK_CLASS, $id));

            if ($link && $this->ownedBy($link, $owner, $relation) && $link->stagesDiffer()) {
                $pending[] = $link;
            }
        }

        return $pending;
    }

    /**
     * Links owned by the record under a link relation that its draft no
     * longer points at, on either stage: what a type change (which writes a
     * new link) leaves behind. Once the owner publishes, neither stage
     * references them.
     *
     * @return DataObject[]
     */
    public function stale(DataObject $owner): array
    {
        $stale = [];

        foreach ($this->linkRelations($owner) as $relation) {
            $filter = [
                'OwnerID' => (int) $owner->ID,
                'OwnerRelation' => $relation,
                'OwnerClass' => ClassInfo::subclassesFor(DataObject::getSchema()->baseDataClass($owner)),
            ];
            $current = (int) $owner->getField($relation . 'ID');

            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                $links = $this->onStage($stage, function () use ($filter, $current) {
                    $list = DataObject::get(OwnedLinks::LINK_CLASS)->filter($filter);

                    return iterator_to_array($current > 0 ? $list->exclude('ID', $current) : $list);
                });

                foreach ($links as $link) {
                    $stale[(int) $link->ID] ??= $link;
                }
            }
        }

        return array_values($stale);
    }

    /**
     * The class whose verbs govern a record in a publish cascade: an owned
     * link's owner class, otherwise the record's own.
     */
    public function governingClass(DataObject $record): string
    {
        if (is_a($record, OwnedLinks::LINK_CLASS) && $record->hasMethod('Owner')) {
            $owner = $record->Owner();

            if ($owner instanceof DataObject && $owner->exists()) {
                return get_class($owner);
            }
        }

        return get_class($record);
    }

    /**
     * @return string[] the record's has_one relation names that point at a Link
     */
    protected function linkRelations(DataObject $owner): array
    {
        if (!class_exists(OwnedLinks::LINK_CLASS) || !$owner->exists()) {
            return [];
        }

        $schema = DataObject::getSchema();
        $relations = [];

        foreach (array_keys((array) $owner->hasOne()) as $relation) {
            $class = $schema->hasOneComponent(get_class($owner), $relation);

            if ($class && is_a($class, OwnedLinks::LINK_CLASS, true)) {
                $relations[] = (string) $relation;
            }
        }

        return $relations;
    }

    protected function ownedBy(DataObject $link, DataObject $owner, string $relation): bool
    {
        $ownerClass = (string) $link->getField('OwnerClass');

        return (int) $link->getField('OwnerID') === (int) $owner->ID
            && (string) $link->getField('OwnerRelation') === $relation
            && class_exists($ownerClass)
            && DataObject::getSchema()->baseDataClass($ownerClass) === DataObject::getSchema()->baseDataClass($owner);
    }

    private function onStage(string $stage, callable $read): mixed
    {
        return Versioned::withVersionedMode(function () use ($stage, $read) {
            Versioned::set_stage($stage);

            return $read();
        });
    }
}
