<?php
/**
 * @brief		Coinpayments Gateway
 * @author		<a href='https://flawlessmanagement.com'>Ahmad @ FlawlessManagement</a>
 * @copyright	(c) 2016 FlawlessManagement
 * @package		IPS Community Suite
 * @subpackage	Nexus
 * @since		25 Jan 2016
 * @version		1.0
 */

namespace IPS\coinpayments;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

require_once 'coinpayments/coinpayments.inc.php';

/**
 * Coinpayments Gateway
 */
class _Coinpayments extends \IPS\nexus\Gateway
{
    /* !Features */

    const SUPPORTS_REFUNDS = FALSE;
    const SUPPORTS_PARTIAL_REFUNDS = FALSE;

    /**
     * Initialize Gateway
     *
     * @param $private_key API Private Key
     * @param $public_key API Public Key
     * @return object CoinPaymentsAPI
     */
    public function initGateway($private_key, $public_key)
    {
        $cps = new \CoinPaymentsAPI();
        $cps->Setup($private_key, $public_key);
        return $cps;
    }

    /**
     * Supports billing agreements?
     *
     * @return    bool
     */
    public function billingAgreements()
    {
        return FALSE;
    }

    /**
     * Payment Screen Fields
     *
     * @param    \IPS\nexus\Invoice $invoice Invoice
     * @param    \IPS\nexus\Money $amount The amount to pay now
     * @param    \IPS\Member $member The member the payment screen is for (if in the ACP charging to a member's card) or NULL for currently logged in member
     * @param    array $recurrings Details about recurring costs
     * @param    string $type 'checkout' means the customer is doing this on the normal checkout screen, 'admin' means the admin is doing this in the ACP, 'card' means the user is just adding a card
     *
     * @return    array
     *
     * @throws \InvalidArgumentException
     */
    public function paymentScreen(\IPS\nexus\Invoice $invoice, \IPS\nexus\Money $amount, \IPS\nexus\Customer $member = NULL, $recurrings = array(), $type = 'checkout')
    {
        $settings = json_decode($this->settings, TRUE);
        $cps = $this->initGateway($settings['private_key'], $settings['public_key']);
        $response = $cps->GetRates(false);

        $currencies = [];

        if ($response['error'] !== 'ok') {
            throw new \InvalidArgumentException($response['error']);
        }

        foreach ($response['result'] as $currency => $info) {
            if (($pos = mb_strpos($currency, '.')) !== false) {
                $append = mb_substr($currency, $pos + 1);
                $info['name'] = $info['name'] . ' ' . $append;
            }

            if ($info['accepted'] == 1) {
                $currencies[$currency] = $info['name'];
            }
        }

        return array('coinpayments_currency2' => new \IPS\Helpers\Form\Select('coinpayments_currency2', 'BTC', TRUE, array('options' => $currencies)));
    }

    /**
     * Authorize
     *
     * @param    \IPS\nexus\Transaction $transaction Transaction
     * @param    array|\IPS\nexus\Customer\CreditCard $values Values from form
     * @param    \IPS\nexus\Fraud\MaxMind\Request|NULL $maxMind *If* MaxMind is enabled, the request object will be passed here so gateway can additional data before request is made
     * @param    array $recurrings Details about recurring costs
     * @param    string|NULL $source 'checkout' if the customer is doing this at a normal checkout, 'renewal' is an automatically generated renewal invoice, 'manual' is admin manually charging. NULL is unknown
     *
     * @return void Auth is valid until or NULL to indicate auth is good forever
     */
    public function auth(\IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL, $recurrings = array(), $source = NULL)
    {
        /* We need a transaction ID */
        $transaction->save();

        /* User selected currency */
        $currency2 = $values['coinpayments_currency2'];

        $this->coinpaymentsAuth($transaction, $currency2);
    }

    /**
     * Authorize Coinpayments Payment
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param string $currency2 Currency that the user has selected to pay with
     *
     * @return void Auth is valid until or NULL to indicate auth is good forever
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    protected function coinpaymentsAuth(\IPS\nexus\Transaction $transaction, $currency2)
    {
        $settings = json_decode($this->settings, TRUE);
        $cps = $this->initGateway($settings['private_key'], $settings['public_key']);

        /* Get the product name(s) for Coinpayments */
        $summary = $transaction->invoice->summary();
        foreach ($summary['items'] as $item) {
            $productNames[] = $item->quantity . ' x ' . $item->name;
        }

