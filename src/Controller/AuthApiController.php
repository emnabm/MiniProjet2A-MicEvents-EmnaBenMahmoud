<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Service\WebAuthnService;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthApiController extends AbstractController
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private RefreshTokenManagerInterface $refreshTokenManager,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private WebAuthnService $webAuthnService,
        private WebauthnCredentialRepository $credentialRepository
    ) {}

    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $username = $data['username'] ?? null;

        if (!$email || !$password || !$username) {
            return $this->json(['error' => 'Email, username & password required'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->userRepository->findOneBy(['email' => $email]);
        if ($existing) {
            return $this->json(['error' => 'Email already used'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->userRepository->save($user, true);

        $jwt = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshTokenManager->save($refreshToken);

        return $this->json([
            'success' => true,
            'token' => $jwt,
            'refresh_token' => $refreshToken->getRefreshToken(),
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername()
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/register/options', methods: ['POST'])]
    public function registerOptions(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['error' => 'Email required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setUsername($data['username'] ?? $email);
            $user->setPassword('');
            $this->userRepository->save($user, true);
        }

        try {
            $options = $this->webAuthnService->generateRegistrationOptions(
                $user->getId(),
                $user->getEmail()
            );
            return $this->json($options);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/register/verify', methods: ['POST'])]
    public function registerVerify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;
        $credential = $data['credential'] ?? null;

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user || !$credential) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->webAuthnService->verifyRegistration($credential);

            $this->credentialRepository->saveCredential(
                $user,
                $result['credential_id'],
                $result['public_key'],
                $result['sign_count']
            );

            $jwt = $this->jwtManager->create($user);
            $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
            $this->refreshTokenManager->save($refreshToken);

            return $this->json([
                'success' => true,
                'token' => $jwt,
                'refresh_token' => $refreshToken->getRefreshToken(),
                'user' => ['id' => $user->getId(), 'email' => $user->getEmail()]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/login/options', methods: ['POST'])]
    public function loginOptions(): JsonResponse
    {
        try {
            $options = $this->webAuthnService->generateAuthenticationOptions();
            return $this->json($options);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/login/verify', methods: ['POST'])]
    public function loginVerify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $credential = $data['credential'] ?? null;

        if (!$credential) {
            return $this->json(['error' => 'Required credentials'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $credentialId = $credential['id'];
            $storedCredential = $this->credentialRepository->findByCredentialId($credentialId);

            if (!$storedCredential) {
                return $this->json(['error' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
            }

            $this->webAuthnService->verifyAuthentication(
                $credential,
                $storedCredential->getPublicKey(),
                $storedCredential->getSignCount()
            );

            $storedCredential->touch();
            $storedCredential->setSignCount($storedCredential->getSignCount() + 1);
            $this->credentialRepository->getEntityManager()->flush();

            $user = $storedCredential->getUser();
            $jwt = $this->jwtManager->create($user);
            $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
            $this->refreshTokenManager->save($refreshToken);

            return $this->json([
                'success' => true,
                'token' => $jwt,
                'refresh_token' => $refreshToken->getRefreshToken(),
                'user' => ['id' => $user->getId(), 'email' => $user->getEmail()]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authentified'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'roles' => $user->getRoles()
        ]);
    }
}