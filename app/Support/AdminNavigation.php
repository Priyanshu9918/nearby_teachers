<?php

namespace App\Support;

use App\Reporting\ReportRegistry;

final class AdminNavigation
{
    public function __construct(private readonly ReportRegistry $reports) {}

    public function groups(): array
    {
        $groups = [
            'People' => ['icon' => 'users', 'reports' => ['users', 'logins', 'teacher-profiles', 'institute-profiles']],
            'Enquiries & recruitment' => ['icon' => 'document', 'reports' => ['franchise-enquiries', 'job-applications', 'job-application-courses']],
            'Public pages & SEO' => ['icon' => 'document', 'reports' => ['seo-pages', 'seo-location-pages', 'seo-subject-routes', 'seo-url-history']],
            'Chat & lessons' => ['icon' => 'chat', 'reports' => ['conversations', 'messages', 'bookings', 'reviews']],
            'Community & support' => ['icon' => 'shield', 'reports' => ['posts', 'comments', 'likes', 'comment-likes', 'support', 'suggestions', 'reports', 'blocks']],
            'Learning catalog' => ['icon' => 'book', 'reports' => ['subjects', 'subject-domains', 'profile-subjects']],
            'Insights & activity' => ['icon' => 'chart', 'reports' => ['activity', 'otp-delivery', 'referrals', 'phone-reveals', 'page-views']],
            'Admin access logs' => ['icon' => 'lock', 'reports' => ['admin-access', 'api-chat-access', 'api-booking-access']],
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
