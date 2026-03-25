<?php
namespace App\Controller;

use App\Entity\Admin;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'admin_logout')]
    public function logout(): void {}

    #[Route('/', name: 'admin_dashboard')]
    #[Route('/dashboard', name: 'admin_dashboard_alt')]
    public function dashboard(
        EventRepository $eventRepository,
        ReservationRepository $reservationRepository
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'events' => $eventRepository->findAll(),
            'total_reservations' => count($reservationRepository->findAll()),
            'total_events' => count($eventRepository->findAll()),
        ]);
    }

    #[Route('/events', name: 'admin_events')]
    public function events(EventRepository $eventRepository): Response
    {
        return $this->render('admin/events/index.html.twig', [
            'events' => $eventRepository->findAll()
        ]);
    }

    #[Route('/events/new', name: 'admin_event_new', methods: ['GET', 'POST'])]
    public function newEvent(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $event = new Event();
            $event->setTitle($request->request->get('title'));
            $event->setDescription($request->request->get('description'));
            $event->setDate(new \DateTime($request->request->get('date')));
            $event->setLocation($request->request->get('location'));
            $event->setSeats((int)$request->request->get('seats'));

            $imageFile = $request->files->get('image');
            if ($imageFile) {
                $newFilename = uniqid() . '.' . $imageFile->guessExtension();
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/events';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $imageFile->move($uploadDir, $newFilename);
                $event->setImage('uploads/events/' . $newFilename);
            }

            $em->persist($event);
            $em->flush();

            $this->addFlash('success', 'Événement créé !');
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/events/new.html.twig');
    }

    #[Route('/events/{id}/edit', name: 'admin_event_edit', methods: ['GET', 'POST'])]
    public function editEvent(int $id, Request $request, EventRepository $eventRepository, EntityManagerInterface $em): Response
    {
        $event = $eventRepository->find($id);
        if (!$event) throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            $event->setTitle($request->request->get('title'));
            $event->setDescription($request->request->get('description'));
            $event->setDate(new \DateTime($request->request->get('date')));
            $event->setLocation($request->request->get('location'));
            $event->setSeats((int)$request->request->get('seats'));

            $imageFile = $request->files->get('image');
            if ($imageFile) {
                $newFilename = uniqid() . '.' . $imageFile->guessExtension();
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/events';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $imageFile->move($uploadDir, $newFilename);
                $event->setImage('uploads/events/' . $newFilename);
            }

            $em->flush();
            $this->addFlash('success', 'Événement modifié.');
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/events/edit.html.twig', ['event' => $event]);
    }

    #[Route('/events/{id}/delete', name: 'admin_event_delete', methods: ['POST'])]
    public function deleteEvent(int $id, EventRepository $eventRepository, EntityManagerInterface $em): Response
    {
        $event = $eventRepository->find($id);
        if ($event) {
            $em->remove($event);
            $em->flush();
            $this->addFlash('success', 'Événement supprimé.');
        }
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/events/{id}/reservations', name: 'admin_event_reservations')]
    public function eventReservations(int $id, EventRepository $eventRepository): Response
    {
        $event = $eventRepository->find($id);
        if (!$event) throw $this->createNotFoundException();

        return $this->render('admin/events/reservations.html.twig', ['event' => $event]);
    }

    #[Route('/setup', name: 'admin_setup')]
    public function setup(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $existing = $em->getRepository(Admin::class)->findOneBy(['username' => 'admin']);
        if ($existing) {
            return new Response('Admin existe déjà !');
        }

        $admin = new Admin();
        $admin->setUsername('admin');
        $admin->setPassword($hasher->hashPassword($admin, 'admin2026'));
        $em->persist($admin);
        $em->flush();

        return new Response('Admin créé ! Username: admin / Password: admin2026');
    }
}
