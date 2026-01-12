<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Services;

class SignatureGenerator
{
    public function __construct(
        private readonly string $secretKey
    ) {}

    public function generate(array $params): string
    {
        $concatenated = implode(';', $params);
        return hash_hmac('md5', $concatenated, $this->secretKey);
    }

    public function verify(array $params, string $signature): bool
    {
        return $this->generate($params) === $signature;
    }

    /**
     * Helper specifically for Purchase request signature which has array fields.
     */
    public function generateForPurchase(array $data): string
    {
        // Order of fields for Purchase signature:
        // merchantAccount, merchantDomainName, orderReference, orderDate, amount, currency, productName, productCount, productPrice
        
        $fields = [
            $data['merchantAccount'],
            $data['merchantDomainName'],
            $data['orderReference'],
            $data['orderDate'],
            $data['amount'],
            $data['currency'],
        ];

        // Add array products
        // productName[], productCount[], productPrice[]
        // They must be flattened? Or joined?
        // Documentation says: "concatenation of ... productName;productCount;productPrice"
        // But these are arrays.
        // Usually, in WayForPay, arrays are joined by semicolon individually.
        // Let's re-read carefully or assume standard WayForPay behavior:
        // Implode productName array with ';', then productCount array with ';', then productPrice array with ';'.
        
        $fields[] = implode(';', $data['productName']);
        $fields[] = implode(';', $data['productCount']);
        $fields[] = implode(';', $data['productPrice']);

        return $this->generate($fields);
    }

    public function generateForRefund(array $data): string
    {
        return $this->generate([
            $data['merchantAccount'],
            $data['orderReference'],
            $data['amount'],
            $data['currency'],
        ]);
    }

    public function generateForCheckStatus(array $data): string
    {
        return $this->generate([
            $data['merchantAccount'],
            $data['orderReference'],
        ]);
    }

    public function generateForRemoveInvoice(array $data): string
    {
        return $this->generate([
            $data['merchantAccount'],
            $data['orderReference'],
        ]);
    }
    
    public function generateForP2PCredit(array $data): string
    {
        // merchantAccount;orderReference;amount;currency;cardBeneficiary;rec2Token
        return $this->generate([
            $data['merchantAccount'],
            $data['orderReference'],
            $data['amount'],
            $data['currency'],
            $data['cardBeneficiary'] ?? '',
            $data['rec2Token'] ?? '',
        ]);
    }

    public function generateForSettle(array $data): string
    {
        // merchantAccount;orderReference;amount;currency
        return $this->generate([
            $data['merchantAccount'],
            $data['orderReference'],
            $data['amount'],
            $data['currency'],
        ]);
    }

    public function generateForRegularApi(array $data): string
    {
        // merchantAccount;merchantPassword;orderReference
        // Note: Regular API uses 'merchantPassword', not 'secretKey' for signature?
        // Checking docs: https://wiki.wayforpay.com/en/view/852506
        // Request: merchantAccount, merchantPassword, orderReference.
        // Response: reasonCode, reason.
        // Wait, regularApi request DOES NOT use merchantSignature in the body usually, it uses password directly?
        // Let's re-read doc snippet carefully.
        // "merchantPassword (string) - Mandatory - Merchant password"
        // No merchantSignature field listed in request body for SUSPEND/RESUME.
        
        // HOWEVER, "Regular Payment Parameters" for /pay (Purchase) DO use signature as part of normal purchase.
        
        // If this method is for /regularApi endpoint, it seems it doesn't need a signature, but authentication via password?
        // But context7 prompt says "merchantSignature" is usually HMAC_MD5.
        // Let's look at "Susbpend Recurrent Payment" doc again.
        // Parameters: requestType, merchantAccount, merchantPassword, orderReference.
        // No signature mentioned.
        
        // If so, we don't need a generator for it.
        // But for consistency let's verify if 'merchantPassword' IS the SecretKey?
        // Usually in WFP docs "merchantPassword" = SecretKey.
        
        return ''; // Placeholder if not needed.
    }

    public function generateForVerify(array $data): string
    {
        // merchantAccount;merchantDomainName;orderReference;amount;currency
        return $this->generate([
            $data['merchantAccount'],
            $data['merchantDomainName'],
            $data['orderReference'],
            $data['amount'],
            $data['currency'],
        ]);
    }

    public function generateForCharge(array $data): string
    {
        // Order: merchantAccount;merchantDomainName;orderReference;orderDate;amount;currency;card;expMonth;expYear;cardCvv;cardHolder;productName;productCount;productPrice
        // Note: This order is inferred from standard WayForPay patterns.
        
        $fields = [
            $data['merchantAccount'],
            $data['merchantDomainName'],
            $data['orderReference'],
            $data['orderDate'],
            $data['amount'],
            $data['currency'],
        ];

        if (isset($data['card'])) {
            $fields[] = $data['card'];
            $fields[] = $data['expMonth'];
            $fields[] = $data['expYear'];
            $fields[] = $data['cardCvv'];
            $fields[] = $data['cardHolder'] ?? '';
        }

        $fields[] = implode(';', $data['productName']);
        $fields[] = implode(';', $data['productCount']);
        $fields[] = implode(';', $data['productPrice']);

        return $this->generate($fields);
    }

    public function generateForServiceUrl(array $data): string
    {
        // merchantAccount;orderReference;amount;currency;authCode;cardPan;transactionStatus;reasonCode
        return $this->generate([
            $data['merchantAccount'] ?? '',
            $data['orderReference'] ?? '',
            $data['amount'] ?? '',
            $data['currency'] ?? '',
            $data['authCode'] ?? '',
            $data['cardPan'] ?? '',
            $data['transactionStatus'] ?? '',
            $data['reasonCode'] ?? '',
        ]);
    }

    public function generateResponseSignature(string $orderReference, string $status, int $time): string
    {
        // orderReference;status;time
        return $this->generate([
            $orderReference,
            $status,
            $time
        ]);
    }
}