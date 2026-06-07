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

        // Kiểm tra xem có cấu hình AWS S3 đầy đủ hay không
        $s3Key = config('filesystems.disks.s3.key');
        $s3Secret = config('filesystems.disks.s3.secret');
        $s3Bucket = config('filesystems.disks.s3.bucket');

        if (!empty($s3Key) && !empty($s3Secret) && !empty($s3Bucket)) {
            // 2. Upload file lên S3
            Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()));

            // 3. Trả về URL đầy đủ trỏ tới ảnh trên S3
            return Storage::disk('s3')->url($path);
        } else {
            // FALLBACK: Lưu trữ cục bộ trong thư mục public của Laravel để tránh lỗi 500 khi chưa cấu hình S3
            $destinationPath = public_path('uploads/' . $folder);
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file->move($destinationPath, $fileName);

            // Trả về URL trỏ tới server chính
            return asset('uploads/' . $folder . '/' . $fileName);
        }
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

        // Phân tích cú pháp URL để lấy ra path tương đối
        $parsedUrl = parse_url($imageUrl);
        if (isset($parsedUrl['path'])) {
            $path = ltrim($parsedUrl['path'], '/');

            // 1. Kiểm tra nếu là file cục bộ (nằm trong thư mục uploads/)
            if (str_contains($path, 'uploads/')) {
                // Trích xuất phần path bắt đầu từ uploads/
                $uploadsIndex = strpos($path, 'uploads/');
                $relativePath = substr($path, $uploadsIndex);
                $localPath = public_path($relativePath);
                if (file_exists($localPath)) {
                    return unlink($localPath);
                }
                return false;
            }

            // 2. Nếu là AWS S3
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

    /**
     * Sinh Presigned URL (Temporary URL) cho ảnh trên S3.
     *
     * @param string|null $imageUrl URL S3 tĩnh cần ký
     * @param int $expirationMinutes Thời gian hết hạn tính bằng phút
     * @return string|null URL đã được ký hoặc URL gốc nếu không dùng S3
     */
    public function getPresignedUrl(?string $imageUrl, int $expirationMinutes = 1440): ?string
    {
        if (!$imageUrl) {
            return null;
        }

        // Kiểm tra xem URL có thuộc S3 hay không
        if (str_contains($imageUrl, '.amazonaws.com') || str_contains($imageUrl, 's3.')) {
            $parsedUrl = parse_url($imageUrl);
            if (isset($parsedUrl['path'])) {
                $path = ltrim($parsedUrl['path'], '/');
                $bucketName = config('filesystems.disks.s3.bucket');
                if ($bucketName && str_starts_with($path, $bucketName . '/')) {
                    $path = substr($path, strlen($bucketName . '/'));
                }
                try {
                    return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes($expirationMinutes));
                } catch (\Exception $e) {
                    // Fallback trả về URL gốc nếu lỗi
                    return $imageUrl;
                }
            }
        }

        return $imageUrl;
    }
}

