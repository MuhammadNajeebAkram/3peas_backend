<?php

namespace App\Http\Controllers;

use App\Models\OfferedProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OfferedProgramController extends Controller
{
    //
    public function getOfferedProgramDetails($slug){
        $offeredProgram = OfferedProgram::with([
            'programSubjects' => function ($query) {
                $query->where('is_active', true)->orderBy('display_order');
            },
            'programSubjects.subject',
            'offeredClass'
        ])
        ->where('slug', $slug)->firstOrFail();

        Log::info("Offered program details retrieved for slug: " . $slug, ['offeredProgram' => $offeredProgram]);
        return response()->json($offeredProgram);
       
            }
}
