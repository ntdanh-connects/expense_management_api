<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadService
{
    /**
     * Upload ảnh lên AWS S3 và trả về URL công khai.
     *
     * @param UploadedFile $file Tệp tin ảnh nhận được từ Request
     * @param string $folder Thư mục lưu trữ trên S3 (ví dụ: 'avatars', 'receipts')
     * @return string URL công khai của ảnh trên S3
     */
    public function uploadToS3(UploadedFile $file, string $folder = 'avatars'): string
    {
        // 1. Tạo tên file độc nhất bằng UUIDv7 để đồng bộ với hiệu năng của DB!
        $fileName = (string) Str::uuid7() . '.' . $file->getClientOriginalExtension();
        $path = $folder . '/' . $fileName;

        // 2. Upload file lên S3
        Storage::disk('s3')->put($path, file_get_contents($file));

        // 3. Trả về URL đầy đủ trỏ tới ảnh trên S3
        return Storage::disk('s3')->url($path);
    }

    /**
     * Xóa ảnh cũ trên S3 để tránh rác lưu trữ khi người dùng đổi ảnh mới.
     *
     * @param string|null $imageUrl URL đầy đủ của ảnh cũ cần xóa
     * @return bool
     */
    public function deleteFromS3(?string $imageUrl): bool
    {
        if (!$imageUrl) {
            return false;
        }

        // Phân tích cú pháp URL để lấy ra path tương đối trong bucket
        $parsedUrl = parse_url($imageUrl);
        if (isset($parsedUrl['path'])) {
            $path = ltrim($parsedUrl['path'], '/');

            // Nếu path chứa tên bucket ở đầu (trong trường hợp dùng path-style hoặc CDN), ta cắt bỏ tên bucket đi
            $bucketName = config('filesystems.disks.s3.bucket');
            if (str_starts_with($path, $bucketName . '/')) {
                $path = substr($path, strlen($bucketName . '/'));
            }

            // Xóa file nếu tồn tại trên S3
            if (Storage::disk('s3')->exists($path)) {
                return Storage::disk('s3')->delete($path);
            }
        }

        return false;
    }
}
