<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function midtransHandler(Request $request) 
    {
        try {
            $data = $request->all();
            $signatureKey = $data['signature_key'];
            $orderId = $data['order_id'];
            $statusCode = $data['status_code'];
            $grossAmount = $data['gross_amount'];
            $serverKey = env('MIDTRANS_SERVER_KEY');

            
            $mySignatureKey = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
            $transactionStatus = $data['transaction_status'];
            $type = $data['payment_type'];
            $fraudStatus = $data['fraud_status'];

            if($signatureKey !== $mySignatureKey) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid Signature Key'
                ], 400);
            }


            $realOrderId = explode('-', $orderId);
            $order = Order::find($realOrderId[0]);

            if(!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found'
                ], 404);
            }

            if ($transactionStatus == 'capture'){
                if ($fraudStatus == 'challenge'){
                    $order->status = 'challenge';
                } else if ($fraudStatus == 'accept'){
                    $order->status = 'success';
                }
            } else if ($transactionStatus == 'settlement'){
                $order->status = 'success';
            } else if ($transactionStatus == 'cancel' ||
            $transactionStatus == 'deny' ||
            $transactionStatus == 'expire'){
                $order->status = 'failure';
            } else if ($transactionStatus == 'pending'){
                $order->status = 'pending';
            }

            $logData = [
                'status' => $transactionStatus,
                'raw_response' => json_encode($data),
                'order_id' => $realOrderId[0],
                'payment_type' => $type
            ];

            PaymentLog::create($logData);

            $order->update([
                'status' => $order->status
            ]);      
            
            
            if($order->status == 'success') {
                createPremiumAccess([
                    'user_id' => $order->user_id,
                    'course_id' => $order->course_id
                ]);
            }

            
        } catch (\Throwable $th) {
            $th->getMessage();
        }
        
    }
}
