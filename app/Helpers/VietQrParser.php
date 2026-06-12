<?php
 
namespace App\Helpers;
 
class VietQrParser
{
    /**
     * Decode a QR raw string.
     * Supports:
     * 1. VietQR (EMVCo standard) for external bank transfers
     * 2. Internal App QR formats (JSON, URL, or plain USRxxxxxx code)
     */
    public static function parse(string $qrString): ?array
    {
        $qrString = trim($qrString);
 
        // 1. Check if it is internal JSON
        if (str_starts_with($qrString, '{') && str_ends_with($qrString, '}')) {
            $data = json_decode($qrString, true);
            if (isset($data['type']) && $data['type'] === 'internal' && isset($data['identifier'])) {
                return [
                    'type' => 'internal',
                    'identifier' => $data['identifier'],
                    'amount' => isset($data['amount']) ? (float)$data['amount'] : null,
                    'description' => $data['description'] ?? null
                ];
            }
        }
 
        // 2. Check if it is an internal URL or deep link (e.g., app://transfer?id=USR123456)
        if (filter_var($qrString, FILTER_VALIDATE_URL) || str_starts_with($qrString, 'expenseapp://') || str_starts_with($qrString, 'app://')) {
            $parsedUrl = parse_url($qrString);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                if (isset($queryParams['id']) && str_starts_with($queryParams['id'], 'USR')) {
                    return [
                        'type' => 'internal',
                        'identifier' => $queryParams['id'],
                        'amount' => isset($queryParams['amount']) ? (float)$queryParams['amount'] : null,
                        'description' => $queryParams['description'] ?? null
                    ];
                }
            }
        }
 
        // 3. Check if it is a plain internal user identifier (e.g., USR123456)
        if (preg_match('/^USR\d{6}$/i', $qrString)) {
            return [
                'type' => 'internal',
                'identifier' => strtoupper($qrString),
                'amount' => null,
                'description' => null
            ];
        }
 
        // 4. Try parsing as VietQR (EMVCo)
        // Standard VietQR starts with "000201"
        if (str_starts_with($qrString, '000201')) {
            try {
                $topLevel = self::parseTlv($qrString);
                
                // Tag 38 is the merchant account information (Napas)
                if (isset($topLevel['38'])) {
                    $tag38 = self::parseTlv($topLevel['38']);
                    
                    // Inside tag 38, sub-tag 01 is the beneficiary details
                    if (isset($tag38['01'])) {
                        $subTag01 = self::parseTlv($tag38['01']);
                        
                        // Sub-tag 00 inside sub-tag 01 is Bank BIN (e.g. 970415)
                        // Sub-tag 01 inside sub-tag 01 is Account Number
                        $bankBin = $subTag01['00'] ?? null;
                        $accountNumber = $subTag01['01'] ?? null;
 
                        if ($bankBin && $accountNumber) {
                            $payeeName = $topLevel['59'] ?? 'UNKNOWN RECIPIENT';
                            $amount = isset($topLevel['54']) ? (float)$topLevel['54'] : null;
                            
                            $description = null;
                            if (isset($topLevel['62'])) {
                                $tag62 = self::parseTlv($topLevel['62']);
                                $description = $tag62['08'] ?? null;
                            }
 
                            return [
                                'type' => 'external',
                                'bank_bin' => $bankBin,
                                'account_number' => $accountNumber,
                                'payee_name' => $payeeName,
                                'amount' => $amount,
                                'description' => $description
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // If it fails parsing, fall through to null
            }
        }
 
        return null;
    }
 
    /**
     * Parse TLV (Tag-Length-Value) string format.
     */
    private static function parseTlv(string $string): array
    {
        $results = [];
        $offset = 0;
        $lengthStr = strlen($string);
        
        while ($offset < $lengthStr - 4) {
            $tag = substr($string, $offset, 2);
            $len = (int) substr($string, $offset + 2, 2);
            
            if ($len <= 0 || $offset + 4 + $len > $lengthStr) {
                break;
            }
            
            $val = substr($string, $offset + 4, $len);
            $results[$tag] = $val;
            $offset += 4 + $len;
        }
        
        // Handle checksum Tag 63 if present at the end
        if ($offset < $lengthStr && substr($string, $offset, 2) === '63') {
            $results['63'] = substr($string, $offset + 4, 4);
        }
 
        return $results;
    }
}
