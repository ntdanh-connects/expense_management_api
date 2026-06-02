<?php

namespace App\Services;

use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryService
{
    protected $categoryRepository;

    protected $momoTranslations = [
        'en' => [
            // Nhóm cha (Parents)
            'Chi tiêu - sinh hoạt' => 'Living Expenses',
            'Chi phí phát sinh'   => 'Occasional Expenses',
            'Chi phí cố định'    => 'Fixed Expenses',
            'Đầu tư - tiết kiệm'  => 'Investment & Savings',
            'Thu nhập'            => 'Income',

            // Danh mục con (Children)
            'Ăn uống'        => 'Food & Beverage',
            'Di chuyển'      => 'Transport',
            'Chợ, siêu thị'  => 'Groceries',
            'Mua sắm'        => 'Shopping',
            'Giải trí'       => 'Entertainment',
            'Làm đẹp'        => 'Beauty',
            'Sức khỏe'       => 'Health & Medical',
            'Từ thiện'       => 'Charity',
            'Hóa đơn'        => 'Bills & Utilities',
            'Nhà cửa'        => 'Housing & Rent',
            'Người thân'     => 'Family & Relatives',
            'Đầu tư'         => 'Investment',
            'Học tập'        => 'Education & Study',
            'Lương'          => 'Salary',
            'Thưởng'         => 'Bonus',
            'Kinh doanh'     => 'Business',
            'Lợi nhuận'      => 'Profit',
            'Thu hồi nợ'     => 'Debt Recovery',
            'Trợ cấp'        => 'Allowance',
        ]
    ];

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

        // 2. Lấy ngôn ngữ được phân giải động bởi Middleware
        $language = app()->getLocale();

        // 3. Lấy danh mục dạng cây từ Repository
        $categories = $this->categoryRepository->getVisibleCategories($userId);

        // 4. Dịch động các danh mục mặc định của hệ thống trước khi trả về
        if ($language !== 'vi' && isset($this->momoTranslations[$language])) {
            $dictionary = $this->momoTranslations[$language];
            foreach ($categories as $category) {
                // Dịch danh mục cha
                if ($category->is_default && isset($dictionary[$category->name])) {
                    $category->name = $dictionary[$category->name];
                }

                // Dịch danh mục con trực thuộc
                if ($category->relationLoaded('children') || isset($category->children)) {
                    foreach ($category->children as $child) {
                        if ($child->is_default && isset($dictionary[$child->name])) {
                            $child->name = $dictionary[$child->name];
                        }
                    }
                }
            }
        }

        return $categories;
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
                    throw new \Exception(__('messages.parent_category_not_found'));
                }

                // Bảo vệ cấu trúc: Chỉ cho phép tối đa 2 cấp (Cha -> Con)
                // Nếu danh mục cha đã là con của danh mục khác, chặn không cho tạo cháu
                if ($parent->parent_id !== null) {
                    throw new \Exception(__('messages.max_two_levels'));
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
            throw new \Exception(__('messages.category_not_found'));
        }

        // Chốt chặn bảo mật: Không được phép chỉnh sửa danh mục mặc định của hệ thống
        if ($category->is_default || $category->user_id === null) {
            throw new \Exception(__('messages.cannot_modify_default_category'));
        }

        if ($category->user_id !== $userId) {
            throw new \Exception(__('messages.unauthorized_edit_category'));
        }

        // Chặn không cho đổi loại (Thu nhập/Chi tiêu) nếu nó đang có danh mục cha hoặc con để tránh sai lệch cấu trúc
        if (isset($data['type']) && $data['type'] !== $category->type) {
            throw new \Exception(__('messages.cannot_change_category_type'));
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
            throw new \Exception(__('messages.category_not_found'));
        }

        // Chốt chặn bảo mật: Không được phép xóa danh mục mặc định của hệ thống
        if ($category->is_default || $category->user_id === null) {
            throw new \Exception(__('messages.cannot_delete_default_category'));
        }

        if ($category->user_id !== $userId) {
            throw new \Exception(__('messages.unauthorized_delete_category'));
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
                throw new \Exception(__('messages.merge_same_category'));
            }

            // 1. Lấy thông tin 2 danh mục
            $fromCategory = $this->categoryRepository->find($fromId);
            $toCategory = $this->categoryRepository->find($toId);

            if (!$fromCategory) {
                throw new \Exception(__('messages.source_category_not_found'));
            }

            if (!$toCategory) {
                throw new \Exception(__('messages.target_category_not_found'));
            }

            // Quy tắc 3: Bảo vệ danh mục hệ thống (fromCategory bắt buộc phải là danh mục custom của user)
            if ($fromCategory->is_default || $fromCategory->user_id === null) {
                throw new \Exception(__('messages.cannot_merge_default_category'));
            }

            if ($fromCategory->user_id !== $userId) {
                throw new \Exception(__('messages.unauthorized_merge_source'));
            }

            // Đảm bảo toCategory cũng thuộc quyền sở hữu của user đó hoặc là mặc định hệ thống
            if (!$toCategory->is_default && $toCategory->user_id !== $userId) {
                throw new \Exception(__('messages.unauthorized_merge_target'));
            }

            // Quy tắc 1: Cùng loại Thu nhập hoặc Chi tiêu
            if ($fromCategory->type !== $toCategory->type) {
                throw new \Exception(__('messages.merge_different_types'));
            }

            // Quy tắc 2: Cùng cấp độ danh mục
            $fromIsParent = ($fromCategory->parent_id === null);
            $toIsParent = ($toCategory->parent_id === null);

            if ($fromIsParent !== $toIsParent) {
                throw new \Exception(__('messages.merge_different_levels'));
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
