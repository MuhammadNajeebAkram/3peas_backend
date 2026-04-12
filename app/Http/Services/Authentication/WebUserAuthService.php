<?php


namespace App\Http\Services\Authentication;

use App\Models\WebUser;
use App\Models\WebUserProfile;
use Google\Client as GoogleClient;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Throwable;

use function Symfony\Component\Clock\now;

class WebUserAuthService
{
    private string $guard = 'web_api';

    public function googleLogin(string $idToken)
    {
        try {
            $payload = $this->verifyGoogleIdToken($idToken);

            $googleId = $payload['sub'] ?? null;
            $email = $payload['email'] ?? null;
            $emailVerified = (bool) ($payload['email_verified'] ?? false);
            $name = $payload['name'] ?? 'Google User';
            $avatar = $payload['picture'] ?? null;
            $locale = $payload['locale'] ?? 'en';

            if (!$googleId || !$email) {
                return response()->json([
                    'message' => 'Invalid Google account data.',
                ], 422);
            }

            if (!$emailVerified) {
                return response()->json([
                    'message' => 'Google email is not verified.',
                ], 422);
            }

            $user = DB::transaction(function () use ($googleId, $email, $name, $avatar, $locale) {
                $user = WebUser::where('google_id', $googleId)->first();

                if (!$user) {
                    $user = WebUser::where('email', $email)->lockForUpdate()->first();
                }

                if (!$user) {
                    $user = WebUser::create([
                        'name' => $name,
                        'email' => $email,
                        'google_id' => $googleId,
                        'avatar' => $avatar,
                        'login_provider' => 'google',
                        'status' => 'active',
                    ]);
                } else {
                    $conflict = WebUser::where('google_id', $googleId)
                        ->where('id', '!=', $user->id)
                        ->exists();

                    if ($conflict) {
                        throw new HttpResponseException(
                            response()->json([
                                'message' => 'This Google account is already linked to another user.',
                            ], 409)
                        );
                    }

                    $user->update([
                        'name' => $user->name ?: $name,
                        'google_id' => $googleId,
                        'avatar' => $avatar,
                        'login_provider' => $user->login_provider ?: 'google',
                        'status' => 'active',
                        'email_verified_at' => $user->email_verified_at ?: now(),
                    ]);
                }

                WebUserProfile::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'preferred_language' => $locale,
                        'profile_completed' => false,
                    ]
                );

                return $user->fresh(['profile']);
            });

            $token = Auth::guard($this->guard)->login($user);

