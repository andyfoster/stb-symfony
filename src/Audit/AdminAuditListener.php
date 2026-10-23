<?php

namespace App\Audit;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * What admins create, edit and delete in EasyAdmin (/admin). Edits record
 * which fields changed, not their values; taking away or granting admin
 * access is also recorded as its own event.
 */
final class AdminAuditListener
{
    /** @var \WeakMap<object, list<string>> fields changed, between the before and after events */
    private \WeakMap $changes;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly EntityManagerInterface $manager,
    ) {
        $this->changes = new \WeakMap();
    }

    #[AsEventListener]
    public function onCreated(AfterEntityPersistedEvent $event): void
    {
        $this->audit->record(AuditType::AdminCreated, $event->getEntityInstance());
    }

    #[AsEventListener]
    public function beforeUpdate(BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        if (!$entity instanceof AuditEvent) {
            $this->changes[$entity] = $this->changedFields($entity);
        }
    }

    #[AsEventListener]
    public function onUpdated(AfterEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        $fields = $this->changes[$entity] ?? null;
        unset($this->changes[$entity]);
        if ($fields === null || $fields === []) {
            return;
        }

        $this->audit->record(AuditType::AdminUpdated, $entity, ['fields' => $fields]);
    }

    /** Before: the id is gone once it's deleted. */
    #[AsEventListener]
    public function onDeleting(BeforeEntityDeletedEvent $event): void
    {
        $this->audit->record(AuditType::AdminDeleted, $event->getEntityInstance());
    }

    /**
     * Names of the fields and to-one associations that differ from what was loaded.
     * Also records admin access being granted or taken away.
     *
     * @return list<string>
     */
    private function changedFields(object $entity): array
    {
        $metadata = $this->manager->getClassMetadata($entity::class);
        $original = $this->manager->getUnitOfWork()->getOriginalEntityData($entity);
        $changed = [];

        foreach ([...$metadata->getFieldNames(), ...$metadata->getAssociationNames()] as $field) {
            if (\in_array($field, ['updatedAt', 'createdAt'], true)
                || ($metadata->hasAssociation($field) && !$metadata->isSingleValuedAssociation($field))
                || !\array_key_exists($field, $original)) {
                continue;
            }
            if ($original[$field] !== $metadata->getFieldValue($entity, $field)) {
                $changed[] = $field;
            }
        }

        if ($entity instanceof User && \in_array('roles', $changed, true)) {
            $wasAdmin = \in_array('ROLE_ADMIN', (array) $original['roles'], true);
            if ($wasAdmin !== $entity->isAdmin()) {
                $this->audit->record(AuditType::RoleChanged, $entity, ['admin' => $entity->isAdmin()]);
            }
        }

        return $changed;
    }
}
