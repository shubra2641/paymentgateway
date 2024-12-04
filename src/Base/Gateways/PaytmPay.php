<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Anand\LaravelPaytmWallet\Facades\PaytmWallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Helpers\PaytmChecksum;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\IndianCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class PaytmPay extends PaymentGatewayBase
{
    use PaymentEnvironment,CurrencySupport,IndianCurrencySupport;
    protected $merchant_id;
    protected $merchant_key;
    protected $merchant_website;
    protected $channel;
    protected $industry_type;

    public function setMerchantId($merchant_id){
        $this->merchant_id = $merchant_id;
        return $this;
    }
    public function getMerchantId(){
        return $this->merchant_id;
    }
    public function setMerchantKey($merchant_key){
        $this->merchant_key = $merchant_key;
        return $this;
    }
    public function getMerchantKey(){
        return $this->merchant_key;
    }
    public function setMerchantWebsite($merchant_website){
        $this->merchant_website = $merchant_website;
        return $this;
    }
    public function getMerchantWebsite(){
        return $this->merchant_website;
    }
    public function setChannel($channel){
        $this->channel = $channel;
        return $this;
    }
    public function getChannel(){
        return $this->channel;
    }
    public function setIndustryType($industry_type){
        $this->industry_type = $industry_type;
        return $this;
    }
    public function getIndustryType(){
        return $this->industry_type;
    }

    /*
    * charge_amount();
    * @required param list
    * $amount
    *
    *
    * */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $amount;
        }
        return $this->get_amount_in_inr($amount);
    }


    /**
     * @required param list
     * $args['amount']
     * $args['description']
     * $args['item_name']
     * $args['ipn_url']
     * $args['cancel_url']
     * $args['payment_track']
     * return redirect url for paypal
     *
     * @throws \Exception
     */

    public function charge_customer($args)
    {
        $charge_amount = $this->charge_amount($args['amount']);
        $order_id = PaymentGatewayHelpers::wrapped_id($args['order_id']);
        $final_amount = number_format((float) $charge_amount, 2, '.', '');

        $paytmParams = [
            "body" => [
                "requestType"   => "Payment",
                "mid"           => $this->getMerchantId(),
                "websiteName"   => $this->getEnv() ? 'WEBSTAGING' : 'DEFAULT',
                "orderId"       => $order_id,
                "callbackUrl"   => $args['ipn_url'],
                "txnAmount"     => [
                    "value"     => $final_amount,
                    "currency"  => "INR",
                ],
                "userInfo"      => [
                    "custId"    => $args['email'] ?? "CUST_" . Str::random(10),
                ],
            ]
        ];


        $checksum = PaytmChecksum::generateSignature(
            json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES),
            $this->getMerchantKey()
        );
        $paytmParams["head"] = ["signature" => $checksum];

        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);

        // For Staging
        $url = $this->base_url()."/theia/api/v1/initiateTransaction?mid=" . $this->getMerchantId() . "&orderId=" . $order_id;

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->withBody($post_data)
            ->post($url);
        $result = $response->object();
        if (property_exists($result->head,'signature')){

            if (!property_exists($result->body,'txnToken')){
                abort(500,'txnToken not found');
            }
            $bladeData = [
                'host' => $this->base_url(),
                'txnToken' => $result->body?->txnToken,
                'order_id' => $order_id,
                'amount' => $final_amount,
                'success_url' => $args['success_url'],
                'cancel_url' => $args['cancel_url'],
                'merchant_id' => $this->getMerchantId()
            ];
            return view('paymentgateway::paytm',compact('bladeData')); // build view file for js checkout
        }else {
            abort(500,$result->body?->resultInfo?->resultMsg);
        }

    }
    protected function createReceiveDriver(){

        return $this->buildProvider(
            'Anand\LaravelPaytmWallet\Providers\ReceivePaymentProvider',
            [
                'env' => $this->getEnv() ? 'local': 'production', //env('PAYTM_ENVIRONMENT','local'), // values : (local | production)
                'merchant_id' => $this->getMerchantId(),// env('PAYTM_MERCHANT_ID'),
                'merchant_key' => $this->getMerchantKey(),// env('PAYTM_MERCHANT_KEY'),
                'merchant_website' => $this->getMerchantWebsite(),// env('PAYTM_MERCHANT_WEBSITE'),
                'channel' =>  $this->getChannel(),//env('PAYTM_CHANNEL'),
                'industry_type' => $this->getIndustryType(),//env('PAYTM_INDUSTRY_TYPE'),
            ]
        );
    }
    public function buildProvider($provider, $config){
        return new $provider(
            request(),
            $config
        );
    }

    /**
     * @required param list
     * $args['request']
     * $args['cancel_url']
     * $args['success_url']
     *
     * return @void
     * */
    public function ipn_response($args = []){

        $order_id = request()->get('ORDERID');
        $transaction = $this->createReceiveDriver();
        $response = $transaction->response(); // To get raw response as array
        //Check out response parameters sent by paytm here -> http://paywithpaytm.com/developer/paytm_api_doc?target=interpreting-response-sent-by-paytm
        if ($transaction->isSuccessful()) {

            return $this->verified_data([
                'transaction_id' => $response['TXNID'],
                'order_id' => substr($order_id,5,-5)
            ]);

        }
        return ['status' => 'failed'];
    }

    /**
     * geteway_name();
     * return @string
     * */
    public function gateway_name(){
        return 'paytm';
    }
    /**
     * charge_currency();
     * return @string
     * */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $this->getCurrency();
        }
        return  "INR";
    }
    /**
     * supported_currency_list();
     * it will return all of supported currency for the payment gateway
     * return array
     * */
    public function supported_currency_list(){
        return ['INR'];
    }

    public function base_url()
    {
        $url = $this->getEnv() ? "-stage" : "";
        return 'https://securegw'.$url.'.paytm.in';
    }

}
