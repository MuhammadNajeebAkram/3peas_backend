<?php

namespace App\Http\Services\Freemium;

use App\Http\Services\Freemium\Exceptions\IdempotencyConflictException;
use App\Models\AssessmentIdempotencyRecord;
use App\Models\WebUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssessmentIdempotencyService
{
    /**
     * Validate idempotency key format and length.
     *
     * @param mixed $key
     * @return string
     * @throws InvalidArgumentException
     */
    public function validateKey($key): string
    {
        if (!is_string($key) || trim($key) === '') {
            throw new InvalidArgumentException("Missing or empty idempotency key. A non-empty string is required.");
        }

        $trimmed = trim($key);
        if (strlen($trimmed) > 100) {
            throw new InvalidArgumentException("Idempotency key exceeds maximum length of 100 characters.");
        }

        if (!preg_match('/^[A-Za-z0-9_\-:]+$/', $trimmed)) {
            throw new InvalidArgumentException("Idempotency key contains invalid characters. Only alphanumeric, dashes, underscores, and colons are permitted.");
        }

        return $trimmed;
    }

    /**
     * Compute a canonical SHA-256 fingerprint for a request payload.
     * Recursively sorts all keys and normalizes primitive values.
     *
     * @param array $payload
     * @return string
     */
    public function canonicalFingerprint(array $payload): string
    {
        $normalized = $this->normalizeArray($payload);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Recursively sort array keys for canonical JSON serialization.
     */
    protected function normalizeArray(array $arr): array
    {
        ksort($arr);
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $this->normalizeArray($v);
            }
        }
        return $arr;
    }

    /**
     * Execute an assessment generation operation with transactional idempotency guarantees.
     *
     * @param WebUser $student Authenticated student
     * @param string $operation Operation identifier (e.g. 'formal_test_generation')
     * @param string $idempotencyKey Validated idempotency key
     * @param array $requestPayload Normalized request payload
     * @param \Closure $callback Callback returning array ['data' => ..., 'status' => int]
     * @return array Array containing 'payload' => array, 'status' => int, 'replayed' => bool
     *
     * @throws IdempotencyConflictException On fingerprint mismatch with existing key
     * @throws \Throwable On callback execution failure
     */
    public function execute(
        WebUser $student,
        string $operation,
        string $idempotencyKey,
        array $requestPayload,
        \Closure $callback
    ): array {
        $validatedKey = $this->validateKey($idempotencyKey);
        $fingerprint = $this->canonicalFingerprint($requestPayload);

        // 1. Check existing record outside or inside lock
        $existing = AssessmentIdempotencyRecord::where('user_id', $student->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $validatedKey)
            ->first();

        if ($existing) {
            if ($existing->request_fingerprint !== $fingerprint) {
                throw new IdempotencyConflictException(
                    "Idempotency key '{$validatedKey}' was already used with a different request payload."
                );
            }

            if ($existing->status === 'completed') {
                return [
                    'payload'  => $existing->response_payload,
                    'status'   => $existing->http_status,
                    'replayed' => true,
                ];
            }

            if ($existing->status === 'processing') {
                throw new IdempotencyConflictException(
                    "A request with idempotency key '{$validatedKey}' is currently being processed. Please retry shortly."
                );
            }
        }

        // 2. Reserve record with 'processing' status to lock key atomically
        try {
            $record = AssessmentIdempotencyRecord::create([
                'user_id'             => $student->id,
                'operation'           => $operation,
                'idempotency_key'     => $validatedKey,
                'request_fingerprint' => $fingerprint,
                'status'              => 'processing',
                'http_status'         => 200,
            ]);
        } catch (QueryException $e) {
            // Unique key violation uk_user_op_idempotency: concurrent insert won the race
            if (str_contains($e->getMessage(), 'uk_user_op_idempotency') || str_contains($e->getMessage(), '1062')) {
                $concurrent = AssessmentIdempotencyRecord::where('user_id', $student->id)
                    ->where('operation', $operation)
                    ->where('idempotency_key', $validatedKey)
                    ->first();

                if ($concurrent) {
                    if ($concurrent->request_fingerprint !== $fingerprint) {
                        throw new IdempotencyConflictException(
                            "Idempotency key '{$validatedKey}' was already used with a different request payload."
                        );
                    }
                    if ($concurrent->status === 'completed') {
                        return [
                            'payload'  => $concurrent->response_payload,
                            'status'   => $concurrent->http_status,
                            'replayed' => true,
                        ];
                    }
                }
                throw new IdempotencyConflictException(
                    "A concurrent request with idempotency key '{$validatedKey}' is in progress."
                );
            }
            throw $e;
        }

        // 3. Execute the operation callback
        try {
            $result = $callback($record);

            $httpStatus = (int) ($result['status'] ?? 200);
            $responsePayload = $result['payload'] ?? $result;

            $record->update([
                'status'           => 'completed',
                'response_payload' => $responsePayload,
                'http_status'      => $httpStatus,
            ]);

            return [
                'payload'  => $responsePayload,
                'status'   => $httpStatus,
                'replayed' => false,
            ];
        } catch (\Throwable $t) {
            // Delete the processing record so future retries with this key are not blocked by a failed execution
            $record->delete();
            throw $t;
        }
    }
}
