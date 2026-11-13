<?php

namespace App\Http\Middleware;

use App\Security\GrantAuthority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Content actions use the existing current-session grant transaction. */
final class RequireContentAction
{
    public function __construct(private readonly GrantAuthority $grants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $generation = (int) $request->session()->get('a001_generation', 0);
        $user = $request->user();
        $action = 'content.read';
        if (! $request->isMethod('GET')) {
            $name = (string) $request->route()?->getName();
            $action = match (true) {
                in_array($name, [
                    'internal.content.pages.store', 'internal.content.pages.update',
                    'internal.content.pages.restore', 'internal.content.pages.revisions.restore',
                    'internal.content.articles.store', 'internal.content.articles.update',
                    'internal.content.articles.restore', 'internal.content.articles.revisions.restore',
                ], true) => 'content.edit',
                in_array($name, [
                    'internal.content.sections.store', 'internal.content.sections.reorder',
                    'internal.content.sections.update', 'internal.content.sections.delete',
                    'internal.content.links.store', 'internal.content.links.reorder',
                    'internal.content.links.update', 'internal.content.links.delete',
                    'internal.content.pages.publish', 'internal.content.pages.unpublish',
                    'internal.content.pages.delete', 'internal.content.articles.publish',
                    'internal.content.articles.unpublish', 'internal.content.articles.delete',
                    'internal.content.categories.store', 'internal.content.categories.update',
                    'internal.content.categories.delete', 'internal.content.footer.update',
                ], true) => 'content.publish',
                default => null,
            };
        }
        if ($action === null || ! $this->grants->has($user, $generation, 'staff.view')
            || ! $this->grants->has($user, $generation, 'content.read')
            || ! $this->grants->has($user, $generation, $action)) {
            abort(403);
        }

        return $next($request);
    }
}
