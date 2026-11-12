<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
}
