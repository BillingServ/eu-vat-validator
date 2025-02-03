<?php
/**
 * @author         Pierre-Henry Soria <hi@ph7.me>
 * @copyright      (c) 2017-2024, Pierre-Henry Soria. All Rights Reserved.
 * @license        GNU General Public License; <https://www.gnu.org/licenses/gpl-3.0.en.html>
 */

declare(strict_types=1);

namespace PH7\Eu\Vat\Provider;

use PH7\Eu\Vat\Exception;
use SoapClient;
use SoapFault;

class VatDetails
{
    public function __construct(
        public readonly ?string $countryCode,
        public readonly ?string $vatNumber,
        public readonly ?string $requestDate,
        public readonly bool $valid,
        public readonly string $name,
        public readonly string $address,
        public readonly ?string $consultationNumber
    ) {
    }

    public function toArray(): array
    {
        return [
            'countryCode' => $this->countryCode,
            'vatNumber' => $this->vatNumber,
            'requestDate' => $this->requestDate,
            'valid' => $this->valid,
            'name' => $this->name,
            'address' => $this->address,
            'consultationNumber' => $this->consultationNumber
        ];
    }
}

interface Providable
{
    public function getResource(string $vatNumber, string $countryCode): VatDetails;
}

class Europa implements Providable
{
    public const EU_VAT_API_URL = 'https://ec.europa.eu';
    public const EU_VAT_WSDL_ENDPOINT = '/taxation_customs/vies/checkVatService.wsdl';

    private const IMPOSSIBLE_CONNECT_API_MESSAGE = 'Impossible to connect to the Europa SOAP: %s';
    private const IMPOSSIBLE_RETRIEVE_DATA_MESSAGE = 'Impossible to retrieve the VAT details: %s';

    private SoapClient $client;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        try {
            $this->client = new SoapClient($this->getApiUrl());
        } catch (SoapFault $except) {
            throw new Exception(
                sprintf(self::IMPOSSIBLE_CONNECT_API_MESSAGE, $except->faultstring),
                0,
                $except
            );
        }
    }

    public function getApiUrl(): string
    {
        return static::EU_VAT_API_URL . static::EU_VAT_WSDL_ENDPOINT;
    }

    /**
     * @throws Exception
     */
    public function getResource(string $vatNumber, string $countryCode): VatDetails
    {
        return $this->getResourceWithRequester($vatNumber, $countryCode);
    }

    /**
     * @throws Exception
     */
    public function getResourceWithRequester(
        string $vatNumber,
        string $countryCode,
        ?string $requesterVatNumber = null,
        ?string $requesterCountryCode = null
    ): VatDetails {
        try {
            $details = [
                'countryCode' => strtoupper($countryCode),
                'vatNumber' => $vatNumber,
            ];

            if ($requesterVatNumber && $requesterCountryCode) {
                $details['requesterCountryCode'] = strtoupper($requesterCountryCode);
                $details['requesterVatNumber'] = $requesterVatNumber;
            }

            $businessResponse = $this->client->checkVat([
                'countryCode' => $details['countryCode'],
                'vatNumber' => $details['vatNumber'],
            ]);

            $consultationResponse = null;
            if ($requesterVatNumber) {
                $consultationResponse = $this->client->checkVatApprox($details);
            }

            return new VatDetails(
                countryCode: $businessResponse->countryCode ?? null,
                vatNumber: $businessResponse->vatNumber ?? null,
                requestDate: $businessResponse->requestDate ?? null,
                valid: $businessResponse->valid ?? false,
                name: $businessResponse->name ?? 'N/A',
                address: $businessResponse->address ?? 'N/A',
                consultationNumber: $consultationResponse->requestIdentifier ?? 'Not provided'
            );
        } catch (SoapFault $except) {
            throw new Exception(
                sprintf(self::IMPOSSIBLE_RETRIEVE_DATA_MESSAGE, $except->faultstring)
            );
        }
    }
}