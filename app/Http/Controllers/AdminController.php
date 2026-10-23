<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    private const SECTIONS = [
        'subjects' => 'Subjects',
        'students' => 'Students',
        'teachers' => 'Teachers',
        'institutes' => 'Institutes',
    ];

    public function login(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('admin_authenticated') === true) {
            return redirect()->route('admin.home');
        }

        return view('admin.login', [
            'configured' => filled(config('admin.email')) && filled(config('admin.password_hash')),
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $key = 'admin-login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 6)) {
            return back()->withErrors(['email' => 'Too many sign-in attempts. Please try again shortly.']);
        }

        $configuredEmail = (string) config('admin.email');
        $passwordHash = (string) config('admin.password_hash');
        $validEmail = $configuredEmail !== ''
            && hash_equals(Str::lower($configuredEmail), Str::lower($credentials['email']));

        if (! $validEmail || $passwordHash === '' || ! Hash::check($credentials['password'], $passwordHash)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'The provided admin credentials are invalid.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return redirect()->route('admin.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function home(): RedirectResponse
    {
        return redirect()->route('admin.dashboard');
    }

    public function index(string $section): View
    {
        $this->assertSection($section);

        return $this->renderSection($section);
    }

    public function create(string $section): View
    {
        $this->assertSection($section);

        return $this->renderSection($section);
    }

    public function edit(string $section, int $id): View
    {
        $this->assertSection($section);
        $record = $this->records($section)->where($this->idColumn($section), $id)->first();

        abort_if($record === null, 404);

        return $this->renderSection($section, $record);
    }

    public function store(Request $request, string $section): RedirectResponse
    {
        $this->assertSection($section);

        DB::transaction(function () use ($request, $section): void {
            if ($section === 'subjects') {
                $data = $this->validateSubject($request);
                DB::table('subjects')->insert($data);

                return;
            }

            $data = $this->validateUser($request, $section);
            $now = now();

            $userId = DB::table('users')->insertGetId([
                'account_type' => $this->accountType($section),
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($section !== 'students') {
                $profileData = $this->profileData($data, $section);
                $profileData['lesson_modes'] = '[]';
                DB::table($this->profileTable($section))->insert(
                    ['user_id' => $userId] + $profileData + [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        });

        return redirect()->route('admin.index', ['section' => $section])->with('status', 'Record created.');
    }

    public function update(Request $request, string $section, int $id): RedirectResponse
    {
        $this->assertSection($section);

        DB::transaction(function () use ($request, $section, $id): void {
            if ($section === 'subjects') {
                $data = $this->validateSubject($request, (int) $id);
                $updated = DB::table('subjects')->where('id', $id)->update($data);
                abort_if($updated === 0 && ! DB::table('subjects')->where('id', $id)->exists(), 404);

                return;
            }

            $data = $this->validateUser($request, $section, $id);
            $updated = DB::table('users')
                ->where('id', $id)
                ->where('account_type', $this->accountType($section))
                ->update([
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'updated_at' => now(),
                ]);

            abort_if($updated === 0 && ! DB::table('users')
                ->where('id', $id)
                ->where('account_type', $this->accountType($section))
                ->exists(), 404);

            if ($section !== 'students') {
                DB::table($this->profileTable($section))
                    ->where('user_id', $id)
                    ->update($this->profileData($data, $section) + ['updated_at' => now()]);
            }
        });

        return redirect()->route('admin.index', ['section' => $section])->with('status', 'Record updated.');
    }

    public function destroy(string $section, int $id): RedirectResponse
    {
        $this->assertSection($section);

        if ($section === 'subjects') {
            $inUse = DB::table('profile_subjects')->where('subject_id', $id)->exists()
                || DB::table('bookings')->where('subject_id', $id)->exists()
                || DB::table('posts')->where('subject_id', $id)->exists();

            if ($inUse) {
                return back()->withErrors(['record' => 'This subject is in use and cannot be deleted.']);
            }

            $deleted = DB::table('subjects')->where('id', $id)->delete();
        } else {
            $deleted = DB::table('users')
                ->where('id', $id)
                ->where('account_type', $this->accountType($section))
                ->delete();
        }

        abort_if($deleted === 0, 404);

        return redirect()->route('admin.index', ['section' => $section])->with('status', 'Record deleted.');
    }

    private function renderSection(string $section, ?object $editing = null): View
    {
        $pagination = request()->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([15, 25, 30, 50, 100])]]);
        $domains = $section === 'subjects'
            ? DB::table('subject_domains')->select('id', 'name')->orderBy('sort_order')->orderBy('name')->get()
            : collect();

        return view('admin.index', [
            'section' => $section,
            'title' => self::SECTIONS[$section],
            'records' => $this->records($section)->orderBy($section === 'subjects' ? 'subjects.name' : 'users.full_name')->orderBy($this->idColumn($section))->paginate((int) ($pagination['per_page'] ?? 25))->withQueryString(),
            'domains' => $domains,
            'editing' => $editing,
        ]);
    }

    private function records(string $section): Builder
    {
        return match ($section) {
            'subjects' => DB::table('subjects')
                ->join('subject_domains', 'subject_domains.id', '=', 'subjects.domain_id')
                ->select('subjects.id', 'subjects.name', 'subjects.domain_id', 'subjects.is_bsl', 'subject_domains.name as domain_name'),
            'students' => DB::table('users')
                ->where('users.account_type', 'learner')
                ->select('users.id', 'users.full_name', 'users.email', 'users.phone', 'users.created_at'),
            'teachers' => DB::table('users')
                ->join('teacher_profiles', 'teacher_profiles.user_id', '=', 'users.id')
                ->where('users.account_type', 'teacher')
                ->select('users.id', 'users.full_name', 'users.email', 'users.phone', 'teacher_profiles.bio', 'teacher_profiles.rate_per_hour as rate', 'teacher_profiles.currency', 'teacher_profiles.city', 'teacher_profiles.state', 'teacher_profiles.country', 'teacher_profiles.country_code', 'teacher_profiles.linkedin_url', 'teacher_profiles.created_at'),
            'institutes' => DB::table('users')
                ->join('institute_profiles', 'institute_profiles.user_id', '=', 'users.id')
                ->where('users.account_type', 'institute')
                ->select('users.id', 'users.full_name', 'users.email', 'users.phone', 'institute_profiles.description as bio', 'institute_profiles.starting_rate as rate', 'institute_profiles.currency', 'institute_profiles.city', 'institute_profiles.state', 'institute_profiles.country', 'institute_profiles.country_code', 'institute_profiles.instructors_text', 'institute_profiles.website_url', 'institute_profiles.created_at'),
        };
    }

    private function validateSubject(Request $request, ?int $subjectId = null): array
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer', 'exists:subject_domains,id'],
            'name' => ['required', 'string', 'max:160'],
            'is_bsl' => ['sometimes', 'boolean'],
        ]);

        Validator::make($data, [
            'name' => [Rule::unique('subjects', 'name')
                ->where(fn ($query) => $query->where('domain_id', $data['domain_id']))
                ->ignore($subjectId)],
        ])->validate();

        return [
            'domain_id' => (int) $data['domain_id'],
            'name' => trim($data['name']),
            'is_bsl' => $request->boolean('is_bsl'),
        ];
    }

    private function validateUser(Request $request, string $section, ?int $userId = null): array
    {
        $emailRule = Rule::unique('users', 'email');
        $phoneRule = Rule::unique('users', 'phone');

        if ($userId !== null) {
            $emailRule->ignore($userId);
            $phoneRule->ignore($userId);
        }

        $rules = [
            'full_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone', $emailRule],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email', $phoneRule],
        ];

        if ($section !== 'students') {
            $descriptionField = $section === 'teachers' ? 'bio' : 'description';
            $rateField = $section === 'teachers' ? 'rate_per_hour' : 'starting_rate';
            $rules += [
                $descriptionField => ['nullable', 'string', 'max:1000'],
                $rateField => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
                'currency' => ['nullable', 'string', 'size:3'],
                'city' => ['nullable', 'string', 'max:120'],
                'state' => ['nullable', 'string', 'max:120'],
                'country' => ['nullable', 'string', 'max:120'],
                'country_code' => ['nullable', 'string', 'size:2'],
            ];
        }

        if ($section === 'teachers') {
            $rules['bio'][] = function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && str_word_count(trim($value)) > 14) {
                    $fail('The bio must be 14 words or fewer.');
                }
            };
            $rules['linkedin_url'] = ['nullable', 'url', 'max:2048'];
        } elseif ($section === 'institutes') {
            $rules['description'][] = function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && str_word_count(trim($value)) > 14) {
                    $fail('The description must be 14 words or fewer.');
                }
            };
            $rules['instructors_text'] = ['nullable', 'string', 'max:2000'];
            $rules['website_url'] = ['nullable', 'url', 'max:2048'];
        }

        return $request->validate($rules);
    }

    private function profileData(array $data, string $section): array
    {
        $common = [
            'currency' => $data['currency'] ?? 'INR',
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'country' => $data['country'] ?? null,
            'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
        ];

        if ($section === 'teachers') {
            return $common + [
                'bio' => $data['bio'] ?? null,
                'rate_per_hour' => $data['rate_per_hour'] ?? null,
                'linkedin_url' => $data['linkedin_url'] ?? null,
            ];
        }

        return $common + [
            'description' => $data['description'] ?? null,
            'starting_rate' => $data['starting_rate'] ?? null,
            'instructors_text' => $data['instructors_text'] ?? null,
            'website_url' => $data['website_url'] ?? null,
        ];
    }

    private function assertSection(string $section): void
    {
        abort_unless(array_key_exists($section, self::SECTIONS), 404);
    }

    private function idColumn(string $section): string
    {
        return $section === 'subjects' ? 'subjects.id' : 'users.id';
    }

    private function accountType(string $section): string
    {
        return match ($section) {
            'students' => 'learner',
            'teachers' => 'teacher',
            'institutes' => 'institute',
            default => throw new \InvalidArgumentException('Subjects do not have an account type.'),
        };
    }

    private function profileTable(string $section): string
    {
        return match ($section) {
            'teachers' => 'teacher_profiles',
            'institutes' => 'institute_profiles',
            default => throw new \InvalidArgumentException('This section has no profile table.'),
        };
    }
}
