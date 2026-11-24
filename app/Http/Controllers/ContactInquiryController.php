<?php

namespace App\Http\Controllers;

use App\Models\ContactInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactInquiryController extends Controller
{
    /**
     * Submit a new contact inquiry or publishing request.
     */
    public function submit(Request $request): JsonResponse
    {
        // Normalize payload keys (support both snake_case and camelCase)
        $normalizedData = [
            'type'         => strtolower(trim($request->input('type', $request->input('category', 'general')))),
            'full_name'    => trim($request->input('full_name', $request->input('fullName', ''))),
            'email'        => trim($request->input('email', '')),
            'phone'        => $request->input('phone') ? trim($request->input('phone')) : null,
            'organization' => $request->input('organization') ? trim($request->input('organization')) : null,
            'subject'      => trim($request->input('subject', $request->input('topic', ''))),
            'message'      => trim($request->input('message', $request->input('summary', ''))),
        ];

        $validator = Validator::make($normalizedData, [
            'type'         => 'required|string|in:publishing,general,suggestion,complaint',
            'full_name'    => 'required|string|min:2|max:191',
            'email'        => 'required|email:filter|max:191',
            'phone'        => 'nullable|string|max:50',
            'organization' => 'nullable|string|max:191',
            'subject'      => 'nullable|string|max:255',
            'message'      => 'required|string|min:5|max:10000',
        ], [
            'full_name.required' => 'Full name is required.',
            'email.required'     => 'A valid email address is required.',
            'email.email'        => 'Please provide a valid email format.',
            'message.required'   => 'Message or summary content is required.',
            'message.min'        => 'Message must be at least 5 characters long.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify the submitted details.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $inquiry = ContactInquiry::create([
            'type'         => $normalizedData['type'],
            'full_name'    => $normalizedData['full_name'],
            'email'        => $normalizedData['email'],
            'phone'        => $normalizedData['phone'],
            'organization' => $normalizedData['organization'],
            'subject'      => $normalizedData['subject'] ?: null,
            'message'      => $normalizedData['message'],
            'status'       => 'pending',
            'ip_address'   => $request->ip(),
            'user_agent'   => substr((string) $request->header('User-Agent'), 0, 500),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your inquiry has been submitted successfully. Our team will review it shortly.',
            'data'    => [
                'id'         => $inquiry->id,
                'type'       => $inquiry->type,
                'created_at' => $inquiry->created_at->toISOString(),
            ],
        ], 201);
    }
}
