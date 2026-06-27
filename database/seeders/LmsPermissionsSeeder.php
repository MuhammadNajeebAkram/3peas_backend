<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class LmsPermissionsSeeder extends Seeder
{
    /**
     * Seed LMS/admin permissions.
     */
    public function run(): void
    {
        $permissions = [
            'dashboard.view',

            'admin.media.presign',
            'admin.media.delete',

            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign-permissions',
            'roles.assign-permission-scopes',
            'roles.assign-dashboard-items',
            'permissions.view',
            'dashboard-items.view',

            'admin-users.view',
            'admin-users.create',
            'admin-users.update',
            'admin-users.activate',
            'admin-login-logs.view',

            'data-entry-dashboard.view',
            'data-entry-assignments.view',
            'data-entry-assignments.create',
            'data-entry-assignments.update',
            'data-entry-assignments.submit',
            'data-entry-assignments.review',
            'data-entry-assignments.pay',

            'web-users.view',
            'web-users.create',
            'web-users.update',
            'web-users.approve-subscription',
            'web-users.verify',

            'classes.view',
            'classes.create',
            'classes.update',
            'classes.activate',

            'curriculum-boards.view',
            'curriculum-boards.create',
            'curriculum-boards.update',
            'curriculum-boards.activate',

            'subjects.view',
            'subjects.create',
            'subjects.update',
            'subjects.activate',

            'exam-boards.view',
            'exam-boards.create',
            'exam-boards.update',
            'exam-boards.activate',

            'exam-sessions.view',
            'exam-sessions.create',
            'exam-sessions.update',
            'exam-sessions.activate',

            'books.view',
            'books.create',
            'books.update',
            'books.activate',

            'units.view',
            'units.create',
            'units.update',
            'units.activate',

            'topics.view',
            'topics.create',
            'topics.update',
            'topics.activate',

            'topic-content.view',
            'topic-content.create',
            'topic-content.update',
            'topic-content.activate',

            'question-types.view',
            'question-types.create',
            'question-types.update',
            'question-types.activate',

            'cognitive-domains.view',

            'questions.view',
            'questions.create',
            'questions.update',
            'questions.activate',
            'questions.repeat',
            'questions.review',
            'questions.publish',
            'questions.archive',

            'model-papers.view',
            'model-papers.create',
            'model-papers.update',
            'model-papers.activate-question',
            'model-papers.update-question',
            'model-papers.generate',

            'offered-classes.view',
            'offered-classes.create',
            'offered-classes.update',
            'offered-classes.activate',

            'offered-programs.view',
            'offered-programs.create',
            'offered-programs.update',
            'offered-programs.activate',

            'study-groups.view',
            'study-groups.create',
            'study-groups.update',
            'study-groups.search',

            'study-plans.view',
            'study-plans.create',
            'study-plans.update',
            'study-plans.activate',

            'study-sessions.view',
            'study-sessions.create',

            'tests.view',
            'tests.generate',
            'tests.submit',
            'tests.progress',

            'practice-sessions.generate',
            'practice-sessions.submit',
            'practice-sessions.progress',

            'student-statistics.view',
            'student-activities.view',
            'student-progress.view',

            'payments.view',
            'payments.create-request',
            'payments.approve',
            'payments.reject',
            'payment-accounts.view',
            'payment-accounts.create',
            'payment-accounts.update',
            'payment-accounts.activate',

            'locations.provinces.view',
            'locations.provinces.create',
            'locations.divisions.view',
            'locations.divisions.create',
            'locations.districts.view',
            'locations.districts.create',
            'locations.cities.view',
            'locations.cities.create',
            'institutes.view',
            'institutes.create',
            'institutes.update',
            'heard-about.view',

            'news.view',
            'news.create',
            'news.update',
            'news.activate',
            'news.delete',
            'news.publish',
            'news.archive',
            'news-categories.view',
            'news-categories.create',
            'news-categories.update',
            'news-categories.delete',
            'news-tickers.view',
            'news-tickers.create',
            'news-tickers.update',
            'news-tickers.delete',

            'blogs.view',
            'blogs.create',
            'blogs.update',
            'blogs.activate',
            'blog-categories.view',
            'blog-categories.create',
            'blog-categories.update',
            'blog-categories.activate',

            'feedback.view',
            'feedback.create',
            'answer-ratings.create',
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['name' => $permission]);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdminRole->permissions()->sync(
            Permission::whereIn('name', $permissions)->pluck('id')->all()
        );
    }
}
