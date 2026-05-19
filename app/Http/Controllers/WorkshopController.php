<?php
// app/Http/Controllers/Api/Lms/WorkshopController.php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkshopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'upcoming'); // upcoming | past | all
        $featured = $request->query('featured');
        $mode = $request->query('mode');
        $instituteId = $request->query('institute_id');
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 12);

        $now = now();

        $query = Workshop::query()
            ->with(['institute:id,name'])
            ->where('is_published', true);

        if ($type === 'upcoming') {
            $query->where('start_at', '>=', $now)->orderBy('start_at', 'asc');
        } elseif ($type === 'past') {
            $query->where('start_at', '<', $now)->orderBy('start_at', 'desc');
        } else {
            $query->orderBy('start_at', 'desc');
        }

        if (!is_null($featured)) {
            $query->where('is_featured', (bool) $featured);
        }

        if (!empty($mode)) {
            $query->where('workshop_mode', $mode);
        }

        if (!empty($instituteId)) {
            $query->where('institute_id', $instituteId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('speaker_name', 'like', "%{$search}%");
            });
        }

        $user = $request->user('web_user');

        $workshops = $query->paginate($perPage);

        $workshops->getCollection()->transform(function ($workshop) use ($user) {
            $isRegistered = false;

            if ($user) {
                $isRegistered = WorkshopRegistration::where('workshop_id', $workshop->id)
                    ->where('user_id', $user->id)
                    ->exists();
            }

            return [
                'id' => $workshop->id,
                'title' => $workshop->title,
                'slug' => $workshop->slug,
                'short_description' => $workshop->short_description,
                'cover_image' => $workshop->cover_image,
                'workshop_mode' => $workshop->workshop_mode,
                'institute_name' => $workshop->institute?->name,
                'speaker_name' => $workshop->speaker_name,
                'start_at' => optional($workshop->start_at)->toDateTimeString(),
                'end_at' => optional($workshop->end_at)->toDateTimeString(),
                'location' => $workshop->location,
                'seat_limit' => $workshop->seat_limit,
                'registered_count' => $workshop->registered_count,
                'is_registration_open' => (bool) $workshop->is_registration_open,
                'registration_deadline' => optional($workshop->registration_deadline)->toDateTimeString(),
                'is_registered' => $isRegistered,
            ];
        });

        return response()->json($workshops);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $workshop = Workshop::with([
            'institute:id,name',
            'prerequisites:id,workshop_id,title,description,is_required,display_order',
        ])
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $user = $request->user('web_user');

        $isRegistered = false;
        if ($user) {
            $isRegistered = WorkshopRegistration::where('workshop_id', $workshop->id)
                ->where('user_id', $user->id)
                ->exists();
        }

        $seatsLeft = is_null($workshop->seat_limit)
            ? null
            : max(0, $workshop->seat_limit - $workshop->registered_count);

        $registrationDeadlinePassed = !is_null($workshop->registration_deadline)
            && now()->greaterThan($workshop->registration_deadline);

        $canRegister = $workshop->is_registration_open
            && !$registrationDeadlinePassed
            && (!$workshop->seat_limit || $workshop->registered_count < $workshop->seat_limit)
            && !$isRegistered;

        $canViewRecording = $this->canViewRecording($workshop, $isRegistered, $user !== null);
        $canTakeQuiz = $workshop->quiz_enabled && ($isRegistered || $user !== null);

        return response()->json([
            'id' => $workshop->id,
            'title' => $workshop->title,
            'slug' => $workshop->slug,
            'short_description' => $workshop->short_description,
            'description' => $workshop->description,
            'cover_image' => $workshop->cover_image,
            'og_image' => $workshop->og_image,
            'workshop_mode' => $workshop->workshop_mode,
            'institute' => $workshop->institute
                ? [
                    'id' => $workshop->institute->id,
                    'name' => $workshop->institute->name,
                ]
                : null,
            'speaker_name' => $workshop->speaker_name,
            'speaker_designation' => $workshop->speaker_designation,
            'start_at' => optional($workshop->start_at)->toDateTimeString(),
            'end_at' => optional($workshop->end_at)->toDateTimeString(),
            'location' => $workshop->location,
            'meeting_link' => $canViewRecording ? $workshop->meeting_link : null,
            'seat_limit' => $workshop->seat_limit,
            'registered_count' => $workshop->registered_count,
            'seats_left' => $seatsLeft,
            'registration_deadline' => optional($workshop->registration_deadline)->toDateTimeString(),
            'is_registration_open' => (bool) $workshop->is_registration_open,
            'recording_url' => $canViewRecording ? $workshop->recording_url : null,
            'recording_access' => $workshop->recording_access,
            'quiz_enabled' => (bool) $workshop->quiz_enabled,
            'prerequisites' => $workshop->prerequisites->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'is_required' => (bool) $item->is_required,
                    'display_order' => $item->display_order,
                ];
            })->values(),
            'is_registered' => $isRegistered,
            'can_register' => $canRegister,
            'can_view_recording' => $canViewRecording,
            'can_take_quiz' => $canTakeQuiz,
        ]);
    }

    public function register(Request $request, int $id): JsonResponse
    {
        $user = $request->user('web_user');

        $workshop = Workshop::where('id', $id)
            ->where('is_published', true)
            ->firstOrFail();

        $registrationDeadlinePassed = !is_null($workshop->registration_deadline)
            && now()->greaterThan($workshop->registration_deadline);

        if (!$workshop->is_registration_open) {
            return response()->json([
                'message' => 'Workshop registration is closed.',
            ], 422);
        }

        if ($registrationDeadlinePassed) {
            return response()->json([
                'message' => 'Registration deadline has passed.',
            ], 422);
        }

        if (!is_null($workshop->seat_limit) && $workshop->registered_count >= $workshop->seat_limit) {
            return response()->json([
                'message' => 'No seats are available for this workshop.',
            ], 422);
        }

        $alreadyRegistered = WorkshopRegistration::where('workshop_id', $workshop->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyRegistered) {
            return response()->json([
                'message' => 'You are already registered for this workshop.',
            ], 422);
        }

        DB::transaction(function () use ($workshop, $user) {
            WorkshopRegistration::create([
                'workshop_id' => $workshop->id,
                'user_id' => $user->id,
                'status' => 'registered',
                'registered_at' => now(),
            ]);

            $workshop->increment('registered_count');
        });

        return response()->json([
            'message' => 'Workshop registered successfully.',
            'data' => [
                'workshop_id' => $workshop->id,
                'status' => 'registered',
                'registered_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    public function unregister(Request $request, int $id): JsonResponse
    {
        $user = $request->user('web_user');

        $workshop = Workshop::findOrFail($id);

        $registration = WorkshopRegistration::where('workshop_id', $workshop->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$registration) {
            return response()->json([
                'message' => 'You are not registered for this workshop.',
            ], 422);
        }

        if ($registration->status === 'attended') {
            return response()->json([
                'message' => 'Attendance is already marked. Registration cannot be cancelled now.',
            ], 422);
        }

        DB::transaction(function () use ($registration, $workshop) {
            $registration->update([
                'status' => 'cancelled',
            ]);

            if ($workshop->registered_count > 0) {
                $workshop->decrement('registered_count');
            }
        });

        return response()->json([
            'message' => 'Workshop registration cancelled successfully.',
        ]);
    }

    private function canViewRecording(Workshop $workshop, bool $isRegistered, bool $isLoggedIn): bool
    {
        if (empty($workshop->recording_url)) {
            return false;
        }

        return match ($workshop->recording_access) {
            'public' => true,
            'logged_in' => $isLoggedIn,
            'registered_only' => $isRegistered,
            default => false,
        };
    }
}