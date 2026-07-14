<?php
 
namespace App\Http\Controllers;
 
use Illuminate\Http\Request;
use App\Helpers\VietQrParser;
use App\Services\VietinBankService;
use App\Services\PayeeService;
use App\Services\WalletService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
 
class QrTransferController extends Controller
{
    protected VietinBankService $vietinBankService;
    protected PayeeService $payeeService;
    protected WalletService $walletService;
 
    public function __construct(
        VietinBankService $vietinBankService,
        PayeeService $payeeService,
        WalletService $walletService
    ) {
        $this->vietinBankService = $vietinBankService;
        $this->payeeService = $payeeService;
        $this->walletService = $walletService;
    }
 
    /**
     * POST /api/qr/decode
     * Decode the QR string, query user/bank details, virtualize payee, and return decoded info.
     */
    public function decode(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
        }
 
        $validated = $request->validate([
            'qr_string' => 'required|string',
        ]);
 
        $decoded = VietQrParser::parse($validated['qr_string']);
 
        if (!$decoded) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mã QR không hợp lệ hoặc không đúng định dạng chuẩn.'
            ], 400);
        }
 
        // Case A: Internal QR
        if ($decoded['type'] === 'internal') {
            $recipient = User::where('identifier', $decoded['identifier'])
                ->with('profile')
                ->first();
 
            if (!$recipient) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Không tìm thấy người dùng sở hữu mã định danh này trên hệ thống.'
                ], 404);
            }
 
            if ($recipient->user_id === $userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bạn không thể tự chuyển tiền cho chính mình.'
                ], 400);
            }
 
            $payeeName = $recipient->profile ? $recipient->profile->full_name : 'Người dùng hệ thống';
 
            // Automatically virtualize payee into the database
            $payee = $this->payeeService->saveOrUpdatePayee($userId, [
                'payee_type' => 'internal',
                'payee_user_id' => $recipient->user_id,
                'identifier' => $decoded['identifier'],
                'payee_name' => $payeeName,
            ]);

            // Query the recipient's target wallet name
            $recipientWallet = null;
            $toWalletId = $decoded['wallet_id'] ?? null;
            if (!empty($toWalletId)) {
                $recipientWallet = DB::table('wallets')
                    ->where('id', $toWalletId)
                    ->where('user_id', $recipient->user_id)
                    ->whereNull('deleted_at')
                    ->first();
            }
            if (!$recipientWallet) {
                $recipientWallet = DB::table('wallets')
                    ->where('user_id', $recipient->user_id)
                    ->whereIn('type', ['bank', 'ewallet'])
                    ->where('currency_code', 'VND')
                    ->where('is_default_receiving', true)
                    ->whereNull('deleted_at')
                    ->first();
            }
            if (!$recipientWallet) {
                $recipientWallet = DB::table('wallets')
                    ->where('user_id', $recipient->user_id)
                    ->whereIn('type', ['bank', 'ewallet'])
                    ->where('currency_code', 'VND')
                    ->whereNull('deleted_at')
                    ->first();
            }
 
            return response()->json([
                'status' => 'success',
                'message' => 'Giải mã QR nội bộ thành công và đã lưu người nhận vào danh bạ.',
                'data' => [
                    'payee_id' => $payee->id,
                    'type' => 'internal',
                    'payee_user_id' => $recipient->user_id,
                    'to_wallet_id' => $toWalletId,
                    'recipient_wallet_name' => $recipientWallet ? $recipientWallet->name : null,
                    'identifier' => $decoded['identifier'],
                    'payee_name' => $payeeName,
                    'avatar_url' => $recipient->profile ? $recipient->profile->avatar_url : null,
                    'amount' => $decoded['amount'],
                    'description' => $decoded['description']
                ]
            ], 200);
        }
 
        // Case B: External VietQR
        if ($decoded['type'] === 'external') {
            // Resolve Bank shortName, logo using the BIN code
            $bank = $this->vietinBankService->getBankByBin($decoded['bank_bin']);
            $bankName = $bank ? $bank['shortName'] : 'Ngân hàng ngoài';
            $bankLogo = $bank ? $bank['logo'] : null;
 
            // Vấn tin tài khoản (Inquire Account Name)
            $inquiry = $this->vietinBankService->inquireAccount($decoded['bank_bin'], $decoded['account_number']);
            
            // Ưu tiên tên thật từ mã QR (tag 59) nếu vấn tin là mock/giả lập
            if (!empty($inquiry['is_mocked']) && !empty($decoded['payee_name']) && $decoded['payee_name'] !== 'UNKNOWN RECIPIENT') {
                if (str_contains($inquiry['account_name'], 'HIGHLANDS COFFEE') && str_contains($decoded['payee_name'], 'HIGHLANDS COFFEE')) {
                    $payeeName = $inquiry['account_name'];
                } else {
                    $payeeName = $decoded['payee_name'];
                }
            } else {
                $payeeName = $inquiry['account_name'] ?? $decoded['payee_name'];
            }
 
            // Automatically virtualize payee into database
            $payee = $this->payeeService->saveOrUpdatePayee($userId, [
                'payee_type' => 'external',
                'identifier' => $decoded['account_number'],
                'bank_code' => $decoded['bank_bin'],
                'bank_name' => $bankName,
                'payee_name' => $payeeName,
            ]);
 
            return response()->json([
                'status' => 'success',
                'message' => 'Giải mã VietQR thành công và đã lưu người nhận vào danh bạ.',
                'data' => [
                    'payee_id' => $payee->id,
                    'type' => 'external',
                    'bank_code' => $decoded['bank_bin'],
                    'bank_name' => $bankName,
                    'bank_logo' => $bankLogo,
                    'account_number' => $decoded['account_number'],
                    'payee_name' => $payeeName,
                    'amount' => $decoded['amount'],
                    'description' => $decoded['description']
                ]
            ], 200);
        }
 
        return response()->json(['status' => 'error', 'message' => 'Định dạng QR không xác định.'], 400);
    }
 
    /**
     * GET /api/qr/generate-my-qr
     * Generate an internal QR or external VietQR for the current user's wallet.
     */
    public function generateMyQr(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
        }
 
        $user = User::where('user_id', $userId)->with('profile')->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Không tìm thấy thông tin người dùng.'], 404);
        }
 
        $walletId = $request->query('wallet_id');
        $amount = $request->query('amount') ? (float)$request->query('amount') : null;
        $description = $request->query('description');
 
        // If a wallet_id is provided and it is a bank wallet, we generate a VietQR
        if ($walletId) {
            $wallet = DB::table('wallets')->where('id', $walletId)->where('user_id', $userId)->first();
            if ($wallet && $wallet->type === 'bank' && $wallet->bank_code && $wallet->account_number) {
                $payeeName = $user->profile ? $user->profile->full_name : 'CHỦ TÀI KHOẢN';
                $qrData = $this->vietinBankService->generateVietQr(
                    $wallet->bank_code,
                    $wallet->account_number,
                    $payeeName,
                    $amount,
                    $description
                );
 
                return response()->json([
                    'status' => 'success',
                    'message' => 'Tạo mã VietQR cho ví thành công.',
                    'data' => [
                        'type' => 'external',
                        'wallet_id' => $walletId,
                        'bank_code' => $wallet->bank_code,
                        'account_number' => $wallet->account_number,
                        'payee_name' => $payeeName,
                        'qr_code' => $qrData['qr_code'],
                        'qr_image' => $qrData['qr_image']
                    ]
                ], 200);
            }
        }
 
        // Default to returning user's internal QR payload
        $internalPayload = json_encode([
            'type' => 'internal',
            'identifier' => $user->identifier,
            'wallet_id' => $walletId,
            'amount' => $amount,
            'description' => $description
        ]);
 
        return response()->json([
            'status' => 'success',
            'message' => 'Tạo mã QR định danh nội bộ thành công.',
            'data' => [
                'type' => 'internal',
                'identifier' => $user->identifier,
                'qr_code' => $internalPayload,
                'qr_image' => "https://quickchart.io/qr?text=" . urlencode($internalPayload)
            ]
        ], 200);
    }
 
    /**
     * GET /api/payees
     * List user's saved payees contacts book.
     */
    public function listPayees(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
        }
 
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 20);
 
        $payees = $this->payeeService->getSavedPayees($userId, $search, $perPage);
 
        return response()->json([
            'status' => 'success',
            'message' => 'Lấy danh bạ người thụ hưởng thành công.',
            'data' => $payees
        ], 200);
    }
 
    /**
     * DELETE /api/payees/{id}
     * Remove a contact from saved payees.
     */
    public function deletePayee(Request $request, $id)
    {
        $userId = $request->attributes->get('user_id');
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
        }
 
        try {
            $this->payeeService->deletePayee($userId, $id);
            return response()->json([
                'status' => 'success',
                'message' => 'Đã xóa người thụ hưởng khỏi danh bạ.'
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
 
    /**
     * POST /api/qr/transfer
     * Execute simulated/virtual transfer based on scanned QR or saved payee details.
     */
    public function transfer(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => __('messages.user_id_required')], 400);
        }
 
        $validated = $request->validate([
            'from_wallet_id' => 'required|uuid',
            'payee_type' => 'required|string|in:internal,external',
            'amount' => 'required|numeric|min:1000',
            'notes' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|timezone',
            'is_qr' => 'nullable|boolean',
            'category_id' => 'nullable|uuid',
            // Fields required for internal P2P
            'payee_user_id' => 'required_if:payee_type,internal|uuid',
            'to_wallet_id' => 'nullable|uuid',
            // Fields required for external VietQR
            'bank_code' => 'required_if:payee_type,external|string',
            'account_number' => 'required_if:payee_type,external|string',
            'payee_name' => 'required_if:payee_type,external|string',
        ]);
 
        try {
            $fromWallet = DB::table('wallets')->where('id', $validated['from_wallet_id'])->where('user_id', $userId)->first();
            if (!$fromWallet) {
                return response()->json(['status' => 'error', 'message' => __('messages.wallet_not_found_or_unauthorized')], 404);
            }

            // 1. Quét mã QR không cho phép bằng ví tiền mặt (cash)
            if ($fromWallet->type === 'cash') {
                return response()->json(['status' => 'error', 'message' => __('messages.qr_cash_not_allowed')], 400);
            }

            // 2. Chuyển khoản ngoài (external) không cho phép bằng ngoại tệ (chỉ VND)
            if ($validated['payee_type'] === 'external' && $fromWallet->currency_code !== 'VND') {
                return response()->json(['status' => 'error', 'message' => __('messages.qr_external_foreign_currency_not_allowed')], 400);
            }

            $categoryId = $validated['category_id'] ?? null;

            if ($validated['payee_type'] === 'internal') {
                $recipientId = $validated['payee_user_id'];
 
                if ($recipientId === $userId) {
                    return response()->json(['status' => 'error', 'message' => 'Bạn không thể tự chuyển khoản cho chính mình.'], 400);
                }
 
                // Find recipient's specific, default, or first active wallet to receive the virtual transfer (Must be bank/ewallet in VND)
                $recipientWallet = null;
                if (!empty($validated['to_wallet_id'])) {
                    $recipientWallet = DB::table('wallets')
                        ->where('id', $validated['to_wallet_id'])
                        ->where('user_id', $recipientId)
                        ->whereNull('deleted_at')
                        ->first();
                }

                if (!$recipientWallet) {
                    $recipientWallet = DB::table('wallets')
                        ->where('user_id', $recipientId)
                        ->whereIn('type', ['bank', 'ewallet'])
                        ->where('currency_code', 'VND')
                        ->where('is_default_receiving', true)
                        ->whereNull('deleted_at')
                        ->first();
                }

                if (!$recipientWallet) {
                    $recipientWallet = DB::table('wallets')
                        ->where('user_id', $recipientId)
                        ->whereIn('type', ['bank', 'ewallet'])
                        ->where('currency_code', 'VND')
                        ->whereNull('deleted_at')
                        ->first();
                }

                if (!$recipientWallet) {
                    return response()->json([
                        'status' => 'error',
                        'message' => __('messages.qr_recipient_no_valid_wallet')
                    ], 400);
                }

                $recipient = User::where('user_id', $recipientId)->first();
                if (!$recipient) {
                    return response()->json(['status' => 'error', 'message' => 'Không tìm thấy người nhận.'], 404);
                }
                $payeeName = $recipient->profile ? $recipient->profile->full_name : 'Người dùng hệ thống';
                
                $payee = $this->payeeService->saveOrUpdatePayee($userId, [
                    'payee_type' => 'internal',
                    'payee_user_id' => $recipientId,
                    'identifier' => $recipient->identifier,
                    'payee_name' => $payeeName,
                ]);
 
                $isQr = (bool) $request->input('is_qr', true);
                $result = $this->walletService->p2pTransfer(
                    $userId,
                    $recipientId,
                    $validated['from_wallet_id'],
                    $recipientWallet->id,
                    $validated['amount'],
                    $validated['notes'],
                    $validated['timezone'] ?? null,
                    $payee->id,
                    $isQr,
                    $categoryId
                );
 
                return response()->json([
                    'status' => 'success',
                    'message' => "Đã chuyển tiền ảo thành công đến người dùng {$result['recipient_name']}.",
                    'data' => $result
                ], 200);
            }
 
            if ($validated['payee_type'] === 'external') {
                // Resolve Bank name
                $bank = $this->vietinBankService->getBankByBin($validated['bank_code']);
                $bankName = $bank ? $bank['shortName'] : 'Ngân hàng ngoài';

                $payee = $this->payeeService->saveOrUpdatePayee($userId, [
                    'payee_type' => 'external',
                    'identifier' => $validated['account_number'],
                    'bank_code' => $validated['bank_code'],
                    'bank_name' => $bankName,
                    'payee_name' => $validated['payee_name'],
                ]);

                // Call local bankTransfer to deduct balance and record expense
                $isQr = (bool) $request->input('is_qr', true);
                $result = $this->walletService->bankTransfer(
                    $userId,
                    $validated['from_wallet_id'],
                    $validated['bank_code'],
                    $validated['account_number'],
                    $validated['payee_name'],
                    $validated['amount'],
                    $validated['notes'],
                    $validated['timezone'] ?? null,
                    $payee->id,
                    $isQr,
                    $categoryId
                );
 
                // Call VietinBank Sandbox Service to simulate external bank transfer logging
                $fromWallet = DB::table('wallets')->where('id', $validated['from_wallet_id'])->first();
                $fromAccount = $fromWallet ? ($fromWallet->account_number ?? 'APP_VIRTUAL_ACC') : 'APP_VIRTUAL_ACC';
                
                $sandboxResult = $this->vietinBankService->executeSandboxTransfer(
                    $fromAccount,
                    $validated['bank_code'],
                    $validated['account_number'],
                    $validated['amount'],
                    $validated['notes'] ?? 'QR payment'
                );
 
                return response()->json([
                    'status' => 'success',
                    'message' => "Đã thanh toán ảo thành công cho {$result['payee_name']}.",
                    'data' => array_merge($result, [
                        'bank_code' => $validated['bank_code'],
                        'account_number' => $validated['account_number'],
                        'sandbox' => $sandboxResult
                    ])
                ], 200);
            }
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
 
        return response()->json(['status' => 'error', 'message' => 'Hành động không hợp lệ.'], 400);
    }
}
