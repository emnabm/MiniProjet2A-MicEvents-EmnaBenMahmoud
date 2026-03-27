<?php
namespace App\Controller;

use App\Entity\Reservation;
use App\Repository\EventRepository;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EventController extends AbstractController
{
    #[Route('/', name: 'home')]
    #[Route('/events', name: 'events_list')]
    public function index(EventRepository $eventRepository): Response
    {
        $events = $eventRepository->findAll();
        return $this->render('events/index.html.twig', ['events' => $events]);
    }

    #[Route('/events/{id}', name: 'event_detail')]
    public function show(int $id, EventRepository $eventRepository): Response
    {
        $event = $eventRepository->find($id);
        if (!$event) {
            throw $this->createNotFoundException('Event not found');
        }
        return $this->render('events/show.html.twig', ['event' => $event]);
    }

    #[Route('/events/{id}/reserve', name: 'event_reserve', methods: ['GET', 'POST'])]
    public function reserve(
        int $id,
        Request $request,
        EventRepository $eventRepository,
        ReservationRepository $reservationRepository
    ): Response {
        if (!$this->getUser()) {
            $this->addFlash('error', 'You must be logged in to reserve a spot.');
            return $this->redirectToRoute('user_login');
        }

        $event = $eventRepository->find($id);
        if (!$event) {
            throw $this->createNotFoundException('Event not found');
        }

        if ($event->getAvailableSeats() <= 0) {
            $this->addFlash('error', 'No more available places');
            return $this->redirectToRoute('event_detail', ['id' => $id]);
        }

        $error = null;
        $success = false;

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $email = trim($request->request->get('email', ''));
            $phone = trim($request->request->get('phone', ''));

            if (empty($name) || empty($email) || empty($phone)) {
                $error = 'All fields are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } else {
                $reservation = new Reservation();
                $reservation->setEvent($event);
                $reservation->setName($name);
                $reservation->setEmail($email);
                $reservation->setPhone($phone);
                $reservation->setUser($this->getUser());

                $reservationRepository->save($reservation, true);
                $success = true;
            }
        }

        return $this->render('events/reserve.html.twig', [
            'event' => $event,
            'error' => $error,
            'success' => $success
        ]);
    }
}