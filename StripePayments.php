<?php

/**
 * Created by PhpStorm.
 * User: TJ 
 * Date: 19/10/18
 * Time: 8:24 PM
 */
namespace App\Libraries;

use App\Models\Cards;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\Card as StripeCard;
use Exception;

class StripePayment
{
    private $user;
    public $customer, $charge, $lastResponse;

    const MODE_SB = 'sandbox';
    const MODE_LIVE = 'live';

    const USD = 'usd';

    //Change below line to set stripe mode
    public static $mode = null;

    public function __construct(\App\User $user=null)
    {
        $this->user = $user;
        self::$mode = self::$mode ?? config('stripe.mode');
        // Init Stripe config
        \Stripe\Stripe::setApiKey(config('stripe.secret'));
    }

    public static function setMode($mode)
    {
        self::$mode = $mode;
    }

    private function _validateStripeId()
    {
        if(!$this->user->stripe_id || ($this->user->stripe_id === '')){
            throw new Exception('Stripe ID not found or empty');
        }
        return $this;
    }

    public function subscribeToPlan($plan, $stripe_planid, $token=null)
    {
        // Only if user has card AND token is not provided
        if(is_null($token) && ($this->user->getDefaultCard() instanceof Cards)) {
            $card = new StripeCard;
            return $this->user->newSubscription($plan, $stripe_planid)->create($this->user->getDefaultCard()->card_id);
        } else if (!is_null($token)) {
            return $this->user->newSubscription($plan, $stripe_planid)->create($token);
        }
        return false;
    }

    private function _validateToken($token)
    {
        if(!$token || empty($token)){
            throw new Exception('Token Empty');
        }
        return $this;
    }

    private function _validateCardInfo($cardinfo)
    {
        if(!isset($cardinfo['exp_month'])) {
            throw new Exception('Expiry month is missing');
        }
        if(!isset($cardinfo['exp_year'])) {
            throw new Exception('Expiry year is missing');
        }
        if(!isset($cardinfo['number'])) {
            throw new Exception('Card number is missing');
        }
        if(!isset($cardinfo['cvv'])) {
            throw new Exception('CVV is missing');
        }
        return $this;
    }

    private function _formatCardInfo($cardinfo)
    {
        if(!isset($cardinfo['object']))
        {
            $cardinfo['object'] = 'card';
        }
        return $cardinfo;
    }

