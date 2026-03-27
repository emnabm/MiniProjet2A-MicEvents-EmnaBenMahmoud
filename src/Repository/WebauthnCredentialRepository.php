<?php
namespace App\Repository;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WebauthnCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function saveCredential(User $user, string $credentialId, string $publicKey, int $signCount): WebauthnCredential
    {
        $credential = new WebauthnCredential();
        $credential->setUser($user);
        $credential->setCredentialId($credentialId);
        $credential->setPublicKey($publicKey);
        $credential->setSignCount($signCount);

        $em = $this->getEntityManager();
        $em->persist($credential);
        $em->flush();

        return $credential;
    }

    public function findByCredentialId(string $credentialId): ?WebauthnCredential
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }
}