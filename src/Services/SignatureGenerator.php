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
        return hash_equals($this->generate($params), $signature);
    }

    /**
     * Helper specifically for Purchase request signature which has array fields.
     */
    public function generateForPurchase(array $data): string
    {
        $fields = [
            $data['merchantAccount'],
            $data['merchantDomainName'],
            $data['orderReference'],
            $data['orderDate'],
            $data['amount'],
            $data['currency'],
        ];

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
        return $this->generate([
            $data['merchantAccount'],
            $data['orderReference'],
            $data['amount'],
            $data['currency'],
        ]);
    }

    public function generateForVerify(array $data): string
    {
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
        return $this->generate([
            $orderReference,
            $status,
            $time
        ]);
    }
}