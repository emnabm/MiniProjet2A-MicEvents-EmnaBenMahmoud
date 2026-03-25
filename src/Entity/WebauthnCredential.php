<?php
namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
class WebauthnCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'webauthnCredentials')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $credentialId;

    #[ORM\Column(type: 'text')]
    private string $publicKey;

    #[ORM\Column]
    private int $signCount = 0;

    #[ORM\Column(length: 255)]
    private string $name = 'Ma Passkey';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getCredentialId(): string { return $this->credentialId; }
    public function setCredentialId(string $credentialId): static { $this->credentialId = $credentialId; return $this; }
    public function getPublicKey(): string { return $this->publicKey; }
    public function setPublicKey(string $publicKey): static { $this->publicKey = $publicKey; return $this; }
    public function getSignCount(): int { return $this->signCount; }
    public function setSignCount(int $signCount): static { $this->signCount = $signCount; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): \DateTimeImmutable { return $this->lastUsedAt; }
    public function touch(): void { $this->lastUsedAt = new \DateTimeImmutable(); }
}