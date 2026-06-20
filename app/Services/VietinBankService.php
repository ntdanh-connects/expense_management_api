<?php
 
namespace App\Services;
 
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
 
class VietinBankService
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $inquiryEndpoint;
 
    public function __construct()
    {
        $this->baseUrl = config('services.vietinbank.base_url', 'https://sandbox.vietinbank.vn/vtb/openbanking');
        $this->clientId = config('services.vietinbank.client_id', '');
        $this->clientSecret = config('services.vietinbank.client_secret', '');
        $this->inquiryEndpoint = config('services.vietinbank.inquiry_endpoint', '/accounts/inquiry');
    }
 
    /**
     * Fetch the list of banks from VietQR.io (cached for 24 hours).
     */
    public function getBankList(): array
    {
        return Cache::remember('vietnam_bank_list', 86400, function () {
            try {
                $response = Http::timeout(5)->get('https://api.vietqr.io/v2/banks');
                if ($response->successful()) {
                    $body = $response->json();
                    if (isset($body['data']) && is_array($body['data'])) {
                        return $body['data'];
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Failed to fetch bank list from VietQR.io: ' . $e->getMessage());
            }
 
            // Fallback bank list if API fails
            return [
                ['bin' => '970415', 'name' => 'Ngan hang TMCP Cong Thuong Viet Nam', 'shortName' => 'VietinBank', 'code' => 'ICB', 'logo' => 'https://api.vietqr.io/img/ICB.png'],
                ['bin' => '970436', 'name' => 'Ngan hang TMCP Ngoai Thuong Viet Nam', 'shortName' => 'Vietcombank', 'code' => 'VCB', 'logo' => 'https://api.vietqr.io/img/VCB.png'],
                ['bin' => '970407', 'name' => 'Ngan hang TMCP Ky Thuong Viet Nam', 'shortName' => 'Techcombank', 'code' => 'TCB', 'logo' => 'https://api.vietqr.io/img/TCB.png'],
                ['bin' => '970418', 'name' => 'Ngan hang TMCP Dau tu va Phat trien Viet Nam', 'shortName' => 'BIDV', 'code' => 'BIDV', 'logo' => 'https://api.vietqr.io/img/BIDV.png'],
                ['bin' => '970422', 'name' => 'Ngan hang TMCP Quan doi', 'shortName' => 'MBBank', 'code' => 'MB', 'logo' => 'https://api.vietqr.io/img/MB.png'],
            ];
        });
    }
 
    /**
     * Resolve bank short name and logo from BIN.
     */
    public function getBankByBin(string $bin): ?array
    {
        $banks = $this->getBankList();
        foreach ($banks as $bank) {
            if ($bank['bin'] === $bin) {
                return $bank;
            }
        }
        return null;
    }
 
    /**
     * Tra cứu tên tài khoản qua cổng VietQR.io nếu cấu hình API Key.
     */
    public function lookupAccountName(string $bankBin, string $accountNumber): ?string
    {
        if (app()->environment('testing')) {
            $clientId = 'test';
            $apiKey = 'test';
        } else {
            $clientId = env('VIETQR_CLIENT_ID', '');
            $apiKey = env('VIETQR_API_KEY', '');
        }
        
        if (empty($clientId) || empty($apiKey)) {
            return null;
        }
        
        try {
            $response = Http::withHeaders([
                'x-client-id' => $clientId,
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(5)->post('https://api.vietqr.io/v2/lookup', [
                'bin' => $bankBin,
                'accountNumber' => $accountNumber,
            ]);
            
            if ($response->successful()) {
                $body = $response->json();
                if (isset($body['code']) && $body['code'] === '00' && isset($body['data']['accountName'])) {
                    return strtoupper($body['data']['accountName']);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to lookup account name via VietQR.io: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Inquire Account Name (Vấn tin tài khoản).
     * Since this is a student project, we mock this API to make it 100% stable,
     * but simulate the API call lifecycle.
     */
    public function inquireAccount(string $bankBin, string $accountNumber): array
    {
        Log::info("Inquiring account name for BIN: {$bankBin}, Account: {$accountNumber}");
 
        // 1. Thử tra cứu tên tài khoản thực tế bằng API VietQR.io (nếu có cấu hình API Key)
        $realName = $this->lookupAccountName($bankBin, $accountNumber);
        if ($realName) {
            return [
                'status' => 'success',
                'account_name' => $realName,
                'account_number' => $accountNumber,
                'bank_bin' => $bankBin,
                'is_mocked' => false
            ];
        }

        // 2. Chỉ gọi API VietinBank Sandbox cho tài khoản thuộc VietinBank (BIN 970415)
        if ($bankBin !== '970415') {
            // Với các ngân hàng khác, trả về Mock thành công để tránh lỗi vấn tin liên ngân hàng trên Sandbox
            $mockName = $this->getMockAccountName($bankBin, $accountNumber);
            return [
                'status' => 'success',
                'account_name' => $mockName,
                'account_number' => $accountNumber,
                'bank_bin' => $bankBin,
                'is_mocked' => true
            ];
        }
 
        // 3. Nếu chưa cấu hình credentials VietinBank Sandbox, trả về mock của VietinBank
        if (empty($this->clientId) || empty($this->clientSecret)) {
            $mockName = $this->getMockAccountName($bankBin, $accountNumber);
            return [
                'status' => 'success',
                'account_name' => $mockName,
                'account_number' => $accountNumber,
                'bank_bin' => $bankBin,
                'is_mocked' => true
            ];
        }
 
        try {
            // Gửi HTTP Request thực tế đến VietinBank Sandbox bằng endpoint động từ config
            $endpointUrl = rtrim($this->baseUrl, '/') . '/' . ltrim($this->inquiryEndpoint, '/');
            $response = Http::withHeaders([
                'X-IBM-Client-Id' => $this->clientId,
                'X-IBM-Client-Secret' => $this->clientSecret,
                'Content-Type' => 'application/json',
            ])->timeout(3)->post($endpointUrl, [
                'bankBin' => $bankBin,
                'accountNumber' => $accountNumber,
            ]);
 
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['accountName'])) {
                    return [
                        'status' => 'success',
                        'account_name' => strtoupper($data['accountName']),
                        'account_number' => $accountNumber,
                        'bank_bin' => $bankBin,
                        'is_mocked' => false
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("VietinBank API inquiry failed: {$e->getMessage()}. Falling back to mock.");
        }
 
        // Fallback cuối cùng nếu gọi API lỗi
        $mockName = $this->getMockAccountName($bankBin, $accountNumber);
        return [
            'status' => 'success',
            'account_name' => $mockName,
            'account_number' => $accountNumber,
            'bank_bin' => $bankBin,
            'is_mocked' => true
        ];
    }

    /**
     * Lấy tên tài khoản giả lập (Mock) cho các tài khoản test khi API chưa cấu hình
     * hoặc khi vấn tin thẻ của các ngân hàng ngoài hệ thống Sandbox VietinBank.
     */
    private function getMockAccountName(string $bankBin, string $accountNumber): string
    {
        if (str_contains($accountNumber, '7777')) {
            return 'CONG TY CO PHAN HIGHLANDS COFFEE';
        }
        if ($accountNumber === '99995555') {
            return 'HIGHLANDS COFFEE';
        }
        if (str_contains($accountNumber, '8888') || str_contains($accountNumber, '1111')) {
            return 'CONG TY CO PHAN PHUC LONG HERITAGE';
        }
        if (str_contains($accountNumber, '2222')) {
            return 'NGUYEN VAN A';
        }
        if (str_contains($accountNumber, '3333')) {
            return 'TRAN THI B';
        }

        $bank = $this->getBankByBin($bankBin);
        $bankShortName = $bank ? $bank['shortName'] : 'NGAN HANG';

        return "KHACH HANG GIAP LAP (" . strtoupper($bankShortName) . " - *" . substr($accountNumber, -4) . ")";
    }
 
    /**
     * Generate VietQR string and image.
     */
    public function generateVietQr(string $bankBin, string $accountNumber, string $accountName, ?float $amount = null, ?string $description = null): array
    {
        try {
            $payload = [
                'bin' => $bankBin,
                'accountNumber' => $accountNumber,
                'accountName' => $accountName,
                'amount' => $amount ? (int)$amount : null,
                'addInfo' => $description ?? '',
                'format' => 'text'
            ];
 
            $response = Http::timeout(5)->post('https://api.vietqr.io/v2/generate', $payload);
            if ($response->successful()) {
                $body = $response->json();
                if (isset($body['data'])) {
                    return [
                        'status' => 'success',
                        'qr_code' => $body['data']['qrCode'],
                        'qr_image' => $body['data']['qrDataURL'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to generate QR via VietQR.io: ' . $e->getMessage());
        }
 
        // Local Fallback: return a dummy image and generate a raw EMVCo string
        $dummyQrCode = "00020101021138" . str_pad(strlen($bankBin) + strlen($accountNumber) + 20, 2, '0', STR_PAD_LEFT)
            . "0010A00000072701" . str_pad(strlen($bankBin) + strlen($accountNumber) + 4, 2, '0', STR_PAD_LEFT)
            . "00" . str_pad(strlen($bankBin), 2, '0', STR_PAD_LEFT) . $bankBin
            . "01" . str_pad(strlen($accountNumber), 2, '0', STR_PAD_LEFT) . $accountNumber
            . "0208QRIBFTTA5204601153037045802VN59" . str_pad(strlen($accountName), 2, '0', STR_PAD_LEFT) . strtoupper($accountName)
            . "6005Hanoi" . ($amount ? "54" . str_pad(strlen((int)$amount), 2, '0', STR_PAD_LEFT) . (int)$amount : "")
            . ($description ? "62" . str_pad(strlen($description) + 4, 2, '0', STR_PAD_LEFT) . "08" . str_pad(strlen($description), 2, '0', STR_PAD_LEFT) . $description : "")
            . "63041D3F";
 
        return [
            'status' => 'success',
            'qr_code' => $dummyQrCode,
            'qr_image' => "https://quickchart.io/qr?text=" . urlencode($dummyQrCode),
        ];
    }
 
    /**
     * Simulate VietinBank Sandbox Fund Transfer.
     */
    public function executeSandboxTransfer(string $fromAccount, string $toBankBin, string $toAccount, float $amount, string $description): array
    {
        Log::info("Simulating VietinBank Sandbox transfer of {$amount} VND from {$fromAccount} to {$toBankBin}/{$toAccount}");
 
        // Simulate API latency
        usleep(300000); // 300ms
 
        $transactionId = 'VTB' . strtoupper(Str::random(12));
 
        return [
            'status' => 'success',
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'description' => $description,
            'transferred_at' => now()->toIso8601String(),
            'message' => 'Simulated VietinBank Sandbox transaction completed successfully.'
        ];
    }
}
