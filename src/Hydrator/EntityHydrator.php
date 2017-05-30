<?php

/*
 * This file is part of the GraphAware Neo4j PHP OGM package.
 *
 * (c) GraphAware Ltd <info@graphaware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GraphAware\Neo4j\OGM\Hydrator;

use GraphAware\Common\Result\Record;
use GraphAware\Common\Result\Result;
use GraphAware\Common\Type\Node;
use GraphAware\Common\Type\Relationship;
use GraphAware\Neo4j\OGM\Common\Collection;
use GraphAware\Neo4j\OGM\Converters\Converter;
use GraphAware\Neo4j\OGM\EntityManager;
use GraphAware\Neo4j\OGM\Metadata\NodeEntityMetadata;
use GraphAware\Neo4j\OGM\Metadata\RelationshipEntityMetadata;
use GraphAware\Neo4j\OGM\Util\DirectionUtils;

class EntityHydrator
{
    /**
     * @var EntityManager
     */
    private $_em;

    /**
     * @var NodeEntityMetadata
     */
    private $_classMetadata;

    public function __construct($className, EntityManager $em)
    {
        $this->_em = $em;
        $this->_classMetadata = $this->_em->getClassMetadataFor($className);
    }

    /**
     * @param Result $dbResult
     *
     * @return array
     */
    public function hydrateAll(Result $dbResult)
    {
        $result = [];

        foreach ($dbResult->records() as $record) {
            $this->hydrateRecord($record, $result);
        }

        return $result;
    }

    /**
     * @param Result $dbResult
     * @param object $sourceEntity
     * @param mixed  $alias
     */
    public function hydrateSimpleRelationship($alias, Result $dbResult, $sourceEntity)
    {
        if (0 === $dbResult->size()) {
            return;
        }

        $relationshipMetadata = $this->_classMetadata->getRelationship($alias);
        $targetHydrator = $this->_em->getEntityHydrator($relationshipMetadata->getTargetEntity());
        $targetMeta = $this->_em->getClassMetadataFor($relationshipMetadata->getTargetEntity());
        $hydrated = $targetHydrator->hydrateAll($dbResult);

        $o = $hydrated[0];
        $relationshipMetadata->setValue($sourceEntity, $o);

        $mappedBy = $relationshipMetadata->getMappedByProperty();
        if ($mappedBy) {
            $targetRel = $targetMeta->getRelationship($mappedBy);
            if ($targetRel->isCollection()) {
                $targetRel->addToCollection($o, $sourceEntity);
            } else {
                $targetRel->setValue($o, $sourceEntity);
            }
        }
        $this->_em->getUnitOfWork()->addManagedRelationshipReference($sourceEntity, $o, $relationshipMetadata->getPropertyName(), $relationshipMetadata);
    }

    public function hydrateSimpleRelationshipCollection($alias, Result $dbResult, $sourceEntity)
    {
        $relationshipMetadata = $this->_classMetadata->getRelationship($alias);
        $this->initRelationshipCollection($alias, $sourceEntity);
        /** @var Collection $coll */
        $coll = $relationshipMetadata->getValue($sourceEntity);
        $targetHydrator = $this->_em->getEntityHydrator($relationshipMetadata->getTargetEntity());
        $targetMeta = $this->_em->getClassMetadataFor($relationshipMetadata->getTargetEntity());
        foreach ($dbResult->records() as $record) {
            $node = $record->get($targetMeta->getEntityAlias());
            $item = $targetHydrator->hydrateNode($node, $relationshipMetadata->getTargetEntity());
            $coll->add($item);
            $mappedBy = $relationshipMetadata->getMappedByProperty();
            if ($mappedBy) {
                $mappedRel = $targetMeta->getRelationship($mappedBy);
                if ($mappedRel->isCollection()) {
                    $mappedRel->initializeCollection($item);
                    $this->_em->getUnitOfWork()->addManagedRelationshipReference($sourceEntity, $item, $relationshipMetadata->getPropertyName(), $relationshipMetadata);
                } else {
                    $mappedRel->setValue($item, $sourceEntity);
                    $this->_em->getUnitOfWork()->addManagedRelationshipReference($sourceEntity, $item, $relationshipMetadata->getPropertyName(), $relationshipMetadata);
                }
            }
        }
    }

    public function hydrateRelationshipEntity($alias, Result $dbResult, $sourceEntity)
    {
        $relationshipMetadata = $this->_classMetadata->getRelationship($alias);
        /** @var RelationshipEntityMetadata $relationshipEntityMetadata */
        $relationshipEntityMetadata = $this->_em->getClassMetadataFor($relationshipMetadata->getRelationshipEntityClass());
        $otherClass = $this->guessOtherClassName($alias);
        $otherMetadata = $this->_em->getClassMetadataFor($otherClass);
        $otherHydrator = $this->_em->getEntityHydrator($otherClass);

        // initialize collection on source entity to avoid it being null
        if ($relationshipMetadata->isCollection()) {
            $relationshipMetadata->initializeCollection($sourceEntity);
        }

        // we iterate the result of records which are a map
        // {target: (Node) , re: (Relationship) }
        foreach ($dbResult->records() as $record) {
            $k = $relationshipMetadata->getAlias();
            /** @var Node $targetNode */
            $targetNode = $record->get($k)['target'];
            /** @var Relationship $relationship */
            $relationship = $record->get($k)['re'];

            // hydrate the target node :
            $targetEntity = $otherHydrator->hydrateNode($targetNode);

            $startNodePropertyName = $relationshipEntityMetadata->getStartNodePropertyName();
            $sourceEntityMappedName = $relationshipMetadata->getMappedByProperty();

            $startNodeIsSourceEntity = $startNodePropertyName === $sourceEntityMappedName;


            // create the relationship entity
            $entity = $this->_em->getUnitOfWork()->createRelationshipEntity(
                $relationship,
                $relationshipEntityMetadata->getClassName(),
                $sourceEntity,
                $relationshipMetadata->getPropertyName()
            );

            // set properties on the relationship entity
            foreach ($relationshipEntityMetadata->getPropertiesMetadata() as $key => $propertyMetadata) {

                if ($propertyMetadata->hasConverter()) {
                    $converter = Converter::getConverter($propertyMetadata->getConverterType(), $key);
                    $value = $converter->toPHPValue($relationship->values(), $propertyMetadata->getConverterOptions());
                    $propertyMetadata->setValue($entity, $value);
                } else {
                    $v = $relationship->hasValue($key) ? $relationship->get($key) : null;
                    $propertyMetadata->setValue($entity, $v);
                }
            }

            // set the start node
            if ($startNodeIsSourceEntity) {
                $relationshipEntityMetadata->setStartNodeProperty($entity, $sourceEntity);
            } else {
                $relationshipEntityMetadata->setStartNodeProperty($entity, $targetEntity);
            }

            // set the end node
            if (!$startNodeIsSourceEntity) {
                $relationshipEntityMetadata->setEndNodeProperty($entity, $sourceEntity);
            } else {
                $relationshipEntityMetadata->setEndNodeProperty($entity, $targetEntity);
            }

            // set the relationship entity on the source entity
            if (!$relationshipMetadata->isCollection()) {
                $relationshipMetadata->setValue($sourceEntity, $entity);
            } else {
                $relationshipMetadata->initializeCollection($sourceEntity);
                $relationshipMetadata->addToCollection($sourceEntity, $entity);
            }

            // guess the name of the property on the other node
            foreach ($otherMetadata->getRelationships() as $rel) {
                if ($rel->isRelationshipEntity() && $rel->getRelationshipEntityClass() === $relationshipEntityMetadata->getClassName()) {
                    if (!$rel->isCollection()) {
                        $rel->setValue($targetEntity, $entity);
                    } else {
                        $rel->initializeCollection($targetEntity);
                        $rel->addToCollection($targetEntity, $entity);
                    }
                }
            }
        }
    }

    public function hydrateRecord(Record $record, array &$result, $collection = false)
    {
        $cqlAliasMap = $this->getAliases();

        foreach ($record->keys() as $cqlAlias) {
            $data = $record->get($cqlAlias);
            $entityName = $cqlAliasMap[$cqlAlias];
            $data = $collection ? $data : [$data];
            foreach ($data as $node) {
                $id = $node->identity();

                // Check the entity is not managed yet by the uow
                if (null !== $entity = $this->_em->getUnitOfWork()->getEntityById($id)) {
                    $result[] = $entity;
                    continue;
                }

                // create the entity
                $entity = $this->_em->getUnitOfWork()->createEntity($node, $entityName, $id);
                $this->hydrateProperties($entity, $node);
                $this->hydrateLabels($entity, $node);

                $result[] = $entity;
            }
        }
    }

    public function hydrateNode(Node $node, $class = null)
    {
        $cm = null === $class ? $this->_classMetadata->getClassName() : $class;
        $id = $node->identity();

        // Check the entity is not managed yet by the uow
        if (null !== $entity = $this->_em->getUnitOfWork()->getEntityById($id)) {
            return $entity;
        }

        // create the entity
        $entity = $this->_em->getUnitOfWork()->createEntity($node, $cm, $id);
        $this->hydrateProperties($entity, $node);
        $this->hydrateLabels($entity, $node);

        return $entity;
    }

    /**
     * @param Node $node
     * @param object $entity
     */
    public function refresh(Node $node, $entity)
    {
        $this->hydrateProperties($entity, $node);
        $this->hydrateLabels($entity, $node);

    }

    protected function hydrateProperties($object, Node $node)
    {
        foreach ($this->_classMetadata->getPropertiesMetadata() as $key => $metadata) {
            if ($metadata->hasConverter()) {
                $converter = Converter::getConverter($metadata->getConverterType(), $key);
                $value = $converter->toPHPValue($node->values(), $metadata->getConverterOptions());
                $metadata->setValue($object, $value);
            } else {
                $v = $node->hasValue($key) ? $node->get($key) : null;
                $metadata->setValue($object, $v);
            }
        }
    }

    protected function hydrateLabels($object, Node $node)
    {
        foreach ($this->_classMetadata->getLabeledProperties() as $labeledProperty) {
            if ($node->hasLabel($labeledProperty->getLabelName())) {
                $labeledProperty->setLabel($object, true);
            } else {
                $labeledProperty->setLabel($object, false);
            }
        }
    }

    protected function getAliases()
    {
        return [$this->_classMetadata->getEntityAlias() => $this->_classMetadata->getClassName()];
    }

    private function guessOtherClassName($alias)
    {
        $relationshipMetadata = $this->_classMetadata->getRelationship($alias);
        /** @var RelationshipEntityMetadata $relationshipEntityMetadata */
        $relationshipEntityMetadata = $this->_em->getClassMetadataFor($relationshipMetadata->getRelationshipEntityClass());
        $inversedSide = $relationshipEntityMetadata->getOtherClassNameForOwningClass($this->_classMetadata->getClassName());
        /* @todo will not work for Direction.BOTH */
        return $inversedSide;
    }

    private function initRelationshipCollection($alias, $sourceEntity)
    {
        $this->_classMetadata->getRelationship($alias)->initializeCollection($sourceEntity);
    }
}
