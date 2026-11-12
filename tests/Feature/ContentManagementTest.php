<?php

namespace Tests\Feature;

use App\Content\ContentAdmin;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ContentManagementTest extends TestCase
{
    private function administrator(bool $grant = true): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert([
            'user_id' => $user->id, 'kind' => 'founder', 'status' => 'active',
            'attested_by' => $user->id, 'attested_at' => now(), 'factor_independence_attested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([1, 2] as $number) {
            $db->table('passkeys')->insert([
                'user_id' => $user->id, 'name' => 'Content test authenticator '.$number,
                'credential_id' => 'content-test-'.$user->id.'-'.$number, 'credential' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($grant) {
            app(GrantAuthority::class)->issueFounderBootstrapGrants($user, 1);
        }
        $this->actingAsInternal($user);

        return $user;
    }

    /** Visit the public site as an anonymous reader, not as the signed-in administrator. */
    private function asGuest(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    private function pagePayload(string $slug, string $title, string $body): array
    {
        return [
            'slug_ar' => $slug, 'slug_en' => $slug,
            'title_ar' => $title, 'title_en' => $title,
            'excerpt_ar' => null, 'excerpt_en' => null,
            'body_ar' => '<p>'.$body.'</p>', 'body_en' => '<p>'.$body.'</p>',
            'seo_title_ar' => null, 'seo_title_en' => null,
            'seo_description_ar' => null, 'seo_description_en' => null,
            'position' => 0,
        ];
    }

    public function test_category_slug_conflict_returns_the_conflicting_field_without_partial_writes(): void
    {
        $admin = $this->administrator();
        $url = '/internal/administration/content/categories';
        $payload = ['name_ar' => 'Category', 'name_en' => 'Category', 'visible' => true];
        $this->postJson($url, $payload + ['slug_ar' => 'shared-ar', 'slug_en' => 'shared-en'])->assertOk();
        $this->postJson($url, $payload + ['slug_ar' => 'other-ar', 'slug_en' => 'shared-ar'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug_en')->assertJsonMissingValidationErrors('slug_ar');
        $this->postJson($url, $payload + ['slug_ar' => 'shared-en', 'slug_en' => 'other-en'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug_ar')->assertJsonMissingValidationErrors('slug_en');
        $this->assertSame(1, DB::table('content_categories')->count());
        $this->assertSame(1, $this->categorySaveCount($admin));
    }

    public function test_concurrent_category_create_requests_commit_only_one_category_and_audit_event(): void
    {
        $admin = $this->administrator();
        $payload = ['name_ar' => 'Category', 'name_en' => 'Category', 'visible' => true];
        $db = DB::connection();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/ContentCategoryCreateWorker.php'),
            (string) $admin->id, json_encode($payload + ['slug_ar' => 'other-ar', 'slug_en' => 'shared'], JSON_THROW_ON_ERROR)], base_path());
        $worker->setTimeout(25);

        $holder = (int) $db->scalar('SELECT pg_backend_pid()');
        $db->listen(function (QueryExecuted $query) use ($worker, $holder): void {
            if (! str_starts_with($query->sql, 'insert into "content_categories"')) {
                return;
            }
            $worker->start();
            $deadline = microtime(true) + 10;
            $blocked = false;
            do {
                $this->assertTrue($worker->isRunning(), $worker->getOutput().$worker->getErrorOutput());
                if (preg_match('/^PID (\d+)$/m', $worker->getOutput(), $match)) {
                    $blocked = (bool) DB::connection('pgsql_migrations')->scalar(
                        'SELECT ? = ANY(pg_blocking_pids(?))', [$holder, (int) $match[1]]);
                    if ($blocked) {
                        break;
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked, 'The second category create did not wait on the first transaction.');
        });

        try {
            $this->postJson('/internal/administration/content/categories',
                $payload + ['slug_ar' => 'shared', 'slug_en' => 'winner-en'])->assertOk();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            [, $result] = explode("\n", $worker->getOutput(), 2);
            $response = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(422, $response['status']);
            $this->assertSame(['slug_en'], array_keys($response['body']['errors']));
            $this->assertSame(['shared'], DB::table('content_categories')->pluck('slug_ar')->all());
            $this->assertSame(1, $this->categorySaveCount($admin));
        } finally {
            $worker->stop();
        }
    }

    private function categorySaveCount(User $actor): int
    {
        return DB::connection('pgsql_authority')->table('security_audit_events')
            ->where('actor_user_id', $actor->id)->where('event', 'content_category_saved')->count();
    }

    public function test_restored_revision_history_follows_the_publication_pointer_for_pages_and_articles(): void
    {
        $admin = $this->administrator();
        foreach (['page' => '/p/', 'article' => '/articles/'] as $kind => $publicPath) {
            $slug = 'restore-'.$kind;
            $list = '/internal/administration/content/'.$kind.'s';
            $table = 'content_'.$kind.'_revisions';
            $payload = $this->pagePayload($slug, 'Original', 'Original public body');
            $id = $this->postJson($list, $payload)->assertOk()->json($kind.'.'.$kind.'.public_id');
            $url = $list.'/'.$id;
            $this->postJson($url.'/publish', ['revision' => 1])->assertOk();
            $before = (array) DB::table($table)->where('revision', 1)->first();
            $this->postJson($url.'/delete')->assertOk();
            $this->postJson($url.'/restore')->assertOk()->assertJsonPath($kind.'.published', null)
                ->assertJsonPath($kind.'.revisions.0.state', 'superseded')
                ->assertJsonPath($kind.'.draft.state', 'superseded');
            $this->assertSame($before, (array) DB::table($table)->where('revision', 1)->first());
            $this->asGuest();
            $this->get($publicPath.$slug)->assertNotFound();
            $this->actingAsInternal($admin);
            $this->postJson($url, $this->pagePayload($slug, 'Updated', 'New public body') + ['expected_revision' => 1])->assertOk();
            $this->postJson($url.'/publish', ['revision' => 2])->assertOk()
                ->assertJsonPath($kind.'.revisions.0.state', 'published')
                ->assertJsonPath($kind.'.revisions.1.state', 'superseded');
            $this->asGuest();
            $this->get($publicPath.$slug)->assertOk()->assertSee('New public body')->assertDontSee('Original public body');
            $this->actingAsInternal($admin);
        }
    }

    public function test_draft_position_preserves_omitted_and_null_values_and_accepts_explicit_zero(): void
    {
        $this->administrator();
        foreach (['page', 'article'] as $kind) {
            $list = '/internal/administration/content/'.$kind.'s';
            $payload = $this->pagePayload('position-'.$kind, 'Position', 'Body');
            $payload['position'] = 20;
            $id = $this->postJson($list, $payload)->assertOk()->json($kind.'.'.$kind.'.public_id');
            $url = $list.'/'.$id;
            $this->postJson($url.'/publish', ['revision' => 1])->assertOk();
            unset($payload['position']);
            $this->postJson($url, $payload + ['expected_revision' => 1])->assertOk()
                ->assertJsonPath($kind.'.draft.position', 20);
            $this->postJson($url.'/publish', ['revision' => 2])->assertOk()
                ->assertJsonPath($kind.'.'.$kind.'.position', 20);
            $this->postJson($url, $payload + ['expected_revision' => 2, 'position' => 30])->assertOk()
                ->assertJsonPath($kind.'.draft.position', 30)->assertJsonPath($kind.'.'.$kind.'.position', 20);
            $this->postJson($url, $payload + ['expected_revision' => 3, 'position' => null])->assertOk()
                ->assertJsonPath($kind.'.draft.position', 30)->assertJsonPath($kind.'.'.$kind.'.position', 20);
            $this->postJson($url.'/publish', ['revision' => 4])->assertOk()
                ->assertJsonPath($kind.'.'.$kind.'.position', 30);
            $this->postJson($url, $payload + ['expected_revision' => 4, 'position' => 0])->assertOk()
                ->assertJsonPath($kind.'.draft.position', 0)->assertJsonPath($kind.'.'.$kind.'.position', 30);
            $this->postJson($url.'/publish', ['revision' => 5])->assertOk()
                ->assertJsonPath($kind.'.'.$kind.'.position', 0);
        }
    }

    public function test_content_reads_require_staff_view_and_mutations_require_fresh_authentication(): void
    {
        $this->administrator(false);
        $this->getJson('/internal/administration/content/sections')->assertForbidden();

        $admin = $this->administrator();
        $sessions = DB::connection('pgsql_authority')->table('security_sessions')->where('user_id', $admin->id);
        $sections = DB::connection('pgsql_migrations')->table('content_navigation_sections');

        // Routine authoring keeps a bounded 30-minute step-up window.
        $sessions->update(['authenticated_at' => now()->subMinutes(6)]);
        $this->postJson('/internal/administration/content/sections', [
            'name_ar' => 'الشركة', 'name_en' => 'Company', 'placement' => 'header',
            'position' => 0, 'visible' => true,
        ])->assertOk();
        $this->assertSame(1, $sections->count());

        // Past that window nothing is written and the recovery path is bound.
        $sessions->update(['authenticated_at' => now()->subMinutes(31)]);
        $this->postJson('/internal/administration/content/sections', [
            'name_ar' => 'التحقق', 'name_en' => 'Verification', 'placement' => 'footer',
            'position' => 0, 'visible' => true,
        ])->assertForbidden()
            ->assertJsonPath('code', 'fresh_authentication_required')
            ->assertJsonPath('reauthenticate', '/internal/identity/switch');
        $this->assertSame(1, $sections->count());
    }

    public function test_page_publication_keeps_saved_draft_edits_private_until_republished(): void
    {
        $admin = $this->administrator();
        $this->postJson('/internal/administration/content/pages', $this->pagePayload('company', 'الشركة', 'Public version'))
            ->assertOk()->assertJsonPath('page.draft.revision', 1);
        $page = DB::connection('pgsql_migrations')->table('content_pages')->first();

        $this->asGuest();
        $this->get('/p/company')->assertNotFound();
        $this->actingAsInternal($admin);

        $this->postJson('/internal/administration/content/pages/'.$page->public_id.'/publish', ['revision' => 1])
            ->assertOk()->assertJsonPath('page.published.body_ar', '<p>Public version</p>');
        $this->asGuest();
        $this->get('/p/company')->assertOk()->assertSee('Public version')->assertDontSee('Draft version');
        $this->actingAsInternal($admin);

        $this->postJson('/internal/administration/content/pages/'.$page->public_id,
            $this->pagePayload('company', 'الشركة', 'Draft version') + ['expected_revision' => 1])
            ->assertOk()->assertJsonPath('page.draft.revision', 2);
        $reopened = $this->getJson('/internal/administration/content/pages/'.$page->public_id)
            ->assertOk()->json('data');
        $this->assertSame('Draft version', strip_tags($reopened['draft']['body_ar']));
        $this->assertSame('<p>Public version</p>', $reopened['published']['body_ar']);

        $this->asGuest();
        $this->get('/p/company')->assertOk()->assertSee('Public version')->assertDontSee('Draft version');
        $this->actingAsInternal($admin);

        $this->postJson('/internal/administration/content/pages/'.$page->public_id.'/publish', ['revision' => 2])
            ->assertOk()->assertJsonPath('page.published.body_ar', '<p>Draft version</p>');
        $this->asGuest();
        $this->get('/p/company')->assertOk()->assertSee('Draft version')->assertDontSee('Public version');
    }

    public function test_company_section_link_order_visibility_and_deletion_leave_the_page_intact(): void
    {
        $admin = $this->administrator();
        $this->postJson('/internal/administration/content/pages', $this->pagePayload('company', 'الشركة', 'Company page'))
            ->assertOk();
        $page = DB::connection('pgsql_migrations')->table('content_pages')->first();
        $this->postJson('/internal/administration/content/pages/'.$page->public_id.'/publish', ['revision' => 1])->assertOk();

        $this->postJson('/internal/administration/content/sections', [
            'name_ar' => 'الشركة', 'name_en' => 'Company', 'placement' => 'header', 'position' => 20, 'visible' => true,
        ])->assertOk();
        $company = DB::connection('pgsql_migrations')->table('content_navigation_sections')->where('name_en', 'Company')->first();
        $this->postJson('/internal/administration/content/sections', [
            'name_ar' => 'الأول', 'name_en' => 'First', 'placement' => 'header', 'position' => 10, 'visible' => true,
        ])->assertOk();
        $first = DB::connection('pgsql_migrations')->table('content_navigation_sections')->where('name_en', 'First')->first();

        $this->postJson('/internal/administration/content/sections/'.$company->public_id.'/links', [
            'label_ar' => 'الشركة', 'label_en' => 'Company', 'link_type' => 'page',
            'target' => $page->public_id, 'position' => 0, 'visible' => true, 'open_in_new_tab' => false,
        ])->assertOk();

        $this->postJson('/internal/administration/content/sections/reorder', ['items' => [
            ['public_id' => $company->public_id, 'position' => 0],
            ['public_id' => $first->public_id, 'position' => 10],
        ]])->assertOk();
        $this->assertSame($company->public_id, $this->getJson('/internal/administration/content/sections')
            ->assertOk()->json('data.sections.0.public_id'));

        $editor = $this->getJson('/internal/administration/content/pages/'.$page->public_id)
            ->assertOk()->json('data');
        $this->assertSame([$company->public_id], array_column($editor['placements'], 'section_public_id'));
        $this->assertContains($company->public_id, array_column($editor['sections'], 'public_id'));

        $this->asGuest();
        $this->get('/')->assertOk()->assertSee('الشركة')->assertSee('/p/company');
        $this->actingAsInternal($admin);

        $this->postJson('/internal/administration/content/sections/'.$company->public_id, [
            'name_ar' => 'الشركة', 'name_en' => 'Company', 'placement' => 'header', 'position' => 0, 'visible' => false,
        ])->assertOk();
        $this->asGuest();
        $this->get('/')->assertOk()->assertDontSee('الشركة')->assertDontSee('/p/company');
        $this->actingAsInternal($admin);

        $this->postJson('/internal/administration/content/sections/'.$company->public_id.'/delete')->assertOk();
        $this->assertNotNull(DB::connection('pgsql_migrations')->table('content_navigation_sections')
            ->where('public_id', $company->public_id)->value('deleted_at'));
        $this->assertNotNull(DB::connection('pgsql_migrations')->table('content_pages')
            ->where('public_id', $page->public_id)->value('published_revision_id'));

        $this->asGuest();
        $this->get('/p/company')->assertOk()->assertSee('Company page');
    }

    public function test_categories_and_articles_reopen_and_publish_with_draft_edits(): void
    {
        $admin = $this->administrator();
        $this->postJson('/internal/administration/content/categories', [
            'slug_ar' => 'news', 'slug_en' => 'news', 'name_ar' => 'الأخبار', 'name_en' => 'News',
            'description_ar' => null, 'description_en' => null, 'position' => 0, 'visible' => true,
        ])->assertOk();
        $category = DB::connection('pgsql_migrations')->table('content_categories')->first();

        $this->postJson('/internal/administration/content/articles', $this->pagePayload('launch', 'إطلاق', 'Article version one') + [
            'summary_ar' => null, 'summary_en' => null, 'image_path' => null,
            'image_alt_ar' => null, 'image_alt_en' => null, 'category_public_id' => $category->public_id,
        ])->assertOk()->assertJsonPath('article.draft.revision', 1);
        $article = DB::connection('pgsql_migrations')->table('content_articles')->first();
        $this->postJson('/internal/administration/content/articles/'.$article->public_id.'/publish', ['revision' => 1])
            ->assertOk();

        $this->asGuest();
        $this->get('/articles/launch')->assertOk()->assertSee('Article version one');
        $this->actingAsInternal($admin);

        $this->postJson('/internal/administration/content/articles/'.$article->public_id,
            $this->pagePayload('launch', 'إطلاق', 'Article version two') + [
                'summary_ar' => null, 'summary_en' => null, 'image_path' => null,
                'image_alt_ar' => null, 'image_alt_en' => null, 'category_public_id' => $category->public_id,
                'expected_revision' => 1,
            ])->assertOk()->assertJsonPath('article.draft.revision', 2);
        $this->getJson('/internal/administration/content/articles/'.$article->public_id)
            ->assertOk()->assertJsonPath('data.draft.revision', 2);

        $this->asGuest();
        $this->get('/articles/launch')->assertOk()->assertSee('Article version one')->assertDontSee('Article version two');
        $this->actingAsInternal($admin);

        $this->postJson('/internal/administration/content/articles/'.$article->public_id.'/publish', ['revision' => 2])
            ->assertOk();
        $this->asGuest();
        $this->get('/articles/launch')->assertOk()->assertSee('Article version two')->assertDontSee('Article version one');
        $this->get('/articles/category/news')->assertOk()->assertSee('إطلاق')->assertSee('/articles/launch');
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('content_categories')->count());
    }

    public function test_page_slug_changes_only_on_publish_and_restore_stays_unpublished(): void
    {
        $admin = $this->administrator();
        $this->postJson('/internal/administration/content/pages', $this->pagePayload('old-page', 'Page', 'Public body'))->assertOk();
        $page = DB::table('content_pages')->first();
        $url = '/internal/administration/content/pages/'.$page->public_id;
        $this->postJson($url.'/publish', ['revision' => 1])->assertOk();
        $this->postJson($url, $this->pagePayload('new-page', 'Page', 'Private body') + ['expected_revision' => 1])
            ->assertOk()->assertJsonPath('page.draft.slug_ar', 'new-page')
            ->assertJsonPath('page.page.slug_ar', 'old-page');
        $this->postJson($url, $this->pagePayload('lost-page', 'Page', 'Lost body') + ['expected_revision' => 1])
            ->assertStatus(409);
        $this->postJson($url.'/publish', ['revision' => 1])->assertStatus(409);
        $this->asGuest();
        $this->get('/p/old-page')->assertOk()->assertSee('Public body');
        $this->get('/p/new-page')->assertNotFound();
        $this->actingAsInternal($admin);
        $this->postJson($url.'/publish', ['revision' => 2])->assertOk();
        $this->asGuest();
        $this->get('/p/old-page')->assertNotFound();
        $this->get('/p/new-page')->assertOk()->assertSee('Private body');
        $this->actingAsInternal($admin);
        $this->postJson($url.'/delete')->assertOk();
        $this->asGuest();
        $this->get('/p/new-page')->assertNotFound();
        $this->actingAsInternal($admin);
        $this->postJson($url.'/restore')->assertOk()->assertJsonPath('page.published', null);
        $this->assertSame(2, DB::table('content_page_revisions')->where('page_id', $page->id)->count());
        $this->asGuest();
        $this->get('/p/new-page')->assertNotFound();
        $this->actingAsInternal($admin);
        $this->postJson($url.'/publish', ['revision' => 2])->assertOk();
        $this->asGuest();
        $this->get('/p/new-page')->assertOk();
    }

    public function test_article_draft_category_and_slug_are_private_and_hidden_category_keeps_article_visible(): void
    {
        $admin = $this->administrator();
        foreach (['first', 'second'] as $slug) {
            $this->postJson('/internal/administration/content/categories', [
                'slug_ar' => $slug, 'slug_en' => $slug, 'name_ar' => $slug, 'name_en' => $slug,
                'visible' => true, 'position' => 0,
            ])->assertOk();
        }
        $categories = DB::table('content_categories')->orderBy('id')->get();
        $payload = $this->pagePayload('article-old', 'Article', 'Published article') +
            ['category_public_id' => $categories[0]->public_id];
        $this->postJson('/internal/administration/content/articles', $payload)->assertOk();
        $article = DB::table('content_articles')->first();
        $url = '/internal/administration/content/articles/'.$article->public_id;
        $this->postJson($url.'/publish', ['revision' => 1])->assertOk();
        $draft = $this->pagePayload('article-new', 'Article', 'Private article') +
            ['category_public_id' => $categories[1]->public_id, 'expected_revision' => 1];
        $this->postJson($url, $draft)->assertOk()->assertJsonPath('article.draft.category_public_id', $categories[1]->public_id);
        $this->asGuest();
        $this->get('/articles/article-old')->assertOk()->assertSee('/articles/category/first');
        $this->get('/articles/article-new')->assertNotFound();
        $this->get('/articles/category/first')->assertOk()->assertSee('/articles/article-old');
        $this->get('/articles/category/second')->assertOk()->assertDontSee('/articles/article-old');
        $this->actingAsInternal($admin);
        $this->postJson($url.'/publish', ['revision' => 2])->assertOk();
        $this->postJson('/internal/administration/content/categories/'.$categories[1]->public_id, [
            'slug_ar' => 'second', 'slug_en' => 'second', 'name_ar' => 'second', 'name_en' => 'second',
            'visible' => false, 'position' => 0,
        ])->assertOk();
        $this->asGuest();
        $this->get('/articles/article-old')->assertNotFound();
        $this->get('/articles/article-new')->assertOk()->assertDontSee('/articles/category/second');
        $this->get('/articles')->assertOk()->assertSee('/articles/article-new')->assertDontSee('/articles/category/second');
        $this->get('/articles/category/second')->assertNotFound();
        $this->actingAsInternal($admin);
        $this->postJson($url.'/delete')->assertOk();
        $this->postJson($url.'/restore')->assertOk()->assertJsonPath('article.published', null);
        $this->asGuest();
        $this->get('/articles/article-new')->assertNotFound();
    }

    public function test_cross_language_slug_conflicts_and_preview_headers_and_authority(): void
    {
        $admin = $this->administrator();
        $this->postJson('/internal/administration/content/pages', $this->pagePayload('arabic-route', 'First', 'Private'))
            ->assertOk();
        $page = DB::table('content_pages')->first();
        $collision = $this->pagePayload('other-route', 'Second', 'Private');
        $collision['slug_en'] = 'ARABIC-ROUTE';
        $this->postJson('/internal/administration/content/pages', $collision)
            ->assertStatus(422)->assertJsonPath('errors.slug_en.0', 'This slug is already used by another record.');
        $this->get('/internal/administration/content/pages/'.$page->public_id.'/preview')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->asGuest();
        $this->get('/internal/administration/content/pages/'.$page->public_id.'/preview')->assertRedirect();
        $this->actingAsInternal($admin);
    }

    public function test_content_and_audit_roll_back_together_on_runtime_transaction_failure(): void
    {
        $admin = $this->administrator();
        try {
            DB::transaction(function () use ($admin): void {
                app(ContentAdmin::class)->createPage($admin->id,
                    $this->pagePayload('rollback-page', 'Rollback', 'Private'));
                throw new RuntimeException('Force rollback after content and audit writes');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Force rollback after content and audit writes', $error->getMessage());
        }
        $this->assertFalse(DB::table('content_pages')->where('slug_ar', 'rollback-page')->exists());
        $this->assertFalse(DB::connection('pgsql_authority')->table('security_audit_events')
            ->where('actor_user_id', $admin->id)->where('event', 'content_page_created')->exists());
    }

    public function test_runtime_audit_function_rejects_events_outside_content_scope(): void
    {
        $admin = $this->administrator();
        try {
            DB::select('SELECT public.builderad_content_write_audit(?, ?, ?)',
                [$admin->id, 'session_revoked', 'forged']);
            $this->fail('A non-content audit event was accepted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Unsupported content audit event', $error->getMessage());
        }
        $this->assertFalse(DB::connection('pgsql_authority')->table('security_audit_events')
            ->where('actor_user_id', $admin->id)->where('event', 'session_revoked')->exists());
    }

    public function test_slug_guard_serializes_cross_language_writers_on_separate_connections(): void
    {
        $this->administrator();
        config()->set('database.connections.content_parallel', config('database.connections.pgsql'));
        DB::purge('content_parallel');
        $first = DB::connection('pgsql');
        $second = DB::connection('content_parallel');
        $first->beginTransaction();
        try {
            $first->table('content_pages')->insert(['slug_ar' => 'parallel-first', 'slug_en' => 'parallel-shared']);
            $second->statement("SET lock_timeout = '500ms'");
            try {
                $second->table('content_pages')->insert(['slug_ar' => 'PARALLEL-SHARED', 'slug_en' => 'parallel-second']);
                $this->fail('The second writer did not wait for the first slug lock.');
            } catch (QueryException $error) {
                $this->assertSame('55P03', $error->getCode());
            }
            $first->commit();
            try {
                $second->table('content_pages')->insert(['slug_ar' => 'PARALLEL-SHARED', 'slug_en' => 'parallel-second']);
                $this->fail('A committed cross-language slug collision was accepted.');
            } catch (QueryException $error) {
                $this->assertSame('23505', $error->getCode());
            }
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
            $second->statement('RESET lock_timeout');
            DB::disconnect('content_parallel');
        }
    }

    public function test_populated_780_content_upgrades_to_revision_owned_publication_fields(): void
    {
        $admin = $this->administrator();
        $this->postJson('/internal/administration/content/pages',
            $this->pagePayload('legacy-page', 'Legacy page', 'Legacy body'))->assertOk();
        $page = DB::table('content_pages')->first();
        $this->postJson('/internal/administration/content/pages/'.$page->public_id.'/publish',
            ['revision' => 1])->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $folder = database_path('migration-sql/0001_01_01_000790_correct_content_publication');
        $owner->transaction(function () use ($owner, $folder, $page): void {
            $owner->unprepared(file_get_contents($folder.'/teardown.sql'));
            $this->assertFalse($owner->getSchemaBuilder()->hasColumn('content_page_revisions', 'slug_ar'));
            $owner->unprepared(file_get_contents($folder.'/upgrade.sql'));
            $revision = $owner->table('content_page_revisions')->where('page_id', $page->id)->first();
            $this->assertSame('legacy-page', $revision->slug_ar);
            $this->assertSame('legacy-page', $revision->slug_en);
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');
            $this->assertTrue((bool) $owner->scalar(
                'SELECT builderad_private.legacy_catalog_digest() = builderad_private.legacy_installation_digest()'));
        });
    }
}
