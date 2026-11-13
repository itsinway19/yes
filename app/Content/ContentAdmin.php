<?php

namespace App\Content;

use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Canonical writer for platform content, navigation and footer settings. */
final class ContentAdmin
{
    public const PLACEMENTS = ['header', 'footer'];

    public const LINK_TYPES = ['page', 'article', 'internal', 'external'];

    /** Stable, allow-listed destination paths for internal links. */
    public const INTERNAL_PATHS = [
        '/' => ['الرئيسية', 'Home'],
        '/articles' => ['المقالات', 'Articles'],
        '/businesses' => ['أنشطتي', 'My businesses'],
        '/login' => ['تسجيل الدخول', 'Sign in'],
        '/register' => ['إنشاء حساب', 'Create account'],
        '/forgot-password' => ['استعادة الحساب', 'Recover account'],
    ];

    public function sections(): array
    {
        $rows = DB::table('content_navigation_sections as s')
            ->whereNull('s.deleted_at')
            ->select('s.public_id', 's.name_ar', 's.name_en', 's.placement', 's.position', 's.visible', 's.updated_at')
            ->selectRaw('(SELECT count(*) FROM content_navigation_links l WHERE l.section_id = s.id AND l.deleted_at IS NULL) AS link_count')
            ->orderByRaw("CASE s.placement WHEN 'header' THEN 0 ELSE 1 END")
            ->orderBy('s.position')->orderBy('s.id')->get();

        return ['canManage' => $this->can('content.publish'), 'sections' => $rows->map(fn ($row) => $this->sectionRow($row))->all(),
            'placements' => [['value' => 'header', 'label_ar' => 'رأس الموقع', 'label_en' => 'Site header'],
                ['value' => 'footer', 'label_ar' => 'الفوتر', 'label_en' => 'Footer']]];
    }

    public function saveSection(int $userId, array $data, ?string $publicId = null): array
    {
        return DB::transaction(function () use ($userId, $data, $publicId): array {
            $payload = [
                'name_ar' => $data['name_ar'], 'name_en' => $data['name_en'],
                'placement' => $data['placement'], 'position' => (int) ($data['position'] ?? 0),
                'visible' => (bool) ($data['visible'] ?? true), 'updated_at' => now(),
            ];
            if ($publicId !== null) {
                $row = DB::table('content_navigation_sections')->where('public_id', $publicId)
                    ->whereNull('deleted_at')->lockForUpdate()->first();
                abort_unless($row, 404);
                DB::table('content_navigation_sections')->where('id', $row->id)->update($payload);
                $id = $row->id;
            } else {
                $id = DB::table('content_navigation_sections')->insertGetId($payload + [
                    'public_id' => (string) Str::uuid7(), 'created_at' => now()]);
            }
            $this->audit($userId, null, 'content_section_saved', 'section:'.($publicId ?? 'new'));

            return $this->sectionRow(DB::table('content_navigation_sections')->where('id', $id)->first());
        });
    }