        $req['currency1']   = (string) $transaction->amount->currency;
        $req['currency2']   = $currency2;
        $req['amount']      = (string) $transaction->amount->amount;
        $req['item_name']   = implode(', ', $productNames);
        $req['custom']      = $transaction->id;
        $req['success_url'] = \IPS\Settings::i()->base_url . 'applications/coinpayments/interface/gateway.php?nexusTransactionId=' . $transaction->id;
        $req['cancel_url']  = (string) $transaction->invoice->checkoutUrl();
        $req['ipn_url']     = \IPS\Settings::i()->base_url . 'applications/coinpayments/interface/gateway.php?nexusTransactionId=' . $transaction->id;

        /* Show buyers name and email on Coinpayments */
        $req['buyer_name'] = $this->getFullName($transaction);
        $req['buyer_email'] = $this->getBuyerEmail($transaction);

        /* Create transaction using the Coinpayments API */
        $response = $cps->CreateTransaction($req);

        /* Redirect */
        if ($response['error'] === 'ok') {
            $transaction->gw_id = $response['result']['txn_id'];
            $transaction->save();

            \IPS\Output::i()->redirect(\IPS\Http\Url::external($response['result']['status_url']));
        } else {
            throw new \InvalidArgumentException($response['error']);
        }

        /* Still here? */
        throw new \RuntimeException;
    }

    /**
     * Void
     *
     * @param    \IPS\nexus\Transaction    $transaction    Transaction
     * @return    void
     * @throws    \Exception
     */
    public function void(\IPS\nexus\Transaction $transaction)
    {
        /* Nothing to do here... */
    }

    /**
     * Settings
     *
     * @param    \IPS\Helpers\Form    $form    The form
     * @return    void
     */
    public function settings(&$form)
    {
        $settings = json_decode($this->settings, TRUE);

        $form->add(new \IPS\Helpers\Form\Text('coinpayments_public_key', isset($settings['public_key']) ? $settings['public_key'] : '', TRUE));
        $form->add(new \IPS\Helpers\Form\Text('coinpayments_private_key', isset($settings['private_key']) ? $settings['private_key'] : '', TRUE));
        $form->add(new \IPS\Helpers\Form\Text('coinpayments_merchant_id', isset($settings['merchant_id']) ? $settings['merchant_id'] : '', TRUE));
        $form->add(new \IPS\Helpers\Form\Text('coinpayments_ipn_secret', isset($settings['ipn_secret']) ? $settings['ipn_secret'] : '', TRUE));
        $form->add(new \IPS\Helpers\Form\Text('coinpayments_debug_email', isset($settings['debug_email']) ? $settings['debug_email'] : '', TRUE));
        $form->add(new \IPS\Helpers\Form\YesNo('coinpayments_pass_name', isset($settings['pass_name']) ? $settings['pass_name'] : 1, TRUE));
    }

    /**
     * Test Settings
     *
     * @param    array    $settings    Settings
     * @return    array
     * @throws    \InvalidArgumentException
     */
    public function testSettings($settings)
    {
        try {
            $cps = $this->initGateway($settings['private_key'], $settings['public_key']);

            /* Test connection by trying to get the rates for the currencies */
            $response = $cps->GetRates();
            if ($response['error'] !== 'ok') {
                throw new \InvalidArgumentException($response['error']);
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), $e->getCode());
        }

        return $settings;
    }

    /**
     * Get full name for Coinpayments
     *
     * @param \IPS\nexus\Transaction $transaction Transaction
     * @return string
     */
    protected function getFullName(\IPS\nexus\Transaction $transaction)
    {
        return $this->getFirstName($transaction) . ' ' . $this->getLastName($transaction);
    }

    /**
     * Get buyer email for Coinpayments
     *
     * @param \IPS\nexus\Transaction $transaction Transaction
     * @return string
     */
    protected function getBuyerEmail(\IPS\nexus\Transaction $transaction)
    {
        return $transaction->invoice->member->email ?? $transaction->invoice->guest_data['member']['email'] ?? '';
    }

    /**
     * Get first name for Coinpayments
     *
     * @param    \IPS\nexus\Transaction    $transaction    Transaction
     * @return    array
     */
    protected function getFirstName(\IPS\nexus\Transaction $transaction)
    {
        return $transaction->invoice->member->member_id ? $transaction->invoice->member->cm_first_name : $transaction->invoice->guest_data['member']['cm_first_name'];
    }

    /**
     * Get last name for Coinpayments
     *
     * @param \IPS\nexus\Transaction $transaction
     * @return string
     */
    protected function getLastName(\IPS\nexus\Transaction $transaction)
    {
        return $transaction->invoice->member->member_id ? $transaction->invoice->member->cm_last_name : $transaction->invoice->guest_data['member']['cm_last_name'];
    }
}
