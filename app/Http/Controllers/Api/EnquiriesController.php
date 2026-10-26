<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\BusinessActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class EnquiriesController extends Controller
{
    public function franchise(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->contactRules(),
            'location' => ['required', 'string', 'max:255'],
        ]);
        $phone = $this->phone($data['phone']);
        $userId = $request->attributes->get('authenticated_user')->id;
        $enquiry = DB::transaction(function () use ($data, $phone, $userId): array {
            $createdAt = now();
            $id = DB::table('franchise_enquiries')->insertGetId([
                'user_id' => $userId, 'name' => $data['name'], 'phone' => $phone,
                'location' => $data['location'], 'status' => 'submitted', 'created_at' => $createdAt,
            ]);
            app(BusinessActivityRecorder::class)->record('franchise_enquiry.submitted', $id, $userId,
                after: ['status' => 'submitted']);

            return ['id' => $id, 'name' => $data['name'], 'phone' => $phone,
                'location' => $data['location'], 'status' => 'submitted', 'createdAt' => $createdAt->toISOString()];
        });

        return response()->json(['message' => 'Your franchise enquiry has been submitted.', 'enquiry' => $enquiry], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function teachingJob(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->contactRules(),
            'expectedSalary' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'courseIds' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'courseIds.*' => ['required', 'integer', 'min:1', 'max:4294967295', 'distinct'],
        ]);
        $phone = $this->phone($data['phone']);
        $userId = $request->attributes->get('authenticated_user')->id;
        $application = DB::transaction(function () use ($data, $phone, $userId): array {
            $courses = DB::table('subjects')->whereIn('id', $data['courseIds'])->where('is_bsl', true)
                ->orderBy('id')->sharedLock()->get(['id', 'name']);
            if ($courses->count() !== count($data['courseIds'])) {
                throw ValidationException::withMessages(['courseIds' => 'Choose between 1 and 5 available BSL courses from GET /subjects.']);
            }
            $createdAt = now();
            $salary = number_format((float) $data['expectedSalary'], 2, '.', '');
            $id = DB::table('teaching_job_applications')->insertGetId([
                'user_id' => $userId, 'name' => $data['name'], 'phone' => $phone,
                'expected_salary' => $salary, 'currency' => 'INR', 'salary_period' => 'monthly',
                'status' => 'submitted', 'created_at' => $createdAt,
            ]);
            DB::table('teaching_job_application_courses')->insert($courses->map(fn ($course) => [
                'application_id' => $id, 'user_id' => $userId, 'course_id' => $course->id,
                'course_name' => $course->name, 'created_at' => $createdAt,
            ])->all());
            app(BusinessActivityRecorder::class)->record('teaching_job_application.submitted', $id, $userId,
                after: ['status' => 'submitted', 'course_ids' => $courses->pluck('id')->all(),
                    'expected_salary' => $salary, 'currency' => 'INR', 'salary_period' => 'monthly']);

            return ['id' => $id, 'name' => $data['name'], 'phone' => $phone,
                'expectedSalary' => $salary, 'currency' => 'INR', 'salaryPeriod' => 'monthly',
                'courses' => $courses->map(fn ($course) => ['id' => $course->id, 'name' => $course->name])->all(),
                'status' => 'submitted', 'createdAt' => $createdAt->toISOString()];
        });

        return response()->json(['message' => 'Your teaching job application has been submitted.', 'application' => $application], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    private function contactRules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'phone' => ['required', 'string', 'max:40']];
    }

    private function phone(string $input): string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($input);
            if ($util->isValidNumber($number) && ! $number->hasExtension()) {
                return $util->format($number, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // Return the same field error for malformed and invalid numbers.
        }

        throw ValidationException::withMessages(['phone' => 'Enter a valid phone number with country code, e.g. +919876543210.']);
    }
}
