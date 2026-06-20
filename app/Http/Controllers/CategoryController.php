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
     * Lấy danh sách các biểu tượng được hỗ trợ cho danh mục tùy chỉnh (GET /api/categories/icons)
     */
    public function getIcons(Request $request): JsonResponse
    {
        try {
            $icons = $this->categoryService->getSupportedIcons();

            return response()->json([
                'status'  => 'success',
                'message' => __('messages.get_icons_success'),
                'data'    => $icons
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.get_icons_failed'),
                'error'   => $e->getMessage()
            ], 400);
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
                'icon'       => ['required', 'string', 'max:50', \Illuminate\Validation\Rule::in($this->categoryService->getSupportedIcons())],
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
                'icon'       => ['sometimes', 'required', 'string', 'max:50', \Illuminate\Validation\Rule::in($this->categoryService->getSupportedIcons())],
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

    /**
     * Tự động phân loại danh mục bằng AI (POST /api/ai/classify-category)
     */
    public function classifyCategory(Request $request): JsonResponse
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
                'title' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'type'  => 'required|string|in:expense,income'
            ]);

            $title = trim($validated['title'] ?? '');
            $notes = trim($validated['notes'] ?? '');
            $type = $validated['type'];

            // Nếu không có nội dung nào để phân tích thì bỏ qua luôn
            if (empty($title) && empty($notes)) {
                return response()->json([
                    'status' => 'success',
                    'data'   => ['category_id' => null]
                ], 200);
            }

            // Lấy toàn bộ danh mục của user
            $categories = $this->categoryService->getCategoriesTree($userId);

            // Lọc danh mục cha có type tương ứng
            $filteredParents = $categories->where('type', $type);

            // Dựng danh sách phẳng các danh mục lá (hoặc danh mục cha nếu không có con)
            $categoriesList = [];
            foreach ($filteredParents as $parent) {
                $children = $parent->children ?? collect();
                if ($children->isEmpty()) {
                    $categoriesList[] = [
                        'id' => $parent->id,
                        'name' => $parent->name,
                        'parent_name' => null
                    ];
                } else {
                    foreach ($children as $child) {
                        $categoriesList[] = [
                            'id' => $child->id,
                            'name' => $child->name,
                            'parent_name' => $parent->name
                        ];
                    }
                }
            }

            $apiKey = env('GEMINI_API_KEY');
            $model = env('GEMINI_MODEL', 'gemini-3.5-flash');

            if (!$apiKey) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Chưa cấu hình GEMINI_API_KEY trong file .env'
                ], 500);
            }

            // Tạo prompt hướng dẫn AI trả về JSON chứa ID danh mục
            $prompt = "Dựa trên tiêu đề giao dịch và ghi chú dưới đây, hãy chọn danh mục phù hợp nhất từ danh sách danh mục có sẵn.\n"
                . "Tiêu đề: " . ($title ?: '(Trống)') . "\n"
                . "Ghi chú/Nội dung: " . ($notes ?: '(Trống)') . "\n"
                . "Loại giao dịch: " . $type . "\n\n"
                . "Danh sách danh mục có sẵn (gồm ID, tên danh mục, và tên danh mục cha nếu có):\n"
                . json_encode($categoriesList, JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Yêu cầu:\n"
                . "Trả về duy nhất một đối tượng JSON có dạng:\n"
                . "{\"category_id\": \"<ID danh mục được chọn>\"}\n"
                . "Nếu không khớp danh mục nào phù hợp, hãy trả về:\n"
                . "{\"category_id\": null}";

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ]
            ];

            $response = \Illuminate\Support\Facades\Http::timeout(10)->post($url, $payload);

            if ($response->failed()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Lỗi kết nối Gemini API',
                    'error'   => $response->body()
                ], 502);
            }

            $result = $response->json();
            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!$text) {
                return response()->json([
                    'status' => 'success',
                    'data'   => ['category_id' => null]
                ], 200);
            }

            $data = json_decode(trim($text), true);
            $categoryId = $data['category_id'] ?? null;

            // Chốt chặn an toàn: Đảm bảo category_id được AI chọn nằm trong danh sách danh mục thực tế của hệ thống/user
            $validIds = array_column($categoriesList, 'id');
            if ($categoryId && !in_array($categoryId, $validIds)) {
                $categoryId = null;
            }

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'category_id' => $categoryId
                ]
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Lỗi phân loại danh mục',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