    public function deleteSection(int $userId, string $publicId): void
    {
        DB::transaction(function () use ($userId, $publicId): void {
            $row = DB::table('content_navigation_sections')->where('public_id', $publicId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($row, 404);
            $now = now();
            DB::table('content_navigation_links')->where('section_id', $row->id)->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('content_navigation_sections')->where('id', $row->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->audit($userId, null, 'content_section_deleted', 'section:'.$publicId);
        });
    }

    public function reorderSections(int $userId, array $items): void
    {
        $this->reorder($userId, 'content_navigation_sections', $items, null);
    }

    public function sectionLinks(string $sectionPublicId): array
    {
        $section = DB::table('content_navigation_sections')->where('public_id', $sectionPublicId)
            ->whereNull('deleted_at')->first();
        abort_unless($section, 404);

        $links = DB::table('content_navigation_links as l')
            ->leftJoin('content_pages as p', 'p.id', '=', 'l.page_id')
            ->leftJoin('content_page_revisions as pr', 'pr.id', '=', 'p.published_revision_id')
            ->leftJoin('content_articles as a', 'a.id', '=', 'l.article_id')
            ->leftJoin('content_article_revisions as ar', 'ar.id', '=', 'a.published_revision_id')
            ->where('l.section_id', $section->id)->whereNull('l.deleted_at')
            ->orderBy('l.position')->orderBy('l.id')
            ->select('l.public_id', 'l.label_ar', 'l.label_en', 'l.link_type', 'l.internal_path', 'l.external_url',
                'l.position', 'l.visible', 'l.open_in_new_tab',
                'p.public_id as page_public_id', 'p.slug_ar as page_slug_ar', 'p.slug_en as page_slug_en',
                'p.published_revision_id as page_published', 'p.deleted_at as page_deleted',
                'pr.title_ar as page_title_ar', 'pr.title_en as page_title_en',
                'a.public_id as article_public_id', 'a.slug_ar as article_slug_ar', 'a.slug_en as article_slug_en',
                'a.published_revision_id as article_published', 'a.deleted_at as article_deleted',
                'ar.title_ar as article_title_ar', 'ar.title_en as article_title_en')
            ->get();

        return [
            'canManage' => $this->can('content.publish'),
            'section' => $this->sectionRow($section),
            'links' => $links->map(fn ($row) => $this->linkRow($row))->all(),
            'choices' => $this->linkChoices(),
        ];
    }

    public function saveLink(int $userId, string $sectionPublicId, array $data, ?string $publicId = null): array
    {
        $section = DB::table('content_navigation_sections')->where('public_id', $sectionPublicId)
            ->whereNull('deleted_at')->first();
        abort_unless($section, 404);

        $payload = [
            'section_id' => $section->id,
            'label_ar' => $data['label_ar'] ?? null, 'label_en' => $data['label_en'] ?? null,
            'link_type' => $data['link_type'],
            'page_id' => null, 'article_id' => null, 'internal_path' => null, 'external_url' => null,
            'position' => (int) ($data['position'] ?? $this->nextPosition('content_navigation_links', 'section_id', $section->id)),
            'visible' => (bool) ($data['visible'] ?? true),
            'open_in_new_tab' => (bool) ($data['open_in_new_tab'] ?? false),
            'updated_at' => now(),
        ];
        if ($data['link_type'] === 'page') {
            $target = DB::table('content_pages')->where('public_id', $data['target'] ?? '')->whereNull('deleted_at')->first();
            abort_unless($target, 422, 'Page target is unavailable.');
            $payload['page_id'] = $target->id;
        } elseif ($data['link_type'] === 'article') {
            $target = DB::table('content_articles')->where('public_id', $data['target'] ?? '')->whereNull('deleted_at')->first();
            abort_unless($target, 422, 'Article target is unavailable.');
            $payload['article_id'] = $target->id;
        } elseif ($data['link_type'] === 'internal') {
            abort_unless(array_key_exists((string) ($data['internal_path'] ?? ''), self::INTERNAL_PATHS), 422, 'Internal path is not allow-listed.');
            $payload['internal_path'] = $data['internal_path'];
        } else {
            $url = (string) ($data['external_url'] ?? '');
            abort_unless(str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL), 422, 'External links must be valid HTTPS URLs.');
            $payload['external_url'] = $url;
        }
        abort_unless($payload['label_ar'] || $payload['label_en'] || in_array($data['link_type'], ['page', 'article'], true), 422, 'A label is required for this link type.');

        return DB::transaction(function () use ($userId, $payload, $publicId): array {
            if ($publicId !== null) {
                $row = DB::table('content_navigation_links')->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
                abort_unless($row, 404);
                DB::table('content_navigation_links')->where('id', $row->id)->update($payload);
                $id = $row->id;
            } else {
                $id = DB::table('content_navigation_links')->insertGetId($payload + [
                    'public_id' => (string) Str::uuid7(), 'created_at' => now()]);
            }
            $this->audit($userId, null, 'content_link_saved', 'link:'.($publicId ?? 'new'));

            return $this->linkRow($this->linkQuery()->where('l.id', $id)->first());
        });
    }

    public function deleteLink(int $userId, string $publicId): void
    {
        DB::transaction(function () use ($userId, $publicId): void {
            $row = DB::table('content_navigation_links')->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('content_navigation_links')->where('id', $row->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            $this->audit($userId, null, 'content_link_deleted', 'link:'.$publicId);
        });
    }

    public function reorderLinks(int $userId, string $sectionPublicId, array $items): void
    {
        $section = DB::table('content_navigation_sections')->where('public_id', $sectionPublicId)->whereNull('deleted_at')->first();
        abort_unless($section, 404);
        $this->reorder($userId, 'content_navigation_links', $items, $section->id);
    }

    public function pages(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $query = DB::table('content_pages as p')->whereNull('p.deleted_at');
        if ($status === 'deleted') {
            $query = DB::table('content_pages as p')->whereNotNull('p.deleted_at');
        } elseif ($status === 'published') {
            $query->whereNotNull('p.published_revision_id');
        } elseif ($status === 'draft') {
            $query->whereNull('p.published_revision_id');
        }
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('p.slug_ar', 'ilike', $like)->orWhere('p.slug_en', 'ilike', $like)
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('content_page_revisions as r')
                        ->whereColumn('r.page_id', 'p.id')->where(fn ($w) => $w->where('r.title_ar', 'ilike', $like)->orWhere('r.title_en', 'ilike', $like)));
            });
        }
        $total = (clone $query)->count();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $query->select('p.public_id', 'p.slug_ar', 'p.slug_en', 'p.position', 'p.published_revision_id',
            'p.draft_revision_id', 'p.published_at', 'p.updated_at', 'p.deleted_at')
            ->selectRaw('(SELECT title_ar FROM content_page_revisions r WHERE r.id = COALESCE(p.published_revision_id, p.draft_revision_id)) AS title_ar')
            ->selectRaw('(SELECT title_en FROM content_page_revisions r WHERE r.id = COALESCE(p.published_revision_id, p.draft_revision_id)) AS title_en')
            ->orderBy('p.position')->orderByDesc('p.updated_at')->orderBy('p.id')
            ->forPage($page, 20)->get();

        return ['canManage' => $this->can('content.edit'), 'canPublish' => $this->can('content.publish'), 'total' => $total, 'page' => $page, 'perPage' => 20,
            'rows' => $rows->map(fn ($row) => [
                'public_id' => $row->public_id, 'title_ar' => $row->title_ar, 'title_en' => $row->title_en,
                'slug_ar' => $row->slug_ar, 'slug_en' => $row->slug_en, 'position' => (int) $row->position,
                'status' => $row->deleted_at ? 'deleted' : ($row->published_revision_id ? 'published' : 'draft'),
                'deleted' => $row->deleted_at !== null, 'published_at' => $this->iso($row->published_at),
                'updated_at' => $this->iso($row->updated_at),
                'url_ar' => '/p/'.$row->slug_ar, 'url_en' => '/p/'.$row->slug_en,
            ])->all()];
    }

    public function pageEditor(string $publicId): array
    {
        $page = DB::table('content_pages')->where('public_id', $publicId)->first();
        abort_unless($page, 404);

        return ['canManage' => $this->can('content.edit'), 'canPublish' => $this->can('content.publish'), 'page' => [
            'public_id' => $page->public_id, 'slug_ar' => $page->slug_ar, 'slug_en' => $page->slug_en,
            'position' => (int) $page->position, 'deleted' => $page->deleted_at !== null,
            'published_at' => $this->iso($page->published_at),
            'url_ar' => '/p/'.$page->slug_ar, 'url_en' => '/p/'.$page->slug_en,
        ], 'draft' => $this->pageRevision($page->draft_revision_id, $page->published_revision_id),
            'published' => $this->pageRevision($page->published_revision_id, $page->published_revision_id),
            'revisions' => $this->revisionIndex('content_page_revisions', 'page_id', $page->id, $page->published_revision_id),
            'sections' => $this->sectionChoices(),
            'placements' => $this->pagePlacements((int) $page->id)];
    }

    /** Header/footer sections a page can be attached to, in public order. */
    private function sectionChoices(): array
    {
        return DB::table('content_navigation_sections')->whereNull('deleted_at')
            ->orderBy('placement')->orderBy('position')->orderBy('id')
            ->get(['public_id', 'name_ar', 'name_en', 'placement'])->map(fn ($row) => [
                'public_id' => $row->public_id, 'name_ar' => $row->name_ar,
                'name_en' => $row->name_en, 'placement' => $row->placement,
            ])->all();
    }

    /** Where this page already appears, so the editor shows real membership, not a guess. */
    private function pagePlacements(int $pageId): array
    {
        return DB::table('content_navigation_links as l')
            ->join('content_navigation_sections as s', 's.id', '=', 'l.section_id')
            ->where('l.page_id', $pageId)->whereNull('l.deleted_at')->whereNull('s.deleted_at')
            ->orderBy('s.placement')->orderBy('s.position')->orderBy('l.position')->orderBy('l.id')
            ->get(['l.public_id as link_public_id', 'l.visible', 'l.position',
                's.public_id as section_public_id', 's.name_ar as section_name_ar', 's.name_en as section_name_en'])
            ->map(fn ($row) => [
                'link_public_id' => $row->link_public_id, 'section_public_id' => $row->section_public_id,
                'section_name_ar' => $row->section_name_ar, 'section_name_en' => $row->section_name_en,
                'visible' => (bool) $row->visible, 'position' => (int) $row->position,
            ])->all();
    }

    public function createPage(int $userId, array $data): array
    {
        $this->assertSlugsFree('content_pages', $data['slug_ar'], $data['slug_en']);
        $publicId = DB::transaction(function () use ($userId, $data): string {
            $publicId = (string) Str::uuid7();
            $id = DB::table('content_pages')->insertGetId(['public_id' => $publicId,
                'slug_ar' => $data['slug_ar'], 'slug_en' => $data['slug_en'],
                'position' => (int) ($data['position'] ?? 0), 'created_by' => $userId,
                'created_at' => now(), 'updated_at' => now()]);
            $revisionId = $this->insertPageRevision($userId, $id, $data, 1);
            DB::table('content_pages')->where('id', $id)->update(['draft_revision_id' => $revisionId]);
            $this->audit($userId, null, 'content_page_created', 'page:'.$publicId);

            return $publicId;
        });

        return $this->pageEditor($publicId);
    }

    public function savePageDraft(int $userId, string $publicId, array $data): array
    {
        DB::transaction(function () use ($userId, $publicId, $data): void {
            $page = DB::table('content_pages')->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($page, 404);
            $draft = DB::table('content_page_revisions')->where('id', $page->draft_revision_id)->first(['revision', 'position']);
            if ((int) $data['expected_revision'] !== (int) ($draft->revision ?? 0)) {
                throw new ConflictHttpException('This page changed in another session. Reload the editor before saving.');
            }
            $this->assertSlugsFree('content_pages', $data['slug_ar'], $data['slug_en'], $page->id);
            $data['position'] ??= $draft->position ?? $page->position;
            $next = (int) DB::table('content_page_revisions')->where('page_id', $page->id)->max('revision') + 1;
            $revisionId = $this->insertPageRevision($userId, $page->id, $data, $next);
            DB::table('content_pages')->where('id', $page->id)->update([
                'draft_revision_id' => $revisionId, 'updated_at' => now()]);
            $this->audit($userId, null, 'content_page_draft_saved', 'page:'.$publicId.';revision:'.$next);
        });

        return $this->pageEditor($publicId);
    }

    public function publishPage(int $userId, string $publicId, int $revision): array
    {
        $this->publishRevision($userId, 'content_pages', 'content_page_revisions', 'page_id', $publicId, $revision, 'content_page_published');

        return $this->pageEditor($publicId);
    }

    public function unpublishPage(int $userId, string $publicId): array
    {
        $this->unpublish('content_pages', 'content_page_revisions', $publicId, $userId, 'content_page_unpublished');

        return $this->pageEditor($publicId);
    }

    public function deletePage(int $userId, string $publicId): void
    {
        $this->softDelete('content_pages', $publicId, $userId, 'content_page_deleted');
    }

    public function restorePage(int $userId, string $publicId): array
    {
        $this->restoreDeleted('content_pages', $publicId, $userId, 'content_page_restored');

        return $this->pageEditor($publicId);
    }

    public function restorePageRevision(int $userId, string $publicId, int $revision): array
    {
        $this->restoreRevision($userId, 'content_pages', 'content_page_revisions', 'page_id', $publicId, $revision, 'content_page_revision_restored', 'page');

        return $this->pageEditor($publicId);
    }

    public function pagePreview(string $publicId): ?array
    {
        $page = DB::table('content_pages')->where('public_id', $publicId)->first();
        if (! $page) {
            return null;
        }
        $revision = $this->pageRevision($page->draft_revision_id, $page->published_revision_id)
            ?? $this->pageRevision($page->published_revision_id, $page->published_revision_id);

        return $revision ? ['title_ar' => $revision['title_ar'], 'title_en' => $revision['title_en'],
            'body_ar' => $revision['body_ar'], 'body_en' => $revision['body_en'],
            'published' => $page->draft_revision_id === $page->published_revision_id] : null;
    }

    public function articles(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $query = DB::table('content_articles as a')->whereNull('a.deleted_at');
        if ($status === 'deleted') {
            $query = DB::table('content_articles as a')->whereNotNull('a.deleted_at');
        } elseif ($status === 'published') {
            $query->whereNotNull('a.published_revision_id');
        } elseif ($status === 'draft') {
            $query->whereNull('a.published_revision_id');
        }
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('a.slug_ar', 'ilike', $like)->orWhere('a.slug_en', 'ilike', $like)
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('content_article_revisions as r')
                        ->whereColumn('r.article_id', 'a.id')->where(fn ($w) => $w->where('r.title_ar', 'ilike', $like)->orWhere('r.title_en', 'ilike', $like)));
            });
        }
        $total = (clone $query)->count();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $query->leftJoin('content_categories as c', 'c.id', '=', 'a.category_id')
            ->select('a.public_id', 'a.slug_ar', 'a.slug_en', 'a.position', 'a.published_revision_id',
                'a.draft_revision_id', 'a.published_at', 'a.updated_at', 'a.deleted_at', 'c.public_id as category_public_id',
                'c.name_ar as category_name_ar', 'c.name_en as category_name_en')
            ->selectRaw('(SELECT title_ar FROM content_article_revisions r WHERE r.id = COALESCE(a.published_revision_id, a.draft_revision_id)) AS title_ar')
            ->selectRaw('(SELECT title_en FROM content_article_revisions r WHERE r.id = COALESCE(a.published_revision_id, a.draft_revision_id)) AS title_en')
            ->orderBy('a.position')->orderByDesc('a.published_at')->orderBy('a.id')
            ->forPage($page, 20)->get();

        return ['canManage' => $this->can('content.edit'), 'canPublish' => $this->can('content.publish'), 'total' => $total, 'page' => $page, 'perPage' => 20,
            'rows' => $rows->map(fn ($row) => [
                'public_id' => $row->public_id, 'title_ar' => $row->title_ar, 'title_en' => $row->title_en,
                'slug_ar' => $row->slug_ar, 'slug_en' => $row->slug_en, 'position' => (int) $row->position,
                'category_public_id' => $row->category_public_id, 'category_name_ar' => $row->category_name_ar,
                'category_name_en' => $row->category_name_en,
                'status' => $row->deleted_at ? 'deleted' : ($row->published_revision_id ? 'published' : 'draft'),
                'deleted' => $row->deleted_at !== null, 'published_at' => $this->iso($row->published_at),
                'updated_at' => $this->iso($row->updated_at),
                'url_ar' => '/articles/'.$row->slug_ar, 'url_en' => '/articles/'.$row->slug_en,
            ])->all()];
    }

    public function articleEditor(string $publicId): array
    {
        $article = DB::table('content_articles')->where('public_id', $publicId)->first();
        abort_unless($article, 404);
        $category = $article->category_id ? DB::table('content_categories')->where('id', $article->category_id)->first() : null;

        return ['canManage' => $this->can('content.edit'), 'canPublish' => $this->can('content.publish'), 'article' => [
            'public_id' => $article->public_id, 'slug_ar' => $article->slug_ar, 'slug_en' => $article->slug_en,
            'position' => (int) $article->position, 'deleted' => $article->deleted_at !== null,
            'category_public_id' => $category->public_id ?? null,
            'published_at' => $this->iso($article->published_at),
            'url_ar' => '/articles/'.$article->slug_ar, 'url_en' => '/articles/'.$article->slug_en,
        ], 'draft' => $this->articleRevision($article->draft_revision_id, $article->published_revision_id),
            'published' => $this->articleRevision($article->published_revision_id, $article->published_revision_id),
            'revisions' => $this->revisionIndex('content_article_revisions', 'article_id', $article->id, $article->published_revision_id),
            'categories' => $this->categoryChoices()];
    }

    public function createArticle(int $userId, array $data): array
    {
        $this->assertSlugsFree('content_articles', $data['slug_ar'], $data['slug_en']);
        $publicId = DB::transaction(function () use ($userId, $data): string {
            $publicId = (string) Str::uuid7();
            $id = DB::table('content_articles')->insertGetId(['public_id' => $publicId,
                'category_id' => $this->categoryId($data['category_public_id'] ?? null),
                'slug_ar' => $data['slug_ar'], 'slug_en' => $data['slug_en'],
                'position' => (int) ($data['position'] ?? 0), 'created_by' => $userId,
                'created_at' => now(), 'updated_at' => now()]);
            $revisionId = $this->insertArticleRevision($userId, $id, $data, 1);
            DB::table('content_articles')->where('id', $id)->update(['draft_revision_id' => $revisionId]);
            $this->audit($userId, null, 'content_article_created', 'article:'.$publicId);

            return $publicId;
        });

        return $this->articleEditor($publicId);
    }

    public function saveArticleDraft(int $userId, string $publicId, array $data): array
    {
        DB::transaction(function () use ($userId, $publicId, $data): void {
            $article = DB::table('content_articles')->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($article, 404);
            $draft = DB::table('content_article_revisions')->where('id', $article->draft_revision_id)->first(['revision', 'position']);
            if ((int) $data['expected_revision'] !== (int) ($draft->revision ?? 0)) {
                throw new ConflictHttpException('This article changed in another session. Reload the editor before saving.');
            }
            $this->assertSlugsFree('content_articles', $data['slug_ar'], $data['slug_en'], $article->id);
            $data['position'] ??= $draft->position ?? $article->position;
            $next = (int) DB::table('content_article_revisions')->where('article_id', $article->id)->max('revision') + 1;
            $revisionId = $this->insertArticleRevision($userId, $article->id, $data, $next);
            DB::table('content_articles')->where('id', $article->id)->update([
                'draft_revision_id' => $revisionId, 'updated_at' => now()]);
            $this->audit($userId, null, 'content_article_draft_saved', 'article:'.$publicId.';revision:'.$next);
        });

        return $this->articleEditor($publicId);
    }

    public function publishArticle(int $userId, string $publicId, int $revision): array
    {
        $this->publishRevision($userId, 'content_articles', 'content_article_revisions', 'article_id', $publicId, $revision, 'content_article_published');

        return $this->articleEditor($publicId);
    }

    public function unpublishArticle(int $userId, string $publicId): array
    {
        $this->unpublish('content_articles', 'content_article_revisions', $publicId, $userId, 'content_article_unpublished');

        return $this->articleEditor($publicId);
    }

    public function deleteArticle(int $userId, string $publicId): void
    {
        $this->softDelete('content_articles', $publicId, $userId, 'content_article_deleted');
    }

    public function restoreArticle(int $userId, string $publicId): array
    {
        $this->restoreDeleted('content_articles', $publicId, $userId, 'content_article_restored');

        return $this->articleEditor($publicId);
    }

    public function restoreArticleRevision(int $userId, string $publicId, int $revision): array
    {
        $this->restoreRevision($userId, 'content_articles', 'content_article_revisions', 'article_id', $publicId, $revision, 'content_article_revision_restored', 'article');

        return $this->articleEditor($publicId);
    }

    public function articlePreview(string $publicId): ?array
    {
        $article = DB::table('content_articles')->where('public_id', $publicId)->first();
        if (! $article) {
            return null;
        }
        $revision = $this->articleRevision($article->draft_revision_id, $article->published_revision_id)
            ?? $this->articleRevision($article->published_revision_id, $article->published_revision_id);

        return $revision ? ['title_ar' => $revision['title_ar'], 'title_en' => $revision['title_en'],
            'body_ar' => $revision['body_ar'], 'body_en' => $revision['body_en'],
            'published' => $article->draft_revision_id === $article->published_revision_id] : null;
    }

    public function categories(): array
    {
        $rows = DB::table('content_categories as c')
            ->select('c.public_id', 'c.slug_ar', 'c.slug_en', 'c.name_ar', 'c.name_en',
                'c.description_ar', 'c.description_en', 'c.position', 'c.visible', 'c.deleted_at', 'c.updated_at')
            ->selectRaw('(SELECT count(*) FROM content_articles a WHERE a.category_id = c.id AND a.deleted_at IS NULL) AS article_count')
            ->orderBy('c.position')->orderBy('c.id')->get();

        return ['canManage' => $this->can('content.publish'), 'rows' => $rows->map(fn ($row) => [
            'public_id' => $row->public_id, 'slug_ar' => $row->slug_ar, 'slug_en' => $row->slug_en,
            'name_ar' => $row->name_ar, 'name_en' => $row->name_en,
            'description_ar' => $row->description_ar, 'description_en' => $row->description_en,
            'position' => (int) $row->position, 'visible' => (bool) $row->visible,
            'article_count' => (int) $row->article_count, 'deleted' => $row->deleted_at !== null,
            'updated_at' => $this->iso($row->updated_at),
        ])->all()];
    }

    public function saveCategory(int $userId, array $data, ?string $publicId = null): array
    {
        try {
            DB::transaction(function () use ($userId, $data, $publicId): void {
                $payload = ['slug_ar' => $data['slug_ar'], 'slug_en' => $data['slug_en'],
                    'name_ar' => $data['name_ar'], 'name_en' => $data['name_en'],
                    'description_ar' => ContentSanitizer::plain($data['description_ar'] ?? null),
                    'description_en' => ContentSanitizer::plain($data['description_en'] ?? null),
                    'position' => (int) ($data['position'] ?? 0), 'visible' => (bool) ($data['visible'] ?? true),
                    'updated_at' => now()];
                if ($publicId !== null) {
                    $row = DB::table('content_categories')->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
                    abort_unless($row, 404);
                    $this->assertSlugsFree('content_categories', $data['slug_ar'], $data['slug_en'], $row->id);
                    DB::table('content_categories')->where('id', $row->id)->update($payload);
                } else {
                    DB::table('content_categories')->insertGetId($payload + ['public_id' => (string) Str::uuid7(), 'created_at' => now()]);
                }
                $this->audit($userId, null, 'content_category_saved', 'category:'.($publicId ?? 'new'));
            });
        } catch (QueryException $error) {
            if ($publicId === null && ($error->errorInfo[0] ?? null) === '23505'
                && preg_match('/\bSlug is already used in content_categories(?:\r?\n|$)/', $error->errorInfo[2] ?? '')) {
                // The failed transaction has rolled back, so the winning slug is readable.
                $this->assertSlugsFree('content_categories', $data['slug_ar'], $data['slug_en']);
            }
            throw $error;
        }

        return $this->categories();
    }

    public function deleteCategory(int $userId, string $publicId): void
    {
        DB::transaction(function () use ($userId, $publicId): void {
            $row = DB::table('content_categories')->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($row, 404);
            $inUse = DB::table('content_articles')->where('category_id', $row->id)->whereNull('deleted_at')->exists();
            if ($inUse) {
                throw ValidationException::withMessages(['category' => 'This category is still assigned to articles. Reassign them before deleting it.']);
            }
            DB::table('content_categories')->where('id', $row->id)->update(['deleted_at' => now(), 'visible' => false, 'updated_at' => now()]);
            $this->audit($userId, null, 'content_category_deleted', 'category:'.$publicId);
        });
    }

    public function footer(): array
    {
        $row = DB::table('content_footer_settings')->where('id', 1)->first();

        return ['canManage' => $this->can('content.publish'), 'footer' => [
            'revision' => (int) ($row->revision ?? 0),
            'description_ar' => $row->description_ar ?? '', 'description_en' => $row->description_en ?? '',
            'copyright_ar' => $row->copyright_ar ?? '', 'copyright_en' => $row->copyright_en ?? '',
            'social_links' => json_decode($row->social_links ?? '[]', true) ?: [],
            'show_sections' => (bool) ($row->show_sections ?? true),
            'published_at' => $this->iso($row->published_at ?? null),
        ]];
    }

    public function saveFooter(int $userId, array $data): array
    {
        DB::transaction(function () use ($userId, $data): void {
            $row = DB::table('content_footer_settings')->where('id', 1)->lockForUpdate()->first();
            $current = (int) ($row->revision ?? 0);
            if ((int) $data['revision'] !== $current) {
                throw new ConflictHttpException('Footer settings changed in another session. Reload before saving.');
            }
            $links = [];
            foreach ($data['social_links'] ?? [] as $link) {
                $url = (string) ($link['url'] ?? '');
                if (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL)) {
                    $links[] = ['label_ar' => mb_substr((string) ($link['label_ar'] ?? ''), 0, 120),
                        'label_en' => mb_substr((string) ($link['label_en'] ?? ''), 0, 120), 'url' => $url];
                }
            }
            DB::table('content_footer_settings')->where('id', 1)->update([
                'revision' => $current + 1,
                'description_ar' => ContentSanitizer::plain($data['description_ar'] ?? null, 1200) ?? '',
                'description_en' => ContentSanitizer::plain($data['description_en'] ?? null, 1200) ?? '',
                'copyright_ar' => ContentSanitizer::plain($data['copyright_ar'] ?? null, 300) ?? '',
                'copyright_en' => ContentSanitizer::plain($data['copyright_en'] ?? null, 300) ?? '',
                'social_links' => json_encode($links, JSON_THROW_ON_ERROR),
                'show_sections' => (bool) ($data['show_sections'] ?? true),
                'published_by' => $userId, 'published_at' => now(),
            ]);
            $this->audit($userId, null, 'content_footer_published', 'footer_revision:'.($current + 1));
        });

        return $this->footer();
    }

    private function publishRevision(int $userId, string $entityTable, string $revisionTable, string $foreignKey,
        string $publicId, int $revision, string $event): void
    {
        DB::transaction(function () use ($userId, $entityTable, $revisionTable, $foreignKey, $publicId, $revision, $event): void {
            $entity = DB::table($entityTable)->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($entity, 404);
            $revisionRow = DB::table($revisionTable)->where($foreignKey, $entity->id)->where('revision', $revision)->first();
            abort_unless($revisionRow, 404);
            if ((int) $entity->draft_revision_id !== (int) $revisionRow->id) {
                throw new ConflictHttpException('The draft changed before publication. Reload the editor.');
            }
            if ((int) $entity->published_revision_id === (int) $revisionRow->id) {
                return; // A lost response must not create another publication event.
            }
            $this->assertSlugsFree($entityTable, $revisionRow->slug_ar, $revisionRow->slug_en, $entity->id);
            $now = now();
            if ($entity->published_revision_id) {
                DB::table($revisionTable)->where('id', $entity->published_revision_id)->update(['state' => 'superseded']);
            }
            DB::table($revisionTable)->where('id', $revisionRow->id)->update(['state' => 'published']);
            $published = ['slug_ar' => $revisionRow->slug_ar, 'slug_en' => $revisionRow->slug_en,
                'position' => $revisionRow->position, 'published_revision_id' => $revisionRow->id,
                'published_at' => $now, 'updated_at' => $now];
            if ($entityTable === 'content_articles') {
                $published['category_id'] = $revisionRow->category_id;
            }
            DB::table($entityTable)->where('id', $entity->id)->update($published);
            $this->audit($userId, null, $event, $entityTable.':'.$publicId.';revision:'.$revision);
        });
    }

    private function unpublish(string $entityTable, string $revisionTable, string $publicId, int $userId, string $event): void
    {
        DB::transaction(function () use ($entityTable, $revisionTable, $publicId, $userId, $event): void {
            $entity = DB::table($entityTable)->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($entity, 404);
            if ($entity->published_revision_id === null) {
                return;
            }
            if ($entity->published_revision_id) {
                DB::table($revisionTable)->where('id', $entity->published_revision_id)->update(['state' => 'superseded']);
            }
            DB::table($entityTable)->where('id', $entity->id)->update(['published_revision_id' => null,
                'published_at' => null, 'updated_at' => now()]);
            $this->audit($userId, null, $event, $entityTable.':'.$publicId);
        });
    }

    private function softDelete(string $table, string $publicId, int $userId, string $event): void
    {
        DB::transaction(function () use ($table, $publicId, $userId, $event): void {
            $row = DB::table($table)->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table($table)->where('id', $row->id)->update([
                'published_revision_id' => null, 'published_at' => null,
                'deleted_at' => now(), 'updated_at' => now()]);
            $this->audit($userId, null, $event, $table.':'.$publicId);
        });
    }

    private function restoreDeleted(string $table, string $publicId, int $userId, string $event): void
    {
        DB::transaction(function () use ($table, $publicId, $userId, $event): void {
            $row = DB::table($table)->where('public_id', $publicId)->whereNotNull('deleted_at')->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->assertSlugsFree($table, $row->slug_ar, $row->slug_en, $row->id);
            DB::table($table)->where('id', $row->id)->update(['deleted_at' => null,
                'published_revision_id' => null, 'published_at' => null, 'updated_at' => now()]);
            $this->audit($userId, null, $event, $table.':'.$publicId);
        });
    }

    private function restoreRevision(int $userId, string $entityTable, string $revisionTable, string $foreignKey,
        string $publicId, int $revision, string $event, string $kind): void
    {
        DB::transaction(function () use ($userId, $entityTable, $revisionTable, $foreignKey, $publicId, $revision, $event, $kind): void {
            $entity = DB::table($entityTable)->where('public_id', $publicId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($entity, 404);
            $source = DB::table($revisionTable)->where($foreignKey, $entity->id)->where('revision', $revision)->first();
            abort_unless($source, 404);
            $next = (int) DB::table($revisionTable)->where($foreignKey, $entity->id)->max('revision') + 1;
            $copy = (array) $source;
            unset($copy['id'], $copy['revision'], $copy['state'], $copy['created_at']);
            $copy['revision'] = $next;
            $copy['state'] = 'draft';
            $copy['created_by'] = $userId;
            $copy['created_at'] = now();
            $revisionId = DB::table($revisionTable)->insertGetId($copy);
            DB::table($entityTable)->where('id', $entity->id)->update(['draft_revision_id' => $revisionId, 'updated_at' => now()]);
            $this->audit($userId, null, $event, $kind.':'.$publicId.';revision:'.$next);
        });
    }

    private function insertPageRevision(int $userId, int $pageId, array $data, int $revision): int
    {
        return DB::table('content_page_revisions')->insertGetId([
            'page_id' => $pageId, 'revision' => $revision,
            'slug_ar' => $data['slug_ar'], 'slug_en' => $data['slug_en'],
            'position' => (int) ($data['position'] ?? 0),
            'title_ar' => $data['title_ar'], 'title_en' => $data['title_en'],
            'excerpt_ar' => ContentSanitizer::plain($data['excerpt_ar'] ?? null), 'excerpt_en' => ContentSanitizer::plain($data['excerpt_en'] ?? null),
            'body_ar' => ContentSanitizer::html($data['body_ar'] ?? ''), 'body_en' => ContentSanitizer::html($data['body_en'] ?? ''),
            'seo_title_ar' => ContentSanitizer::plain($data['seo_title_ar'] ?? null, 200), 'seo_title_en' => ContentSanitizer::plain($data['seo_title_en'] ?? null, 200),
            'seo_description_ar' => ContentSanitizer::plain($data['seo_description_ar'] ?? null, 300), 'seo_description_en' => ContentSanitizer::plain($data['seo_description_en'] ?? null, 300),
            'state' => 'draft', 'created_by' => $userId, 'created_at' => now(),
        ]);
    }

    private function insertArticleRevision(int $userId, int $articleId, array $data, int $revision): int
    {
        return DB::table('content_article_revisions')->insertGetId([
            'article_id' => $articleId, 'revision' => $revision,
            'slug_ar' => $data['slug_ar'], 'slug_en' => $data['slug_en'],
            'category_id' => $this->categoryId($data['category_public_id'] ?? null),
            'position' => (int) ($data['position'] ?? 0),
            'title_ar' => $data['title_ar'], 'title_en' => $data['title_en'],
            'summary_ar' => ContentSanitizer::plain($data['summary_ar'] ?? null), 'summary_en' => ContentSanitizer::plain($data['summary_en'] ?? null),
            'body_ar' => ContentSanitizer::html($data['body_ar'] ?? ''), 'body_en' => ContentSanitizer::html($data['body_en'] ?? ''),
            'image_path' => $data['image_path'] ?? null,
            'image_alt_ar' => ContentSanitizer::plain($data['image_alt_ar'] ?? null, 300), 'image_alt_en' => ContentSanitizer::plain($data['image_alt_en'] ?? null, 300),
            'seo_title_ar' => ContentSanitizer::plain($data['seo_title_ar'] ?? null, 200), 'seo_title_en' => ContentSanitizer::plain($data['seo_title_en'] ?? null, 200),
            'seo_description_ar' => ContentSanitizer::plain($data['seo_description_ar'] ?? null, 300), 'seo_description_en' => ContentSanitizer::plain($data['seo_description_en'] ?? null, 300),
            'state' => 'draft', 'created_by' => $userId, 'created_at' => now(),
        ]);
    }

    private function pageRevision(?int $id, ?int $publishedId): ?array
    {
        if (! $id) {
            return null;
        }
        $row = DB::table('content_page_revisions')->where('id', $id)->first();

        return $row ? ['revision' => (int) $row->revision, 'slug_ar' => $row->slug_ar,
            'slug_en' => $row->slug_en, 'position' => (int) $row->position,
            'title_ar' => $row->title_ar, 'title_en' => $row->title_en,
            'excerpt_ar' => $row->excerpt_ar, 'excerpt_en' => $row->excerpt_en,
            'body_ar' => $row->body_ar, 'body_en' => $row->body_en,
            'seo_title_ar' => $row->seo_title_ar, 'seo_title_en' => $row->seo_title_en,
            'seo_description_ar' => $row->seo_description_ar, 'seo_description_en' => $row->seo_description_en,
            'state' => $this->revisionState($row, $publishedId), 'created_at' => $this->iso($row->created_at)] : null;
    }

    private function articleRevision(?int $id, ?int $publishedId): ?array
    {
        if (! $id) {
            return null;
        }
        $row = DB::table('content_article_revisions')->where('id', $id)->first();

        return $row ? ['revision' => (int) $row->revision, 'slug_ar' => $row->slug_ar,
            'slug_en' => $row->slug_en, 'position' => (int) $row->position,
            'category_public_id' => $row->category_id ? DB::table('content_categories')->where('id', $row->category_id)->value('public_id') : null,
            'title_ar' => $row->title_ar, 'title_en' => $row->title_en,
            'summary_ar' => $row->summary_ar, 'summary_en' => $row->summary_en,
            'body_ar' => $row->body_ar, 'body_en' => $row->body_en,
            'image_path' => $row->image_path, 'image_alt_ar' => $row->image_alt_ar, 'image_alt_en' => $row->image_alt_en,
            'seo_title_ar' => $row->seo_title_ar, 'seo_title_en' => $row->seo_title_en,
            'seo_description_ar' => $row->seo_description_ar, 'seo_description_en' => $row->seo_description_en,
            'state' => $this->revisionState($row, $publishedId), 'created_at' => $this->iso($row->created_at)] : null;
    }

    private function revisionState(object $row, ?int $publishedId): string
    {
        if ((int) $row->id === $publishedId) {
            return 'published';
        }

        return $row->state === 'published' ? 'superseded' : $row->state;
    }

    private function revisionIndex(string $table, string $foreignKey, int $id, ?int $publishedId): array
    {
        return DB::table($table)->where($foreignKey, $id)->orderByDesc('revision')->limit(50)
            ->get(['id', 'revision', 'state', 'created_at'])->map(fn ($row) => ['revision' => (int) $row->revision,
                'state' => $this->revisionState($row, $publishedId), 'created_at' => $this->iso($row->created_at)])->all();
    }

    private function linkChoices(): array
    {
        $pages = DB::table('content_pages as p')->whereNull('p.deleted_at')
            ->select('p.public_id', 'p.slug_ar', 'p.slug_en', 'p.published_revision_id')
            ->selectRaw('(SELECT title_ar FROM content_page_revisions r WHERE r.id = COALESCE(p.published_revision_id, p.draft_revision_id)) AS title_ar')
            ->selectRaw('(SELECT title_en FROM content_page_revisions r WHERE r.id = COALESCE(p.published_revision_id, p.draft_revision_id)) AS title_en')
            ->orderBy('p.position')->orderBy('p.id')->get();
        $articles = DB::table('content_articles as a')->whereNull('a.deleted_at')
            ->select('a.public_id', 'a.slug_ar', 'a.slug_en', 'a.published_revision_id')
            ->selectRaw('(SELECT title_ar FROM content_article_revisions r WHERE r.id = COALESCE(a.published_revision_id, a.draft_revision_id)) AS title_ar')
            ->selectRaw('(SELECT title_en FROM content_article_revisions r WHERE r.id = COALESCE(a.published_revision_id, a.draft_revision_id)) AS title_en')
            ->orderBy('a.position')->orderBy('a.id')->get();

        return ['pages' => $pages->map(fn ($row) => ['public_id' => $row->public_id, 'title_ar' => $row->title_ar,
            'title_en' => $row->title_en, 'published' => $row->published_revision_id !== null,
            'url_ar' => '/p/'.$row->slug_ar, 'url_en' => '/p/'.$row->slug_en])->all(),
            'articles' => $articles->map(fn ($row) => ['public_id' => $row->public_id, 'title_ar' => $row->title_ar,
                'title_en' => $row->title_en, 'published' => $row->published_revision_id !== null,
                'url_ar' => '/articles/'.$row->slug_ar, 'url_en' => '/articles/'.$row->slug_en])->all(),
            'internal_paths' => collect(self::INTERNAL_PATHS)->map(fn ($labels, $path) => ['path' => $path,
                'label_ar' => $labels[0], 'label_en' => $labels[1]])->values()->all()];
    }

    private function sectionRow(object $row): array
    {
        return ['public_id' => $row->public_id, 'name_ar' => $row->name_ar, 'name_en' => $row->name_en,
            'placement' => $row->placement, 'position' => (int) $row->position, 'visible' => (bool) $row->visible,
            'link_count' => (int) ($row->link_count ?? 0), 'updated_at' => $this->iso($row->updated_at ?? null)];
    }

    private function linkQuery()
    {
        return DB::table('content_navigation_links as l')
            ->leftJoin('content_pages as p', 'p.id', '=', 'l.page_id')
            ->leftJoin('content_page_revisions as pr', 'pr.id', '=', 'p.published_revision_id')
            ->leftJoin('content_articles as a', 'a.id', '=', 'l.article_id')
            ->leftJoin('content_article_revisions as ar', 'ar.id', '=', 'a.published_revision_id')
            ->select('l.public_id', 'l.label_ar', 'l.label_en', 'l.link_type', 'l.internal_path', 'l.external_url',
                'l.position', 'l.visible', 'l.open_in_new_tab',
                'p.public_id as page_public_id', 'p.slug_ar as page_slug_ar', 'p.slug_en as page_slug_en',
                'p.published_revision_id as page_published', 'p.deleted_at as page_deleted',
                'pr.title_ar as page_title_ar', 'pr.title_en as page_title_en',
                'a.public_id as article_public_id', 'a.slug_ar as article_slug_ar', 'a.slug_en as article_slug_en',
                'a.published_revision_id as article_published', 'a.deleted_at as article_deleted',
                'ar.title_ar as article_title_ar', 'ar.title_en as article_title_en');
    }

    private function linkRow(object $row): array
    {
        $published = match ($row->link_type) {
            'page' => $row->page_published !== null && $row->page_deleted === null,
            'article' => $row->article_published !== null && $row->article_deleted === null,
            default => true,
        };
        $url = match ($row->link_type) {
            'page' => '/p/'.$row->page_slug_ar,
            'article' => '/articles/'.$row->article_slug_ar,
            'internal' => $row->internal_path,
            default => $row->external_url,
        };

        return ['public_id' => $row->public_id, 'label_ar' => $row->label_ar, 'label_en' => $row->label_en,
            'link_type' => $row->link_type, 'target' => $row->page_public_id ?? $row->article_public_id,
            'internal_path' => $row->internal_path, 'external_url' => $row->external_url,
            'position' => (int) $row->position, 'visible' => (bool) $row->visible,
            'open_in_new_tab' => (bool) $row->open_in_new_tab,
            'target_title_ar' => $row->page_title_ar ?? $row->article_title_ar,
            'target_title_en' => $row->page_title_en ?? $row->article_title_en,
            'target_published' => $published, 'preview_url' => $url,
            'preview_url_en' => match ($row->link_type) {
                'page' => '/p/'.$row->page_slug_en, 'article' => '/articles/'.$row->article_slug_en,
                'internal' => $row->internal_path, default => $row->external_url,
            }];
    }

    private function categoryChoices(): array
    {
        return DB::table('content_categories')->whereNull('deleted_at')->orderBy('position')->orderBy('id')
            ->get(['public_id', 'name_ar', 'name_en'])->map(fn ($row) => ['public_id' => $row->public_id,
                'name_ar' => $row->name_ar, 'name_en' => $row->name_en])->all();
    }

    private function categoryId(?string $publicId): ?int
    {
        if (! $publicId) {
            return null;
        }
        $id = DB::table('content_categories')->where('public_id', $publicId)->whereNull('deleted_at')->value('id');
        abort_unless($id, 422, 'Category is unavailable.');

        return (int) $id;
    }

    private function assertSlugsFree(string $table, string $slugAr, string $slugEn, ?int $exceptId = null): void
    {
        foreach ([['slug_ar', $slugAr], ['slug_en', $slugEn]] as [$column, $slug]) {
            $query = DB::table($table)->whereNull('deleted_at')->where(function ($query) use ($slug) {
                $query->whereRaw('lower(slug_ar) = lower(?)', [$slug])
                    ->orWhereRaw('lower(slug_en) = lower(?)', [$slug]);
            });
            if ($exceptId !== null) {
                $query->where('id', '<>', $exceptId);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages([$column => 'This slug is already used by another record.']);
            }
        }
    }

    private function reorder(int $userId, string $table, array $items, ?int $sectionId): void
    {
        DB::transaction(function () use ($userId, $table, $items, $sectionId): void {
            foreach ($items as $item) {
                $query = DB::table($table)->where('public_id', $item['public_id'])->whereNull('deleted_at');
                if ($sectionId !== null) {
                    $query->where('section_id', $sectionId);
                }
                $row = $query->lockForUpdate()->first();
                abort_unless($row, 404);
                DB::table($table)->where('id', $row->id)->update(['position' => (int) $item['position'], 'updated_at' => now()]);
            }
            $this->audit($userId, null, 'content_reordered', $table);
        });
    }

    private function nextPosition(string $table, string $column, int $value): int
    {
        return (int) DB::table($table)->where($column, $value)->whereNull('deleted_at')->max('position') + 10;
    }

    private function audit(int $userId, ?int $subjectId, string $event, string $reason): void
    {
        DB::select('SELECT public.builderad_content_write_audit(?, ?, ?)',
            [$userId, $event, $reason]);
    }

    private function can(string $action): bool
    {
        $request = request();

        return $request->user() !== null && app(GrantAuthority::class)->has(
            $request->user(), (int) $request->session()->get('a001_generation', 0), $action,
        );
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
