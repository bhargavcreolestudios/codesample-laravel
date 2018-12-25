<?php

namespace App\Notifications\Messages;

use Carbon\Carbon;

class SendSMS
{

    /*
    |--------------------------------------------------------------------------
    | SendSMS
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : Send custom sms with configurations
    | Date      : 10th August 2018
    |
     */
    /**
     *
     * the message type to be set
     *
     */

    public $MessageType;

    /**
     *
     * the batch type to be set.
     * @var string
     */

    public $BatchType;

    /**
     *
     * the source address to be set
     * @var string
     */

    public $SourceAddr;

    /**
     *
     * the destination address to be set
     * @var string
     */

    public $DestinationAddr;

    /**
     *
     * the auth details to be set
     * @var string
     */

    public $AuthDetails;

    /**
     *
     * the message payload to be set
     * @var string
     */

    public $MessagePayload;

    /**
     * Set message type here else user default 3.
     *
     * @param  string  $type
     *
     * @return $this
     */
    public function setType($type = 3)
    {
        $this->MessageType = [(string) $type];
        return $this;
    }
    /**
     * Set message batch type here else user default 1.
     *
     * @param  string  $type
     *
     * @return $this
     */
    public function batchType($type = 1)
    {
        $this->BatchType = [(string) $type];
        return $this;
    }
    /**
     * Set source address from config services.
     *
     *
     * @return $this
     */
    public function sourceAddress()
    {
        $this->SourceAddr = [Config('services.custom.source')];
        return $this;
    }
    /**
     * Set destination address here else throw an exaption.
     *
     * @param  array  $notifiable
     *
     * @return $this
     */
    public function destinationAddress($notifiable)
    {
        $recipients = $this->explodeRecord($notifiable);
        $addresses  = $this->setDestinations($recipients);

        $this->DestinationAddr = $addresses;
        return $this;
    }
    /**
     * Set auth details from config services.
     *
     *
     * @return $this
     */
    public function authDetails()
    {
        $responseData              = [];
        $responseData['UserID']    = (string) Config('services.custom.user_id');
        $responseData['Token']     = (string) Config('services.custom.token');
        $responseData['Timestamp'] = (string) Carbon::now()->timestamp;

        $this->AuthDetails = [$responseData];

        return $this;
    }
    /**
     * Set message payload here else throw an exaption.
     *
     * @param  array  $notifiable
     *
     * @return $this
     */
    public function payload($message)
    {
        $payload              = [];
        $payload['Text']      = $message;
        $this->MessagePayload = [$payload];

        return $this;
    }

    private function explodeRecord($value)
    {
        $returnData = false;
        if ($value) {
            $returnData = explode(',', $value);
        }
        return $returnData;
    }

    private function setDestinations(array $record)
    {
        if (count($record) > 0) {
            $response = [];
            $counting = 1;
            foreach ($record as $key => $value) {
                $address             = [];
                $address['MSISDN']   = (string) $value;
                $address['LinkID']   = '';
                $address['SourceID'] = (string) $counting;
                array_push($response, $address);
                $counting++;
            }
            return $response;
        }
        return false;
    }

}
