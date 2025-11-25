<?php
namespace App\Http\Services;

use App\Models\Book;
use Illuminate\Support\Facades\Log; // Added for debugging
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\TopicContentStructure;
use App\Models\TopicContentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;


class BookService {
    private WebUserService  $webUserService;
    public function __construct(WebUserService $webUserService) {
        // Constructor logic if needed
        $this->webUserService = $webUserService;
    }
    public function getUnitsOfBook($book_id, $alp, $activate){
        try{

            /*
            $units = DB::table('book_unit_tbl')
            ->where('book_id', '=', $book_id)
            ->where('activate', '=', 1)
            ->select([
                'id',
                'unit_name'
            ])
            ->get();*/
            $units = [];

            if($alp == 2 && $activate == 2){
                $units = BookUnit::where('book_id', $book_id)                
                ->get();
            }
            else if($alp == 2 && $activate < 2){
                $units = BookUnit::where('book_id', $book_id) 
                ->where('activate', $activate)               
                ->get();
            }
             else if($alp < 2 && $activate == 2){
                $units = BookUnit::where('book_id', $book_id) 
                ->where('is_alp', $alp)               
                ->get();
            }
            else{
                $units = BookUnit::where('book_id', $book_id)
                ->where('activate', $activate)
                ->where('is_alp', $alp)
                ->get();
            }

            return response()->json([
                'success' => 1,
                'units' => $units
            ]);

        }
       catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getUnitsOfBook of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getUnitsOfBook of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
    }

    public function getUnitsOfUserSelectedBook($request){
        try{
            $userClass = $this->webUserService->getUserClass($request);
        $class_id = $userClass->original['data']->class_id;
        $board_id = $userClass->original['data']->curriculum_board_id;
        $subject_id = $request->subject_id;
        $book = Book::where('class_id', $class_id)
                ->where('curriculum_board_id', $board_id)
                ->where('subject_id', $subject_id)
                ->where('activate', 1)
                ->first();

                $is_alp = $request->is_alp ?? 2;
                $activate = $request->activate ?? 2;

                

                $units = $this->getUnitsOfBook($book->id, $is_alp, $activate);

               $unitsData = $units->getData();

               if($unitsData->success == 1){
                 return response()->json([
                'success' => 1,
                'units' => $unitsData->units,
            ]);

               }
               else{
                    return response()->json([
                        'success' => 0,
                        'message' => $unitsData->message,
                    ]);
               }

                
            

        }catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getUnitsOfUserSelectedBook of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getUnitsOfUserSelectedBook of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
        


    }

    

    public function saveUnit($request){
        try{
             $validatedData = $request->validate([
            'book_id' => 'required|integer|exists:book_tbl,id',
            
            // CORRECTED: Only scope the uniqueness check by the book_id
            'unit_name' => [
                'required', 
                'string', 
                'max:255',
                Rule::unique('book_unit_tbl', 'unit_name')->where(function ($query) use ($request) {
                    return $query->where('book_id', $request->book_id);
                }),
            ],            
            'unit_no' => 'required|integer',
            'activate' => 'required|boolean',
            'is_alp' => 'required|boolean',
        ]);
        BookUnit::create($validatedData);
        
            return response()->json([
                'success' => 1, // Successfully inserted
                'message' => 'Unit saved successfully.'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
            'success' => 2, // Duplicate entry         
            'message' => $e->errors() // Includes specific validation errors
        ], 422);

        }catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in saveUnit of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in saveUnit of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
       
       
    }

