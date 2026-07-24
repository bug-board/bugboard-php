<?php

declare(strict_types=1);

namespace App\Controller;

use BugBoard\Client as BugBoardClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController extends AbstractController
{
    // Autowire the client by constructor injection.
    public function __construct(private readonly BugBoardClient $bugboard) {}

    #[Route('/checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        try {
            $this->charge($request);
        } catch (\Throwable $e) {
            $this->bugboard->criticalHigh('Payment capture failed', $e, ['payments']);

            return $this->render('checkout/error.html.twig');
        }

        return $this->redirectToRoute('checkout_success');
    }

    private function charge(Request $request): void
    {
        throw new \RuntimeException('card declined');
    }
}
