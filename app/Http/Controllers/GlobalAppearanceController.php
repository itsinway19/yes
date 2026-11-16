<?php

namespace App\Http\Controllers;

use App\Appearance\BrandImage;
use App\Appearance\GlobalAppearance;
use App\Http\Middleware\EnsureFreshAuthentication;
use App\Http\Responses\SpaPage;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class GlobalAppearanceController
{
    public function image(Request $request, string $hash)
    {
        abort_unless((bool) DB::scalar("SELECT to_regclass('public.global_appearance_images') IS NOT NULL"), 404);
        $image = DB::table('global_appearance_images')->where('sha256', $hash)->first();
        abort_unless($image, 404);
        $headers = ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$hash.'"', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox"];
        if ($request->header('If-None-Match') === $headers['ETag']) {
            return response('', 304, $headers);
        }

        return response(base64_decode($image->content_base64, true), 200, $headers);
    }

    public function index(Request $request, GlobalAppearance $appearance): SpaPage
    {
        $this->requireFounder($request);

        return new SpaPage(SpaPage::needsData($request) ? $appearance->current() : []);
    }

    public function publish(Request $request, EnsureFreshAuthentication $fresh, GlobalAppearance $appearance)
    {
        // Establish the action's current authority before offering recoverable
        // reauthentication. Staleness must never mask a revoked Founder/grant.
        $this->requireFounder($request);

        return $fresh->handle($request, fn () => $this->publishFresh($request, $appearance));
    }

    private function publishFresh(Request $request, GlobalAppearance $appearance)
    {
        if (array_diff(array_keys($request->all()), ['primary', 'secondary', 'revision', 'logo', 'favicon']) !== []) {
            throw ValidationException::withMessages(['appearance' => 'Only primary, secondary, revision, logo and favicon are supported.']);
        }
        $input = $request->validate([
            'primary' => ['required', 'string', 'size:7', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'secondary' => ['required', 'string', 'size:7', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'revision' => ['required', 'integer', 'between:0,2147483646'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:699074'],
            'favicon' => ['sometimes', 'nullable', 'string', 'max:699074'],
        ]);
        $images = [];
        foreach (['logo', 'favicon'] as $field) {
            if (array_key_exists($field, $input)) {
                $images[$field] = $input[$field] === null ? null : BrandImage::parse($input[$field], $field);
            }
        }
        $db = DB::connection('pgsql_authority');
        $imagesInstalled = (bool) $db->scalar("SELECT to_regclass('public.global_appearance_images') IS NOT NULL")
            && (int) $db->scalar("SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='global_appearance' AND column_name IN ('logo_sha256','favicon_sha256')") === 2;
        if (! $imagesInstalled && array_filter($images, fn ($image) => $image !== null)) {
            throw ValidationException::withMessages(['logo' => app()->getLocale() === 'ar'
                ? 'رفع الشعار والأيقونة يحتاج إكمال تحديث المعاينة. لم تُنشر الصورة أو الألوان.'
                : 'Image publication requires the preview schema update. No image or color was published.']);
        }
        $published = $db->transaction(function () use ($request, $input, $images, $imagesInstalled, $appearance, $db) {
            // Same current Founder/grant owner as central administration; no new grant framework.
            $this->requireFounder($request, true);
            $db->table('global_appearance')->insertOrIgnore(['id' => 1, 'revision' => 0,
                'primary_color' => GlobalAppearance::DEFAULTS['primary'], 'secondary_color' => GlobalAppearance::DEFAULTS['secondary']]);
            $prior = $db->table('global_appearance')->where('id', 1)->lockForUpdate()->sole();
            if ((int) $prior->revision !== (int) $input['revision']) {
                throw new ConflictHttpException('Appearance changed. Review the published values before publishing again.');
            }
            $assetHashes = ['logo' => $prior->logo_sha256 ?? null, 'favicon' => $prior->favicon_sha256 ?? null];
            foreach ($images as $field => $image) {
                if ($image !== null) {
                    $db->table('global_appearance_images')->insertOrIgnore([...$image, 'published_by' => $request->user()->id, 'published_at' => now()]);
                }
                $assetHashes[$field] = $image['sha256'] ?? null;
            }
            $revision = (int) $prior->revision + 1;
            $db->table('global_appearance')->where('id', 1)->update([
                'primary_color' => strtolower($input['primary']), 'secondary_color' => strtolower($input['secondary']),
                ...($imagesInstalled ? ['logo_sha256' => $assetHashes['logo'], 'favicon_sha256' => $assetHashes['favicon']] : []),
                'revision' => $revision, 'published_by' => $request->user()->id, 'published_at' => now(),
            ]);
            $generation = (int) $request->session()->get('a001_generation');
            $db->table('security_audit_events')->insert([
                'actor_user_id' => $request->user()->id, 'subject_user_id' => $request->user()->id,
                'event' => 'global_appearance_published', 'reason' => 'appearance_revision:'.$revision,
                'prior_status' => 'current', 'new_status' => 'current', 'prior_generation' => $generation,
                'new_generation' => $generation, 'occurred_at' => now(),
            ]);

            return ['revision' => $revision, 'primary' => strtolower($input['primary']),
                'secondary' => strtolower($input['secondary']), 'defaults' => GlobalAppearance::DEFAULTS,
                'logo' => $appearance->imageDescription($assetHashes['logo'], $db),
                'favicon' => $appearance->imageDescription($assetHashes['favicon'], $db)];
        });

        return response()->json(['saved' => true, 'appearance' => $published])->header('Cache-Control', 'no-store');
    }

    private function requireFounder(Request $request, bool $lock = false): void
    {
        abort_unless($request->is('internal/*') && $request->user()
            && app(GrantAuthority::class)->has($request->user(), (int) $request->session()->get('a001_generation', 0), 'grant.manage'), 403);
        $query = DB::connection('pgsql_authority')->table('internal_personnel')
            ->where('user_id', $request->user()->id)->where('kind', 'founder')->where('status', 'active');
        if ($lock) {
            $query->sharedLock();
        }
        abort_unless($query->first(), 403);
    }
}
