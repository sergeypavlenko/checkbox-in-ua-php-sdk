<?php

namespace Checkbox;

use Checkbox\Errors\InvalidCredentials;
use Checkbox\Errors\EmptyResponse;
use Checkbox\Errors\Validation;
use Checkbox\Mappers\Cashier\CashierMapper;
use Checkbox\Mappers\CashRegisters\CashRegisterInfoMapper;
use Checkbox\Mappers\CashRegisters\CashRegisterMapper;
use Checkbox\Mappers\CashRegisters\CashRegistersMapper;
use Checkbox\Mappers\Receipts\ReceiptMapper;
use Checkbox\Mappers\Receipts\ReceiptsMapper;
use Checkbox\Mappers\Receipts\SellReceiptMapper;
use Checkbox\Mappers\Receipts\Taxes\GoodTaxesMapper;
use Checkbox\Mappers\Shifts\CloseShiftMapper;
use Checkbox\Mappers\Shifts\CreateShiftMapper;
use Checkbox\Mappers\Shifts\ShiftMapper;
use Checkbox\Mappers\Shifts\ShiftsMapper;
use Checkbox\Models\Cashier\Cashier;
use Checkbox\Models\CashRegisters\CashRegistersQueryParams;
use Checkbox\Models\Receipts\ReceiptsQueryParams;
use Checkbox\Models\Receipts\SellReceipt;
use Checkbox\Models\Shifts\CloseShift;
use Checkbox\Models\Shifts\CreateShift;
use Checkbox\Models\Shifts\Shift;
use Checkbox\Models\Shifts\Shifts;
use Checkbox\Models\Shifts\ShiftsQueryParams;
use GuzzleHttp\Client;

class CheckboxJsonApi
{
    private $routes;
    private $guzzleClient;
    private $connectTimeout;
    private $config = null;
    private $requestOptions;

    const METHOD_GET = 'get';
    const METHOD_POST = 'post';

    public function __construct(Config $config = null, int $connectTimeoutSeconds = 5)
    {
        if (!is_null($config)) {
            $this->routes = new Routes($config->get(Config::API_URL));
        }

        $this->config = $config;
        $this->connectTimeout = $connectTimeoutSeconds;

        $this->guzzleClient = new Client([
            'verify' => false,
            'http_errors' => false
        ]);

        $this->requestOptions = [
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'Content-Type' => 'application/json'
            ]
        ];

        if (!is_null($config)) {
            $this->requestOptions['headers']['X-License-Key'] = $this->config->get('licenseKey');
        }