            return $this->authResponse($user, $token, 'Google login successful.');
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (Throwable $e) {
            Log::error('Google login failed', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Google login failed. Please try again.',
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = trim($request->input('login'));
        $password = $request->input('password');

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $credentials = [
            $field => $login,
            'password' => $password,
        ];

            $token = Auth::guard($this->guard)->attempt($credentials);

            if (!$token) {
                return response()->json([
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            /** @var WebUser|null $user */
            $user = Auth::guard($this->guard)->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unable to authenticate user.',
                ], 401);
            }

            if (($user->status ?? 'active') !== 'active') {
                Auth::guard($this->guard)->logout();

                return response()->json([
                    'message' => 'Your account is inactive.',
                ], 403);
            }

            $user->load(['profile']);

            return $this->authResponse($user, $token, 'Login successful.');
        } catch (Throwable $e) {
            Log::error('Email login failed', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Login failed. Please try again.',
            ], 500);
        }
    }

    public function me()
    {
        try {
            /** @var WebUser|null $user */
            $user = Auth::guard($this->guard)->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized',
                ], 401);
            }

            $user->load(['profile']);

            return response()->json([
                'user' => $this->userPayload($user),
                'profile_complete' => (bool) optional($user->profile)->profile_completed,
            ]);
        } catch (Throwable $e) {
            Log::error('Fetch current user failed', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Could not fetch user profile.',
            ], 500);
        }
    }

    public function logout()
    {
        try {
            $token = JWTAuth::getToken();

            if ($token) {
                JWTAuth::invalidate($token);
            }
        } catch (Throwable $e) {
            Log::warning('Logout token invalidate failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ])->withoutCookie(
            $this->cookieName(),
            '/',
            $this->cookieDomain()
        );
    }

    private function verifyGoogleIdToken(string $idToken): array
    {
        $client = new GoogleClient([
            'client_id' => config('services.google.client_id'),
        ]);

        $payload = $client->verifyIdToken($idToken);

        if (!$payload) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Invalid Google ID token.',
                ], 401)
            );
        }

        return $payload;
    }

    private function authResponse(WebUser $user, string $token, string $message = 'Authenticated successfully.')
    {
        $user->load(['profile']);

        return response()->json([
            'message' => $message,
            'user' => $this->userPayload($user),
            'profile_complete' => (bool) optional($user->profile)->profile_completed,
        ])->cookie(
            $this->cookieName(),
            $token,
            60 * 24 * 7,
            '/',
            $this->cookieDomain(),
            $this->cookieSecure(),
            true,
            false,
            'Lax'
        );
    }

    private function cookieDomain(): ?string
    {
        if (app()->environment('production')) {
            return 'api.thestudentstimes.com';
        }

        return null;
    }

    private function cookieSecure(): bool
    {
        return app()->environment('production');
    }

    private function cookieName(): string
    {
        return env('LMS_JWT_COOKIE_NAME', 'lms_auth_token');
    }

    private function userPayload(WebUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? optional($user->profile)->phone,
            'avatar' => $user->avatar,
            'login_provider' => $user->login_provider,
            'role' => $user->role,
            'profile' => [
                'city' => optional($user->profile)->city,
                'school_name' => optional($user->profile)->school_name,
                'teacher_referral_code' => optional($user->profile)->teacher_referral_code,
                'preferred_language' => optional($user->profile)->preferred_language,
            ],
        ];
    }

   public function registerWebUser(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:web_users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'role' => ['required', 'string'],               
                'phone' => ['required', 'string'],               
               // 'gender_id' => ['nullable', 'integer'],
               // 'dob' => ['required', 'date'],
               // 'designation' => ['nullable', 'string'],
               // 'heard_about_id' => ['required', 'integer', 'exists:heard_about_tbl,id'],
            ]);

          
            $user = DB::transaction(function () use ($validatedData) {
                $user = WebUser::create([
                    'name' => $validatedData['name'],
                    'email' => $validatedData['email'],
                    'password' => Hash::make($validatedData['password']),
                    'role' => $validatedData['role'],                                       
                    'login_provider' => 'email',
                    'status' => 'active',
                    'phone' => $validatedData['phone'] ?? null,
                ]);

                WebUserProfile::create([
                    'user_id' => $user->id,                   
                    'phone' => $validatedData['phone'] ?? null,
                    //'gender_id' => $validatedData['gender_id'] ?? null,
                    //'dob' => $validatedData['dob'] ?? null,
                   // 'designation' => $validatedData['designation'] ?? null,
                   // 'heard_about_id' => $validatedData['heard_about_id'],                    
                    'profile_completed' => true,
                ]);

                event(new Registered($user));

                return $user->fresh(['profile']);
            });

            $token = Auth::guard($this->guard)->login($user);

            return $this->authResponse($user, $token, 'User registered successfully.');
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['email'])) {
                foreach ($errors['email'] as $msg) {
                    if (
                        str_contains(strtolower($msg), 'already') ||
                        str_contains(strtolower($msg), 'taken') ||
                        str_contains(strtolower($msg), 'unique')
                    ) {
                        return response()->json([
                            'message' => 'Email already exists.',
                            'errors' => $errors,
                        ], 409);
                    }
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        } catch (Throwable $e) {
            Log::error('Web user registration failed', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Registration failed. Please try again.',
            ], 500);
        }
    }
}

