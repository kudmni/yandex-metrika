<?php

namespace PrCy\YandexMetrika;

use \PrCy\YandexMetrika\Client;

/**
 * Class ClientTest
 * @package PrCy\YandexMetrika
 */
class ClientTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \PrCy\YandexMetrika\Exception\InvalidParams
     */
    public function testConstructWithoutCredentials()
    {
        new Client();
    }

    public function testGetAuthUrl()
    {
        $clientId        = 'clientIdMock';
        $clientSecret    = 'clientSecretMock';
        $client          = new Client(['clientId' => $clientId, 'clientSecret' => $clientSecret]);
        $url             = $client->getAuthUrl();
        $this->assertEquals(
            'https://oauth.yandex.ru/authorize?response_type=code&client_id=' . $clientId,
            $url
        );
    }

    /**
     * @expectedException \PrCy\YandexMetrika\Exception\AccessTokenError
     */
    public function testGetTokenEmptyResponse()
    {
        $clientId     = 'clientIdMock';
        $clientSecret = 'clientSecretMock';
        $client = $this->getMockBuilder('\PrCy\YandexMetrika\Client')
            ->setConstructorArgs([['clientId' => $clientId, 'clientSecret' => $clientSecret]])
            ->setMethods(['post'])
            ->getMock();
        $client->expects($this->once())
            ->method('post')
            ->willReturn(false);
        $code = 'codeMock';
        $client->getToken($code);
    }

    /**
     * @expectedException \PrCy\YandexMetrika\Exception\AccessTokenError
     */
    public function testGetTokenError()
    {
        $clientId     = 'clientIdMock';
        $clientSecret = 'clientSecretMock';
        $client = $this->getMockBuilder('\PrCy\YandexMetrika\Client')
            ->setConstructorArgs([['clientId' => $clientId, 'clientSecret' => $clientSecret]])
            ->setMethods(['post'])
            ->getMock();
        $client->expects($this->once())
            ->method('post')
            ->willReturn(['error' => 'Error mock']);
        $code = 'codeMock';
        $client->getToken($code);
    }

    /**
     * @expectedException \PrCy\YandexMetrika\Exception\AccessTokenError
     */
    public function testGetTokenIsAbsent()
    {
        $clientId     = 'clientIdMock';
        $clientSecret = 'clientSecretMock';
        $client = $this->getMockBuilder('\PrCy\YandexMetrika\Client')
            ->setConstructorArgs([['clientId' => $clientId, 'clientSecret' => $clientSecret]])
            ->setMethods(['post'])
            ->getMock();
        $client->expects($this->once())
            ->method('post')
            ->willReturn(['foo' => 'bar']);
        $code = 'codeMock';
        $client->getToken($code);
    }

    public function testGetTokenSuccess()
    {
        $clientId     = 'clientIdMock';
        $clientSecret = 'clientSecretMock';
        $client       = new Client(['clientId' => $clientId, 'clientSecret' => $clientSecret]);

        $tokenData = ['access_token' => 'accessTokenMock'];
        $streamMock = $this->getMockBuilder('\GuzzleHttp\Psr7\Stream')
            ->disableOriginalConstructor()
            ->getMock();
        $streamMock->expects($this->once())
            ->method('getContents')
            ->willReturn(json_encode($tokenData));

        $responseMock = $this->getMockBuilder('\GuzzleHttp\Psr7\Response')
            ->disableOriginalConstructor()
            ->getMock();
        $responseMock->expects($this->once())
            ->method('getBody')
            ->willReturn($streamMock);

        $httpClientMock = $this->getMockBuilder('\GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->setMethods(['post'])
            ->getMock();
        $httpClientMock->expects($this->once())
            ->method('post')
            ->willReturn($responseMock);

        $client->setHttpClient($httpClientMock);

        $code = 'codeMock';
        $this->assertEquals($tokenData['access_token'], $client->getToken($code));
    }

    public function testGetUserInfo()
    {
        $clientId     = 'clientIdMock';
        $clientSecret = 'clientSecretMock';
        $client       = new Client(['clientId' => $clientId, 'clientSecret' => $clientSecret]);
        
        $userDataMock = ['foo' => 'bar'];
        
        $streamMock = $this->getMockBuilder('\GuzzleHttp\Psr7\Stream')
            ->disableOriginalConstructor()
            ->getMock();
        $streamMock->expects($this->once())
            ->method('getContents')
            ->willReturn(json_encode($userDataMock));

        $responseMock = $this->getMockBuilder('\GuzzleHttp\Psr7\Response')
            ->disableOriginalConstructor()
            ->getMock();
        $responseMock->expects($this->once())
            ->method('getBody')
            ->willReturn($streamMock);

        $httpClientMock = $this->getMockBuilder('\GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->setMethods(['get'])
            ->getMock();
        $httpClientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $client->setHttpClient($httpClientMock);

        $token = 'tokenMock';
        $client->setToken($token);

        $this->assertEquals($userDataMock, $client->getUserInfo());
    }

    public function testGetCounterId()
    {
        $counterId    = 12345;
        $responseMock = [
            'counters' => [[
                'id'   => $counterId,
                'site' => 'президент.рф'
            ]]
        ];
        $clientId     = 'clientIdMock';
        $clientSecret = 'clientSecretMock';
        $client       = $this->getMockBuilder('\PrCy\YandexMetrika\Client')
            ->setConstructorArgs([['clientId' => $clientId, 'clientSecret' => $clientSecret]])
            ->setMethods(['get'])
            ->getMock();
        $client->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $punycodeMock = $this->getMockBuilder('\TrueBV\Punycode')
            ->disableOriginalConstructor()
            ->getMock();
        $punycodeMock->expects($this->once())
            ->method('decode')
            ->with('xn--d1abbgf6aiiy.xn--p1ai')
            ->willReturn('президент.рф');
        $client->setPunycode($punycodeMock);

        $this->assertEquals($counterId, $client->getCounterId('xn--d1abbgf6aiiy.xn--p1ai'));
    }

    public function testGetKeywordsTable()
    {
        $counterId    = 12345;
        $responseMock = [
            'data' => [[
                'dimensions' => [['name' => 'Foo bar']],
                'metrics'    => [100500]
            ]]
        ];
        $clientId     = 'clientIdMock';
        $clientSecret = 'clientSecretMock';
        $client       = $this->getMockBuilder('\PrCy\YandexMetrika\Client')
            ->setConstructorArgs([['clientId' => $clientId, 'clientSecret' => $clientSecret]])
            ->setMethods(['get'])
            ->getMock();
        $client->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);
        $result = $client->getKeywordsTable($counterId);
        $this->assertEquals(
            ['keyword' => 'Foo bar', 'visits' => 100500],
            $result[0]
        );
    }
}
