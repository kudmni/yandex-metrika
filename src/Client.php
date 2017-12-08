<?php

namespace PrCy\YandexMetrika;

use \GuzzleHttp\Client;
use PrCy\YandexMetrika\Exception\InvalidParams;
use PrCy\YandexMetrika\Exception\InvalidRequestMethod;
use PrCy\YandexMetrika\Exception\AccessTokenError;

class Client
{
    private $credentials = [
        'clientId'     => null,
        'clientSecret' => null
    ];

    private $accessToken = null;

    public function __construct($credentials = [])
    {
        if (empty($credentials['clientId']) || empty($credentials['clientSecret'])) {
            throw new InvalidParams('client id or secret is undefined');
        }
        $this->credentials = array_merge($this->credentials, $credentials);
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    private function getClient()
    {
        if ($this->client === null) {
            $this->setClient(new Client());
        }
        return $this->client;
    }

    public function getAuthUrl()
    {
        $params = [
            'response_type'  => 'code',
            'client_id'      => $this->credentials['clientId']
        ];
        return 'https://oauth.yandex.ru/authorize?' . http_build_query($params);
    }

    public function getToken($code)
    {
        $data = $this->rawRequest('POST', 'https://oauth.yandex.ru/token', [
            'grant_type'     => 'authorization_code',
            'code'           => $code,
            'client_id'      => $this->credentials['clientId'],
            'client_secret'  => $this->credentials['clientSecret'],
        ]);
        if (!$data) {
            throw new AccessTokenError('Unable to get access token by application code');
        }
        if (isset($data['error'])) {
            throw new AccessTokenError('Error occurred while getting access token: ' . $data['error']);
        }
        if (!isset($data['access_token'])) {
            throw new AccessTokenError('No errors, but access token is absent: ' . print_r($data, true));
        }

        $this->setToken($data['access_token']);
        return $this->accessToken;
    }

    public function setToken($token)
    {
        $this->accessToken = $token;
    }

    public function getUserInfo()
    {
        return $this->rawRequest('GET', 'https://login.yandex.ru/info', ['format' => 'json']);
    }

    public function getCounterId($domain)
    {
        if (empty($domain)) {
            return false;
        }
        $noWwwDomain = preg_replace('/^www\./i', '', $domain);
        $counters = $this->rawRequest('GET', 'http://api-metrika.yandex.ru/counters.json', ['pretty' => 1]);
        if (empty($counters['counters'])) {
            return false;
        }
        foreach ($counters['counters'] as $counter) {
            if ($counter['site'] == $noWwwDomain || $counter['site'] == 'www.' . $noWwwDomain) {
                return $counter['id'];
            }
        }
        return false;
    }

    public function getKeywordsTable($counterId)
    {
        $keywordsTable = [];
        if (empty($counterId)) {
            return $keywordsTable;
        }
        $params = [
            'id'         => $counterId,
            'dimensions' => 'ym:s:searchPhrase',
            'metrics'    => 'ym:s:visits',
            'sort'       => '-ym:s:visits',
            'date1'      => date('Y-m-d', strtotime('-30 day')),
            'date2'      => date('Y-m-d', strtotime('-1 day')),
            'limit'      => 150
        ];
        $keywords = $this->rawRequest(
            'GET',
            'https://api-metrika.yandex.ru/stat/v1/data',
            $params
        );
        if (empty($keywords['data'])) {
            return $keywordsTable;
        }
        foreach ($keywords['data'] as $entry) {
            $keywordsTable[] = ['keyword' => $entry['dimensions'][0]['name'],  'visits' => $entry['metrics'][0]];
        }
        return $keywordsTable;
    }

    protected function rawRequest($method, $url, $params = [])
    {
        if ($this->accessToken) {
            $params['oauth_token'] = $this->accessToken;
        }

        $httpClient = $this->getClient();
        switch ($method) {
            case 'POST':
                $response = $httpClient->post($url, ['form_params' => $params]);
                break;
            case 'GET':
                $response = $httpClient->get($url, ['query' => $params]);
                break;
            default:
                throw new InvalidRequestMethod('Unsupported request method: ' . $method);
        }

        return json_decode($response->getBody()->getContents(), true);
    }
}