    public function payUsingCard($cardInfo, $amount)
    {
        $cardInfo = $this->_validateCardInfo($cardInfo)->_formatCardInfo($cardInfo);
        try {
            $this->charge = \Stripe\Charge::create([
                "amount" => $amount,
                "currency" => "usd",
                "source" => $cardInfo
            ]);
        }  catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }
        $this->lastResponse = $this->charge->getLastResponse();
        return $this;
    }

    public function divideCharge($transfer_group, $chargeid, $amount, $metadata=[])
    {
        //@todo divide charge for express account
        if(!$this->user || (!$this->user->stripe_account_id))
        {
            throw new \Exception('User must have a stripe standard account', 400);
        }

        if(!$amount || ($amount <= 0))
        {
            throw new \Exception('Amount must be a valid positive number', 400);
        }

        $description = "Divide coach payment";    
        if(isset($metadata['desc'])) { $description = $metadata['desc']; }

        $data = [
            'currency' => self::USD,
            'amount' => $amount * 100,
            'destination' => $this->user->stripe_account_id,
            'transfer_group' => $transfer_group,
            'description' => $description
        ];
        if($chargeid != 0){
            $data['source_transaction'] = $chargeid;
        }

       // $balance = \Stripe\Balance::retrieve();   echo "<pre>"; print_r($balance);

        try {
            $this->charge = \Stripe\Transfer::create($data);
        }  catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }
        $this->lastResponse = $this->charge->getLastResponse();
        return $this;

    }

    public function refundCharge($chargeid, $amount=null, $metadata=[])
    {
        $data = [
            "charge" => $chargeid,
            "metadata" => $metadata
        ];
        if($amount && ($amount > 0)){
            $data['amount'] = ($amount * 100); //Converts into cents
        }

        try {
            $this->charge = \Stripe\Refund::create($data);
        }  catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }
        $this->lastResponse = $this->charge->getLastResponse();
        return $this;
    }

    public function getExpressLoginLink()
    {
        if(!$this->user->stripe_account_id) return null;
        $account = Account::retrieve($this->user->stripe_account_id);
        $link = $account->login_links->create();
        return $link['url'] ?? null;
    }

    public function generateTransferGroup()
    {
        return 'tgp_' . md5($this->user->id . now());
    }

    // amount in USD
    public function pay($amount, $token=null, array $params=[], array $headers=[])
    {
        $amount = $amount * 100; //To cents
        $params = array_merge([
            "amount" => $amount,
            'currency' => 'usd',
        ], $params);

        if(!$token || ($token === '')){
            $this->_validateStripeId();
            $params['customer'] = $this->user->stripe_id;
        }
        if($token && ($token !== '')){
            if (is_array($token)) {
                return $this->payUsingCard($amount, $token);
            }
            $params['source'] = $token;
        }
        try{
            $this->charge = \Stripe\Charge::create($params, $headers);
        }  catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }
        $this->lastResponse = $this->charge->getLastResponse();
        return $this;
    }

    public function setDefaultCard($cardid)
    {
        $this->_validateStripeId();
        try {
            $cu = \Stripe\Customer::retrieve($this->user->stripe_id);
            $cu->default_source=$cardid;
            $cu->save();
            $this->lastResponse = $cu->getLastResponse();
            // Save this card in user's db
            if(isset($this->lastResponse->json['default_source'])){
                $this->user->cards()->where('default', 1)->update(['default' => 0]);
                $this->user->cards()->where('card_id', $this->lastResponse->json['default_source'])->update(['default' => 1]);
            }
        } catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }
        return $this;
    }

    public function removeCard($cardid)
    {
        $this->_validateStripeId();
        try {
            $cu = \Stripe\Customer::retrieve($this->user->stripe_id);
            $this->lastResponse = $cu->sources->retrieve($cardid)->delete();
            // Save this card in user's db
            if((isset($this->lastResponse->deleted)) && ($this->lastResponse->deleted === true)){
                $this->user->cards()->where('card_id', $cardid)->delete();
            }
        } catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }
        return $this;
    }

    public function addCard($token)
    {
        $this->_validateStripeId()->_validateToken($token);
        try {
            $cu = \Stripe\Customer::retrieve($this->user->stripe_id);
            $newcard = $cu->sources->create(["source" => $token]);
            $this->lastResponse = $newcard;
            // Save this card in user's db
            if((isset($this->lastResponse->id))){
                $card = new Cards();
                $card->card = $this->lastResponse;
                $this->user->cards()->save($card);
            }
        } catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }
        return $this;
    }

    public function getLastResponse(){
        return $this->lastResponse;
    }

    public function createCustomer($token, $forceCreate=false)
    {
        // Do not create new account for existing stripe customer, unless forced
        if(($this->user && isset($this->user->stripe_id) && ($this->user->stripe_id !== null))
            && ($forceCreate ===  false))
            return $this;

        $this->_validateToken($token);
        try {
            $this->customer = \Stripe\Customer::create(array(
                "email" => $this->user->email,
                "source" => $token,
            ));
        }  catch(\Stripe\Error\Card | \Stripe\Error\RateLimit | \Stripe\Error\InvalidRequest |
        \Stripe\Error\Authentication | \Stripe\Error\ApiConnection | \Stripe\Error\Base $e) {
            Log::debug($e);
            $err = $e->getJsonBody();
            throw new Exception($err['error']['message']);
        } catch (Exception $e) {
            Log::debug($e);
            throw new Exception($e->getMessage(), $e->getCode());
        }

        if(isset($this->customer->id)){
            $this->user->stripe_id = $this->customer->id;
            $this->user->save();
        } else {
            throw new Exception('Could not get customer id');
        }
        $this->lastResponse =$this->customer->getLastResponse();
        if((isset($this->lastResponse->json['sources']))){
            $sources = $this->lastResponse->json['sources'];
            // dd($sources['data']);
            foreach ($sources['data'] as $source){
                $card = new Cards();
                $card->card = $source;
                $this->user->cards()->save($card);
            }

            $this->user->cards()->where('card_id', $this->lastResponse->json['default_source'])->update(['default' => 1]);
        }
        return $this;
    }
}