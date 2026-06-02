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
                    'message' => 'Không thể xác định danh tính người dùng!'
                ], 400);
            }

            $categories = $this->categoryService->getCategoriesTree($userId);

            return response()->json([
                'status'  => 'success',
                'message' => 'Đồng bộ cây danh mục Momo thành công!',
                'data'    => $categories
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Lấy danh mục thất bại!',
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
                    'message' => 'Không thể xác định danh tính người dùng!'
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
                'message' => 'Tạo danh mục tùy chỉnh thành công!',
                'data'    => $category
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tạo danh mục tùy chỉnh thất bại!',
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
                    'message' => 'Không thể xác định danh tính người dùng!'
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
                'message' => 'Cập nhật danh mục thành công!',
                'data'    => $category
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cập nhật danh mục thất bại!',
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
                    'message' => 'Không thể xác định danh tính người dùng!'
                ], 400);
            }

            $this->categoryService->deleteCategory($id, $userId);

            return response()->json([
                'status'  => 'success',
                'message' => 'Xóa danh mục tùy chỉnh thành công!'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Xóa danh mục tùy chỉnh thất bại!',
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
                    'message' => 'Không thể xác định danh tính người dùng!'
                ], 400);
            }

            $validated = $request->validate([
                'from_category_id' => 'required|uuid',
                'to_category_id'   => 'required|uuid'
            ]);

            $this->categoryService->mergeCategories($userId, $validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Gộp danh mục chi tiêu và chuyển đổi toàn bộ giao dịch liên quan thành công!'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gộp danh mục thất bại!',
                'error'   => $e->getMessage()
            ], 400);
        }
    }
}
