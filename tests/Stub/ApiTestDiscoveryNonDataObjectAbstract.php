<?php

namespace Dynamic\ContentApi\Tests\Stub;

/**
 * Plain PHP abstract class (deliberately NOT a DataObject) used only to
 * exercise ClassRegistry::discoveredModels()'s ReflectionClass::isInstantiable()
 * filter without SapphireTest's temp-DB table builder trying to instantiate
 * it — TableBuilder walks every discovered DataObject subclass regardless of
 * test mode and fatals on a literal `abstract class` DataObject. Discovery's
 * filtering logic itself doesn't care whether a class is a DataObject; it
 * only needs ClassInfo::subclassesFor() to find it and ReflectionClass to
 * report it non-instantiable, both true here.
 */
abstract class ApiTestDiscoveryNonDataObjectAbstract
{
}
