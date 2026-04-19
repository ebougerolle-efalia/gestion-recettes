<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use App\Repository\UserRepository;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $username = '';

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $password = '';

    #[ORM\Column(length: 20)]
    private string $role = 'editor'; // reader, editor, admin

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $v): static { $this->username = $v; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $v): static { $this->password = $v; return $this; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $v): static { $this->role = $v; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    // UserInterface
    public function getUserIdentifier(): string { return $this->username; }
    public function eraseCredentials(): void {}

    public function getRoles(): array
    {
        $map = ['reader' => 'ROLE_READER', 'editor' => 'ROLE_EDITOR', 'admin' => 'ROLE_ADMIN'];
        return [$map[$this->role] ?? 'ROLE_READER'];
    }
}
