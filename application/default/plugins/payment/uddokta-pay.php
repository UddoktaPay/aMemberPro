<?php
/**
 * @table paysystems
 * @id uddokta-pay
 * @title UddoktaPay
 * @visible_link https://uddoktapay.com
 * @recurring none
 * @logo_url uddokta-pay.png
 * @am_payment_api 6.0
 */
class Am_Paysystem_UddoktaPay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '@@VERSION@@';

    protected $defaultTitle = 'UddoktaPay';
    protected $defaultDescription = '';

    protected $_isDebug = true;

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('api_url', ['class' => 'am-el-wide'])
            ->setLabel("API URL\nYou will find your API URL in the UddoktaPay Gateway Panel API Settings Page")
            ->addRule('required');

        $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel("API Key\nYou can manage your API keys within the UddoktaPay Gateway Panel API Settings Page")
            ->addRule('required');

        $fs = $form->addAdvFieldset(null, ['id' => "{$this->getId()}-conversion"])
            ->setLabel('Currency Conversion');
        foreach (['USD', 'EUR'] as $currency) {
            $g = $fs->addGroup();
            $g->setLabel("1 $currency =");
            $g->setSeparator(' ');
            $g->addText("conversion_$currency", ['size'=>5]);
            $g->addHtml()->setHtml('BDT');
        }
    }

    public function isConfigured()
    {
        return $this->getConfig('api_url') && $this->getConfig('api_key');
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        $_ = ['BDT'];

        foreach ($this->getConfig() as $token => $val) {
            if (preg_match('/conversion_([A-Z]{3})/', $token, $m))
                if (!empty($val)) {
                    $_[] = $m[1];
                }
        }

        return $_;
    }

    function getAmount(Invoice $invoice)
    {
        if ($invoice->currency == 'BDT') {
            $invoice->first_total;
        }

        return $invoice->first_total * $this->getConfig("conversion_{$invoice->currency}", 1);
    }

    function getEndpoint($slug)
    {
        $host = parse_url($this->getConfig('api_url'),  PHP_URL_HOST);
        return "https://$host/api/$slug";
    }

    public function _process($invoice, $request, $result)
    {
        $req = new Am_HttpRequest($this->getEndpoint('checkout'), Am_HttpRequest::METHOD_POST);
        $req->setHeader('RT-UDDOKTAPAY-API-KEY', $this->getConfig('api_key'));
        $req->setHeader('content-type', 'application/json');
        $req->setBody(json_encode([
            'amount' => $this->getAmount($invoice),
            'full_name' => $invoice->getUser()->getName(),
            'email' => $invoice->getUser()->getEmail(),
            'metadata' => [
                'order_id' => $invoice->public_id
            ],
            'redirect_url' => $this->getReturnUrl(),
            'cancel_url' => $this->getCancelUrl(),
            'webhook_url' => $this->getPluginUrl('ipn'),
        ]));

        $resp = $req->send();
        $this->log($req, $resp, 'checkout');

        if ($resp->getStatus() != 200
            || !($_ = json_decode($resp->getBody(), true))
            || empty($_['payment_url']))
        {
            throw new Am_Exception_InternalError;
        }

        $result->setAction(new Am_Paysystem_Action_Redirect($_['payment_url']));
    }

    function getPayment($invoice_id)
    {
        $req = new Am_HttpRequest($this->getEndpoint("verify-payment"), Am_HttpRequest::METHOD_POST);
        $req->setHeader('RT-UDDOKTAPAY-API-KEY', $this->getConfig('api_key'));
        $req->setHeader('content-type', 'application/json');
        $req->setBody(json_encode([
            'invoice_id' => $invoice_id
        ]));

        $res = $req->send();
        $this->log($req, $res, 'verify-payment');

        if ($res->getStatus() == 200 && ($_ = json_decode($res->getBody(), true))) {
            return $_;
        }
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_UddoktaPay($this, $request, $response, $invokeArgs);
    }

    function log($req, $resp, $title)
    {
        if (!$this->_isDebug)
            return;
        $l = $this->getDi()->invoiceLogRecord;
        $l->paysys_id = $this->getId();
        $l->title = $title;
        $l->add($req);
        $l->add($resp);
    }
}

class Am_Paysystem_Transaction_UddoktaPay extends Am_Paysystem_Transaction_Incoming
{
    protected $payment;

    function validate()
    {
        if ($id = $this->request->getFiltered('invoice_id')) {
            $this->payment = $this->getPlugin()->getPayment($id);
            $this->log->add($this->payment);
        }
        return parent::validate();
    }

    function findInvoiceId()
    {
        return $this->payment['metadata']['order_id'];
    }

    function getUniqId()
    {
        return $this->payment['transaction_id'];
    }

    function validateSource()
    {
        return !empty($this->payment);
    }

    function validateStatus()
    {
        return $this->payment['status'] == 'COMPLETED';
    }

    function validateTerms()
    {
        return true;
    }

    function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}