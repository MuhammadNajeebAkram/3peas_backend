<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsTicker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NewsTickerController extends Controller
{
    public function getActiveTickersForWeb(Request $request)
    {
        try {
            $now = now();

            $tickers = NewsTicker::query()
                ->with(['news:id,title,slug'])
                ->where('is_active', true)
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('start_time')
                        ->orWhere('start_time', '<=', $now);
                })
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('end_time')
                        ->orWhere('end_time', '>=', $now);
                })
                ->orderBy('display_order')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($ticker) {
                    return [
                        'id' => $ticker->id,
                        'news_id' => $ticker->news_id,
                        'news_title' => $ticker->news?->title,
                        'news_slug' => $ticker->news?->slug,
                        'ticker_text' => $ticker->ticker_text,
                        'ticker_link' => $ticker->ticker_link,
                        'display_order' => $ticker->display_order,
                        'start_time' => optional($ticker->start_time)->format('Y-m-d\TH:i'),
                        'end_time' => optional($ticker->end_time)->format('Y-m-d\TH:i'),
                    ];
                });

            return response()->json([
                'success' => 1,
                'data' => $tickers,
            ]);
        } catch (\Throwable $e) {
            Log::error('getActiveTickersForWeb failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve ticker feed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAllTickersForAdmin(Request $request)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $query = NewsTicker::query()
                ->with(['news:id,title'])
                ->orderBy('display_order')
                ->orderByDesc('created_at');

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($builder) use ($search) {
                    $builder->where('ticker_text', 'like', "%{$search}%")
                        ->orWhere('ticker_link', 'like', "%{$search}%")
                        ->orWhereHas('news', function ($newsQuery) use ($search) {
                            $newsQuery->where('title', 'like', "%{$search}%");
                        });
                });
            }

            $tickers = $query->get()->map(function ($ticker) {
                return $this->transformTicker($ticker);
            });

            return response()->json([
                'success' => 1,
                'data' => $tickers,
            ]);
        } catch (\Throwable $e) {
            Log::error('getAllTickersForAdmin failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve tickers.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveTickerForAdmin(Request $request)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $validated = $request->validate([
                'news_id' => 'nullable|integer|exists:news,id',
                'ticker_text' => 'required|string|max:255',
                'ticker_link' => 'nullable|string|max:255',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'start_time' => 'nullable|date',
                'end_time' => 'nullable|date|after_or_equal:start_time',
            ]);

            DB::beginTransaction();

            $ticker = NewsTicker::create([
                'news_id' => $validated['news_id'] ?? null,
                'ticker_text' => $validated['ticker_text'],
                'ticker_link' => $validated['ticker_link'] ?? null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
                'display_order' => $validated['display_order'] ?? 0,
                'start_time' => $validated['start_time'] ?? null,
                'end_time' => $validated['end_time'] ?? null,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Ticker saved successfully.',
                'data' => $this->transformTicker($ticker->load('news:id,title')),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => 0,
                'message' => 'Failed to save ticker.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateTickerForAdmin(Request $request, $id)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $ticker = NewsTicker::findOrFail($id);

            $validated = $request->validate([
                'news_id' => 'nullable|integer|exists:news,id',
                'ticker_text' => 'required|string|max:255',
                'ticker_link' => 'nullable|string|max:255',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'start_time' => 'nullable|date',
                'end_time' => 'nullable|date|after_or_equal:start_time',
            ]);

            DB::beginTransaction();

            $ticker->update([
                'news_id' => $validated['news_id'] ?? null,
                'ticker_text' => $validated['ticker_text'],
                'ticker_link' => $validated['ticker_link'] ?? null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $ticker->is_active,
                'display_order' => $validated['display_order'] ?? $ticker->display_order,
                'start_time' => $validated['start_time'] ?? null,
                'end_time' => $validated['end_time'] ?? null,
                'updated_by' => $admin->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Ticker updated successfully.',
                'data' => $this->transformTicker($ticker->fresh()->load('news:id,title')),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => 0,
                'message' => 'Failed to update ticker.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteTickerForAdmin(Request $request, $id)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $ticker = NewsTicker::findOrFail($id);
            $ticker->delete();

            return response()->json([
                'success' => 1,
                'message' => 'Ticker deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to delete ticker.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getNewsOptionsForTicker(Request $request)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $news = News::query()
                ->select(['id', 'title'])
                ->orderByDesc('created_at')
                ->limit(200)
                ->get();

            return response()->json([
                'success' => 1,
                'data' => $news,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve news options.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function transformTicker(NewsTicker $ticker)
    {
        return [
            'id' => $ticker->id,
            'news_id' => $ticker->news_id,
            'news_title' => $ticker->news?->title,
            'ticker_text' => $ticker->ticker_text,
            'ticker_link' => $ticker->ticker_link,
            'is_active' => (bool) $ticker->is_active,
            'display_order' => $ticker->display_order,
            'start_time' => optional($ticker->start_time)->format('Y-m-d\TH:i'),
            'end_time' => optional($ticker->end_time)->format('Y-m-d\TH:i'),
            'created_at' => optional($ticker->created_at)->toDateTimeString(),
            'updated_at' => optional($ticker->updated_at)->toDateTimeString(),
        ];
    }
}
