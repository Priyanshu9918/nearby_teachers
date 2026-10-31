<?php

namespace App\Support;

use App\Reporting\ReportRegistry;

final class AdminNavigation
{
    public function __construct(private readonly ReportRegistry $reports) {}

    public function groups(): array
    {
        $groups = [
            'People & growth' => ['icon' => 'users', 'reports' => ['users', 'logins']],
            'Teachers & institutes' => ['icon' => 'building', 'reports' => ['teacher-profiles', 'institute-profiles', 'subjects', 'subject-domains', 'profile-subjects']],
            'Lessons & demand' => ['icon' => 'calendar', 'reports' => ['bookings', 'reviews']],
            'Messages & community' => ['icon' => 'chat', 'reports' => ['conversations', 'messages', 'message-reactions', 'posts', 'comments', 'likes', 'comment-likes']],
            'Website & downloads' => ['icon' => 'document', 'reports' => ['page-views', 'no-result-searches', 'seo-pages', 'seo-location-pages', 'seo-subject-routes', 'seo-url-history']],
            'Referrals & posters' => ['icon' => 'chart', 'reports' => ['referrals', 'referral-clicks', 'phone-reveals']],
            'Jobs, franchise & call-backs' => ['icon' => 'briefcase', 'reports' => ['job-applications', 'job-application-courses', 'franchise-enquiries']],
            'Safety & support' => ['icon' => 'shield', 'reports' => ['reports', 'blocks', 'support', 'support-contact-settings', 'suggestions']],
            'WhatsApp alerts & team' => ['icon' => 'chat', 'reports' => ['otp-delivery', 'activity', 'admin-access', 'api-chat-access', 'api-booking-access']],
        ];
        $currentReport = request()->route('report');
        if (request()->routeIs('admin.reports.user')) {
            $currentReport = 'users';
        }
        if (request()->routeIs('admin.reports.conversation')) {
            $currentReport = 'conversations';
        }
        $catalog = $this->reports->all();
        $assigned = [];
        foreach ($groups as &$group) {
            $group['items'] = [];
            foreach ($group['reports'] as $key) {
                if (! isset($catalog[$key])) {
                    continue;
                }
                $group['items'][] = [
                    'key' => $key, 'label' => $catalog[$key]->title,
                    'url' => route('admin.reports.index', ['report' => $key]),
                    'active' => $currentReport === $key,
                ];
                $assigned[] = $key;
            }
        }
        unset($group);
        // New registry reports remain discoverable before a specific group is chosen.
        foreach (array_diff(array_keys($catalog), $assigned) as $key) {
            $groups['Other reports']['icon'] = 'document';
            $groups['Other reports']['items'][] = [
                'key' => $key, 'label' => $catalog[$key]->title,
                'url' => route('admin.reports.index', ['report' => $key]), 'active' => $currentReport === $key,
            ];
        }

        return $groups;
    }
}
