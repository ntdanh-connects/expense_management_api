<?php
 
namespace Tests\Feature;
 
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
 
class QrTransferTest extends TestCase
{
    use DatabaseTransactions;
 
    protected string $token;
    protected string $userId;
    protected string $userIdentifier;
    protected string $walletId;
 
    protected function setUp(): void
    {
        parent::setUp();
 
        // Create sender user and authenticate
        $auth = $this->authenticateUser('sender@example.com', 'Test Sender', 'USR123456');
        $this->token = $auth['token'];
        $this->userId = $auth['user_id'];
        $this->userIdentifier = 'USR123456';
 
        // Create a cash wallet for sender with balance
        $this->walletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $this->walletId,
            'user_id' => $this->userId,
            'name' => 'Ví Ngân Hàng',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
 
        DB::table('wallet_balances')->insert([
            'wallet_id' => $this->walletId,
            'available_balance' => 200000.00,
            'version' => 1,
            'updated_at' => now()
        ]);
 
        // Clear caches and mock exchange rates
        \Illuminate\Support\Facades\Cache::forget('latest_exchange_rates');
        \Illuminate\Support\Facades\Http::fake([
            '*frankfurter.*' => \Illuminate\Support\Facades\Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'rates' => ['VND' => 25000.0, 'USD' => 1.0]
            ], 200),
            'https://api.vietqr.io/v2/banks' => \Illuminate\Support\Facades\Http::response([
                'data' => [
                    ['bin' => '970415', 'name' => 'VietinBank', 'shortName' => 'VietinBank', 'logo' => 'https://api.vietqr.io/img/ICB.png']
                ]
            ], 200),
            'https://api.vietqr.io/v2/generate' => \Illuminate\Support\Facades\Http::response([
                'data' => [
                    'qrCode' => '00020101021138400010A00000072701180006970415010499995204601153037045802VN5916HIGHLANDS COFFEE6304ABCD',
                    'qrDataURL' => 'data:image/png;base64,mockedimage'
                ]
            ], 200),
            'https://api.vietqr.io/v2/lookup' => \Illuminate\Support\Facades\Http::response([
                'code' => '00',
                'desc' => 'Success',
                'data' => [
                    'accountName' => 'CONG TY CO PHAN HIGHLANDS COFFEE'
                ]
            ], 200),
        ]);
    }
 
    protected function authenticateUser(string $email, string $fullName, string $identifier)
    {
        $userId = (string) Str::uuid7();
 
        DB::table('users')->insert([
            'user_id' => $userId,
            'email' => $email,
            'status' => 'active',
            'identifier' => $identifier,
            'created_at' => now(),
            'updated_at' => now()
        ]);
 
        DB::table('user_preferences')->insert([
            'user_id' => $userId,
            'language' => 'vi',
            'theme' => 'light',
            'currency' => 'VND',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'financial_start_day' => 1,
            'created_at' => now()
        ]);
 
        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'full_name' => $fullName,
            'created_at' => now()
        ]);
 
        $token = Str::random(60);
 
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'refresh_token_hash' => hash('sha256', 'refresh'),
            'access_token_hash' => hash('sha256', $token),
            'access_token_expired_at' => now()->addHour(),
            'device_type' => 'web',
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expired_at' => now()->addDays(30),
            'created_at' => now()
        ]);
 
        return ['token' => $token, 'user_id' => $userId];
    }
 
    public function test_decode_internal_qr_code()
    {
        // 1. Create recipient user
        $recipientAuth = $this->authenticateUser('recipient@example.com', 'Recipient User', 'USR999999');
        $recipientId = $recipientAuth['user_id'];
 
        // Create target wallet for recipient
        $recipientWalletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $recipientWalletId,
            'user_id' => $recipientId,
            'name' => 'Ví Tiền Mặt Recipient',
            'type' => 'cash',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
 
        // 2. Decode the QR payload (JSON structure)
        $qrPayload = json_encode([
            'type' => 'internal',
            'identifier' => 'USR999999',
            'wallet_id' => $recipientWalletId,
            'amount' => 50000,
            'description' => 'Test QR Transfer'
        ]);
 
        $response = $this->postJson('/api/qr/decode', [
            'qr_string' => $qrPayload
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
 
        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'internal');
        $response->assertJsonPath('data.payee_name', 'Recipient User');
        $response->assertJsonPath('data.identifier', 'USR999999');
        $response->assertJsonPath('data.to_wallet_id', $recipientWalletId);
        $response->assertJsonPath('data.amount', 50000);
 
        // Check saved payee in DB
        $this->assertDatabaseHas('saved_payees', [
            'user_id' => $this->userId,
            'payee_user_id' => $recipientId,
            'payee_type' => 'internal',
            'identifier' => 'USR999999'
        ]);
    }
 
    public function test_decode_external_vietqr_code()
    {
        // Highlands Coffee mock VietQR string
        $vietQr = '00020101021138400010A00000072701180006970415010477775204601153037045802VN5916HIGHLANDS COFFEE6304ABCD';
 
        $response = $this->postJson('/api/qr/decode', [
            'qr_string' => $vietQr
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
 
        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'external');
        $response->assertJsonPath('data.account_number', '7777');
        $response->assertJsonPath('data.bank_code', '970415');
        // Name should be matched from Tag 59 or mock rules containing 7777 (HIGHLANDS COFFEE)
        $response->assertJsonPath('data.payee_name', 'CONG TY CO PHAN HIGHLANDS COFFEE');
 
        $this->assertDatabaseHas('saved_payees', [
            'user_id' => $this->userId,
            'payee_type' => 'external',
            'identifier' => '7777',
            'bank_code' => '970415'
        ]);
    }
 
    public function test_generate_my_qr()
    {
        $response = $this->getJson('/api/qr/generate-my-qr', [
            'Authorization' => 'Bearer ' . $this->token
        ]);
 
        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'internal');
        $response->assertJsonPath('data.identifier', $this->userIdentifier);
    }
 
    public function test_list_and_delete_payees()
    {
        // 1. Pre-insert a saved payee
        $payeeId = (string) Str::uuid7();
        DB::table('saved_payees')->insert([
            'id' => $payeeId,
            'user_id' => $this->userId,
            'payee_type' => 'external',
            'identifier' => '123456',
            'bank_code' => '970415',
            'bank_name' => 'VietinBank',
            'payee_name' => 'HIGHLANDS COFFEE',
            'last_scanned_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
 
        // 2. List payees
        $response = $this->getJson('/api/payees', [
            'Authorization' => 'Bearer ' . $this->token
        ]);
 
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
 
        // 3. Delete payee
        $deleteResponse = $this->deleteJson("/api/payees/{$payeeId}", [], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
 
        $deleteResponse->assertStatus(200);
        $this->assertDatabaseMissing('saved_payees', ['id' => $payeeId]);
    }
 
    public function test_internal_p2p_transfer()
    {
        // 1. Create recipient user & wallet
        $recipientAuth = $this->authenticateUser('recipient2@example.com', 'Recipient 2', 'USR888888');
        $recipientId = $recipientAuth['user_id'];
 
        $recipientWalletId = (string) Str::uuid7();
        DB::table('wallets')->insert([
            'id' => $recipientWalletId,
            'user_id' => $recipientId,
            'name' => 'Ví Ngân Hàng Recipient 2',
            'type' => 'bank',
            'currency_code' => 'VND',
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now()
        ]);
 
        DB::table('wallet_balances')->insert([
            'wallet_id' => $recipientWalletId,
            'available_balance' => 0.00,
            'version' => 1,
            'updated_at' => now()
        ]);
 
        // 2. Perform transfer
        $response = $this->postJson('/api/qr/transfer', [
            'from_wallet_id' => $this->walletId,
            'payee_type' => 'internal',
            'payee_user_id' => $recipientId,
            'amount' => 50000.00,
            'notes' => 'Chuyển tiền P2P test'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
 
        $response->assertStatus(200);
 
        // Verify balance changes
        $senderBalance = DB::table('wallet_balances')->where('wallet_id', $this->walletId)->value('available_balance');
        $recipientBalance = DB::table('wallet_balances')->where('wallet_id', $recipientWalletId)->value('available_balance');
 
        $this->assertEquals(150000.00, (float)$senderBalance);
        $this->assertEquals(50000.00, (float)$recipientBalance);
 
        // Verify transactions created
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'type' => 'expense',
            'amount' => 50000.00
        ]);
 
        $this->assertDatabaseHas('transactions', [
            'user_id' => $recipientId,
            'wallet_id' => $recipientWalletId,
            'type' => 'income',
            'amount' => 50000.00
        ]);
    }
 
    public function test_external_bank_transfer()
    {
        // Perform transfer to bank
        $response = $this->postJson('/api/qr/transfer', [
            'from_wallet_id' => $this->walletId,
            'payee_type' => 'external',
            'bank_code' => '970415',
            'account_number' => '99995555',
            'payee_name' => 'HIGHLANDS COFFEE',
            'amount' => 30000.00,
            'notes' => 'Coffee at Highlands'
        ], [
            'Authorization' => 'Bearer ' . $this->token
        ]);
 
        $response->assertStatus(200);
 
        // Verify balance changes
        $senderBalance = DB::table('wallet_balances')->where('wallet_id', $this->walletId)->value('available_balance');
        $this->assertEquals(170000.00, (float)$senderBalance);
 
        // Verify transaction was created in DB
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->userId,
            'wallet_id' => $this->walletId,
            'type' => 'expense',
            'amount' => 30000.00,
            'title' => 'Coffee at Highlands'
        ]);
    }
}
