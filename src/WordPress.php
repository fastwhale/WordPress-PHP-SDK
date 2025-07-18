<?php

namespace Fastwhale\WordPress;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Fastwhale\WordPress\Exception\ServerErrorException;
use Fastwhale\WordPress\Exception\UnauthorizedException;
use Fastwhale\WordPress\Exception\ObjectNotFoundException;
use Fastwhale\WordPress\Exception\ValidationException;
use Fastwhale\WordPress\Object\User;
use Fastwhale\WordPress\Object\CustomPost;
use Fastwhale\WordPress\Object\Tag;

/**
 * WordPress PHP SDK.
 *
 * @version    1.0.0
 *
 * @copyright  Copyright (c) 2019 WordPress (https://www.madeit.be)
 * @author     Tjebbe Lievens <tjebbe.lievens@madeit.be>
 * @license    http://www.gnu.org/licenses/old-licenses/lgpl-3.txt    LGPL
 */
class WordPress
{
    protected $version = '1.4.0';
    private $server = 'https://localhost';
    private $accessToken = null;

    private $client = null;
    private $outputAsObject = true;
    private $rawOutput = false;
    private $latestHeader = null;

    //application password
    private $username = null;
    private $password = null;

    /**
     * Construct WordPress.
     *
     * @param $clientId
     * @param $clientSecret;
     * @param $client
     */
	public function __construct ($appUrl, $client = NULL)
	{
		$this->setServer($appUrl, $client);
	}

    public function setClient($client)
    {
        $this->client = $client;
    }

    public function getClient()
    {
        return $this->client;
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function getLatestHeader()
    {
        return $this->latestHeader;
    }

	public function setServer ($url, $client = NULL)
	{
		$this->server = rtrim($url, '/') . '/';

		if ($client == NULL) {
			$this->client = new Client([
				'base_uri' => $this->server,
				'timeout'  => 60.0,
				'headers'  => [
					'User-Agent' => 'Made I.T. - WordPress PHP SDK V' . $this->version,
					'Accept'     => 'application/json',
				],
				'verify'   => true,
			]);
		} else {
			$this->setClient($client);
		}

		return $this;
	}

    public function getServer()
    {
        return $this->server;
    }

    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    public function setApplicationPassword($password)
    {
        $this->password = $password;
        return $this;
    }

	private function cannotRetry ()
	{
		if (strpos($this->server, 'index.php') !== false) {
			return true;
		}

		$newUri                    = rtrim($this->server, '/') . '/index.php/';
		$clientOptions             = $this->getClient()->getConfig();
		$clientOptions['base_uri'] = $newUri;
		$newClient                 = new Client($clientOptions);
		$this->setServer($newUri, $newClient);

		return false;
	}


    /**
     * Execute API call.
     *
     * @param $requestType
     * @param $endPoint
     * @param $data
     */
    private function call($requestType, $endPoint, $data = null)
    {
        $endPoint = '/'.ltrim($endPoint, '/');

        $body = [];
        if ($data != null && isset($data['multipart'])) {
            $body = $data;
        } elseif ($data != null && $requestType !== 'GET') {
            $body = ['json' => $data];
        } else if($data !== null && $requestType === 'GET') {
            $endPoint .= '?'.http_build_query($data);
        }

        $endPoint = '/'.ltrim($endPoint, '/');
        $headers = $this->buildHeader($endPoint);
	    $endPoint = ltrim($endPoint, '/');

        try {
            $response = $this->client->request($requestType, $endPoint, $body + $headers);
        } catch (ServerException $e) {
	        if ($this->cannotRetry()) {
		        throw new ServerErrorException($e->getMessage(), $e->getCode(), $e);
	        } else {
		        return $this->call($requestType, $endPoint, $data);
	        }
        } catch (ClientException $e) {
	        if ($this->cannotRetry()) {
		        if ($e->getCode() == 401) {
			        throw new UnauthorizedException($e->getResponse(), $e);
		        } elseif ($e->getCode() == 403) {
			        throw new UnauthorizedException($e->getCode(), $e);
		        } elseif ($e->getCode() == 404) {
			        throw new ObjectNotFoundException($e->getCode(), $e);
		        } elseif ($e->getCode() == 400) {
			        throw new ValidationException($e->getResponse(), $e->getCode(), $e);
		        } elseif ($e->getCode() == 422) {
			        throw new ValidationException($e->getResponse(), $e->getCode(), $e);
		        }

		        throw $e;
	        } else {
		        return $this->call($requestType, $endPoint, $data);
	        }
        }

        if (in_array($response->getStatusCode(), [200, 201])) {
            $body = (string) $response->getBody();
	        $body = preg_replace("/^\xEF\xBB\xBF|\xEF\xBB\xBF$/u", '', $body);
            $this->latestHeader = $response->getHeaders();
        } elseif ($response->getStatusCode() == 401) {
            throw new UnauthorizedException();
        } else {
            throw new ServerErrorException('', $response->getStatusCode());
        }

        if ($this->rawOutput) {
            return $body;
        }

        return json_decode($body, !$this->outputAsObject);
    }

    public function buildHeader($endPoint)
    {
        $headers = ['headers' => []];

        if (!empty($this->accessToken)) {
            $headers['headers'] = ['Authorization' => 'Bearer '.$this->accessToken];
        }

        if(!empty($this->username) && !empty($this->password)) {
            $headers['auth'] = [$this->username, $this->password];
        }

        return $headers;
    }

    public function postCall($endPoint, $data)
    {
        return $this->call('POST', $endPoint, $data);
    }

    public function getCall($endPoint, $data = null)
    {
        return $this->call('GET', $endPoint, $data);
    }

    public function putCall($endPoint, $data)
    {
        return $this->call('PUT', $endPoint, $data);
    }

    public function deleteCall($endPoint)
    {
        return $this->call('DELETE', $endPoint);
    }

    public function optionsCall($endPoint)
    {
        return $this->call('OPTIONS', $endPoint);
    }

    /**
     * Login.
     */
    public function login($email, $password)
    {
        $result = $this->postCall('/wp-json/jwt-auth/v1/token', [
            'username' => $email,
            'password' => $password,
        ]);

        $this->setAccessToken($result->token);

        return $result;
    }

    public function user()
    {
        $user = new User($this);

        return $user;
    }

    public function post()
    {
        $post = new CustomPost($this, 'posts');

        return $post;
    }

    public function customPost($postType)
    {
        $post = new CustomPost($this, $postType);

        return $post;
    }

    public function tag()
    {
        $tag = new Tag($this);

        return $tag;
    }

    public function outputAsObject($outputAsObject)
    {
        $this->outputAsObject = $outputAsObject;
    }

    public function rawOutput($rawOutput)
    {
        $this->rawOutput = $rawOutput;
    }
}
