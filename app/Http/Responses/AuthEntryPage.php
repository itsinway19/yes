<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

final class AuthEntryPage implements Responsable
{
    public function __construct(private string $kind, private array $configuration = []) {}

    public function toResponse($request): Response
    {
        if (! $request->expectsJson()) {
            $request->session()->keep(['errors', '_old_input', 'status']);
        }

        return (new SpaPage(SpaPage::needsData($request) ? [
            'kind' => $this->kind,
            'canResetPassword' => false,
            'passwordPolicy' => null,
            ...$this->configuration,
            'status' => $request->session()->get('status'),
            'errors' => $request->session()->get('errors')?->getBag('default')->messages() ?? [],
            'old' => ['name' => is_string($request->old('name')) ? $request->old('name') : null,
                'email' => is_string($request->old('email')) ? $request->old('email') : null],
        ] : []))->toResponse($request)->header('Referrer-Policy', 'no-referrer');
    }
}
