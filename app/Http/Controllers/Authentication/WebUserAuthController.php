<?php

namespace App\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Services\Authentication\WebUserAuhService;
use App\Http\Services\WebUserService;
use Illuminate\Http\Request;

class WebUserAuthController extends Controller
{
    protected WebUserAuhService $webUserAuthService;
    protected WebUserService $webUserService;
    public function __construct()
    {
        $this->webUserAuthService = new WebUserAuhService();
        $this->webUserService = new WebUserService();
       
    }
    public function login(Request $request){
        $status = $this->webUserAuthService->login($request);
        $statusData = $status->getData();

        if($statusData->success == 0){
             return response()->json([
                'success' => 0,
                'error' => 'Unauthorized'
            ], 401);
        }

        return response()->json([
            'success' => 1,
            'token' => $statusData->token,
            'expires_in' => $statusData->expires_in,
        ]);

    }

    public function getStatus(Request $request){

        $user = $request->user();

        $userClass = $this->webUserService->getUserClass($request);
        $userClassData = $userClass->getData();       

         return response()->json([
            'success' => 1,
            'message' => 'Login successful',
                   
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'classInfo' => $userClassData->success === 1 ? $userClassData->data : [] ,
        ]);
    }
    
}
