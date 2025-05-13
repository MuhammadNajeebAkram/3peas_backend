<?php

namespace App\Http\Controllers;

use App\Models\Institute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstituteController extends Controller
{
    //
    public function saveInstitute(Request $request){
        try{
            $validated = $request->validate([
                'name' => 'required|string',
                'city_id' => 'required|numeric|exists:city_tbl,id',
                'address' => 'string',
                'phone' => 'string',
                'sector' => 'required|string',
                'activate' => 'required|boolean',
            ]);

            Institute::create($validated);

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

    public function updateInstitute(Request $request){
        try{
            $validated = $request->validate([
                'id' => 'required|numeric|exists:institute_tbl,id',
                'name' => 'required|string',
                'city_id' => 'required|numeric|exists:city_tbl,id',
                'address' => 'string',
                'phone' => 'string',
                'sector' => 'required|string',
                'activate' => 'required|boolean',
            ]);

            $institute = Institute::findOrFail($validated['id']);
            $institute->update($validated);

            
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

    public function getInstitutes(Request $request){
        try{
            $institute = DB::table('institute_tbl')
            ->where(
                'activate', '=', $request -> status,               
            )
            ->where('city_id', '=', $request -> city_id)
            ->select(['id', 'name'])
            ->orderBy('name', 'asc')
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

    public function getInstitutesByCity($city_id){
        try{
            $data = Institute::where('city_id', $city_id)
            ->get();

            return response()->json([
                'success' => 1,
                'data' => $data,
            ], 200);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e -> getMessage(),
            ]);

        }
    }
}
