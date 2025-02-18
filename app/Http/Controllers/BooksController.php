<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BooksController extends Controller
{
    //
    public function getAllBooks(Request $request){
        try{
            $books = DB::table('book_view')->get();

            return response()->json([
                'success' => 1,
                'books' => $books
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'books' => 'Failed to retrieve books'], 500);

        }
    }
    public function getBooks(Request $request){
        try{
            $books = DB::table('book_tbl')
            ->where('activate', '=', 1)
            ->select([
                'id',
                'book_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'books' => $books
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'books' => 'Failed to retrieve books'], 500);

        }
    }

    public function getBooksByClassAndSubject(Request $request){
        try{
            $books = DB::table('book_tbl') 
            ->where('activate', '=', 1)
            ->where('class_id', '=', $request -> class_id)
            ->where('subject_id', '=', $request -> subject_id)
            ->select([
                'id',
                'book_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'books' => $books
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'books' => 'Failed to retrieve books'], 500);

        }
    }
    public function saveBook(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('book_tbl')
        ->where('book_name', '=', $request->book_name)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Book Name is already exists.'
            ]);

        }

            $books = DB::table('book_tbl')
            ->insert([
                'book_name' => $request -> book_name,
                'class_id' => $request -> class_id,
                'subject_id' => $request -> subject_id,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => 1 // Successfully inserted
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,  // Error occurred
                'error' => $e->getMessage(),
            ]);

        }
    }
    public function editBook(Request $request){
        try{

            if($request -> book_name != $request -> oldBookName){
                $checkDuplicate = DB::table('book_tbl')
                ->where('book_name', '=', $request->book_name)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Book Name is already exists.'
                    ]); 
                }
        
            }

            $book = DB::table('book_tbl')
         ->where('id', '=', $request -> id)
        ->update(['book_name' => $request -> book_name,
                  'class_id' => $request -> class_id,
                  'subject_id' => $request -> subject_id,
                  'updated_at' => now()]);

                  if ($book) {
                    return response()->json(['success' => 1], 200);
                } else {
                    return response()->json(['success' => 3, 'message' => 'Bad Request'], 400);
                }

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0, // error
                'message' => $e->getMessage(),
            ]);

        }
    }
    public function activateBook(Request $request){
        $editClass = DB::table('book_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }
}
