<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\OfferedProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                'curriculum_board_id' => $request->curriculum_board_id,
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
                  'curriculum_board_id' => $request->curriculum_board_id,
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

    public function getBooksByProgram( $program_id, $subject_id){

        try{
            $program = OfferedProgram::with('offeredClass:id,class_id,curriculum_board_id')
            ->find($program_id);

            if (!$program || !$program->offeredClass) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Program not found',
                ], 404);
            }


            $books = Book::with('units')             
             ->where('activate', '=', 1)
            ->where('class_id', '=', $program->offeredClass->class_id)
            ->where('curriculum_board_id', '=', $program->offeredClass->curriculum_board_id)
            ->where('subject_id', '=', $subject_id )
            ->get();

            return response()->json($books);

        }
        catch(\Exception $e){
            Log::error("Failed to retrieve chapters for program_id: " . $program_id . ", subject_id: " . $subject_id, ['error_message' => $e->getMessage()]);
            return response()->json([
                'success' => 0,
                'chapters' => 'Failed to retrieve chapters'], 500);

        }
    }

    public function getBooksForAdmin()
    {
        try {
            $books = Book::with([
                'userClass:id,class_name',
                'subject:id,subject_name',
                'curriculmBoard:id,name',
            ])
                ->orderBy('book_name')
                ->get()
                ->map(function ($book) {
                    return [
                        'id' => $book->id,
                        'book_name' => $book->book_name,
                        'class_id' => $book->class_id,
                        'class_name' => $book->userClass?->class_name,
                        'subject_id' => $book->subject_id,
                        'subject_name' => $book->subject?->subject_name,
                        'curriculum_board_id' => $book->curriculum_board_id,
                        'curriculum_board_name' => $book->curriculmBoard?->name,
                        'activate' => (bool) $book->activate,
                    ];
                });

            return response()->json([
                'success' => 1,
                'books' => $books,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActiveBooksForAdmin()
    {
        try {
            $books = Book::with([
                'userClass:id,class_name',
                'subject:id,subject_name',
                'curriculmBoard:id,name',
            ])
                ->where('activate', 1)
                ->orderBy('book_name')
                ->get()
                ->map(function ($book) {
                    return [
                        'id' => $book->id,
                        'book_name' => $book->book_name,
                        'class_id' => $book->class_id,
                        'class_name' => $book->userClass?->class_name,
                        'subject_id' => $book->subject_id,
                        'subject_name' => $book->subject?->subject_name,
                        'curriculum_board_id' => $book->curriculum_board_id,
                        'curriculum_board_name' => $book->curriculmBoard?->name,
                    ];
                });

            return response()->json([
                'success' => 1,
                'books' => $books,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveBookForAdmin(Request $request)
    {
        $request->validate([
            'book_name' => 'required|string|max:255|unique:book_tbl,book_name',
            'class_id' => 'required|integer|exists:class_tbl,id',
            'subject_id' => 'required|integer|exists:subject_tbl,id',
            'curriculum_board_id' => 'required|integer|exists:curriculum_board_tbl,id',
        ]);

        try {
            Book::create([
                'book_name' => $request->book_name,
                'class_id' => $request->class_id,
                'subject_id' => $request->subject_id,
                'curriculum_board_id' => $request->curriculum_board_id,
                'activate' => 1,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Book saved successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateBookForAdmin(Request $request, $id)
    {
        $request->validate([
            'book_name' => 'required|string|max:255|unique:book_tbl,book_name,' . $id,
            'class_id' => 'required|integer|exists:class_tbl,id',
            'subject_id' => 'required|integer|exists:subject_tbl,id',
            'curriculum_board_id' => 'required|integer|exists:curriculum_board_tbl,id',
        ]);

        try {
            $book = Book::findOrFail($id);
            $book->book_name = $request->book_name;
            $book->class_id = $request->class_id;
            $book->subject_id = $request->subject_id;
            $book->curriculum_board_id = $request->curriculum_board_id;

            if ($request->has('activate')) {
                $book->activate = $request->activate;
            }

            $book->save();

            return response()->json([
                'success' => 1,
                'message' => 'Book updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activateBookForAdmin(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:book_tbl,id',
            'activate' => 'required|boolean',
        ]);

        try {
            $book = Book::findOrFail($request->id);
            $book->activate = $request->activate;
            $book->save();

            return response()->json([
                'success' => 1,
                'message' => 'Book status updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
