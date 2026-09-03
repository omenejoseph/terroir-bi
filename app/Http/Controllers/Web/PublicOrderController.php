<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The self-service order page a customer reaches via their order token
 * (Customers · "Generate Order Link", Figma 231:9336). Unauthenticated and
 * outside `tenant.web` on purpose — the token itself is both the credential
 * and the tenant selector, resolved by `Api\PublicOrderController` /
 * `PublicTokenResolver`, not by a session.
 *
 * This controller renders only the page shell. The token is never validated
 * here: the page fetches its catalog from, and posts its order to, the same
 * public JSON API endpoints the token was always meant to authenticate
 * against (`GET/POST /api/v1/public/{token}/...`), so an invalid or revoked
 * token surfaces the same "this link isn't valid" state a real request would
 * hit rather than a second, duplicate check that could drift from the first.
 */
class PublicOrderController extends Controller
{
    public function show(string $token): Response
    {
        return Inertia::render('PublicOrder', ['token' => $token]);
    }
}
