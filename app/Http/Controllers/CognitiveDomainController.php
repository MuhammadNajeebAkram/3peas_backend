<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CognitiveDomainController extends Controller
{
    //
    public function getDomain(){
        try{
            $domain = DB::table('cognitive_domain_tbl')
            ->get();

            return response()->json([
                'success' => 1,
                'domain' => $domain,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
