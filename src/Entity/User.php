<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone with an account. Identified by email, so password, social and
 * magic-link sign-ins can all land on the same account.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
// "user" is reserved in PostgreSQL.
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
class User extends Base implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Always stored lower-case; see UserRepository::loadUserByIdentifier(). */
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private ?string $displayName = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /** Hashed. Null for accounts that only sign in another way (social, magic link). */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column]
    private bool $verified = false;

    /** Cellphone, shown on listings only when showPhone is on. */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[Assert\Regex('/^\+?[0-9 ()-]{6,}$/', message: 'Enter a phone number using digits, spaces and an optional leading +.')]
    private ?string $phone = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $showPhone = false;

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // Every account has at least ROLE_USER.
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $phone       = $phone === null ? null : trim($phone);
        $this->phone = $phone === '' ? null : $phone;

        return $this;
    }

    public function isShowPhone(): bool
    {
        return $this->showPhone;
    }

    public function setShowPhone(bool $showPhone): static
    {
        $this->showPhone = $showPhone;

        return $this;
    }

    /** The number to show buyers, or null when there isn't one to show. */
    public function getPublicPhone(): ?string
    {
        return $this->showPhone ? $this->phone : null;
    }

    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function setAdmin(bool $admin): static
    {
        $roles = array_values(array_diff($this->roles, ['ROLE_ADMIN']));
        if ($admin) {
            $roles[] = 'ROLE_ADMIN';
        }
        $this->roles = $roles;

        return $this;
    }

    /**
     * Keep the real password hash out of the session; Symfony compares this CRC instead.
     */
    public function __serialize(): array
    {
        $data                                    = (array) $this;
        $data["\0" . self::class . "\0password"] = $this->password === null ? null : hash('crc32c', $this->password);

        return $data;
    }
}
