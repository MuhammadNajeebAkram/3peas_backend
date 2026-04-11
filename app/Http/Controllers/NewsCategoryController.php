<?php

namespace App\Http\Controllers;

use App\Models\NewsCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NewsCategoryController extends Controller
{
    //
    public function getAllNewsCategory(Request $request){
        try{
            $news_category = DB::table('news_category_tbl')->get();

            return response()->json([
                'success' => 1,
                'news_category' => $news_category
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news_category' => 'Failed to retrieve news category'], 500);

        }
    }
    public function getActiveNewsCategory(Request $request){
        try{
            $news_category = DB::table('news_category_tbl')
            ->where('activate', '=', 1)
            ->select([
                'id',
                'category_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'news_category' => $news_category
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news_category' => 'Failed to retrieve news category'], 500);

        }
    }

    public function saveCategory(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('news_category_tbl')
        ->where('category_name', '=', $request->category_name)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Category Name is already exists.'
            ]);

        }

            $books = DB::table('news_category_tbl')
            ->insert([
                'category_name' => $request -> category_name,                
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

    public function editCategory(Request $request){
        try{

            if($request -> category_name != $request -> oldCategoryName){
                $checkDuplicate = DB::table('news_category_tbl')
                ->where('category_name', '=', $request->category_name)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Category Name is already exists.'
                    ]); 
                }
        
            }

            $category = DB::table('news_category_tbl')
         ->where('id', '=', $request -> id)
        ->update(['category_name' => $request -> category_name,                  
                  'updated_at' => now()]);

                  if ($category) {
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
    public function activateCategory(Request $request){
        $editClass = DB::table('news_category_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }

    public function saveNewsCategoryForAdmin(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:news_categories,slug',
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
            ]);

            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            DB::beginTransaction();

            $baseSlug = $validated['slug'] ?? Str::slug($validated['name']);
            $slug = $baseSlug;
            $suffix = 1;

            while (DB::table('news_categories')->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }

            $categoryId = DB::table('news_categories')->insertGetId([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
                'display_order' => $validated['display_order'] ?? 0,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $category = DB::table('news_categories')->where('id', $categoryId)->first();

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'News category saved successfully.',
                'data' => $category,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => 0,
                'message' => 'Failed to save news category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAllNewsCategoryForAdmin(Request $request)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

           $categories = NewsCategory::orderBy('created_at', 'desc')->get();

            return response()->json($categories);
            
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve news categories.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActiveNewsCategoryForAdmin(Request $request)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

           $categories = NewsCategory::where('is_active', true)
           ->orderBy('created_at', 'desc')->get();

            return response()->json($categories);
            
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve active news categories.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateNewsCategoryForAdmin(Request $request, $id)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $category = NewsCategory::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:news_categories,slug,' . $category->id,
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
            ]);

            DB::beginTransaction();

            $category->update([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Str::slug($validated['name']),
                'description' => $validated['description'] ?? null,
                'is_active' => array_key_exists('is_active', $validated)
                    ? (bool) $validated['is_active']
                    : $category->is_active,
                'display_order' => $validated['display_order'] ?? $category->display_order,
                'updated_by' => $admin->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'News category updated successfully.',
                'data' => $category->fresh(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => 0,
                'message' => 'Failed to update news category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteNewsCategoryForAdmin(Request $request, $id)
    {
        try {
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $category = NewsCategory::findOrFail($id);

            if ($category->news()->exists()) {
                return response()->json([
                    'success' => 0,
                    'message' => 'This category is already used by news records and cannot be deleted.',
                ], 422);
            }

            $category->delete();

            return response()->json([
                'success' => 1,
                'message' => 'News category deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to delete news category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
