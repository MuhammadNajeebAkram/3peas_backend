<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HeardAboutController extends Controller
{
    //
    public function getHeardAbout(Request $request){
        try{

            $heard=DB::table('heard_about_tbl')
            ->where('activate', '=', $request -> status)
            ->select('id', 'source')
            ->get();

            return response()->json([
                'success' => 1,
                'heard' => $heard,
            ]);
        }catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getActiveHeardAboutForAdmin(){
        try{
            $heardAbout = DB::table('heard_about_tbl')
            ->where('activate', 1)
            ->select(['id', 'source'])
            ->orderBy('source', 'asc')
            ->get();

            return response()->json([
                'success' => 1,
                'data' => $heardAbout,
            ], 200);
        }
        catch(\Exception $e){
            Log::error('error in getActiveHeardAboutForAdmin: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
