<?php

namespace App\Controller;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends AbstractController
{
    public function __construct(
        private string $adminPassword,
        private SubscriptionRepository $subscriptionRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/admin', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();

        // Check if already authenticated
        if ($session->get('admin_authenticated')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');

            if ($password === $this->adminPassword) {
                $session->set('admin_authenticated', true);
                return $this->redirectToRoute('admin_dashboard');
            }

            $error = 'Неверный пароль';
        }

        return $this->render('admin/login.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/admin/dashboard', name: 'admin_dashboard')]
    public function dashboard(Request $request): Response
    {
        if (!$this->checkAuth($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $subscriptions = $this->subscriptionRepository->findBy(
            [],
            ['createdAt' => 'DESC']
        );

        $stats = [
            'total' => count($subscriptions),
            'active' => count(array_filter($subscriptions, fn($s) => $s->isActive())),
            'inactive' => count(array_filter($subscriptions, fn($s) => !$s->isActive())),
        ];

        return $this->render('admin/dashboard.html.twig', [
            'subscriptions' => $subscriptions,
            'stats' => $stats,
        ]);
    }

    #[Route('/admin/subscription/{id}/toggle', name: 'admin_subscription_toggle', methods: ['POST'])]
    public function toggleSubscription(int $id, Request $request): Response
    {
        if (!$this->checkAuth($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $subscription = $this->subscriptionRepository->find($id);

        if (!$subscription) {
            $this->addFlash('error', 'Подписка не найдена');
            return $this->redirectToRoute('admin_dashboard');
        }

        $subscription->setIsActive(!$subscription->isActive());
        $this->entityManager->flush();

        $status = $subscription->isActive() ? 'активирована' : 'деактивирована';
        $this->addFlash('success', "Подписка {$status}");

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/subscription/{id}/delete', name: 'admin_subscription_delete', methods: ['POST'])]
    public function deleteSubscription(int $id, Request $request): Response
    {
        if (!$this->checkAuth($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $subscription = $this->subscriptionRepository->find($id);

        if (!$subscription) {
            $this->addFlash('error', 'Подписка не найдена');
            return $this->redirectToRoute('admin_dashboard');
        }

        $this->entityManager->remove($subscription);
        $this->entityManager->flush();

        $this->addFlash('success', 'Подписка удалена');

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/subscription/{id}/update-times', name: 'admin_subscription_update_times', methods: ['POST'])]
    public function updateSubscriptionTimes(int $id, Request $request): Response
    {
        if (!$this->checkAuth($request)) {
            return $this->redirectToRoute('admin_login');
        }

        $subscription = $this->subscriptionRepository->find($id);

        if (!$subscription) {
            $this->addFlash('error', 'Подписка не найдена');
            return $this->redirectToRoute('admin_dashboard');
        }

        $weekdayTime = $request->request->get('weekday_time');
        $weekendTime = $request->request->get('weekend_time');

        try {
            if ($weekdayTime) {
                $subscription->setWeekdayTime(new \DateTime($weekdayTime));
            }
            if ($weekendTime) {
                $subscription->setWeekendTime(new \DateTime($weekendTime));
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Время отправки обновлено');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка при обновлении времени');
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('admin_authenticated');
        return $this->redirectToRoute('admin_login');
    }

    private function checkAuth(Request $request): bool
    {
        return $request->getSession()->get('admin_authenticated', false);
    }
}
