<?php

namespace App\Http\Controllers;

use App\Models\Division;
use Illuminate\Http\Request;

class DivisionController extends Controller
{
    //
    public function saveDivision(Request $request){
        try{
            $validated = $request->validate([
                'name' => 'required|string',
                'province_id' => 'required|numeric|exists:provinces,id',
                'activate' => 'required|boolean',
            ]);

            Division::create($validated);

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

    public function getDivisions($province){
        try{

            $data = Division::where('province_id', $province)
            ->get();

            return response()->json([
                'success' => 1,
                'data' => $data,
            ], 200);

        }catch(\Exception $e){
            return response()->json([
                'success' => -1,
                'message' => $e->getMessage(),
            ], 500);

        }
    }
}
