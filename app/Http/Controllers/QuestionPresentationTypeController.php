<?php

namespace App\Http\Controllers;

use App\Models\QuestionPresentationType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuestionPresentationTypeController extends Controller
{
    public function getAllPresentationTypes()
    {
        try {
            $presentationTypes = QuestionPresentationType::orderBy('sort_order')
                ->orderBy('type_name')
                ->get([
                    'id',
                    'type_name',
                    'code',
                    'description',
                    'activate',
                    'sort_order',
                ]);

            return response()->json([
                'success' => 1,
                'presentation_types' => $presentationTypes,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve question presentation types.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActivePresentationTypes()
    {
        try {
            $presentationTypes = QuestionPresentationType::where('activate', 1)
                ->orderBy('sort_order')
                ->orderBy('type_name')
                ->get([
                    'id',
                    'type_name',
                    'code',
                    'description',
                    'sort_order',
                ]);

            return response()->json([
                'success' => 1,
                'presentation_types' => $presentationTypes,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve active question presentation types.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function savePresentationType(Request $request)
    {
        $validated = $request->validate([
            'type_name' => ['required', 'string', 'max:255', 'unique:question_presentation_type_tbl,type_name'],
            'code' => ['nullable', 'string', 'max:100', 'unique:question_presentation_type_tbl,code'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'activate' => ['nullable', 'boolean'],
        ]);

        try {
            $presentationType = QuestionPresentationType::create([
                'type_name' => $validated['type_name'],
                'code' => $validated['code'] ?? null,
                'description' => $validated['description'] ?? null,
                'sort_order' => $validated['sort_order'] ?? 0,
                'activate' => $validated['activate'] ?? 1,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Question presentation type saved successfully.',
                'presentation_type' => $presentationType,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to save question presentation type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePresentationType(Request $request, $id)
    {
        $validated = $request->validate([
            'type_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('question_presentation_type_tbl', 'type_name')->ignore($id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('question_presentation_type_tbl', 'code')->ignore($id),
            ],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'activate' => ['nullable', 'boolean'],
        ]);

        try {
            $presentationType = QuestionPresentationType::find($id);

            if (! $presentationType) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Question presentation type not found.',
                ], 404);
            }

            $presentationType->update([
                'type_name' => $validated['type_name'],
                'code' => $validated['code'] ?? null,
                'description' => $validated['description'] ?? null,
                'sort_order' => $validated['sort_order'] ?? 0,
                'activate' => array_key_exists('activate', $validated)
                    ? $validated['activate']
                    : $presentationType->activate,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Question presentation type updated successfully.',
                'presentation_type' => $presentationType,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update question presentation type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activatePresentationType(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'exists:question_presentation_type_tbl,id'],
            'activate' => ['required', 'boolean'],
        ]);

        try {
            $presentationType = QuestionPresentationType::find($validated['id']);
            $presentationType->update([
                'activate' => $validated['activate'],
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Question presentation type status updated successfully.',
                'presentation_type' => $presentationType,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update question presentation type status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
