<?php

namespace Plugin\FakaBridge\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Plugin\FakaBridge\Services\AccountReadService;
use Plugin\FakaBridge\Services\FakaBridgeConfig;
use Plugin\FakaBridge\Services\ProtocolV2Exception;
use Plugin\FakaBridge\Services\ProtocolV2Receiver;

class AccountV2Controller extends Controller
{
    public function __construct(protected FakaBridgeConfig $config) {}

    public function capabilities(Request $request): JsonResponse { return $this->handle($request, 'capabilities'); }
    public function resolve(Request $request): JsonResponse { return $this->handle($request, 'user-resolve'); }
    public function subscription(Request $request): JsonResponse { return $this->handle($request, 'user-subscription'); }

    private function handle(Request $request, string $action): JsonResponse
    {
        $requestId = null;
        try {
            $runtime = $this->config->forV2Request();
            $body = (new ProtocolV2Receiver($runtime))->receive($request);
            $reader = new AccountReadService($runtime);
            $expected = match ($action) {
                'capabilities' => ['request_id'],
                'user-resolve' => ['request_id', 'external_order_no'],
                'user-subscription' => ['request_id', 'user_id'],
            };
            if (count($body) !== count($expected) || array_diff(array_keys($body), $expected)
                || !is_string($body['request_id'] ?? null)
                || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $body['request_id'])) {
                throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            }
            $requestId = $body['request_id'];
            if ($action === 'user-resolve' && (!is_string($body['external_order_no'] ?? null)
                || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $body['external_order_no']))) {
                throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            }
            if ($action === 'user-subscription' && (!is_string($body['user_id'] ?? null)
                || !preg_match('/^[1-9][0-9]{0,18}$/D', $body['user_id'])
                || (strlen($body['user_id']) === 19 && strcmp($body['user_id'], (string) PHP_INT_MAX) > 0))) {
                throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            }
            $result = match ($action) {
                'capabilities' => $reader->capabilities(),
                'user-resolve' => $reader->resolve($body['external_order_no']),
                'user-subscription' => $reader->subscription($body['user_id']),
            };
            return $this->json(['success' => true, 'protocol_version' => 2, 'request_id' => $requestId,
                'queried_at' => gmdate('Y-m-d\TH:i:s\Z')] + $result);
        } catch (ProtocolV2Exception $e) {
            return $this->json(['success' => false, 'protocol_version' => 2, 'request_id' => $requestId, 'error_code' => $e->errorCode], $e->httpStatus);
        } catch (\Throwable $e) {
            // Never log raw bodies, email, secret or upstream exception messages.
            Log::error('[FakaBridge] v2 read failed', ['action' => $action, 'exception' => $e::class]);
            return $this->json(['success' => false, 'protocol_version' => 2, 'request_id' => $requestId, 'error_code' => 'BRIDGE_UNAVAILABLE'], 503);
        }
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'private, no-store']);
    }
}
