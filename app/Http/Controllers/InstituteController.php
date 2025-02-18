<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstituteController extends Controller
{
    //
    public function getInstitutes(Request $request){
        try{
            $institute = DB::table('institute_tbl')
            ->where(
                'activate', '=', $request -> status,               
            )
            ->where('city_id', '=', $request -> city_id)
            ->select(['id', 'name'])
            ->get();

            return response()->json([
                'success' => 1,
                'institute' => $institute,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e -> getMessage(),
            ]);

        }
    }
}
