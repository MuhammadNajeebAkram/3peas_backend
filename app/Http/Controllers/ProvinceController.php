<?php

namespace App\Http\Controllers;

use App\Models\Province;
use Illuminate\Http\Request;

class ProvinceController extends Controller
{
    //

    public function saveProvince(Request $request){
        try{
            $validated = $request->validate([
                'name' => 'required|string',
                'activate' => 'required|boolean',
            ]);

            Province::create($validated);

            return response()->json([
                'success' => 1,

            ], 200);

        }catch(\Exception $e){
            return response()->json([
                'success' => -1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getProvinces(){
        try{

            $provinces = Province::get();

            return response()->json([
                'success' => 1,
                'data' => $provinces,
            ], 200);

        }catch(\Exception $e){
            return response()->json([
                'success' => -1,
                'message' => $e->getMessage(),
            ], 500);

        }
    }
}
