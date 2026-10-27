<?php

namespace App\Audit;

use App\Entity\AuditEvent;
use App\Entity\Base;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records what happens on the site: an AuditEvent row for the admin's Audit
 * log, and the same line on the "audit" Monolog channel (var/log/audit.log,
 * or stderr in production).
 *
 * Rows are inserted straight through the connection rather than flushed, so
 * recording never flushes someone else's half-made changes, and a failure
 * (say, before the migration has run) is logged instead of closing the entity
 * manager mid-login.
 */
#[WithMonologChannel('audit')]
class AuditLog
{
    /** Events older than this are deleted every night (PruneAuditLog). */
    public const int RETENTION_DAYS = 365;

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param object|null          $subject what it happened to, usually an entity; read now, so record deletions before flushing them
     * @param array<string, mixed> $context small extra facts (ids, statuses); never passwords or message bodies
     * @param User|null            $actor   defaults to the signed-in user
     * @param string|null          $email   defaults to the actor's
     */
    public function record(AuditType $type, ?object $subject = null, array $context = [], ?User $actor = null, ?string $email = null): AuditEvent
    {
        $actor ??= $this->currentUser();
        $event = (new AuditEvent($type))
            ->setActor($actor)
            ->setEmail($email ?? $actor?->getEmail());

        if ($subject !== null) {
            $event->setSubject(...self::describe($subject));
        }

        $request = $this->requests->getMainRequest();
        if ($request !== null) {
            $event->setIp($request->getClientIp())->setUserAgent($request->headers->get('User-Agent'));
        } elseif (\PHP_SAPI === 'cli') {
            $context['via'] ??= 'console';
        }
        $event->setContext($context);

        $this->logger->info('{type} by {email} on {subject}', [
            'type'    => $type->value,
            'email'   => $event->getEmail() ?? 'guest',
            'subject' => $event->getSubject() ?? '-',
            'actor'   => $actor?->getId(),
            'ip'      => $event->getIp(),
            'agent'   => $event->getUserAgent(),
            'context' => $context,
        ]);

        try {
            $this->insert($event);
        } catch (\Throwable $exception) {
            $this->logger->error('Could not save audit event {type}: {message}', [
                'type'      => $type->value,
                'message'   => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        return $event;
    }

    /**
     * Type, id and a readable label, e.g. ["Listing", 12, "Chemistry: The Central Science"].
     *
     * @return array{?string, ?int, ?string}
     */
    public static function describe(object $subject): array
    {
        $type = $subject instanceof Base ? $subject->getEntityType() : new \ReflectionClass($subject)->getShortName();
        $id = method_exists($subject, 'getId') ? $subject->getId() : null;

        $label = null;
        foreach (['getTitle', 'getEmail', 'getCode', 'getLabel'] as $getter) {
            if (method_exists($subject, $getter) && \is_string($value = $subject->{$getter}())) {
                $label = $value;
                break;
            }
        }

        return [$type, \is_int($id) ? $id : null, $label];
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /** One INSERT built from AuditEvent's mapping, so the entity stays the single description of the table. */
    private function insert(AuditEvent $event): void
    {
        $metadata = $this->manager->getClassMetadata(AuditEvent::class);
        $platform = $this->manager->getConnection()->getDatabasePlatform();
        $row = [];

        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field)) {
                continue;
            }
            $value = $metadata->getFieldValue($event, $field);
            $value = $value instanceof \BackedEnum ? $value->value : $value;
            $row[$metadata->getColumnName($field)] = Type::getType((string) $metadata->getTypeOfField($field))->convertToDatabaseValue($value, $platform);
        }
        $row[$metadata->getSingleAssociationJoinColumnName('actor')] = $event->getActor()?->getId();

        $this->manager->getConnection()->insert($metadata->getTableName(), $row);
    }
}
