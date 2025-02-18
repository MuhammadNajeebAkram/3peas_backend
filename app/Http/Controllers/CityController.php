<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CityController extends Controller
{
    
    //
    public function getCities(Request $request){
        try{
            $city = DB::table('city_tbl')
            ->where('activate', '=', $request -> status)
            ->select(['id', 'name'])
            ->get();

            return response()->json([
                'success' => 1,
                'city' => $city,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e -> getMessage(),
            ]);

        }
    }
}
