<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SeoMaintenance
{
    /** Only recognize untouched English starter wording; never overwrite custom admin slugs. */
    public function repairWording(): int
    {
        $catalog = json_decode(file_get_contents(resource_path('seo/subjects.json')), true, flags: JSON_THROW_ON_ERROR);
        $changed = 0;
        foreach (DB::table('seo_subject_routes as r')->join('subjects as s', 's.id', '=', 'r.subject_id')
            ->where('r.language', 'en')->select('r.*', 's.name as subject_name')->orderBy('r.id')->get() as $route) {
            $style = config('seo.countries.'.$route->country.'.style');
            if ($style === null) {
                continue;
            }
            $key = Str::slug($route->subject_name);
            $fallback = substr($key.'-'.$style, 0, 220);
            if ($route->city_slug !== $fallback || $route->online_slug !== $fallback) {
                continue;
            }
            $last = DB::table('business_activity')->where('event', 'seo.route_configured')
                ->where('entity_id', $route->id)->orderByDesc('id')->value('after_data');
            if ($last && array_key_exists('display_name', json_decode($last, true, flags: JSON_THROW_ON_ERROR))) {
                continue; // An explicit admin save takes precedence over starter wording.
            }
            $known = null;
            foreach ($catalog as $base => $definition) {
                if (in_array($key, $definition['aliases'], true)) {
                    $known = $base;
                    break;
                }
            }
            if ($known !== null) {
                $values = ['city_slug' => substr($key, 0, 220), 'online_slug' => substr($key, 0, 220)];
            } else {
                // Legacy auto-generated subjects need explicit editorial approval. Admin saves
                // include display_name; original automatic seed events deliberately did not.
                $values = ['enabled' => false];
            }
            if (collect($values)->every(fn ($value, $key) => ($key === 'enabled' ? (bool) $route->$key === (bool) $value : $value === $route->$key))) {
                continue;
            }
            DB::table('seo_subject_routes')->where('id', $route->id)->update($values + ['updated_at' => now()]);
            app(BusinessActivityRecorder::class)->record('seo.route_configured', $route->id, null,
                before: array_intersect_key((array) $route, $values), after: $values);
            $changed++;
        }

        return $changed;
    }

    /** Remove only unavailable generated page rows. URL reservations/history survive. */
    public function prune(): array
    {
        $counts = ['listing' => 0, 'location' => 0];
        foreach (['listing' => 'seo_directory_pages', 'location' => 'seo_location_pages'] as $type => $table) {
            foreach (DB::table($table)->orderBy('id')->lazyById(100) as $candidate) {
                DB::transaction(function () use ($candidate, $type, $table, &$counts): void {
                    $page = DB::table($table)->where('id', $candidate->id)->lockForUpdate()->first();
                    if ($page === null) {
                        return;
                    }
                    $active = $type === 'listing' ? app(SeoDirectory::class)->pages()->where('r.id', $page->id)
                        : app(SeoDirectory::class)->locationPages()->where('lp.id', $page->id);
                    if ($active->exists()) {
                        return;
                    }
                    app(BusinessActivityRecorder::class)->record($type === 'listing' ? 'seo.page_pruned' : 'seo.location_page_pruned',
                        $page->id, null, before: ['path' => $page->path], after: ['reason' => 'unavailable']);
                    DB::table($table)->where('id', $page->id)->delete();
                    $counts[$type]++;
                });
            }
        }

        return $counts;
    }
}
