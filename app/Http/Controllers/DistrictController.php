<?php

namespace App\Http\Controllers;

use App\Models\District;
use Illuminate\Http\Request;

class DistrictController extends Controller
{
    //
    public function saveDistrict(Request $request){
        try{
            $validated = $request->validate([
                'name' => 'required|string',
                'division_id' => 'required|numeric|exists:divisions,id',
                'activate' => 'required|boolean',
            ]);

            District::create($validated);

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

    public function getDistricts($division){
        try{

            $data = District::where('division_id', $division)
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
