<?php

namespace App\Http\Controllers\Stats;

use App\Http\Controllers\Controller;
use App\Models\OfferedProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentStudyTimeController extends Controller
{
    public function getStudentStudyTimeForLms(Request $request)
    {
        $validated = $request->validate([
            'offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['required', 'integer', 'exists:subject_tbl,id'],
        ]);

        try {
            $userId = $request->user()->id;
            $programId = (int) $validated['offered_program_id'];
            $program = OfferedProgram::with(['offeredClass', 'programSubjects.subject'])
                ->findOrFail($programId);

            $practice = DB::table('practice_session_questions as answers')
                ->join('practice_sessions as sessions', 'sessions.id', '=', 'answers.session_id')
                ->join('exam_question_tbl as questions', 'questions.id', '=', 'answers.question_id')
                ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
                ->where('sessions.user_id', $userId)
                ->where('sessions.offered_program_id', $programId)
                ->select('topics.unit_id')
                ->selectRaw('COALESCE(SUM(answers.time_spent_seconds), 0) as seconds')
                ->groupBy('topics.unit_id')
                ->pluck('seconds', 'unit_id');

            $formal = DB::table('test_attempt_questions as answers')
                ->join('test_attempts as attempts', 'attempts.id', '=', 'answers.attempt_id')
                ->join('tests', 'tests.id', '=', 'attempts.test_id')
                ->join('exam_question_tbl as questions', 'questions.id', '=', 'answers.question_id')
                ->join('book_unit_topic_tbl as topics', 'topics.id', '=', 'questions.topic_id')
                ->where('attempts.user_id', $userId)
                ->where('tests.offered_program_id', $programId)
                ->select('topics.unit_id')
                ->selectRaw('COALESCE(SUM(answers.time_spent_seconds), 0) as seconds')
                ->groupBy('topics.unit_id')
                ->pluck('seconds', 'unit_id');

            $subjectIds = $program->programSubjects->where('is_active', true)->pluck('subject_id');
            $units = DB::table('book_unit_tbl as units')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->where('books.class_id', $program->offeredClass->class_id)
                ->where('books.curriculum_board_id', $program->offeredClass->curriculum_board_id)
                ->whereIn('books.subject_id', $subjectIds)
                ->where('books.activate', 1)
                ->where('units.activate', 1)
                ->orderBy('books.id')
                ->orderBy('units.unit_no')
                ->select('units.id', 'units.unit_name', 'units.unit_no', 'books.subject_id', 'books.book_name')
                ->get()
                ->map(function ($unit) use ($practice, $formal) {
                    return [
                        'unit_id' => (int) $unit->id,
                        'unit_name' => $unit->unit_name,
                        'unit_no' => $unit->unit_no,
                        'book_name' => $unit->book_name,
                        'subject_id' => (int) $unit->subject_id,
                        'practice_seconds' => (int) ($practice[$unit->id] ?? 0),
                        'test_seconds' => (int) ($formal[$unit->id] ?? 0),
                    ];
                });

            $subjects = $program->programSubjects->where('is_active', true)
                ->unique('subject_id')
                ->map(function ($programSubject) use ($units) {
                    $subjectUnits = $units->where('subject_id', $programSubject->subject_id);
                    return [
                        'subject_id' => (int) $programSubject->subject_id,
                        'subject_name' => $programSubject->subject?->subject_name,
                        'practice_seconds' => (int) $subjectUnits->sum('practice_seconds'),
                        'test_seconds' => (int) $subjectUnits->sum('test_seconds'),
                    ];
                })->values();

            return response()->json([
                'success' => 1,
                'data' => [
                    'offered_program_id' => $programId,
                    'subject_id' => (int) $validated['subject_id'],
                    'subjects' => $subjects,
                    'chapters' => $units->where('subject_id', (int) $validated['subject_id'])->values(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 0, 'error' => $e->getMessage()], 500);
        }
    }
}
