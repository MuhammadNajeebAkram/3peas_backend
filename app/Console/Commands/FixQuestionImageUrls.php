<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixQuestionImageUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'questions:fix-image-urls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up corrupt image URLs (e.g. images/2https://... -> https://...) in question scenarios, questions, and options';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning database for corrupt image references...');

        $sanitize = function ($text) {
            if (!is_string($text) || empty($text)) return $text;
            return preg_replace('/(?:images\/[a-zA-Z0-9_\-\.\/]*|images\/)(https?:\/\/[^"\'\s>]+)/i', '$1', $text);
        };

        // 1. Fix question_scenario_groups_tbl
        $scenarioCount = 0;
        $scenarios = DB::table('question_scenario_groups_tbl')
            ->where(function ($q) {
                $q->where('scenario_text', 'like', '%images/%http%')
                  ->orWhere('scenario_text_um', 'like', '%images/%http%')
                  ->orWhere('scenario_image', 'like', '%images/%http%');
            })
            ->get();

        foreach ($scenarios as $s) {
            $updated = [];
            if (!empty($s->scenario_text)) {
                $cleaned = $sanitize($s->scenario_text);
                if ($cleaned !== $s->scenario_text) $updated['scenario_text'] = $cleaned;
            }
            if (!empty($s->scenario_text_um)) {
                $cleaned = $sanitize($s->scenario_text_um);
                if ($cleaned !== $s->scenario_text_um) $updated['scenario_text_um'] = $cleaned;
            }
            if (!empty($s->scenario_image)) {
                $cleaned = $sanitize($s->scenario_image);
                if ($cleaned !== $s->scenario_image) $updated['scenario_image'] = $cleaned;
            }
            if (!empty($updated)) {
                DB::table('question_scenario_groups_tbl')->where('id', $s->id)->update($updated);
                $scenarioCount++;
                $this->line("Fixed Scenario Group #{$s->id}: {$s->title}");
            }
        }

        // 2. Fix exam_question_tbl
        $questionCount = 0;
        $questions = DB::table('exam_question_tbl')
            ->where(function ($q) {
                $q->where('question', 'like', '%images/%http%')
                  ->orWhere('question_um', 'like', '%images/%http%')
                  ->orWhere('explanation', 'like', '%images/%http%')
                  ->orWhere('explanation_um', 'like', '%images/%http%');
            })
            ->get();

        foreach ($questions as $q) {
            $updated = [];
            if (!empty($q->question)) {
                $cleaned = $sanitize($q->question);
                if ($cleaned !== $q->question) $updated['question'] = $cleaned;
            }
            if (!empty($q->question_um)) {
                $cleaned = $sanitize($q->question_um);
                if ($cleaned !== $q->question_um) $updated['question_um'] = $cleaned;
            }
            if (!empty($q->explanation)) {
                $cleaned = $sanitize($q->explanation);
                if ($cleaned !== $q->explanation) $updated['explanation'] = $cleaned;
            }
            if (!empty($q->explanation_um)) {
                $cleaned = $sanitize($q->explanation_um);
                if ($cleaned !== $q->explanation_um) $updated['explanation_um'] = $cleaned;
            }
            if (!empty($updated)) {
                DB::table('exam_question_tbl')->where('id', $q->id)->update($updated);
                $questionCount++;
                $this->line("Fixed Question #{$q->id}");
            }
        }

        // 3. Fix exam_question_options_tbl
        $optionCount = 0;
        $options = DB::table('exam_question_options_tbl')
            ->where(function ($q) {
                $q->where('option', 'like', '%images/%http%')
                  ->orWhere('option_um', 'like', '%images/%http%');
            })
            ->get();

        foreach ($options as $opt) {
            $updated = [];
            if (!empty($opt->option)) {
                $cleaned = $sanitize($opt->option);
                if ($cleaned !== $opt->option) $updated['option'] = $cleaned;
            }
            if (!empty($opt->option_um)) {
                $cleaned = $sanitize($opt->option_um);
                if ($cleaned !== $opt->option_um) $updated['option_um'] = $cleaned;
            }
            if (!empty($updated)) {
                DB::table('exam_question_options_tbl')->where('id', $opt->id)->update($updated);
                $optionCount++;
                $this->line("Fixed Option #{$opt->id}");
            }
        }

        $this->info("Done! Cleaned {$scenarioCount} scenario groups, {$questionCount} questions, and {$optionCount} options.");
        return Command::SUCCESS;
    }
}

