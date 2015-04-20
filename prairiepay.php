<?php
/**
 * Payeezy Credit Card processing gateway. Supports both
 * onsite and offsite payment processing for Credit Cards.
 *
 * The Payeezy API can be found at: https://developer.payeezy.com/
 *
 * @package blesta
 * @subpackage blesta.components.gateways.payeezy
 * @copyright Copyright (c) 2015, Silicon-Prairie.Net LLC
 * @license http://www.silicon-prairie.net/license.html
 * @link http://www.silicon-prairie.net/ Silicon-Prairie.Net LLC
 */


use Omnipay\Omnipay;


class Payeezy extends MerchantGateway implements MerchantCc, MerchantCcOffsite
{

    /**
    * @param Http An Http object, used to make HTTP requests
    */
    protected $Http;

    /**
     * Sets the currency code to be used for all subsequent payments
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency) {
        $this->currency = $currency;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta=null) {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View("settings", "default");
        $this->view->setDefaultView("components" . DS . "gateways" . DS . "merchant" . DS . "stripe_gateway" . DS);
        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        $this->view->set("meta", $meta);

        return $this->view->fetch();
    }

    /**
     *
     * Validates the given meta (settings) data to be updated for this gateway
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure

     */
    public function editSettings(array $meta) {
        // Verify meta data is valid
        $rules = array(
            'api_key'=>array(
                'empty'=>array(
                    'rule'=>"isEmpty",
                    'negate'=>true,
                    'message'=>Language::_("Stripe_gateway.!error.api_key.empty", true)
                )
            )
        );

        // Set checkbox if not set
        if (!isset($meta['stored']))
            $meta['stored'] = "false";

        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);
        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    public function encryptableFields() {
        return array("api_key");
    }

    public function setMeta(array $meta=null) {
        $this->meta = $meta;
    }


    /**
     * Used to determine whether this gateway can be configured for autodebiting accounts
     *
     * @return boolean True if the customer must be present (e.g. in the case of credit card customer must enter security code), false otherwise
     */
    public function requiresCustomerPresent()
    {}

    /**
     * Process a request over HTTP using the supplied method type, url and parameters.
     *
     * @param string $method The method type (e.g. GET, POST)
     * @param string $url The URL to post to
     * @param mixed An array of parameters or a URL encoded list of key/value pairs
     * @param string The output result from executing the request
     */
    protected function httpRequest($method, $url=null, $params=null) {
        if (!isset($this->Http)) {
            Loader::loadComponents($this, array("Net"));
            $this->Http = $this->Net->create("Http");
        }

        if (is_array($params))
            $params = http_build_query($params);

        return $this->Http->request($method, $url, $params);
    }

    /**
     * Fetches an array containing the error response to be set using Input::setErrors()
     *
     * @param string $type The type of error to fetch. Values include:
     * 	- card_number_invalid
     * 	- card_expired
     * 	- routing_number_invalid
     * 	- account_number_invalid
     * 	- duplicate_transaction
     * 	- card_not_accepted
     * 	- invalid_security_code
     * 	- address_verification_failed
     * 	- transaction_not_found The transaction was not found on the remote gateway
     * 	- unsupported The action is not supported by the gateway
     * 	- general A general error occurred
     * @return mixed An array containing the error to populate using Input::setErrors(), false if the type does not exist
     */
    protected function getCommonError($type) {
        Language::loadLang("merchant_gateway");

        $message = "";
        $field = "";

        switch ($type) {
            case "card_number_invalid":
                $field = "card_number";
                $message = Language::_("MerchantGateway.!error.card_number_invalid", true);
                break;
            case "card_expired":
                $field = "card_exp";
                $message = Language::_("MerchantGateway.!error.card_expired", true);
                break;
            case "routing_number_invalid":
                $field = "routing_number";
                $message = Language::_("MerchantGateway.!error.routing_number_invalid", true);
                break;
            case "account_number_invalid":
                $field = "account_number";
                $message = Language::_("MerchantGateway.!error.account_number_invalid", true);
                break;
            case "duplicate_transaction":
                $field = "amount";
                $message = Language::_("MerchantGateway.!error.duplicate_transaction", true);
                break;
            case "card_not_accepted":
                $field = "type";
                $message = Language::_("MerchantGateway.!error.card_not_accepted", true);
                break;
            case "invalid_security_code":
                $field = "card_security_code";
                $message = Language::_("MerchantGateway.!error.invalid_security_code", true);
                break;
            case "address_verification_failed":
                $field = "zip";
                $message = Language::_("MerchantGateway.!error.address_verification_failed", true);
                break;
            case "transaction_not_found":
                $field = "transaction_id";
                $message = Language::_("MerchantGateway.!error.transaction_not_found", true);
                break;
            case "unsupported":
                $message = Language::_("MerchantGateway.!error.unsupported", true);
                break;
            case "general":
                $message = Language::_("MerchantGateway.!error.general", true);
                break;
            default:
                return false;
        }

        return array(
            $field => array(
                $type => $message
            )
        );
    }