    public function updateUnit($request, $unit_id){
        try{
            $validatedData = $request->validate([
                'unit_name' => [
                    'required', 
                    'string', 
                    'max:255',
                    Rule::unique('book_unit_tbl', 'unit_name')->ignore($unit_id)->where(function ($query) use ($request) {
                        return $query->where('book_id', $request->book_id);
                    }),
                ],
                'unit_no' => 'required|integer',                
                'is_alp' => 'required|boolean',
            ]);

            $unit = BookUnit::findOrFail($unit_id);
            $unit->update($validatedData);

            return response()->json([
                'success' => 1,
                'message' => 'Unit updated successfully.'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 2, // Duplicate entry         
                'message' => $e->errors() // Includes specific validation errors
            ], 422);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Database error in updateUnit of BookService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => 0,
                'message' => 'Database error: ' . $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::error('General exception in updateUnit of BookService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => 0,
                'message' => 'Unexpected error: ' . $e->getMessage(),
            ], 500);
        }
    }

    //----------------------------- Topics ----------------------------//

    public function getTopicsOfUnit($unit_id, $alp, $activate){
        try{

            /*
            $units = DB::table('book_unit_tbl')
            ->where('book_id', '=', $book_id)
            ->where('activate', '=', 1)
            ->select([
                'id',
                'unit_name'
            ])
            ->get();*/
            $topics = [];

            if($alp == 2 && $activate == 2){
                $topics = BookUnitTopic::where('unit_id', $unit_id)                
                ->get();
            }
            else if($alp == 2 && $activate < 2){
                $topics = BookUnitTopic::where('unit_id', $unit_id) 
                ->where('activate', $activate)               
                ->get();
            }
             else if($alp < 2 && $activate == 2){
                $topics = BookUnitTopic::where('unit_id', $unit_id) 
                ->where('is_alp', $alp)               
                ->get();
            }
            else{
                $topics = BookUnitTopic::where('unit_id', $unit_id)
                ->where('activate', $activate)
                ->where('is_alp', $alp)
                ->get();
            }

            return response()->json([
                'success' => 1,
                'topics' => $topics
            ]);

        }
       catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getTopicsOfUnit of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getTopicsOfUnit of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
    }

    public function saveTopic($request){
        DB::beginTransaction(); // Start transaction
        try{
             $validatedData = $request->validate([
            'unit_id' => 'required|integer|exists:book_unit_tbl,id',
            
            // CORRECTED: Only scope the uniqueness check by the book_id
            'topic_name' => [
                'required', 
                'string', 
                'max:255',
                Rule::unique('book_unit_topic_tbl', 'topic_name')->where(function ($query) use ($request) {
                    return $query->where('unit_id', $request->unit_id);
                }),
            ],  
            'topic_name_um' => [                 
                'string', 
                'max:255',
                Rule::unique('book_unit_topic_tbl', 'topic_name_um')->where(function ($query) use ($request) {
                    return $query->where('unit_id', $request->unit_id);
                }),
            ],                       
            'topic_no' => 'required|integer',
            'activate' => 'required|boolean',
            'is_alp' => 'required|boolean',
        ]);
        $topic = BookUnitTopic::create($validatedData);
        
            return response()->json([
                'success' => 1, // Successfully inserted
                'message' => 'Topic saved successfully.'
            ]);

            foreach($request->contents as $content){
                $data = [
                    'topic_id' => $topic->id,
                    'topic_content_type_id' => $content,                   
                ];
                TopicContentType::create($data);
            }

            DB::commit();

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
            'success' => 2, // Duplicate entry         
            'message' => $e->errors() // Includes specific validation errors
        ], 422);

        }catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
        Log::error('Database error in saveTopic of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('General exception in saveTopic of BookService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
       
       
    }

    public function updateTopic($request, $topic_id){
        DB::beginTransaction();
        try{
            $validatedData = $request->validate([                
                'topic_name' => [
                    'required', 
                    'string', 
                    'max:255',
                    Rule::unique('book_unit_topic_tbl', 'topic_name')->ignore($topic_id)->where(function ($query) use ($request) {
                        return $query->where('unit_id', $request->unit_id);
                    }),
                ],
                'topic_name_um' => [ 
                    'nullable', // Make this field optional               
                    'string', 
                    'max:255',
                    Rule::unique('book_unit_topic_tbl', 'topic_name_um')->ignore($topic_id)->where(function ($query) use ($request) {
                        return $query->where('unit_id', $request->unit_id);
                    }),
                ],
                'topic_no' => 'required|integer',                
                'is_alp' => 'required|boolean',
            ]);

            $topic = BookUnitTopic::findOrFail($topic_id);
            $topic->update($validatedData);

            $topicContents = TopicContentStructure::where('topic_id', $topic_id)->get();
            $topicContents->each(function ($content) {
                $content->delete();
            });

             foreach($request->contents as $content){
                $data = [
                    'topic_id' => $topic_id,
                    'topic_content_type_id' => $content,                   
                ];
                TopicContentStructure::create($data);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Topic updated successfully.'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => 2, // Duplicate entry         
                'message' => $e->errors() // Includes specific validation errors
            ], 422);

        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            Log::error('Database error in updateTopic of BookService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => 0,
                'message' => 'Database error: ' . $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('General exception in updateTopic of BookService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => 0,
                'message' => 'Unexpected error: ' . $e->getMessage(),
            ], 500);
        }
    }
}