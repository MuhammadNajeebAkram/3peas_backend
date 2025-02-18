<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'error' => $e.getMessage(),
            ]);

        }
    }
}
