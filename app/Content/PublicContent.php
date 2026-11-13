<?php

namespace App\Content;

use Illuminate\Support\Facades\DB;

/** Read-only public projection. Drafts and unpublished content never appear here. */
final class PublicContent
{
    public function navigation(): array
    {
        $sections = DB::table('content_navigation_sections')->whereNull('deleted_at')
            ->where('visible', true)->orderBy('position')->orderBy('id')->get();
        $navigation = ['header' => [], 'footer' => []];
        foreach ($sections as $section) {
            $links = $this->sectionLinks((int) $section->id);
            if (count($links) === 0) {
                continue;
            }
            $navigation[$section->placement][] = ['public_id' => $section->public_id,
                'name_ar' => $section->name_ar, 'name_en' => $section->name_en, 'links' => $links];
        }

        return $navigation;
    }

    public function footer(): array
    {
        $row = DB::table('content_footer_settings')->where('id', 1)->first();
        $navigation = $this->navigation();

        return ['description_ar' => $row->description_ar ?? '', 'description_en' => $row->description_en ?? '',
            'copyright_ar' => $row->copyright_ar ?? '', 'copyright_en' => $row->copyright_en ?? '',
            'social_links' => json_decode($row->social_links ?? '[]', true) ?: [],
            'show_sections' => (bool) ($row->show_sections ?? true),
            'sections' => $navigation['footer']];
    }

    public function page(string $slug): ?array
    {
        $row = DB::table('content_pages as p')
            ->join('content_page_revisions as r', 'r.id', '=', 'p.published_revision_id')
            ->whereNull('p.deleted_at')
            ->where(fn ($query) => $query->whereRaw('lower(p.slug_ar) = lower(?)', [$slug])
                ->orWhereRaw('lower(p.slug_en) = lower(?)', [$slug]))
            ->select('p.public_id', 'p.slug_ar', 'p.slug_en', 'p.published_at', 'r.title_ar', 'r.title_en',
                'r.excerpt_ar', 'r.excerpt_en', 'r.body_ar', 'r.body_en',
                'r.seo_title_ar', 'r.seo_title_en', 'r.seo_description_ar', 'r.seo_description_en')
            ->first();

        return $row ? (array) $row + ['url_ar' => '/p/'.$row->slug_ar, 'url_en' => '/p/'.$row->slug_en] : null;
    }

    public function article(string $slug): ?array
    {
        $row = DB::table('content_articles as a')
            ->join('content_article_revisions as r', 'r.id', '=', 'a.published_revision_id')
            ->leftJoin('content_categories as c', function ($join) {
                $join->on('c.id', '=', 'a.category_id')->whereNull('c.deleted_at')->where('c.visible', true);
            })
            ->whereNull('a.deleted_at')
            ->where(fn ($query) => $query->whereRaw('lower(a.slug_ar) = lower(?)', [$slug])
                ->orWhereRaw('lower(a.slug_en) = lower(?)', [$slug]))
            ->select('a.public_id', 'a.slug_ar', 'a.slug_en', 'a.published_at', 'r.title_ar', 'r.title_en',
                'r.summary_ar', 'r.summary_en', 'r.body_ar', 'r.body_en', 'r.image_path',
                'r.image_alt_ar', 'r.image_alt_en', 'r.seo_title_ar', 'r.seo_title_en',
                'r.seo_description_ar', 'r.seo_description_en',
                'c.public_id as category_public_id', 'c.name_ar as category_name_ar', 'c.name_en as category_name_en',
                'c.slug_ar as category_slug_ar', 'c.slug_en as category_slug_en')
            ->first();

        return $row ? (array) $row + ['url_ar' => '/articles/'.$row->slug_ar, 'url_en' => '/articles/'.$row->slug_en] : null;
    }

    public function articles(?string $categorySlug = null): array
    {
        $query = DB::table('content_articles as a')
            ->join('content_article_revisions as r', 'r.id', '=', 'a.published_revision_id')
            ->leftJoin('content_categories as c', function ($join) {
                $join->on('c.id', '=', 'a.category_id')->whereNull('c.deleted_at')->where('c.visible', true);
            })
            ->whereNull('a.deleted_at');
        $category = null;
        if ($categorySlug !== null) {
            $category = $this->category($categorySlug);
            if (! $category) {
                return ['category' => null, 'articles' => []];
            }
            $query->where('a.category_id', $category['id']);
        }
        $rows = $query->orderBy('a.position')->orderByDesc('a.published_at')->orderByDesc('a.id')
            ->select('a.public_id', 'a.slug_ar', 'a.slug_en', 'a.published_at',
                'r.title_ar', 'r.title_en', 'r.summary_ar', 'r.summary_en', 'r.image_path',
                'r.image_alt_ar', 'r.image_alt_en',
                'c.name_ar as category_name_ar', 'c.name_en as category_name_en',
                'c.slug_ar as category_slug_ar', 'c.slug_en as category_slug_en')
            ->limit(60)->get();

        return ['category' => $category, 'articles' => $rows->map(fn ($row) => (array) $row + [
            'url_ar' => '/articles/'.$row->slug_ar, 'url_en' => '/articles/'.$row->slug_en])->all()];
    }

