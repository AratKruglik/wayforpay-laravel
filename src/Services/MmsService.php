<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use AratKruglik\WayForPay\Contracts\MmsServiceInterface;
use AratKruglik\WayForPay\Domain\Merchant;
use AratKruglik\WayForPay\Domain\Partner;
use AratKruglik\WayForPay\Services\Concerns\HandlesApiResponse;
use InvalidArgumentException;

class MmsService implements MmsServiceInterface
{
    use HandlesApiResponse;

    private string $merchantAccount;
    private int $timeout;
    private string $baseUrl = 'https://api.wayforpay.com/mms/';

    public function __construct(
        private readonly SignatureGenerator $signatureGenerator,
        private readonly HttpFactory $http
    ) {
        $this->merchantAccount = Config::get('wayforpay.merchant_account');
        $this->timeout = (int) Config::get('wayforpay.timeout', 30);
    }

    public function addPartner(Partner $partner): array
    {
        $data = array_merge(
            ['merchantAccount' => $this->merchantAccount],
            $partner->toArray()
        );

        $data['merchantSignature'] = $this->signatureGenerator->generateForAddPartner([
            'merchantAccount' => $this->merchantAccount,
            'partnerCode' => $partner->partnerCode,
            'phone' => $partner->phone,
            'email' => $partner->email,
        ]);

        return $this->sendMmsRequest('addPartner.php', $data);
    }

    public function partnerInfo(string $partnerCode): array
    {
        $data = [
            'merchantAccount' => $this->merchantAccount,
            'partnerCode' => $partnerCode,
        ];

        $data['merchantSignature'] = $this->signatureGenerator->generateForPartnerInfo($data);

        return $this->sendMmsRequest('partnerInfo.php', $data);
    }

    private const ALLOWED_UPDATE_FIELDS = [
        'site', 'phone', 'email', 'description',
        'compensationCardNumber', 'compensationCardExpYear', 'compensationCardExpMonth',
        'compensationCardCvv', 'compensationCardHolder', 'compensationCardToken',
        'compensationAccount', 'compensationAccountIban', 'compensationAccountMfo',
        'compensationAccountOkpo', 'compensationAccountName',
    ];

    public function updatePartner(string $partnerCode, array $updates): array
    {
        $filtered = array_intersect_key($updates, array_flip(self::ALLOWED_UPDATE_FIELDS));

        $data = array_merge($filtered, [
            'merchantAccount' => $this->merchantAccount,
            'merchantAccountEdit' => $partnerCode,
        ]);

        $data['merchantSignature'] = $this->signatureGenerator->generateForUpdatePartner([
            'merchantAccount' => $this->merchantAccount,
            'partnerCode' => $partnerCode,
        ]);

        return $this->sendMmsRequest('updatePartner.php', $data);
    }

    public function addMerchant(Merchant $merchant): array
    {
        $data = array_merge(
            ['merchantAccount' => $this->merchantAccount],
            $merchant->toArray()
        );

        $data['merchantSignature'] = $this->signatureGenerator->generateForAddMerchant([
            'merchantAccount' => $this->merchantAccount,
            'site' => $merchant->site,
            'phone' => $merchant->phone,
            'email' => $merchant->email,
        ]);

        return $this->sendMmsRequest('addMerchant.php', $data);
    }

    public function merchantInfo(string $merchantAccountInfo, string $secretKey): array
    {
        $data = [
            'merchantAccount' => $this->merchantAccount,
            'merchantAccountInfo' => $merchantAccountInfo,
            'secretKey' => $secretKey,
        ];

        return $this->sendMmsRequest('merchantInfo.php', $data);
    }

    public function merchantBalance(?string $toDate = null): array
    {
        $data = [
            'merchantAccount' => $this->merchantAccount,
        ];

        $data['merchantSignature'] = $this->signatureGenerator->generateForMerchantBalance($data);

        if ($toDate !== null) {
            if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $toDate)) {
                throw new InvalidArgumentException('toDate must be in dd.mm.yyyy format');
            }
            $data['toDate'] = $toDate;
        }

        return $this->sendMmsRequest('merchantBalance.php', $data);
    }

    private function sendMmsRequest(string $endpoint, array $data): array
    {
        $response = $this->http->asJson()
            ->timeout($this->timeout)
            ->post($this->baseUrl . $endpoint, $data);

        return $this->parseResponse($response, 'MMS API');
    }
}
