<?php

namespace App\Http\Controllers;

use App\Content\ContentAdmin;
use App\Http\Responses\SpaPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class InternalContentController extends Controller
{
    private function slug(): array
    {
        return ['required', 'string', 'max:120', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}-]{0,119}$/u'];
    }

    private function pagePayload(): array
    {
        return [
            'slug_ar' => $this->slug(), 'slug_en' => $this->slug(),
            'title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'],
            'excerpt_ar' => ['nullable', 'string', 'max:600'], 'excerpt_en' => ['nullable', 'string', 'max:600'],
            'body_ar' => ['nullable', 'string', 'max:200000'], 'body_en' => ['nullable', 'string', 'max:200000'],
            'seo_title_ar' => ['nullable', 'string', 'max:200'], 'seo_title_en' => ['nullable', 'string', 'max:200'],
            'seo_description_ar' => ['nullable', 'string', 'max:300'], 'seo_description_en' => ['nullable', 'string', 'max:300'],
            'position' => ['nullable', 'integer', 'between:0,1000000'],
        ];
    }

    private function articlePayload(): array
    {
        return $this->pagePayload() + [
            'summary_ar' => ['nullable', 'string', 'max:600'], 'summary_en' => ['nullable', 'string', 'max:600'],
            'image_path' => ['nullable', 'string', 'max:2048', 'regex:/^(https:\/\/|\/storage\/|\/content-media\/)/'],
            'image_alt_ar' => ['nullable', 'string', 'max:300'], 'image_alt_en' => ['nullable', 'string', 'max:300'],
            'category_public_id' => ['nullable', 'uuid'],
        ];
    }

    public function sections(Request $request, ContentAdmin $content): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->sections() : []);
    }

    public function sectionLinks(Request $request, ContentAdmin $content, string $section): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->sectionLinks($section) : []);
    }

    public function pages(Request $request, ContentAdmin $content): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->pages($request->only(['search', 'status', 'page'])) : []);
    }

    public function pageEditor(Request $request, ContentAdmin $content, string $page): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->pageEditor($page) : []);
    }

    public function pagePreview(ContentAdmin $content, string $page): Response
    {
        $preview = $content->pagePreview($page);
        abort_unless($preview, 404);

        return response()->view('public.admin-preview', ['preview' => $preview, 'kind' => 'page',
            'locale' => app()->getLocale()], 200,
            ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private']);
    }

    public function articles(Request $request, ContentAdmin $content): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->articles($request->only(['search', 'status', 'page'])) : []);
    }

    public function articleEditor(Request $request, ContentAdmin $content, string $article): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->articleEditor($article) : []);
    }

    public function articlePreview(ContentAdmin $content, string $article): Response
    {
        $preview = $content->articlePreview($article);
        abort_unless($preview, 404);

        return response()->view('public.admin-preview', ['preview' => $preview, 'kind' => 'article',
            'locale' => app()->getLocale()], 200,
            ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private']);
    }

    public function categories(Request $request, ContentAdmin $content): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->categories() : []);
    }

    public function footer(Request $request, ContentAdmin $content): SpaPage
    {
        return new SpaPage(SpaPage::needsData($request) ? $content->footer() : []);
    }

    public function storeSection(Request $request, ContentAdmin $content): JsonResponse
    {
        $data = $request->validate($this->sectionPayload());

        return response()->json(['saved' => true, 'section' => $content->saveSection($request->user()->id, $data)]);
    }

    public function updateSection(Request $request, ContentAdmin $content, string $section): JsonResponse
    {
        $data = $request->validate($this->sectionPayload());

        return response()->json(['saved' => true, 'section' => $content->saveSection($request->user()->id, $data, $section)]);
    }

    public function deleteSection(Request $request, ContentAdmin $content, string $section): JsonResponse
    {
        $content->deleteSection($request->user()->id, $section);

        return response()->json(['saved' => true]);
    }

    public function reorderSections(Request $request, ContentAdmin $content): JsonResponse
    {
        $data = $request->validate($this->reorderPayload());
        $content->reorderSections($request->user()->id, $data['items']);

        return response()->json(['saved' => true]);
    }

    public function storeLink(Request $request, ContentAdmin $content, string $section): JsonResponse
    {
        $data = $request->validate($this->linkPayload());

        return response()->json(['saved' => true, 'links' => $content->saveLink($request->user()->id, $section, $data)]);
    }

    public function updateLink(Request $request, ContentAdmin $content, string $link): JsonResponse
    {
        $data = $request->validate($this->linkPayload() + ['section' => ['required', 'uuid']]);

        return response()->json(['saved' => true, 'links' => $content->saveLink($request->user()->id, $data['section'], $data, $link)]);
    }

    public function deleteLink(Request $request, ContentAdmin $content, string $link): JsonResponse
    {
        $content->deleteLink($request->user()->id, $link);

        return response()->json(['saved' => true]);
    }

    public function reorderLinks(Request $request, ContentAdmin $content, string $section): JsonResponse
    {
        $data = $request->validate($this->reorderPayload());
        $content->reorderLinks($request->user()->id, $section, $data['items']);

        return response()->json(['saved' => true]);
    }

    public function storePage(Request $request, ContentAdmin $content): JsonResponse
    {
        $data = $request->validate($this->pagePayload());

        return response()->json(['saved' => true, 'page' => $content->createPage($request->user()->id, $data)]);
    }

    public function updatePage(Request $request, ContentAdmin $content, string $page): JsonResponse
    {
        $data = $request->validate($this->pagePayload() + ['expected_revision' => ['required', 'integer', 'between:0,1000000000']]);

        return response()->json(['saved' => true, 'page' => $content->savePageDraft($request->user()->id, $page, $data)]);
    }

    public function publishPage(Request $request, ContentAdmin $content, string $page): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'between:1,1000000000']]);

        return response()->json(['saved' => true, 'page' => $content->publishPage($request->user()->id, $page, (int) $data['revision'])]);
    }

    public function unpublishPage(Request $request, ContentAdmin $content, string $page): JsonResponse
    {
        return response()->json(['saved' => true, 'page' => $content->unpublishPage($request->user()->id, $page)]);
    }

    public function deletePage(Request $request, ContentAdmin $content, string $page): JsonResponse
    {
        $content->deletePage($request->user()->id, $page);

        return response()->json(['saved' => true]);
    }

    public function restorePage(Request $request, ContentAdmin $content, string $page): JsonResponse
    {
        return response()->json(['saved' => true, 'page' => $content->restorePage($request->user()->id, $page)]);
    }

    public function restorePageRevision(Request $request, ContentAdmin $content, string $page, int $revision): JsonResponse
    {
        return response()->json(['saved' => true, 'page' => $content->restorePageRevision($request->user()->id, $page, $revision)]);
    }

    public function storeArticle(Request $request, ContentAdmin $content): JsonResponse
    {
        $data = $request->validate($this->articlePayload());

        return response()->json(['saved' => true, 'article' => $content->createArticle($request->user()->id, $data)]);
    }

    public function updateArticle(Request $request, ContentAdmin $content, string $article): JsonResponse
    {
        $data = $request->validate($this->articlePayload() + ['expected_revision' => ['required', 'integer', 'between:0,1000000000']]);

        return response()->json(['saved' => true, 'article' => $content->saveArticleDraft($request->user()->id, $article, $data)]);
    }

    public function publishArticle(Request $request, ContentAdmin $content, string $article): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'between:1,1000000000']]);

        return response()->json(['saved' => true, 'article' => $content->publishArticle($request->user()->id, $article, (int) $data['revision'])]);
    }

    public function unpublishArticle(Request $request, ContentAdmin $content, string $article): JsonResponse
    {
        return response()->json(['saved' => true, 'article' => $content->unpublishArticle($request->user()->id, $article)]);
    }

    public function deleteArticle(Request $request, ContentAdmin $content, string $article): JsonResponse
    {
        $content->deleteArticle($request->user()->id, $article);

        return response()->json(['saved' => true]);
    }

    public function restoreArticle(Request $request, ContentAdmin $content, string $article): JsonResponse
    {
        return response()->json(['saved' => true, 'article' => $content->restoreArticle($request->user()->id, $article)]);
    }

    public function restoreArticleRevision(Request $request, ContentAdmin $content, string $article, int $revision): JsonResponse
    {
        return response()->json(['saved' => true, 'article' => $content->restoreArticleRevision($request->user()->id, $article, $revision)]);
    }

    public function storeCategory(Request $request, ContentAdmin $content): JsonResponse
    {
        $data = $request->validate($this->categoryPayload());

        return response()->json(['saved' => true, 'categories' => $content->saveCategory($request->user()->id, $data)]);
    }

    public function updateCategory(Request $request, ContentAdmin $content, string $category): JsonResponse
    {
        $data = $request->validate($this->categoryPayload());

        return response()->json(['saved' => true, 'categories' => $content->saveCategory($request->user()->id, $data, $category)]);
    }

    public function deleteCategory(Request $request, ContentAdmin $content, string $category): JsonResponse
    {
        $content->deleteCategory($request->user()->id, $category);

        return response()->json(['saved' => true]);
    }

    public function updateFooter(Request $request, ContentAdmin $content): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'between:0,1000000000'],
            'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000'],
            'copyright_ar' => ['nullable', 'string', 'max:300'], 'copyright_en' => ['nullable', 'string', 'max:300'],
            'show_sections' => ['required', 'boolean'],
            'social_links' => ['nullable', 'array', 'max:20'],
            'social_links.*.label_ar' => ['nullable', 'string', 'max:120'],
            'social_links.*.label_en' => ['nullable', 'string', 'max:120'],
            'social_links.*.url' => ['required', 'url:https', 'max:2048'],
        ]);

        return response()->json(['saved' => true, 'footer' => $content->saveFooter($request->user()->id, $data)]);
    }

    private function sectionPayload(): array
    {
        return ['name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'],
            'placement' => ['required', 'in:header,footer'], 'position' => ['nullable', 'integer', 'between:0,1000000'],
            'visible' => ['required', 'boolean']];
    }

    private function linkPayload(): array
    {
        return ['label_ar' => ['nullable', 'string', 'max:120'], 'label_en' => ['nullable', 'string', 'max:120'],
            'link_type' => ['required', 'in:page,article,internal,external'],
            'target' => ['nullable', 'uuid'], 'internal_path' => ['nullable', 'string', 'max:200'],
            'external_url' => ['nullable', 'url:https', 'max:2048'],
            'position' => ['nullable', 'integer', 'between:0,1000000'],
            'visible' => ['required', 'boolean'], 'open_in_new_tab' => ['required', 'boolean']];
    }

    private function reorderPayload(): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.public_id' => ['required', 'uuid'], 'items.*.position' => ['required', 'integer', 'between:0,1000000']];
    }

    private function categoryPayload(): array
    {
        return ['slug_ar' => $this->slug(), 'slug_en' => $this->slug(),
            'name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'],
            'description_ar' => ['nullable', 'string', 'max:600'], 'description_en' => ['nullable', 'string', 'max:600'],
            'position' => ['nullable', 'integer', 'between:0,1000000'], 'visible' => ['required', 'boolean']];
    }
}