        return $this;
    }

    public function setConfig(Config $config)
    {
        return new CheckboxJsonApi($config);
    }

    public function setConnectTimeout(int $connectTimeoutSeconds)
    {
        return new CheckboxJsonApi($this->config, $connectTimeoutSeconds);
    }


    private function setHeadersWithToken(string $token)
    {
        $this->requestOptions['headers']['Authorization'] = 'Bearer ' . $token;
    }

    private function validateResponseStatus($json, $statusCode)
    {
        switch ($statusCode) {
            case 403:
                throw new InvalidCredentials($json['message']);
                break;
            case 422:
                throw new Validation($json);
                break;
        }
    }

    // start Cashier methods //

    public function signInCashier()
    {
        $options = $this->requestOptions;
        $options['body'] = \json_encode([
            'login' => $this->config->get(Config::LOGIN),
            'password' => $this->config->get(Config::PASSWORD)
        ]);

        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->singInCashier(),
            $options
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        if (is_null($jsonResponse)) {
            throw new EmptyResponse('Запрос вернул пустой результат');
        }

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        $this->setHeadersWithToken($jsonResponse['access_token']);

        return $this;
    }

    public function signOutCashier()
    {
        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->singOutCashier(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return $this;
    }

    /*
    public function signInCashierViaSignature(string $signature)
    {
        $options = $this->requestOptions;
        $options['body'] = \json_encode([
            'signature' => $signature
        ]);

        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->signInCashierViaSignature(),
            $options
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return $jsonResponse;
    }
    */

    /*
    public function signInCashierViaPinCode(string $pinCode)
    {
        $options = $this->requestOptions;
        $options['body'] = \json_encode([
            'pin_code' => $pinCode
        ]);

        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->signInCashierViaPinCode(),
            $options
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return $jsonResponse;
    }
    */

    public function getCashierProfile(): Cashier
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getCashierProfile(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new CashierMapper())->jsonToObject($jsonResponse);
    }

    public function getCashierShift(): Shift
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getCashierShift(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        if (is_null($jsonResponse)) {
            throw new EmptyResponse('Запрос вернул пустой результат');
        }

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new ShiftMapper())->jsonToObject($jsonResponse);
    }

    public function pingTaxServiceAction()
    {
        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->pingTaxServiceAction(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return $jsonResponse;
    }

    // end Cashier methods //

    // start Shift methods //

    public function getShifts(ShiftsQueryParams $queryParams = null): Shifts
    {
        if (is_null($queryParams)) {
            $queryParams = new ShiftsQueryParams();
        }

        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getShifts($queryParams),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new ShiftsMapper())->jsonToObject($jsonResponse);
    }

    public function createShift(): CreateShift
    {
        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->createShift(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new CreateShiftMapper())->jsonToObject($jsonResponse);
    }

    public function getShift(string $shiftId): Shift
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getShift($shiftId),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new ShiftMapper())->jsonToObject($jsonResponse);
    }

    public function closeShift(): CloseShift
    {
        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->closeShift(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new CloseShiftMapper())->jsonToObject($jsonResponse);
    }

    // end Shift methods //

    // start cash registers methods //

    public function getCashRegisters(CashRegistersQueryParams $queryParams = null)
    {
        if (is_null($queryParams)) {
            $queryParams = new CashRegistersQueryParams();
        }

        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getCashRegisters($queryParams),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new CashRegistersMapper())->jsonToObject($jsonResponse);
    }

    public function getCashRegister(string $registerId)
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getCashRegister($registerId),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new CashRegisterMapper())->jsonToObject($jsonResponse);
    }

    public function getCashRegisterInfo()
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getCashRegisterInfo(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new CashRegisterInfoMapper())->jsonToObject($jsonResponse);
    }

    // end cash registers methods //

    // start receipts methods //

    public function getReceipts(ReceiptsQueryParams $queryParams = null)
    {
        if (is_null($queryParams)) {
            $queryParams = new ReceiptsQueryParams();
        }
        $this->routes->getReceipts($queryParams);

        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getReceipts($queryParams),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new ReceiptsMapper())->jsonToObject($jsonResponse);
    }

    public function getReceipt(string $receiptId)
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getReceipt($receiptId),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new ReceiptMapper())->jsonToObject($jsonResponse);
    }

    public function createSellReceipt(SellReceipt $receipt)
    {
        $options = $this->requestOptions;
        $options['body'] = \json_encode((new SellReceiptMapper())->objectToJson($receipt));

        $response = $this->guzzleClient->request(
            self::METHOD_POST,
            $this->routes->createSellReceipt(),
            $options
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new ReceiptMapper())->jsonToObject($jsonResponse);
    }

    public function getReceiptPdf(string $receiptId)
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getReceiptPdf($receiptId),
            $this->requestOptions
        );

        $response = $response->getBody()->getContents();

        $jsonResponse = json_decode($response, true);

        if (!is_null($jsonResponse)) {
            throw new Validation($jsonResponse);
        }

        return $response;
    }

    public function getReceiptHtml(string $receiptId)
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getReceiptHtml($receiptId),
            $this->requestOptions
        );

        $response = $response->getBody()->getContents();

        $jsonResponse = json_decode($response, true);

        if (!is_null($jsonResponse)) {
            throw new Validation($jsonResponse);
        }

        return $response;
    }

    public function getReceiptText(string $receiptId)
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getReceiptText($receiptId),
            $this->requestOptions
        );

        $response = $response->getBody()->getContents();

        $jsonResponse = json_decode($response, true);

        if (!is_null($jsonResponse)) {
            throw new Validation($jsonResponse);
        }

        return $response;
    }

    public function getReceiptQrCodeImage(string $receiptId)
    {
        $options = $this->requestOptions;
        $options['headers']['Content-Type'] = 'image/png';

        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getReceiptQrCodeImage($receiptId),
            $options
        );

        $response = $response->getBody()->getContents();

        $jsonResponse = json_decode($response, true);

        if (!is_null($jsonResponse)) {
            throw new Validation($jsonResponse);
        }

        return $response;
    }

    // end receipts methods //

    // start taxes methods //

    public function getAllTaxes()
    {
        $response = $this->guzzleClient->request(
            self::METHOD_GET,
            $this->routes->getAllTaxes(),
            $this->requestOptions
        );

        $jsonResponse = json_decode($response->getBody()->getContents(), true);

        $this->validateResponseStatus($jsonResponse, $response->getStatusCode());

        return (new GoodTaxesMapper())->jsonToObject($jsonResponse);
    }

    // end taxes methods //






}