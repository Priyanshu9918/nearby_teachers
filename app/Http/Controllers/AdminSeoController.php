<?php

namespace App\Http\Controllers;

use App\Reporting\BusinessActivityRecorder;
use App\Reporting\ReportAccessLog;
use App\Services\SeoDirectory;
use App\Services\TeacherPublicPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminSeoController extends Controller
{
    public function index(Request $request, ?int $id = null): View
    {
        app(ReportAccessLog::class)->record($request, 'seo-subject-routes', 'view', $id);
        $editing = $id === null ? null : DB::table('seo_subject_routes')->where('id', $id)->first();
        abort_if($id !== null && $editing === null, 404);
        $subjects = DB::table('subjects')->select('id', 'name')->orderBy('name')->get();

        return view('admin.seo', compact('editing', 'subjects') + ['countries' => config('seo.countries')]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:seo_subject_routes,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'country' => ['required', Rule::in(array_keys(config('seo.countries')))],
            'language' => ['required', 'string', 'max:8'],
            'display_name' => ['required', 'string', 'max:200'],
            'city_slug' => ['required', 'string', 'max:230', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'online_slug' => ['required', 'string', 'max:230', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'enabled' => ['required', 'boolean'],
        ]);
        if (! in_array($data['language'], config('seo.countries.'.$data['country'].'.languages'), true)) {
            throw ValidationException::withMessages(['language' => 'Choose a configured language for this country.']);
        }
        try {
            $id = DB::transaction(function () use ($data): int {
                $id = $data['id'] ?? null;
                $old = $id === null ? null : DB::table('seo_subject_routes')->where('id', $id)->lockForUpdate()->first();
                if ($old && ((int) $old->subject_id !== (int) $data['subject_id'] || $old->country !== $data['country'] || $old->language !== $data['language'])) {
                    throw ValidationException::withMessages(['subject_id' => 'An existing mapping keeps its subject, country and language. Create a separate mapping instead.']);
                }
                $conflict = DB::table('seo_subject_routes')->where('country', $data['country'])->where('language', $data['language'])
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))
                    ->where(fn ($q) => $q->where('subject_id', $data['subject_id'])->orWhere('city_slug', $data['city_slug'])->orWhere('online_slug', $data['online_slug']))->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['city_slug' => 'This subject/locale or slug is already configured.']);
                }
                $values = array_intersect_key($data, array_flip(['subject_id', 'country', 'language', 'display_name', 'city_slug', 'online_slug', 'enabled']));
                $values['enabled'] = (bool) $values['enabled'];
                if ($id === null) {
                    $id = DB::table('seo_subject_routes')->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('seo_subject_routes')->where('id', $id)->update($values + ['updated_at' => now()]);
                }
                app(BusinessActivityRecorder::class)->record('seo.route_configured', (int) $id, null,
                    before: $old ? ['city_slug' => $old->city_slug, 'online_slug' => $old->online_slug, 'enabled' => (bool) $old->enabled] : [],
                    after: $values);
                $directory = app(SeoDirectory::class);
                foreach (DB::table('teacher_profiles')->where('seo_country', $data['country'])->orderBy('user_id')->lazyById(100, 'user_id') as $profile) {
                    $directory->ensurePages($profile, TeacherPublicPages::subjects((int) $profile->user_id)->pluck('id')->all());
                }

                return (int) $id;
            });
        } catch (\LogicException $error) {
            if ($error->getMessage() !== 'SEO URL is already owned.') {
                throw $error;
            }
            throw ValidationException::withMessages(['city_slug' => 'This URL is reserved by another current or historical page. Choose a different slug.']);
        }
        app(ReportAccessLog::class)->record($request, 'seo-subject-routes', 'update', $id);

        return redirect()->route('admin.seo.edit', ['id' => $id])->with('status', 'SEO mapping saved. Existing listing URLs keep redirects.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $count = app(SeoDirectory::class)->sync();
        app(ReportAccessLog::class)->record($request, 'seo-pages', 'sync');

        return redirect()->route('admin.reports.index', ['report' => 'seo-pages'])->with('status', 'Processed '.$count.' teacher profiles.');
    }
}
