<?php

namespace App\Services;

use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryService
{
    protected $categoryRepository;

    public function __construct(CategoryRepositoryInterface $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * Lấy danh sách danh mục theo dạng cây Cha -> Con.
     * Tự động nạp bộ danh mục Momo mặc định nếu DB trống.
     */
    public function getCategoriesTree(string $userId)
    {
        // 1. Kiểm tra xem DB đã có danh mục hệ thống nào chưa.
        // Nếu trống rỗng, tự động seed ngay lập tức.
        $defaultCount = DB::table('categories')->whereNull('user_id')->where('is_default', true)->count();
        if ($defaultCount === 0) {
            $this->seedDefaultMomoCategories();
        }

        // 2. Lấy danh mục dạng cây từ Repository
        return $this->categoryRepository->getVisibleCategories($userId);
    }

    /**
     * Tạo danh mục tùy chỉnh riêng của user (chỉ được tạo con/nhánh)
     */
    public function createCategory(string $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            $parentId = $data['parent_id'] ?? null;

            if ($parentId) {
                // Kiểm tra xem danh mục cha có tồn tại và hợp lệ không
                $parent = $this->categoryRepository->find($parentId);
                if (!$parent) {
                    throw new \Exception("Danh mục cha không tồn tại!");
                }

                // Bảo vệ cấu trúc: Chỉ cho phép tối đa 2 cấp (Cha -> Con)
                // Nếu danh mục cha đã là con của danh mục khác, chặn không cho tạo cháu
                if ($parent->parent_id !== null) {
                    throw new \Exception("Hệ thống chỉ hỗ trợ tối đa 2 cấp danh mục (Cha và Con)!");
                }

                // Loại của con phải trùng với loại của cha
                $data['type'] = $parent->type;
            }

            // Gán các thông tin mặc định cho danh mục custom
            $data['user_id'] = $userId;
            $data['is_default'] = false;
            if (!isset($data['sort_order'])) {
                $data['sort_order'] = 100; // Cho xuống dưới cùng
            }

            return $this->categoryRepository->create($data);
        });
    }

    /**
     * Cập nhật danh mục tùy chỉnh
     */
    public function updateCategory(string $categoryId, string $userId, array $data)
    {
        $category = $this->categoryRepository->find($categoryId);

        if (!$category) {
            throw new \Exception("Danh mục không tồn tại!");
        }

        // Chốt chặn bảo mật: Không được phép chỉnh sửa danh mục mặc định của hệ thống
        if ($category->is_default || $category->user_id === null) {
            throw new \Exception("Không thể chỉnh sửa danh mục mặc định của hệ thống!");
        }

        if ($category->user_id !== $userId) {
            throw new \Exception("Bạn không có quyền chỉnh sửa danh mục này!");
        }

        // Chặn không cho đổi loại (Thu nhập/Chi tiêu) nếu nó đang có danh mục cha hoặc con để tránh sai lệch cấu trúc
        if (isset($data['type']) && $data['type'] !== $category->type) {
            throw new \Exception("Không thể thay đổi loại Thu nhập/Chi tiêu của danh mục!");
        }

        $category->update($data);
        return $category;
    }

    /**
     * Xóa danh mục tùy chỉnh
     */
    public function deleteCategory(string $categoryId, string $userId)
    {
        $category = $this->categoryRepository->find($categoryId);

        if (!$category) {
            throw new \Exception("Danh mục không tồn tại!");
        }

        // Chốt chặn bảo mật: Không được phép xóa danh mục mặc định của hệ thống
        if ($category->is_default || $category->user_id === null) {
            throw new \Exception("Không thể xóa danh mục mặc định của hệ thống!");
        }

        if ($category->user_id !== $userId) {
            throw new \Exception("Bạn không có quyền thao tác trên danh mục này!");
        }

        return DB::transaction(function () use ($category) {
            // Nếu xóa danh mục cha, xóa luôn toàn bộ danh mục con tùy chỉnh của nó
            if ($category->parent_id === null) {
                DB::table('categories')
                    ->where('parent_id', $category->id)
                    ->where('is_default', false)
                    ->update(['deleted_at' => now()]);
            }

            return $category->delete();
        });
    }

    /**
     * Tự động nạp bộ danh mục Momo mặc định vào database
     */
    protected function seedDefaultMomoCategories(): void
    {
        $defaults = [
            [
                'name' => 'Chi tiêu - sinh hoạt',
                'type' => 'expense',
                'sort_order' => 1,
                'children' => [
                    ['name' => 'Ăn uống', 'icon' => 'food', 'color' => '#FF8F9C', 'sort_order' => 1],
                    ['name' => 'Di chuyển', 'icon' => 'car', 'color' => '#9BE5FF', 'sort_order' => 2],
                    ['name' => 'Chợ, siêu thị', 'icon' => 'shopping_cart', 'color' => '#FFC68C', 'sort_order' => 3],
                ]
            ],
            [
                'name' => 'Chi phí phát sinh',
                'type' => 'expense',
                'sort_order' => 2,
                'children' => [
                    ['name' => 'Mua sắm', 'icon' => 'shopping_bag', 'color' => '#FF8F9C', 'sort_order' => 1],
                    ['name' => 'Giải trí', 'icon' => 'gamepad', 'color' => '#E99BFF', 'sort_order' => 2],
                    ['name' => 'Làm đẹp', 'icon' => 'beauty', 'color' => '#FF8CEE', 'sort_order' => 3],
                    ['name' => 'Sức khỏe', 'icon' => 'health', 'color' => '#FFA8A8', 'sort_order' => 4],
                    ['name' => 'Từ thiện', 'icon' => 'heart', 'color' => '#FFA8B9', 'sort_order' => 5],
                ]
            ],
            [
                'name' => 'Chi phí cố định',
                'type' => 'expense',
                'sort_order' => 3,
                'children' => [
                    ['name' => 'Hóa đơn', 'icon' => 'receipt', 'color' => '#9BFFE5', 'sort_order' => 1],
                    ['name' => 'Nhà cửa', 'icon' => 'house', 'color' => '#9BAFFF', 'sort_order' => 2],
                    ['name' => 'Người thân', 'icon' => 'users', 'color' => '#FFB59B', 'sort_order' => 3],
                ]
            ],
            [
                'name' => 'Đầu tư - tiết kiệm',
                'type' => 'expense',
                'sort_order' => 4,
                'children' => [
                    ['name' => 'Đầu tư', 'icon' => 'chart', 'color' => '#9BFFB2', 'sort_order' => 1],
                    ['name' => 'Học tập', 'icon' => 'book', 'color' => '#BFA8FF', 'sort_order' => 2],
                ]
            ],
            [
                'name' => 'Thu nhập',
                'type' => 'income',
                'sort_order' => 5,
                'children' => [
                    ['name' => 'Lương', 'icon' => 'salary', 'color' => '#9BE5FF', 'sort_order' => 1],
                    ['name' => 'Thưởng', 'icon' => 'award', 'color' => '#FFE68C', 'sort_order' => 2],
                    ['name' => 'Kinh doanh', 'icon' => 'business', 'color' => '#B5FF8C', 'sort_order' => 3],
                    ['name' => 'Lợi nhuận', 'icon' => 'profit', 'color' => '#FF8F9C', 'sort_order' => 4],
                    ['name' => 'Thu hồi nợ', 'icon' => 'debt', 'color' => '#9BFFE5', 'sort_order' => 5],
                    ['name' => 'Trợ cấp', 'icon' => 'support', 'color' => '#FFC68C', 'sort_order' => 6],
                ]
            ],
        ];

        DB::transaction(function () use ($defaults) {
            foreach ($defaults as $parentData) {
                $parentId = (string) Str::uuid7();

                // 1. Chèn cha
                DB::table('categories')->insert([
                    'id'         => $parentId,
                    'user_id'    => null,
                    'parent_id'  => null,
                    'type'       => $parentData['type'],
                    'name'       => $parentData['name'],
                    'icon'       => null,
                    'color'      => null,
                    'sort_order' => $parentData['sort_order'],
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // 2. Chèn các con của cha đó
                foreach ($parentData['children'] as $childData) {
                    $childId = (string) Str::uuid7();
                    DB::table('categories')->insert([
                        'id'         => $childId,
                        'user_id'    => null,
                        'parent_id'  => $parentId,
                        'type'       => $parentData['type'],
                        'name'       => $childData['name'],
                        'icon'       => $childData['icon'],
                        'color'      => $childData['color'],
                        'sort_order' => $childData['sort_order'],
                        'is_default' => true,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        });
    }

    /**
     * Gộp danh mục tùy chỉnh vào một danh mục khác (cùng loại)
     */
    public function mergeCategories(string $userId, array $data): void
    {
        DB::transaction(function () use ($userId, $data) {
            $fromId = $data['from_category_id'];
            $toId = $data['to_category_id'];

            if ($fromId === $toId) {
                throw new \Exception("Danh mục nguồn và danh mục đích không thể trùng nhau!");
            }

            // 1. Lấy thông tin 2 danh mục
            $fromCategory = $this->categoryRepository->find($fromId);
            $toCategory = $this->categoryRepository->find($toId);

            if (!$fromCategory) {
                throw new \Exception("Danh mục cần gộp không tồn tại!");
            }

            if (!$toCategory) {
                throw new \Exception("Danh mục đích không tồn tại!");
            }

            // Quy tắc 3: Bảo vệ danh mục hệ thống (fromCategory bắt buộc phải là danh mục custom của user)
            if ($fromCategory->is_default || $fromCategory->user_id === null) {
                throw new \Exception("Không thể gộp và xóa danh mục mặc định của hệ thống!");
            }

            if ($fromCategory->user_id !== $userId) {
                throw new \Exception("Bạn không có quyền thao tác trên danh mục nguồn!");
            }

            // Đảm bảo toCategory cũng thuộc quyền sở hữu của user đó hoặc là mặc định hệ thống
            if (!$toCategory->is_default && $toCategory->user_id !== $userId) {
                throw new \Exception("Bạn không có quyền thao tác trên danh mục đích!");
            }

            // Quy tắc 1: Cùng loại Thu nhập hoặc Chi tiêu
            if ($fromCategory->type !== $toCategory->type) {
                throw new \Exception("Hai danh mục không cùng loại giao dịch (Thu nhập/Chi tiêu)!");
            }

            // Quy tắc 2: Cùng cấp độ danh mục
            $fromIsParent = ($fromCategory->parent_id === null);
            $toIsParent = ($toCategory->parent_id === null);

            if ($fromIsParent !== $toIsParent) {
                throw new \Exception("Chỉ cho phép gộp hai danh mục cùng cấp độ (cùng là nhóm cha hoặc cùng là nhóm con)!");
            }

            // 2. Chuyển toàn bộ các giao dịch cũ đang dùng danh mục cũ sang danh mục mới
            DB::table('transactions')
                ->where('category_id', $fromId)
                ->update([
                    'category_id' => $toId,
                    'updated_at'  => now()
                ]);

            // 3. Nếu là gộp danh mục CHA -> chuyển toàn bộ danh mục con của CHA cũ sang làm con của CHA mới
            if ($fromIsParent) {
                DB::table('categories')
                    ->where('parent_id', $fromId)
                    ->update([
                        'parent_id'  => $toId,
                        'updated_at' => now()
                    ]);
            }

            // 4. Lưu vết gộp vào merge_to_category_id và xóa mềm danh mục nguồn
            $fromCategory->update([
                'merge_to_category_id' => $toId,
                'deleted_at'           => now()
            ]);
        });
    }
}