    /**
     * Store a credit card off site
     *
     * @param array $card_info An array of card info to store off site including:
     *    - first_name The first name on the card
     *    - last_name The last name on the card
     *    - card_number The card number
     *    - card_exp The card expiration date in yyyymm format
     *    - card_security_code The 3 or 4 digit security code of the card (if available)
     *    - type The credit card type
     *    - address1 The address 1 line of the card holder
     *    - address2 The address 2 line of the card holder
     *    - city The city of the card holder
     *    - state An array of state info including:
     *        - code The 2 or 3-character state code
     *        - name The local name of the country
     *    - country An array of country info including:
     *        - alpha2 The 2-character country code
     *        - alpha3 The 3-character country code
     *        - name The english name of the country
     *        - alt_name The local name of the country
     *    - zip The zip/postal code of the card holder
     * @param array $contact An array of contact information for the billing contact this account is to be set up under including:
     *    - id The ID of the contact
     *    - client_id The ID of the client this contact resides under
     *    - user_id The ID of the user this contact represents
     *    - contact_type The contact type
     *    - contact_type_id The reference ID for this custom contact type
     *    - contact_type_name The name of the contact type
     *    - first_name The first name of the contact
     *    - last_name The last name of the contact
     *    - title The title of the contact
     *    - company The company name of the contact
     *    - email The email address of the contact
     *    - address1 The address of the contact
     *    - address2 The address line 2 of the contact
     *    - city The city of the contact
     *    - state An array of state info including:
     *        - code The 2 or 3-character state code
     *        - name The local name of the country
     *    - country An array of country info including:
     *        - alpha2 The 2-character country code
     *        - alpha3 The 3-character country code
     *        - name The english name of the country
     *        - alt_name The local name of the country
     *    - zip The zip/postal code of the contact
     *    - date_added The date/time the contact was added
     * @param string $client_reference_id The reference ID for the client on the remote gateway (if one exists)
     * @return mixed False on failure or an array containing:
     *    - client_reference_id The reference ID for this client
     *    - reference_id The reference ID for this payment account
     */
    public function storeCc(array $card_info, array $contact, $client_reference_id = null)
    {}

    /**
     * Update a credit card stored off site
     *
     * @param array $card_info An array of card info to store off site including:
     *    - first_name The first name on the card
     *    - last_name The last name on the card
     *    - card_number The card number
     *    - card_exp The card expiration date in yyyymm format
     *    - card_security_code The 3 or 4 digit security code of the card (if available)
     *    - type The credit card type
     *    - address1 The address 1 line of the card holder
     *    - address2 The address 2 line of the card holder
     *    - city The city of the card holder
     *    - state An array of state info including:
     *        - code The 2 or 3-character state code
     *        - name The local name of the country
     *    - country An array of country info including:
     *        - alpha2 The 2-character country code
     *        - alpha3 The 3-character country code
     *        - name The english name of the country
     *        - alt_name The local name of the country
     *    - zip The zip/postal code of the card holder
     *    - account_changed True if the account details (bank account or card number, etc.) have been updated, false otherwise
     * @param array $contact An array of contact information for the billing contact this account is to be set up under including:
     *    - id The ID of the contact
     *    - client_id The ID of the client this contact resides under
     *    - user_id The ID of the user this contact represents
     *    - contact_type The contact type
     *    - contact_type_id The reference ID for this custom contact type
     *    - contact_type_name The name of the contact type
     *    - first_name The first name of the contact
     *    - last_name The last name of the contact
     *    - title The title of the contact
     *    - company The company name of the contact
     *    - email The email address of the contact
     *    - address1 The address of the contact
     *    - address2 The address line 2 of the contact
     *    - city The city of the contact
     *    - state An array of state info including:
     *        - code The 2 or 3-character state code
     *        - name The local name of the country
     *    - country An array of country info including:
     *        - alpha2 The 2-character country code
     *        - alpha3 The 3-character country code
     *        - name The english name of the country
     *        - alt_name The local name of the country
     *    - zip The zip/postal code of the contact
     *    - date_added The date/time the contact was added
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
     * @return mixed False on failure or an array containing:
     *    - client_reference_id The reference ID for this client
     *    - reference_id The reference ID for this payment account
     */
    public function updateCc(array $card_info, array $contact, $client_reference_id, $account_reference_id)
    {}

    /**
     * Remove a credit card stored off site
     *
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway to remove
     * @return array An array containing:
     *    - client_reference_id The reference ID for this client
     *    - reference_id The reference ID for this payment account
     */
    public function removeCc($client_reference_id, $account_reference_id)
    {}

