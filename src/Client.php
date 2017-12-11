<?php

namespace PrCy\YandexMetrika;

use \GuzzleHttp\Client as HttpClient;
use \PrCy\YandexMetrika\Exception\InvalidParams;
use \PrCy\YandexMetrika\Exception\AccessTokenError;

class Client
{
    private $credentials = [
        'clientId'     => null,
        'clientSecret' => null
    ];

    protected $accessToken = null;
    protected $httpClient  = null;

    public function __construct($credentials = [])
    {
        if (empty($credentials['clientId']) || empty($credentials['clientSecret'])) {
            throw new InvalidParams('client id or secret is undefined');
        }
        $this->credentials = array_merge($this->credentials, $credentials);
    }

    public function setHttpClient(HttpClient $client)
    {
        $this->httpClient = $client;
    }

    protected function getHttpClient()
    {
        if ($this->httpClient === null) {
            // @codeCoverageIgnoreStart
            $this->setHttpClient(new HttpClient());
        }
        // @codeCoverageIgnoreEnd
        return $this->httpClient;
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
        $data = $this->post('POST', 'https://oauth.yandex.ru/token', [
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
        return $this->get('https://login.yandex.ru/info', ['format' => 'json']);
    }

    public function getCounterId($domain)
    {
        $noWwwDomain = preg_replace('/^www\./i', '', $domain);
        $response    = $this->get('http://api-metrika.yandex.ru/counters.json', ['pretty' => 1]);
        $counters    = empty($response['counters']) ? [] : $response['counters'];
        $counterId   = false;
        foreach ($counters as $counter) {
            if ($counter['site'] == $noWwwDomain || $counter['site'] == 'www.' . $noWwwDomain) {
                $counterId = $counter['id'];
                break;
            }
        }
        return $counterId;
    }

    public function getKeywordsTable($counterId)
    {
        $keywordsTable = [];
        $params        = [
            'id'         => $counterId,
            'dimensions' => 'ym:s:searchPhrase',
            'metrics'    => 'ym:s:visits',
            'sort'       => '-ym:s:visits',
            'date1'      => date('Y-m-d', strtotime('-30 day')),
            'date2'      => date('Y-m-d', strtotime('-1 day')),
            'limit'      => 150
        ];
        $response = $this->get('https://api-metrika.yandex.ru/stat/v1/data', $params);
        $keywords = empty($response['data']) ? [] : $response['data'];
        foreach ($keywords as $entry) {
            $keywordsTable[] = [
                'keyword' => $entry['dimensions'][0]['name'],
                'visits' => $entry['metrics'][0]
            ];
        }
        return $keywordsTable;
    }

    protected function getAuthHeaders()
    {
        $headers = [];
        if ($this->accessToken) {
            $headers['Authorization'] = 'OAuth ' . $this->accessToken;
        }
        return $headers;
    }

    protected function get($url, $params = [])
    {
        $response = $this->getHttpClient()->get(
            $url,
            [
                'query'   => $params,
                'headers' => $this->getAuthHeaders()
            ]
        );
        return $this->processResponse($response);
    }

    protected function post($url, $params = [])
    {
        $response = $this->getHttpClient()
            ->post(
                $url,
                [
                    'form_params' => $params,
                    'headers'     => $this->getAuthHeaders()
                ]
            );
        return $this->processResponse($response);
    }

    protected function processResponse($response)
    {
        return json_decode($response->getBody()->getContents(), true);
    }
}
