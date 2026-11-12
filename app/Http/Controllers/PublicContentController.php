<?php

namespace App\Http\Controllers;

use App\Appearance\GlobalAppearance;
use App\Content\PublicContent;
use App\Security\IdentityDocument;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PublicContentController extends Controller
{
    public function page(Request $request, PublicContent $content, string $slug): View
    {
        $page = $content->page($slug);
        abort_unless($page, 404);

        return $this->render($request, $content, 'public.page', [
            'page' => $page,
            'canonical' => url('/p/'.($this->locale() === 'ar' ? $page['slug_ar'] : $page['slug_en'])),
        ]);
    }

    public function article(Request $request, PublicContent $content, string $slug): View
    {
        $article = $content->article($slug);
        abort_unless($article, 404);

        return $this->render($request, $content, 'public.article', [
            'article' => $article,
            'categories' => $content->categories(),
            'canonical' => url('/articles/'.($this->locale() === 'ar' ? $article['slug_ar'] : $article['slug_en'])),
        ]);
    }

    public function articles(Request $request, PublicContent $content): View
    {
        return $this->render($request, $content, 'public.articles', [
            'listing' => $content->articles(),
            'categories' => $content->categories(),
            'canonical' => url('/articles'),
        ]);
    }

    public function category(Request $request, PublicContent $content, string $slug): View
    {
        $listing = $content->articles($slug);
        abort_unless($listing['category'], 404);

        return $this->render($request, $content, 'public.articles', [
            'listing' => $listing,
            'categories' => $content->categories(),
            'canonical' => url('/articles/category/'.($this->locale() === 'ar'
                ? $listing['category']['slug_ar'] : $listing['category']['slug_en'])),
        ]);
    }

    private function render(Request $request, PublicContent $content, string $view, array $data): View
    {
        return view($view, $data + [
            'locale' => $this->locale(),
            'navigation' => $content->navigation(),
            'footer' => $content->footer(),
            'appearanceTokens' => GlobalAppearance::tokens(app(GlobalAppearance::class)->current()),
            'maintenance' => request()->attributes->get('platform.maintenance'),
            'publicIdentity' => app(IdentityDocument::class)->metadata($request),
        ]);
    }

    private function locale(): string
    {
        return app()->getLocale() === 'ar' ? 'ar' : 'en';
    }
}
