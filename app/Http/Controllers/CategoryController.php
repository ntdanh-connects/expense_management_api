<?php

namespace App\Http\Controllers;

use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    protected $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Lấy toàn bộ cây danh mục của hệ thống & user (GET /api/categories)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.user_id_required')
                ], 400);
            }

            $categories = $this->categoryService->getCategoriesTree($userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.sync_categories_success'),
                'data'    => $categories
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.get_categories_failed'),
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tạo danh mục tùy chỉnh mới (POST /api/categories)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.user_id_required')
                ], 400);
            }

            $validated = $request->validate([
                'name'       => 'required|string|max:100',
                'parent_id'  => 'required|uuid', // Momo yêu cầu tạo danh mục con thuộc nhóm cha
                'icon'       => 'required|string|max:50',
                'color'      => ['required', 'string', 'regex:/^#([A-Fa-f0-9]{6})$/'], // Định dạng mã màu Hex chuẩn (#FF8F9C)
                'sort_order' => 'nullable|integer'
            ]);

            $category = $this->categoryService->createCategory($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.create_category_success'),
                'data'    => $category
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.create_category_failed'),
                'error'   => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Cập nhật danh mục tùy chỉnh (POST /api/categories/{id})
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.user_id_required')
                ], 400);
            }

            $validated = $request->validate([
                'name'       => 'sometimes|required|string|max:100',
                'icon'       => 'sometimes|required|string|max:50',
                'color'      => ['sometimes', 'required', 'string', 'regex:/^#([A-Fa-f0-9]{6})$/'],
                'sort_order' => 'sometimes|required|integer'
            ]);

            $category = $this->categoryService->updateCategory($id, $userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.update_category_success'),
                'data'    => $category
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.update_category_failed'),
                'error'   => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Xóa danh mục tùy chỉnh (DELETE /api/categories/{id})
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.user_id_required')
                ], 400);
            }

            $this->categoryService->deleteCategory($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.delete_category_success')
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.delete_category_failed'),
                'error'   => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Gộp danh mục tùy chỉnh vào một danh mục khác (POST /api/categories/merge)
     */
    public function merge(Request $request): JsonResponse
    {
        try {
            $userId = $request->attributes->get('user_id')
                    ?? $request->header('X-User-Id');

            if (!$userId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.user_id_required')
                ], 400);
            }

            $validated = $request->validate([
                'from_category_id' => 'required|uuid',
                'to_category_id'   => 'required|uuid'
            ]);

            $this->categoryService->mergeCategories($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.merge_categories_success')
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.merge_categories_failed'),
                'error'   => $e->getMessage()
            ], 400);
        }
    }
}
