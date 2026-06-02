<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Models\Category;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function getModel()
    {
        return Category::class;
    }

    public function getVisibleCategories(string $userId)
    {
        // Lấy các danh mục gốc (parent_id is null) kèm theo các con của nó (children)
        // thuộc về hệ thống (user_id null, is_default true) hoặc của chính user
        return Category::with(['children' => function ($query) use ($userId) {
                $query->where(function ($q) use ($userId) {
                    $q->whereNull('user_id')
                      ->where('is_default', true);
                })->orWhere('user_id', $userId)
                  ->orderBy('sort_order', 'asc');
            }])
            ->whereNull('parent_id')
            ->where(function ($query) use ($userId) {
                $query->where(function ($q) {
                    $q->whereNull('user_id')
                      ->where('is_default', true);
                })->orWhere('user_id', $userId);
            })
            ->orderBy('sort_order', 'asc')
            ->get();
    }
}
