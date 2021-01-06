<?php

namespace DelayQueue;

use GuzzleHttp\Client as HttpClient;
use ReflectionClass;
use DelayQueue\Exception\ClassNotFoundException;
use DelayQueue\Exception\InvalidResponseBodyException;
use DelayQueue\Exception\SubClassException;
use Exception;

class DelayQueue
{
    /**
     * @var string 延迟队列服务器地址 http://127.0.0.1:9277
     */
    protected $server;

    /**
     * @var int httpClient超时设置
     */
    protected $timeout = 10;

    public function __construct($server)
    {
        $this->server = rtrim($server, '/');
    }

    /**
     * @param int $timeout
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * 添加Job到延迟队列中
     *
     * @param string $className 处理Job的类名, 必须是[DelayQueue\Handler\AbstractHandler]的子类
     * @param Job    $job
     * @throws ClassNotFoundException
     * @throws Exception
     * @throws InvalidResponseBodyException
     * @throws SubClassException
     */
    public function push($className, Job $job)
    {
        $this->validateClassName($className);
        $job->appendValueToBody('className', $className);

        $response = $this->getHttpClient()->post('/push', [
            'json' => $job,
            'allow_redirects' => false,
        ]);
        $this->checkResponseBody($this->getResponseData($response));
    }

    /**
     * 从队列中取出已过期的Job
     *
     * @param  array $topics  队列名称
     * @return null|array
     * @throws Exception
     * @throws InvalidResponseBodyException
     */
    public function pop(array $topics)
    {
        if (!$topics) {
            return null;
        }
        $response = $this->getHttpClient()->post('/pop', [
            'json' => [
                'topic' => implode(',', $topics),
            ],
            'allow_redirects' => false,
        ]);

        $data =  $this->getResponseData($response);
        $this->checkResponseBody($data);
        if (!isset($data['data']) || empty($data['data'])) {
            return null;
        }

        if (!isset($data['data']['id']) || !isset($data['data']['body'])) {
            throw new InvalidResponseBodyException('response body miss required parameter, id or body');
        }
        $id        = $data['data']['id'];
        $body      = json_decode($data['data']['body'], true);
        if (!isset($body['className'])) {
            throw new InvalidResponseBodyException('response body miss required parameter className');
        }
        $className = $body['className'];
        unset($body['className']);

        return [
            'className' => $className,
            'id' => $id,
            'body' => $body,
        ];
    }

    /**
     * 从延迟队列中删除Job
     *
     * @param  string    $id Job唯一标识
     * @throws Exception
     * @throws InvalidResponseBodyException
     */
    public function delete($id)
    {
        $response = $this->getHttpClient()->post('/delete', [
            'json' => [
               'id' => $id
            ],
            'allow_redirects' => false,
        ]);
        $this->checkResponseBody($this->getResponseData($response));
    }

    /**
     * Job处理完成, 确认删除
     *
     * @param  string $id Job唯一标识
     * @return true
     * @throws Exception
     * @throws InvalidResponseBodyException
     */
    public function finish($id)
    {
        $response = $this->getHttpClient()->post('/finish', [
            'json' => [
                'id' => $id,
            ],
            'allow_redirects' => false,
        ]);
        $body = $this->getResponseData($response);
        $this->checkResponseBody($body);
    }

    public function validateClassName($className) {
        if (!class_exists($className)) {
            throw new ClassNotFoundException(sprintf('can not find class [%s]', $className));
        }
        $reflection = new ReflectionClass($className);
        $parentClassName = 'DelayQueue\Handler\AbstractHandler';
        if (!$reflection->isSubclassOf($parentClassName)) {
            throw new SubClassException(sprintf('[%s] is not subclass of [%s]', $className, $parentClassName));
        }
    }

    protected function getHttpClient()
    {
        $httpClient = new HttpClient(
            [
                'base_uri' => $this->server,
                'timeout' => $this->timeout,
            ]
        );

        return $httpClient;
    }

    /**
     * @param  array $body
     * @throws Exception
     * @throws InvalidResponseBodyException
     */
    protected function checkResponseBody(array $body)
    {
        if (!array_key_exists('code', $body) || !array_key_exists('message', $body)) {
            throw new InvalidResponseBodyException('response body miss required parameter, code or message');
        }
        if ($body['code'] !== 0) {
            throw new Exception($body['message']);
        }
    }

    private function getResponseData(\Psr\Http\Message\ResponseInterface $response)
    {
        return json_decode($response->getBody()->getContents(), true);
    }
}