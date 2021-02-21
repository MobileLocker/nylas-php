<?php

namespace Nylas;


use GuzzleHttp\Utils;
use Nylas\Models;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;


class Nylas
{

    protected string $apiServer = 'https://api.nylas.com';
    protected GuzzleClient $apiClient;
    protected ?string $apiToken;
    public string $apiRoot = '';

    public function __construct(string $appID,
                                string $appSecret,
                                ?string $token = null,
                                ?string $apiServer = null)
    {
        $this->appID = $appID;
        $this->appSecret = $appSecret;
        $this->apiToken = $token;
        $this->apiClient = $this->createApiClient();

        if ($apiServer) {
            $this->apiServer = $apiServer;
        }
    }

    protected function createHeaders(): array
    {
        $token = 'Basic ' . base64_encode($this->apiToken . ':');
        $headers = array('headers' => ['Authorization' => $token,
            'X-Nylas-API-Wrapper' => 'php']);
        return $headers;
    }

    private function createApiClient(): GuzzleClient
    {
        return new GuzzleClient(['base_url' => $this->apiServer]);
    }

    public function createAuthURL($redirect_uri, $login_hint = null)
    {
        $args = [
            "client_id" => $this->appID,
            "redirect_uri" => $redirect_uri,
            "response_type" => "code",
            "scope" => "email",
            "login_hint" => $login_hint,
            "state" => $this->generateId(),
        ];

        return $this->apiServer . '/oauth/authorize?' . http_build_query($args);
    }

    /**
     * @param $code
     * @return mixed|string|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getAuthToken($code)
    {
        $args = [
            "client_id" => $this->appID,
            "client_secret" => $this->appSecret,
            "grant_type" => "authorization_code",
            "code" => $code,
        ];

        $url = $this->apiServer . '/oauth/token';
        $payload = [];
        $payload['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $payload['headers']['Accept'] = 'text/plain';
        $payload['body'] = $args;

        $response = $this->jsonDecode($this->apiClient->post($url, $payload));

        if (array_key_exists('access_token', $response)) {
            $this->apiToken = $response['access_token'];
        }

        return $this->apiToken;
    }

    public function account()
    {
        $apiObj = new NylasAPIObject();
        $nsObj = new Models\Account();
        $accountData = $this->getResource('', $nsObj, '', []);
        $account = $apiObj->_createObject($accountData->klass, null, $accountData->data);
        return $account;
    }

    public function threads()
    {
        $msgObj = new Models\Thread($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function messages()
    {
        $msgObj = new Models\Message($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function drafts()
    {
        $msgObj = new Models\Draft($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function labels()
    {
        $msgObj = new Models\Label($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function files()
    {
        $msgObj = new Models\File($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function contacts()
    {
        $msgObj = new Models\Contact($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function calendars()
    {
        $msgObj = new Models\Calendar($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function events()
    {
        $msgObj = new Models\Event($this);
        return new NylasModelCollection($msgObj, $this, null, [], 0, []);
    }

    public function getResources($namespace, $klass, $filter)
    {
        $suffix = ($namespace) ? '/' . $klass->apiRoot . '/' . $namespace : '';
        $url = $this->apiServer . $suffix . '/' . $klass->collectionName;
        $url = $url . '?' . http_build_query($filter);
        $data = $this->jsonDecode($this->apiClient->get($url, $this->createHeaders()));

        $mapped = [];
        foreach ($data as $i) {
            $mapped[] = clone $klass->_createObject($this, $namespace, $i);
        }
        return $mapped;
    }

    public function getResource($namespace, $klass, $id, $filters)
    {
        $extra = '';
        if (array_key_exists('extra', $filters)) {
            $extra = $filters['extra'];
            unset($filters['extra']);
        }
        $response = $this->getResourceRaw($namespace, $klass, $id, $filters);
        return $klass->_createObject($this, $namespace, $response);
    }

    public function getResourceRaw($namespace, $klass, $id, $filters): ?array
    {
        $extra = '';
        if (array_key_exists('extra', $filters)) {
            $extra = $filters['extra'];
            unset($filters['extra']);
        }
        $prefix = ($namespace) ? '/' . $klass->apiRoot . '/' . $namespace : '';
        $postfix = ($extra) ? '/' . $extra : '';
        $url = $this->apiServer . $prefix . '/' . $klass->collectionName . '/' . $id . $postfix;
        $url = $url . '?' . http_build_query($filters);
        $response = $this->apiClient->get($url, $this->createHeaders());
        return $this->jsonDecode($response);
    }

    public function getResourceData($namespace, $klass, $id, $filters)
    {
        $extra = '';
        $customHeaders = [];
        if (array_key_exists('extra', $filters)) {
            $extra = $filters['extra'];
            unset($filters['extra']);
        }
        if (array_key_exists('headers', $filters)) {
            $customHeaders = $filters['headers'];
            unset($filters['headers']);
        }
        $prefix = ($namespace) ? '/' . $klass->apiRoot . '/' . $namespace : '';
        $postfix = ($extra) ? '/' . $extra : '';
        $url = $this->apiServer . $prefix . '/' . $klass->collectionName . '/' . $id . $postfix;
        $url = $url . '?' . http_build_query($filters);
        $customHeaders = array_merge($this->createHeaders()['headers'], $customHeaders);
        $headers = array('headers' => $customHeaders);
        $data = $this->apiClient->get($url, $headers)->getBody();
        return $data;
    }

    public function _createResource($namespace, $klass, $data)
    {
        $prefix = ($namespace) ? '/' . $klass->apiRoot . '/' . $namespace : '';
        $url = $this->apiServer . $prefix . '/' . $klass->collectionName;

        $payload = $this->createHeaders();
        if ($klass->collectionName == 'files') {
            $payload['headers']['Content-Type'] = 'multipart/form-data';
            $payload['body'] = $data;
        } else {
            $payload['headers']['Content-Type'] = 'application/json';
            $payload['json'] = $data;
        }

        $response = $this->jsonDecode($this->apiClient->post($url, $payload));
        return $klass->_createObject($this, $namespace, $response);
    }

    public function _updateResource($namespace, $klass, $id, $data)
    {
        $prefix = ($namespace) ? '/' . $klass->apiRoot . '/' . $namespace : '';
        $url = $this->apiServer . $prefix . '/' . $klass->collectionName . '/' . $id;

        if ($klass->collectionName == 'files') {
            $payload['headers']['Content-Type'] = 'multipart/form-data';
            $payload['body'] = $data;
        } else {
            $payload = $this->createHeaders();
            $payload['json'] = $data;
            $response = $this->jsonDecode($this->apiClient->put($url, $payload));
            return $klass->_createObject($this, $namespace, $response);
        }
    }

    public function _deleteResource($namespace, $klass, $id): ?array
    {
        $prefix = ($namespace) ? '/' . $klass->apiRoot . '/' . $namespace : '';
        $url = $this->apiServer . $prefix . '/' . $klass->collectionName . '/' . $id;

        $payload = $this->createHeaders();
        return $this->jsonDecode($this->apiClient->delete($url, $payload));
    }

    private function generateId()
    {
        // Generates unique UUID
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * @param ResponseInterface $response
     * @return array|null
     */
    protected function jsonDecode(ResponseInterface $response): ?array
    {
        return Utils::jsonDecode($response->getBody()->getContents(), true);
    }
}
