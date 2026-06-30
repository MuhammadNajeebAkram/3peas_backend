<?php

namespace Database\Seeders;

use App\Models\DashboardItem;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DashboardItemsSeeder extends Seeder
{
    /**
     * Seed assignable dashboard statistic items.
     */
    public function run(): void
    {
        $items = [
            ['code' => 'total_admin_users', 'title' => 'Total Admin Users', 'category' => 'Administration', 'data_key' => 'admin_users.total', 'permission_name' => 'admin-users.view'],
            ['code' => 'active_admin_users', 'title' => 'Active Admin Users', 'category' => 'Administration', 'data_key' => 'admin_users.active', 'permission_name' => 'admin-users.view'],
            ['code' => 'total_web_users', 'title' => 'Total Web Users', 'category' => 'Users', 'data_key' => 'web_users.total', 'permission_name' => 'web-users.view'],
            ['code' => 'unverified_web_users', 'title' => 'Unverified Web Users', 'category' => 'Users', 'data_key' => 'web_users.unverified', 'permission_name' => 'web-users.view'],
            ['code' => 'active_subscriptions', 'title' => 'Active Subscriptions', 'category' => 'Payments', 'data_key' => 'subscriptions.active', 'permission_name' => 'payments.view'],
            ['code' => 'pending_payment_requests', 'title' => 'Pending Payment Requests', 'category' => 'Payments', 'data_key' => 'payments.pending_requests', 'permission_name' => 'payments.view'],
            ['code' => 'approved_payment_requests', 'title' => 'Approved Payment Requests', 'category' => 'Payments', 'data_key' => 'payments.approved_requests', 'permission_name' => 'payments.view'],
            ['code' => 'total_classes', 'title' => 'Total Classes', 'category' => 'LMS Setup', 'data_key' => 'classes.total', 'permission_name' => 'classes.view'],
            ['code' => 'total_curriculum_boards', 'title' => 'Total Curriculum Boards', 'category' => 'LMS Setup', 'data_key' => 'curriculum_boards.total', 'permission_name' => 'curriculum-boards.view'],
            ['code' => 'total_subjects', 'title' => 'Total Subjects', 'category' => 'LMS Setup', 'data_key' => 'subjects.total', 'permission_name' => 'subjects.view'],
            ['code' => 'total_books', 'title' => 'Total Books', 'category' => 'LMS Setup', 'data_key' => 'books.total', 'permission_name' => 'books.view'],
            ['code' => 'total_units', 'title' => 'Total Units', 'category' => 'LMS Setup', 'data_key' => 'units.total', 'permission_name' => 'units.view'],
            ['code' => 'total_topics', 'title' => 'Total Topics', 'category' => 'LMS Setup', 'data_key' => 'topics.total', 'permission_name' => 'topics.view'],
            ['code' => 'total_questions', 'title' => 'Total Questions', 'category' => 'Question Bank', 'data_key' => 'questions.total', 'permission_name' => 'questions.view'],
            ['code' => 'active_questions', 'title' => 'Active Questions', 'category' => 'Question Bank', 'data_key' => 'questions.active', 'permission_name' => 'questions.view'],
            ['code' => 'mcq_questions', 'title' => 'MCQ Questions', 'category' => 'Question Bank', 'data_key' => 'questions.mcq', 'permission_name' => 'questions.view'],
            ['code' => 'alp_questions', 'title' => 'ALP Questions', 'category' => 'Question Bank', 'data_key' => 'questions.alp', 'permission_name' => 'questions.view'],
            ['code' => 'diagram_questions', 'title' => 'Diagram Questions', 'category' => 'Question Bank', 'data_key' => 'questions.diagram', 'permission_name' => 'questions.view'],
            [
                'code' => 'book_question_type_summary',
                'title' => 'Book Question Types',
                'category' => 'Question Bank',
                'widget_type' => 'bar_chart',
                'data_key' => 'questions.by_book_type',
                'permission_name' => 'dashboard-question-analytics.view',
                'width' => 'large',
                'description' => 'Question totals by type for a selected book.',
                'settings' => [
                    'endpoint' => '/admin/auth/dashboard-items/question-types/by-book',
                    'method' => 'POST',
                    'required_filters' => ['book_id'],
                    'chart_type' => 'bar',
                ],
            ],
            [
                'code' => 'creator_question_type_summary',
                'title' => 'Creator Question Types',
                'category' => 'Question Bank',
                'widget_type' => 'bar_chart',
                'data_key' => 'questions.by_creator_type',
                'permission_name' => 'dashboard-question-analytics.view',
                'width' => 'large',
                'description' => 'Question totals by creator and type for a selected date range.',
                'settings' => [
                    'endpoint' => '/admin/auth/dashboard-items/question-types/by-creator',
                    'method' => 'POST',
                    'required_filters' => ['start_date', 'end_date'],
                    'optional_filters' => ['user_id'],
                    'chart_type' => 'bar',
                ],
            ],
            [
                'code' => 'unit_question_type_summary',
                'title' => 'Unit Question Types',
                'category' => 'Question Bank',
                'widget_type' => 'bar_chart',
                'data_key' => 'questions.by_unit_type',
                'permission_name' => 'dashboard-question-analytics.view',
                'width' => 'large',
                'description' => 'Question totals by unit and type for a selected book.',
                'settings' => [
                    'endpoint' => '/admin/auth/dashboard-items/question-types/by-unit',
                    'method' => 'POST',
                    'required_filters' => ['book_id'],
                    'chart_type' => 'bar',
                ],
            ],
            ['code' => 'total_tests', 'title' => 'Total Tests', 'category' => 'Tests', 'data_key' => 'tests.total', 'permission_name' => 'tests.view'],
            ['code' => 'total_test_attempts', 'title' => 'Test Attempts', 'category' => 'Tests', 'data_key' => 'test_attempts.total', 'permission_name' => 'tests.view'],
            ['code' => 'total_news', 'title' => 'Total News', 'category' => 'Content', 'data_key' => 'news.total', 'permission_name' => 'news.view'],
            ['code' => 'published_news', 'title' => 'Published News', 'category' => 'Content', 'data_key' => 'news.published', 'permission_name' => 'news.view'],
            ['code' => 'featured_news', 'title' => 'Featured News', 'category' => 'Content', 'data_key' => 'news.featured', 'permission_name' => 'news.view'],
            ['code' => 'news_categories', 'title' => 'News Categories', 'category' => 'Content', 'data_key' => 'news_categories.total', 'permission_name' => 'news-categories.view'],
            ['code' => 'news_tickers', 'title' => 'News Tickers', 'category' => 'Content', 'data_key' => 'news_tickers.total', 'permission_name' => 'news-tickers.view'],
            ['code' => 'total_workshops', 'title' => 'Total Workshops', 'category' => 'Workshops', 'data_key' => 'workshops.total', 'permission_name' => 'dashboard.view'],
            ['code' => 'active_workshops', 'title' => 'Active Workshops', 'category' => 'Workshops', 'data_key' => 'workshops.active', 'permission_name' => 'dashboard.view'],
            ['code' => 'data_entry_assignments', 'title' => 'Data Entry Assignments', 'category' => 'Data Entry', 'data_key' => 'data_entry.assignments.total', 'permission_name' => 'data-entry-assignments.view'],
            ['code' => 'data_entry_active_assignments', 'title' => 'Active Data Entry Assignments', 'category' => 'Data Entry', 'data_key' => 'data_entry.assignments.active', 'permission_name' => 'data-entry-assignments.view'],
            ['code' => 'data_entry_pending_review', 'title' => 'Pending Data Entry Review', 'category' => 'Data Entry', 'data_key' => 'data_entry.items.pending_review', 'permission_name' => 'data-entry-assignments.review'],
            ['code' => 'data_entry_approved_items', 'title' => 'Approved Data Entry Items', 'category' => 'Data Entry', 'data_key' => 'data_entry.items.approved', 'permission_name' => 'data-entry-assignments.view'],
            ['code' => 'data_entry_correction_items', 'title' => 'Correction Needed Items', 'category' => 'Data Entry', 'data_key' => 'data_entry.items.needs_correction', 'permission_name' => 'data-entry-assignments.view'],
            ['code' => 'data_entry_payable_amount', 'title' => 'Data Entry Payable', 'category' => 'Data Entry', 'data_key' => 'data_entry.payments.payable', 'permission_name' => 'data-entry-assignments.pay'],
            ['code' => 'data_entry_paid_amount', 'title' => 'Data Entry Paid', 'category' => 'Data Entry', 'data_key' => 'data_entry.payments.paid', 'permission_name' => 'data-entry-assignments.pay'],
            ['code' => 'data_entry_balance_amount', 'title' => 'Data Entry Balance', 'category' => 'Data Entry', 'data_key' => 'data_entry.payments.balance', 'permission_name' => 'data-entry-assignments.pay'],
        ];

        foreach ($items as $index => $item) {
            DashboardItem::updateOrCreate(
                ['code' => $item['code']],
                array_merge([
                    'widget_type' => 'stat_card',
                    'width' => 'small',
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'description' => null,
                    'settings' => null,
                ], $item)
            );
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdminRole->dashboardItems()->sync(
            DashboardItem::query()
                ->orderBy('sort_order')
                ->pluck('id')
                ->mapWithKeys(fn ($id, $index) => [
                    $id => [
                        'is_visible' => true,
                        'sort_order' => $index,
                    ],
                ])
                ->all()
        );
    }
}