    public function category(string $slug): ?array
    {
        $row = DB::table('content_categories')->whereNull('deleted_at')->where('visible', true)
            ->where(fn ($query) => $query->whereRaw('lower(slug_ar) = lower(?)', [$slug])
                ->orWhereRaw('lower(slug_en) = lower(?)', [$slug]))
            ->select('id', 'public_id', 'slug_ar', 'slug_en', 'name_ar', 'name_en', 'description_ar', 'description_en')
            ->first();

        return $row ? (array) $row + ['url_ar' => '/articles/category/'.$row->slug_ar,
            'url_en' => '/articles/category/'.$row->slug_en] : null;
    }

    public function categories(): array
    {
        return DB::table('content_categories as c')->whereNull('c.deleted_at')->where('c.visible', true)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('content_articles as a')
                ->whereColumn('a.category_id', 'c.id')->whereNull('a.deleted_at')->whereNotNull('a.published_revision_id'))
            ->orderBy('c.position')->orderBy('c.id')
            ->get(['c.public_id', 'c.slug_ar', 'c.slug_en', 'c.name_ar', 'c.name_en'])->map(fn ($row) => (array) $row + [
                'url_ar' => '/articles/category/'.$row->slug_ar, 'url_en' => '/articles/category/'.$row->slug_en,
            ])->all();
    }

    private function sectionLinks(int $sectionId): array
    {
        $rows = DB::table('content_navigation_links as l')
            ->leftJoin('content_pages as p', 'p.id', '=', 'l.page_id')
            ->leftJoin('content_page_revisions as pr', 'pr.id', '=', 'p.published_revision_id')
            ->leftJoin('content_articles as a', 'a.id', '=', 'l.article_id')
            ->leftJoin('content_article_revisions as ar', 'ar.id', '=', 'a.published_revision_id')
            ->where('l.section_id', $sectionId)->whereNull('l.deleted_at')->where('l.visible', true)
            ->orderBy('l.position')->orderBy('l.id')
            ->select('l.public_id', 'l.label_ar', 'l.label_en', 'l.link_type', 'l.internal_path', 'l.external_url',
                'l.open_in_new_tab', 'p.slug_ar as page_slug_ar', 'p.slug_en as page_slug_en',
                'p.published_revision_id as page_published', 'p.deleted_at as page_deleted',
                'pr.title_ar as page_title_ar', 'pr.title_en as page_title_en',
                'a.slug_ar as article_slug_ar', 'a.slug_en as article_slug_en',
                'a.published_revision_id as article_published', 'a.deleted_at as article_deleted',
                'ar.title_ar as article_title_ar', 'ar.title_en as article_title_en')
            ->get();

        $links = [];
        foreach ($rows as $row) {
            if ($row->link_type === 'page' && ($row->page_published === null || $row->page_deleted !== null)) {
                continue;
            }
            if ($row->link_type === 'article' && ($row->article_published === null || $row->article_deleted !== null)) {
                continue;
            }
            $links[] = [
                'public_id' => $row->public_id,
                'label_ar' => $row->label_ar ?: ($row->link_type === 'page' ? $row->page_title_ar : ($row->link_type === 'article' ? $row->article_title_ar : null)),
                'label_en' => $row->label_en ?: ($row->link_type === 'page' ? $row->page_title_en : ($row->link_type === 'article' ? $row->article_title_en : null)),
                'url_ar' => match ($row->link_type) {
                    'page' => '/p/'.$row->page_slug_ar, 'article' => '/articles/'.$row->article_slug_ar,
                    'internal' => $row->internal_path, 'external' => $row->external_url,
                },
                'url_en' => match ($row->link_type) {
                    'page' => '/p/'.$row->page_slug_en, 'article' => '/articles/'.$row->article_slug_en,
                    'internal' => $row->internal_path, 'external' => $row->external_url,
                },
                'external' => $row->link_type === 'external',
                'open_in_new_tab' => (bool) $row->open_in_new_tab || $row->link_type === 'external',
            ];
        }

        return $links;
    }
}
