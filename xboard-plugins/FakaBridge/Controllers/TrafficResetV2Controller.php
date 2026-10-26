<?php
namespace Plugin\FakaBridge\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Plugin\FakaBridge\Services\{FakaBridgeConfig,ProtocolV2Receiver,ProtocolV2Exception,TrafficResetService};

class TrafficResetV2Controller extends Controller
{
    public function __construct(protected FakaBridgeConfig $config) {}
    public function preview(Request $r): JsonResponse { return $this->handle($r, 'traffic-reset-preview'); }
    public function execute(Request $r): JsonResponse { return $this->handle($r, 'traffic-reset'); }
    public function status(Request $r): JsonResponse { return $this->handle($r, 'traffic-reset-status'); }
    public function cancel(Request $r): JsonResponse { return $this->handle($r, 'traffic-reset-cancel'); }
    private function handle(Request $request, string $action): JsonResponse {
        $requestId = null;
        try {
            $runtime = $this->config->forV2Request();
            $body = (new ProtocolV2Receiver($runtime))->receive($request);
            $expected = $action === 'traffic-reset-preview' ? ['request_id','user_id']
                : ['request_id','operation_id','actor_ref','user_id','benefit_code','expected_plan_id','subscription_fingerprint'];
            if (count($body) !== count($expected) || array_diff(array_keys($body), $expected)) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            foreach (['request_id', ...($action === 'traffic-reset-preview' ? [] : ['operation_id'])] as $key) {
                if (!is_string($body[$key]) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $body[$key])) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            }
            $requestId = $body['request_id'];
            foreach (['user_id', ...($action === 'traffic-reset-preview' ? [] : ['actor_ref','expected_plan_id'])] as $key) {
                $v = $body[$key];
                if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,18}$/D', $v) || (strlen($v) === 19 && strcmp($v, (string) PHP_INT_MAX) > 0)) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            }
            if ($action !== 'traffic-reset-preview' && ($body['benefit_code'] !== 'welcome_traffic_reset_v1'
                || !is_string($body['subscription_fingerprint']) || !preg_match('/^[a-f0-9]{64}$/D', $body['subscription_fingerprint']))) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            $service = new TrafficResetService($runtime);
            $result = $action === 'traffic-reset-preview' ? $service->preview($body['user_id']) : $service->operate($action, $body);
            return $this->json(['success'=>true,'protocol_version'=>2,'request_id'=>$requestId,'queried_at'=>gmdate('Y-m-d\TH:i:s\Z')] + $result);
        } catch (ProtocolV2Exception $e) {
            return $this->json(['success'=>false,'protocol_version'=>2,'request_id'=>$requestId,'error_code'=>$e->errorCode], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('[FakaBridge] v2 reset failed', ['action'=>$action,'exception'=>$e::class]);
            return $this->json(['success'=>false,'protocol_version'=>2,'request_id'=>$requestId,'error_code'=>'BRIDGE_UNAVAILABLE'], 503);
        }
    }
    private function json(array $body, int $status = 200): JsonResponse {
        return new JsonResponse($body, $status, ['Cache-Control'=>'private, no-store']);
    }
}
