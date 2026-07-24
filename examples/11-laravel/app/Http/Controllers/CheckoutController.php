<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use BugBoard\Client as BugBoardClient;
use BugBoard\Laravel\Facades\BugBoard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The three equivalent ways to report — pick one and be consistent.
 */
final class CheckoutController extends Controller
{
    // 2. Injection (best for testing — you can swap the binding).
    public function __construct(private readonly BugBoardClient $bugboard) {}

    public function store(Request $request): Response
    {
        try {
            $this->charge($request);
        } catch (\Throwable $e) {
            // 1. Facade (most common).
            BugBoard::critical('Payment failed', $e, ['payments', 'checkout']);

            // 2. Injection.
            $this->bugboard->major('Checkout is slow');

            // 3. Container alias (where neither fits).
            app('bugboard')->minor('Tooltip misaligned');

            return response('Checkout failed', 500);
        }

        return response('OK');
    }

    private function charge(Request $request): void
    {
        throw new \RuntimeException('card declined');
    }
}
