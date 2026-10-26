<?php
namespace Plugin\FakaBridge\Controllers;

use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Plugin\FakaBridge\Services\{FakaBridgeConfig,PaidResetService,ProtocolV2Exception,ProtocolV2Receiver};

class PaidResetV2Controller extends Controller
{
    public function __construct(protected FakaBridgeConfig $config) {}
    public function preview(Request $r): JsonResponse { return $this->handle($r, 'paid-reset-preview'); }
    public function execute(Request $r): JsonResponse { return $this->handle($r, 'paid-reset'); }
    public function status(Request $r): JsonResponse { return $this->handle($r, 'paid-reset-status'); }
    public function revoke(Request $r): JsonResponse { return $this->handle($r, 'paid-reset-revoke'); }
    private function handle(Request $request, string $action): JsonResponse {
        $requestId = null;
        try {
            $runtime = $this->config->forV2Request();
            $body = (new ProtocolV2Receiver($runtime))->receive($request);
            $preview = $action === 'paid-reset-preview';
            $expected = $preview ? ['request_id','user_id','sku'] : ['request_id','order_no','user_id','sku','expected_plan_id','period'];
            if (count($body) !== count($expected) || array_diff(array_keys($body), $expected)) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            if (!is_string($body['request_id']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $body['request_id'])) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            $requestId = $body['request_id'];
            foreach ($preview ? ['user_id'] : ['user_id','expected_plan_id'] as $key) {
                $v = $body[$key];
                if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,18}$/D', $v)
                    || strlen($v) === 19 && strcmp($v, (string) PHP_INT_MAX) > 0) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            }
            if (!is_string($body['sku']) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/D', $body['sku'])) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            // faka_orders.plan_id is a signed SQL INT in the existing schema.
            if (!$preview && (int) $body['expected_plan_id'] > 2147483647) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            if (!$preview && (!is_string($body['order_no']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $body['order_no'])
                || $body['period'] !== 'reset_traffic')) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            $service = new PaidResetService($runtime);
            $result = $preview ? $service->preview($body['user_id'], $body['sku']) : $service->operate($action, $body);
            return $this->json(['success'=>true,'protocol_version'=>2,'request_id'=>$requestId,'queried_at'=>gmdate('Y-m-d\TH:i:s\Z')] + $result);
        } catch (ProtocolV2Exception $e) {
            return $this->json(['success'=>false,'protocol_version'=>2,'request_id'=>$requestId,'error_code'=>$e->errorCode], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('[FakaBridge] v2 paid reset failed', ['action'=>$action,'exception'=>$e::class]);
            return $this->json(['success'=>false,'protocol_version'=>2,'request_id'=>$requestId,'error_code'=>'BRIDGE_UNAVAILABLE'], 503);
        }
    }
    private function json(array $body, int $status = 200): JsonResponse {
        return new JsonResponse($body, $status, ['Cache-Control'=>'private, no-store']);
    }
}
