<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class UserAuthController extends AbstractController
{
    #[Route('/login', name: 'user_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('events_list');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/register', name: 'user_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('events_list');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));
            $username = trim($request->request->get('username', ''));
            $password = $request->request->get('password', '');
            $confirm = $request->request->get('password_confirm', '');

            if (empty($email) || empty($username) || empty($password)) {
                $error = 'Tous les champs sont obligatoires.';
            } elseif ($password !== $confirm) {
                $error = 'Les mots de passe ne correspondent pas.';
            } elseif ($userRepository->findOneBy(['email' => $email])) {
                $error = 'Cet email est déjà utilisé.';
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setUsername($username);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $userRepository->save($user, true);

                $this->addFlash('success', 'Compte créé ! Connectez-vous.');
                return $this->redirectToRoute('user_login');
            }
        }

        return $this->render('auth/register.html.twig', ['error' => $error]);
    }

    #[Route('/logout', name: 'user_logout')]
    public function logout(): void {}
}