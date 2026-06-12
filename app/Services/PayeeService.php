<?php
 
namespace App\Services;
 
use App\Models\SavedPayee;
use Illuminate\Support\Facades\DB;
 
class PayeeService
{
    /**
     * Virtualize (Save or update) a payee in the database.
     */
    public function saveOrUpdatePayee(string $userId, array $data): SavedPayee
    {
        return DB::transaction(function () use ($userId, $data) {
            $identifier = $data['identifier'];
            $bankCode = $data['bank_code'] ?? null;
 
            // Attempt to find existing saved payee
            $payee = SavedPayee::where('user_id', $userId)
                ->where('identifier', $identifier)
                ->where('bank_code', $bankCode)
                ->first();
 
            if ($payee) {
                // Increment scan count and update last scanned time
                $payee->update([
                    'scan_count' => $payee->scan_count + 1,
                    'last_scanned_at' => now(),
                    // Update payee name if it was updated from bank inquiry
                    'payee_name' => $data['payee_name'] ?? $payee->payee_name
                ]);
            } else {
                // Create a new payee
                $payee = SavedPayee::create([
                    'user_id' => $userId,
                    'payee_type' => $data['payee_type'],
                    'payee_user_id' => $data['payee_user_id'] ?? null,
                    'identifier' => $identifier,
                    'bank_code' => $bankCode,
                    'bank_name' => $data['bank_name'] ?? null,
                    'payee_name' => $data['payee_name'],
                    'nickname' => $data['nickname'] ?? null,
                    'last_scanned_at' => now(),
                    'scan_count' => 1
                ]);
            }
 
            return $payee;
        });
    }
 
    /**
     * List saved payees with search and pagination.
     */
    public function getSavedPayees(string $userId, ?string $search = null, int $perPage = 20)
    {
        $query = SavedPayee::where('user_id', $userId);
 
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('payee_name', 'like', '%' . $search . '%')
                  ->orWhere('identifier', 'like', '%' . $search . '%')
                  ->orWhere('nickname', 'like', '%' . $search . '%')
                  ->orWhere('bank_name', 'like', '%' . $search . '%');
            });
        }
 
        // Order by most recently scanned/used
        return $query->orderBy('last_scanned_at', 'desc')
            ->orderBy('scan_count', 'desc')
            ->paginate($perPage);
    }
 
    /**
     * Delete a saved payee from database.
     */
    public function deletePayee(string $userId, string $id): bool
    {
        $payee = SavedPayee::where('id', $id)
            ->where('user_id', $userId)
            ->first();
 
        if (!$payee) {
            throw new \Exception(__('messages.payee_not_found_or_unauthorized'));
        }
 
        return $payee->delete();
    }
}
