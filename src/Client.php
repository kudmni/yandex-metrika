<?php

namespace PrCy\YandexMetrika;

use \GuzzleHttp\Client as HttpClient;
use \PrCy\YandexMetrika\Exception\InvalidParams;
use \PrCy\YandexMetrika\Exception\AccessTokenError;
use \TrueBV\Punycode;

class Client
{
    private $credentials = [
        'clientId'     => null,
        'clientSecret' => null
    ];

    protected $accessToken = null;
    protected $httpClient  = null;
    protected $punycode    = null;

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

    public function setPunycode(Punycode $punycode)
    {
        $this->punycode = $punycode;
    }

    protected function getPunycode()
    {
        if ($this->punycode === null) {
            // @codeCoverageIgnoreStart
            $this->setPunycode(new Punycode());
        }
        // @codeCoverageIgnoreEnd
        return $this->punycode;
    }

    /**
    * Формирование запроса кода подтверждения
    * @param array $params - массив с возможными ключами
    * device_id - идентификатор устройства
    * device_name - имя устройства
    * login_hint - имя пользователя или электронный адрес
    * scope - запрашиваемые необходимые права
    * optional_scope -запрашиваемые опциональные права
    * force_confirm - признак того, что у пользователя обязательно нужно запросить разрешение на доступ к аккаунту, обрабатывается, если для него указано значение «yes», «true» или «1»
    * state -  произвольная строка
    */
    public function getAuthUrl($params = [])
    {
        $defaultParams = [
            'response_type'  => 'code',
            'client_id'      => $this->credentials['clientId'],
            'device_id'      => null,
            'device_name'    => null,
            'login_hint'     => null,
            'scope'          => null,
            'optional_scope' => null,
            'force_confirm'  => null,
            'state'          => null
        ];
        $mergedParams = array_merge($defaultParams, $params);
        // Удаляем пустые значения
        $nonEmptyParams = array_filter($mergedParams, function($item){
            return !empty($item);
        });
        // Не пропускаем неизвестные параметры
        $unknownParams = array_diff_key($nonEmptyParams, $defaultParams);
        if (count($unknownParams)) {
            throw new InvalidParams("Unknown parameter(s): " . join(', ', array_keys($unknownParams)));
        }
        return 'https://oauth.yandex.ru/authorize?' . http_build_query($nonEmptyParams);
    }

    public function getToken($code)
    {
        $data = $this->post(
            'https://oauth.yandex.ru/token',
            [
                'grant_type'     => 'authorization_code',
                'code'           => $code,
                'client_id'      => $this->credentials['clientId'],
                'client_secret'  => $this->credentials['clientSecret'],
            ]
        );
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
        $domain = preg_replace('/^www\./i', '', $domain);
        // Decode domain, if punycode is detected
        if (strpos($domain, 'xn--') === 0) {
            $domain = $this->getPunycode()->decode($domain);
        }
        $response  = $this->get('https://api-metrika.yandex.ru/management/v1/counters');
        $counters  = empty($response['counters']) ? [] : $response['counters'];
        $counterId = false;
        foreach ($counters as $counter) {
            if ($counter['site'] == $domain || $counter['site'] == 'www.' . $domain) {
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
