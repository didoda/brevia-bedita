<?php
declare(strict_types=1);

/**
 * Chatlas BEdita plugin
 *
 * Copyright 2023 Atlas Srl
 */
namespace BEdita\Chatlas\Event;

use BEdita\Chatlas\Index\CollectionHandler;
use BEdita\Core\Model\Entity\ObjectEntity;
use BEdita\Core\ORM\Association\RelatedTo;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Hash;

/**
 * Event listener for chatlas collection related events.
 */
class ChatlasEventHandler implements EventListenerInterface
{
    use LocatorAwareTrait;

    /**
     * @inheritDoc
     */
    public function implementedEvents(): array
    {
        return [
            'Model.afterDelete' => 'afterDelete',
            'Model.afterSave' => 'afterSave',
            'Associated.afterSave' => 'afterSaveAssociated',
        ];
    }

    /**
     * After save listener:
     *  * create or update collections
     *  * add, update or remove documents from collections
     *
     * @param \Cake\Event\EventInterface $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $entity The Entity saved.
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity)
    {
        $type = (string)$entity->get('type');
        if (empty($type) || !$entity instanceof ObjectEntity) {
            return;
        }
        $handler = new CollectionHandler();
        if ($type === 'collections') {
            if ($entity->isNew()) {
                $handler->createCollection($entity);

                return;
            }

            $handler->updateCollection($entity);
        }
        // Look if there is a `DocumentOf` relation
        $table = $this->fetchTable($type);
        $assoc = $table->associations()->get('DocumentOf');
        if (empty($assoc)) {
            return;
        }
        $table->loadInto($entity, ['DocumentOf']);
        $collections = $entity->get('document_of');
        if (empty($collections)) {
            return;
        }
        foreach ($collections as $collection) {
            $handler->updateDocument($collection, $entity);
        }
    }

    /**
     * Handle 'Associated.afterSave'
     *
     * @param \Cake\Event\Event $event Dispatched event.
     * @return void
     */
    public function afterSaveAssociated(Event $event): void
    {
        $data = $event->getData();
        $association = Hash::get($data, 'association');
        if (empty($association) || !$association instanceof RelatedTo) {
            return;
        }
        $name = $association->getName();
        if (!in_array($name, ['DocumentOf', 'HasDocuments'])) {
            return;
        }
        $entity = Hash::get($data, 'entity');
        if (!$entity instanceof ObjectEntity) {
            return;
        }
        $handler = new CollectionHandler();
        $action = Hash::get($data, 'action');
        $related = (array)Hash::get($data, 'relatedEntities');
        foreach ($related as $item) {
            if ($name === 'DocumentOf') {
                $collection = $item;
                $document = $entity;
            } else {
                $collection = $entity;
                $document = $item;
            }

            if ($action === 'remove') {
                $handler->removeDocument($collection, $document);
            } else {
                $handler->updateDocument($collection, $document, true);
            }
        }
    }

    /**
     * After delete listener: remove collections
     *
     * @param \Cake\Event\EventInterface $event The dispatched event.
     * @param \Cake\Datasource\EntityInterface $entity The Entity saved.
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity)
    {
        $type = (string)$entity->get('type');
        if (!$entity instanceof ObjectEntity || $type !== 'collections') {
            return;
        }
        $handler = new CollectionHandler();
        $handler->removeCollection($entity);
    }
}