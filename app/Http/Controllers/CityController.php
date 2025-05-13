<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CityController extends Controller
{
    
    //

    public function saveCity(Request $request){
        try{
            $validated = $request->validate([
                'name' => 'required|string',
                'district_id' => 'required|numeric|exists:districts,id',
                'activate' => 'required|boolean',
            ]);

            City::create($validated);

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

    public function getCities(Request $request){
        try{
            $city = DB::table('city_tbl')
            ->where('activate', '=', $request -> status)
            ->select(['id', 'name'])
            ->orderBy('name', 'asc')
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
    public function getAllCities(){
        try{
            $data = City::where('activate', 1)
            ->select('id', 'name')
            ->orderBy('name', 'asc')
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
    public function getCitiesByDistrict($district){
        try{

            $data = City::where('district_id', $district)
            ->orderBy('name', 'asc')
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