    /**
     * Charge a credit card stored off site
     *
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
     * @param float $amount The amount to process
     * @param array $invoice_amounts An array of invoices, each containing:
     *    - id The ID of the invoice being processed
     *    - amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function processStoredCc($client_reference_id, $account_reference_id, $amount, array $invoice_amounts = null)
    {}

    /**
     * Authorize a credit card stored off site (do not charge)
     *
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
     * @param float $amount The amount to authorize
     * @param array $invoice_amounts An array of invoices, each containing:
     *    - id The ID of the invoice being processed
     *    - amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function authorizeStoredCc($client_reference_id, $account_reference_id, $amount, array $invoice_amounts = null)
    {}

    /**
     * Charge a previously authorized credit card stored off site
     *
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
     * @param string $transaction_reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The ID of the previously authorized transaction
     * @param float $amount The amount to capture
     * @param array $invoice_amounts An array of invoices, each containing:
     *    - id The ID of the invoice being processed
     *    - amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function captureStoredCc($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {}

    /**
     * Void an off site credit card charge
     *
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
     * @param string $transaction_reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The ID of the previously authorized transaction
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function voidStoredCc($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id)
    {}

    /**
     * Refund an off site credit card charge
     *
     * @param string $client_reference_id The reference ID for the client on the remote gateway
     * @param string $account_reference_id The reference ID for the stored account on the remote gateway to update
     * @param string $transaction_reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The ID of the previously authorized transaction
     * @param float $amount The amount to refund
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function refundStoredCc($client_reference_id, $account_reference_id, $transaction_reference_id, $transaction_id, $amount)
    {}

    /**
     * Used to determine if offsite credit card customer account information is enabled for the gateway
     * This is invoked after the gateway has been initialized and after Gateway::setMeta() has been called.
     * The gateway should examine its current settings to verify whether or not the system
     * should invoke the gateway's offsite methods
     *
     * @return boolean True if the gateway expects the offset methods to be called for credit card payments, false to process the normal methods instead
     */
    public function requiresCcStorage()
    {}

    /**
     * Charge a credit card
     *
     * @param array $card_info An array of credit card info including:
     * 	- first_name The first name on the card
     * 	- last_name The last name on the card
     * 	- card_number The card number
     * 	- card_exp The card expidation date in yyyymm format
     * 	- card_security_code The 3 or 4 digit security code of the card (if available)
     * 	- type The credit card type
     * 	- address1 The address 1 line of the card holder
     * 	- address2 The address 2 line of the card holder
     * 	- city The city of the card holder
     * 	- state An array of state info including:
     * 		- code The 2 or 3-character state code
     * 		- name The local name of the country
     * 	- country An array of country info including:
     * 		- alpha2 The 2-character country code
     * 		- alpha3 The 3-cahracter country code
     * 		- name The english name of the country
     * 		- alt_name The local name of the country
     * 	- zip The zip/postal code of the card holder
     * @param float $amount The amount to charge this card
     * @param array $invoice_amounts An array of invoices, each containing:
     * 	- id The ID of the invoice being processed
     * 	- amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
     * 	- transaction_id The ID returned by the remote gateway to identify this transaction
     * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function processCc(array $card_info, $amount, array $invoice_amounts=null)
    {}

    /**
     * Authorize a credit card
     *
     * @param array $card_info An array of credit card info including:
     * 	- first_name The first name on the card
     * 	- last_name The last name on the card
     * 	- card_number The card number
     * 	- card_exp The card expidation date in yyyymm format
     * 	- card_security_code The 3 or 4 digit security code of the card (if available)
     * 	- type The credit card type
     * 	- address1 The address 1 line of the card holder
     * 	- address2 The address 2 line of the card holder
     * 	- city The city of the card holder
     * 	- state An array of state info including:
     * 		- code The 2 or 3-character state code
     * 		- name The local name of the country
     * 	- country An array of country info including:
     * 		- alpha2 The 2-character country code
     * 		- alpha3 The 3-cahracter country code
     * 		- name The english name of the country
     * 		- alt_name The local name of the country
     * 	- zip The zip/postal code of the card holder
     * @param float $amount The amount to charge this card
     * @param array $invoice_amounts An array of invoices, each containing:
     * 	- id The ID of the invoice being processed
     * 	- amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
     * 	- transaction_id The ID returned by the remote gateway to identify this transaction
     * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function authorizeCc(array $card_info, $amount, array $invoice_amounts=null)
    {}

    /**
     * Capture the funds of a previously authorized credit card
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @param float $amount The amount to capture on this card
     * @param array $invoice_amounts An array of invoices, each containing:
     * 	- id The ID of the invoice being processed
     * 	- amount The amount being processed for this invoice (which is included in $amount)
     * @return array An array of transaction data including:
     * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
     * 	- transaction_id The ID returned by the remote gateway to identify this transaction
     * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function captureCc($reference_id, $transaction_id, $amount, array $invoice_amounts=null)
    {}

    /**
     * Void a credit card charge
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @return array An array of transaction data including:
     * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
     * 	- transaction_id The ID returned by the remote gateway to identify this transaction
     * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function voidCc($reference_id, $transaction_id)
    {}

    /**
     * Refund a credit card charge
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction
     * @param float $amount The amount to refund this card
     * @return array An array of transaction data including:
     * 	- status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     * 	- reference_id The reference ID for gateway-only use with this transaction (optional)
     * 	- transaction_id The ID returned by the remote gateway to identify this transaction
     * 	- message The message to be displayed in the interface in addition to the standard message for this transaction status (optional)
     */
    public function refundCc($reference_id, $transaction_id, $amount)
    {}


}






$gateway = Omnipay::create('Payeezy');
$gateway->setUsername('oasisfleeting');
$gateway->setPassword('gniteelfsisao');