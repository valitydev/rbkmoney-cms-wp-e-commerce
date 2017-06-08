<?php
/**
 * Plugin Name: RBKmoney Payment Gateway for e-commerce
 * Plugin URI: https://www.rbk.money
 * Description: A plugin for RBKmoney Payment in e-commerce
 * Version: 1.0
 * Author: RBKmoney
 * Author URI: https://www.rbk.money
 **/

$nzshpcrt_gateways[$num]['name'] = __('RBKmoney', 'wp-e-commerce');
$nzshpcrt_gateways[$num]['internalname'] = 'rbkmoney';
$nzshpcrt_gateways[$num]['function'] = 'gateway_rbkmoney_payment';
$nzshpcrt_gateways[$num]['form'] = "form_rbkmoney_payment";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_rbkmoney_payment";
$nzshpcrt_gateways[$num]['payment_type'] = "rbkmoney";
$nzshpcrt_gateways[$num]['display_name'] = __('RBKmoney', 'wp-e-commerce');
$nzshpcrt_gateways[$num]['image'] = plugins_url( '', __FILE__ ) . '/rbkmoney-payment/images/rbkmoney_payment.png';


require 'rbkmoney-payment/RBKmoneyPayment.php';
require 'rbkmoney-payment/RBKmoneyPaymentVerification.php';

/**
 * Output payment button
 *
 * @param $separator
 * @param $sessionid
 */
function gateway_rbkmoney_payment($separator, $sessionid)
{
    global $wpdb;

    $purchase_log_sql = $wpdb->prepare("SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `sessionid`= %s LIMIT 1", $sessionid);
    $purchase_log = $wpdb->get_results($purchase_log_sql, ARRAY_A);

    // e.g. RUB
    $currency = WPSC_Countries::get_currency_code(get_option('currency_type'));

    $company_name = '';
    if (!empty(trim(get_option('rbkmoney_payment_form_company_name')))) {
        $company_name = 'data-name="' . trim(get_option('rbkmoney_payment_form_company_name')) . '"';
    }

    $company_logo = '';
    if (!empty(trim(get_option('rbkmoney_payment_form_company_name')))) {
        $company_logo = 'data-logo="' . trim(get_option('rbkmoney_payment_form_path_logo')) . '"';
    }

    $button_label = '';
    if (!empty(trim(get_option('rbkmoney_payment_form_button_label')))) {
        $button_label = 'data-label="' . trim(get_option('rbkmoney_payment_form_button_label')) . '"';
    }

    $description = '';
    if (!empty(trim(get_option('rbkmoney_payment_form_description')))) {
        $description = 'data-description="' . trim(get_option('rbkmoney_payment_form_description')) . '"';
    }


    $amount = number_format($purchase_log[0]['totalprice'], 2, '', '');
    $params = array(
        'shop_id' => trim(get_option('rbkmoney_payment_shop_id')),
        'currency' => $currency,
        'product' => $purchase_log[0]['id'],
        'description' => 'Order ID ' . $purchase_log[0]['id'],
        'amount' => $amount,
        'order_id' => $purchase_log[0]['id'],
        'session_id' => $sessionid,
        'merchant_private_key' => trim(get_option('rbkmoney_payment_private_key')),
    );

    try {
        $rbk_api = new RBKmoneyPayment($params);
        $invoice_id = $rbk_api->create_invoice();
        $invoice_access_token = $rbk_api->create_access_token($invoice_id);
    } catch (Exception $ex) {
        echo $ex->getMessage();
        exit();
    }

    $output = '<html>
    <head>
    	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    </head>
    <body>
    <form action="' . home_url('/?rbkmoney_payment_results') . '" method="GET">
        <input type="hidden" name="rbkmoney_payment_results" value="true">
        <script src="https://checkout.rbk.money/checkout.js" class="rbkmoney-checkout"
                          data-invoice-id="' . $invoice_id . '"
                          data-invoice-access-token="' . $invoice_access_token . '"
                          ' . $company_name . '
                          ' . $company_logo . '
                          ' . $button_label . '
                          ' . $description . '
                          >
                  </script>
    </form></body></html>';

    echo $output;
    exit();
}


/**
 * e.g. http{s}://{your-site}/?rbkmoney_payment_callback
 */
function nzshpcrt_rbkmoney_payment_callback()
{
    global $wpdb;

    if (isset($_GET['rbkmoney_payment_callback'])) {

        if (empty($_SERVER[RBKmoneyPaymentVerification::SIGNATURE])) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                'Webhook notification signature missing'
            );
        }

        $params_signature = RBKmoneyPaymentVerification::get_parameters_content_signature($_SERVER[RBKmoneyPaymentVerification::SIGNATURE]);
        if (empty($params_signature[RBKmoneyPaymentVerification::SIGNATURE_ALG])) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                'Missing required parameter ' . RBKmoneyPaymentVerification::SIGNATURE_ALG
            );
            exit();
        }

        if (empty($params_signature[RBKmoneyPaymentVerification::SIGNATURE_DIGEST])) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                'Missing required parameter ' . RBKmoneyPaymentVerification::SIGNATURE_DIGEST
            );
            exit();
        }

        $content = file_get_contents('php://input');
        $signature = RBKmoneyPaymentVerification::urlsafe_b64decode($params_signature[RBKmoneyPaymentVerification::SIGNATURE_DIGEST]);
        if (!RBKmoneyPaymentVerification::verification_signature($content, $signature, trim(get_option('rbkmoney_payment_callback_public_key')))) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                'Webhook notification signature mismatch'
            );
        }

        $invoice = 'invoice';
        $eventType = 'eventType';

        $required_fields = [$invoice, $eventType];
        $data = json_decode($content, TRUE);
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                _rbkmoney_payment_response_with_code_and_message(
                    RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                    'One or more required fields are missing'
                );
            }
        }

        $current_shop_id = (int)trim(get_option('rbkmoney_payment_shop_id'));
        if ($current_shop_id != $data[$invoice][RBKmoneyPayment::SHOP_ID]) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                RBKmoneyPayment::SHOP_ID . ' is missing'
            );
        }

        if (empty($data[$invoice][RBKmoneyPayment::METADATA][RBKmoneyPayment::ORDER_ID])) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                RBKmoneyPayment::ORDER_ID . ' is missing'
            );
        }

        if (empty($data[$invoice][RBKmoneyPayment::METADATA][RBKmoneyPayment::SESSION_ID])) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                RBKmoneyPayment::SESSION_ID . ' is missing'
            );
        }

        $purchase_log_sql = $wpdb->prepare("SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `id`= %s AND `sessionid`= %s LIMIT 1",
            $data[$invoice][RBKmoneyPayment::METADATA][RBKmoneyPayment::ORDER_ID],
            $data[$invoice][RBKmoneyPayment::METADATA][RBKmoneyPayment::SESSION_ID]
        );
        $purchase_log = $wpdb->get_results($purchase_log_sql, ARRAY_A);
        if (empty($purchase_log)) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                'Purchase not found ' . $purchase_log_sql
            );
        }

        $order_amount = number_format($purchase_log[0]['totalprice'], 2, '', '');
        $invoice_amount = $data[$invoice]['amount'];
        if ($order_amount != $invoice_amount) {
            _rbkmoney_payment_response_with_code_and_message(
                RBKmoneyPayment::HTTP_CODE_BAD_REQUEST,
                'Received amount vs Order amount mismatch '. $order_amount .' - ' . $invoice_amount
            );
        }


        $allowed_event_types = ['InvoicePaid', 'InvoiceCancelled'];
        if (in_array($data[$eventType], $allowed_event_types)) {

            $all_statuses = array(
                '1' => 'pending',
                '2' => 'completed',
                '3' => 'ok',
                '4' => 'processed',
                '5' => 'closed',
                '6' => 'rejected',
            );

            if ($purchase_log[0]['processed'] == array_search('pending', $all_statuses)) {

                $processed = array_search('pending', $all_statuses);
                $details = array(
                    'processed' => $processed,
                    'transactid' => $data[$invoice]['id'],
                    'date' => time(),
                );
                $session_id = $data[$invoice][RBKmoneyPayment::METADATA][RBKmoneyPayment::SESSION_ID];

                switch ($data[$invoice]['status']) {
                    case 'paid':
                        $details['processed'] = array_search('ok', $all_statuses);
                        wpsc_update_purchase_log_details($session_id, $details, 'sessionid');
                        transaction_results($session_id, false, $data[$invoice]['id']);
                        break;
                    case 'cancelled':
                        $details['processed'] = array_search('closed', $all_statuses);
                        wpsc_update_purchase_log_details($session_id, $details, 'sessionid');
                        transaction_results($session_id, false, $data[$invoice]['id']);
                        break;
                    default:
                        // The default action is not needed if a status has come that does not interest us
                        break;
                }

            }
        }
        _rbkmoney_payment_response_with_code_and_message(
            RBKmoneyPayment::HTTP_CODE_OK,
            'OK'
        );
    }
}

/**
 * Helper function for response with http code and message
 *
 * @param $code (e.g. 200)
 * @param $message (e.g. OK)
 */
function _rbkmoney_payment_response_with_code_and_message($code, $message)
{
    $response = array(
        'message' => $message
    );
    http_response_code($code);
    echo json_encode($response);
    exit();
}

/**
 * e.g. http{s}://{your-site}/?rbkmoney_payment_results
 */
function nzshpcrt_rbkmoney_payment_results()

{
    if (isset($_GET['rbkmoney_payment_results'])) {
        header('Location: ' . get_option('transact_url'), true, RBKmoneyPayment::HTTP_CODE_MOVED_PERMANENTLY);
        exit;
    }
}

/**
 * To confirm that you have changed the fields in the admin panel
 *
 * @return bool
 */
function submit_rbkmoney_payment()
{
    $text_fields = array(
        'rbkmoney_payment_shop_id',
        'rbkmoney_payment_form_path_logo',
        'rbkmoney_payment_form_button_label',
        'rbkmoney_payment_form_description',
    );
    _rbkmoney_payment_update_options($text_fields, 'text');

    $textarea_fields = array(
        'rbkmoney_payment_private_key',
        'rbkmoney_payment_callback_public_key',
    );
    _rbkmoney_payment_update_options($textarea_fields, 'textarea');

    return true;
}

/**
 * Helper function for updating fields
 *
 * @param $fields array
 * @param $type string
 */
function _rbkmoney_payment_update_options($fields, $type)
{
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            if ($type == 'textarea') {
                update_option($field, sanitize_textarea_field(trim($_POST[$field])));
            } else if ($type == 'text') {
                update_option($field, sanitize_text_field(trim($_POST[$field])));
            }
        }
    }
}

/**
 * Output fields in the admin panel
 *
 * @return string
 */
function form_rbkmoney_payment()
{
    $output = "
		<tr>
			<td>" . __('Shop ID', 'wp-e-commerce') . "</td>
			<td>
				<input type='text' size='60' value='" . trim(get_option('rbkmoney_payment_shop_id')) . "' name='rbkmoney_payment_shop_id' />
				<p class='description'>
					" . __('Number of the merchant\'s shop system RBKmoney', 'wp-e-commerce') . "
				</p>
			</td>
		</tr>
		<tr>
			<td>" . __('Logo in payment form', 'wp-e-commerce') . "</td>
			<td>
				<input type='text' size='60' value='" . trim(get_option('rbkmoney_payment_form_path_logo')) . "' name='rbkmoney_payment_form_path_logo' />
				<p class='description'>
					" . __('Your logo for payment form', 'wp-e-commerce') . "
				</p>
		</tr>
		<tr>
			<td>" . __('Company name in payment form', 'wp-e-commerce') . "</td>
			<td>
				<input type='text' size='60' value='" . trim(get_option('rbkmoney_payment_form_company_name')) . "' name='rbkmoney_payment_form_company_name' />
				<p class='description'>
					" . __('Your company name for payment form', 'wp-e-commerce') . "
				</p>
		</tr>
		<tr>
			<td>" . __('Button label in payment form', 'wp-e-commerce') . "</td>
			<td>
				<input type='text' size='60' value='" . trim(get_option('rbkmoney_payment_form_button_label')) . "' name='rbkmoney_payment_form_button_label' />
				<p class='description'>
					" . __('Your button label for payment form', 'wp-e-commerce') . "
				</p>
		</tr>
		<tr>
			<td>" . __('Description in payment form', 'wp-e-commerce') . "</td>
			<td>
				<input type='text' size='60' value='" . trim(get_option('rbkmoney_payment_form_description')) . "' name='rbkmoney_payment_form_description' />
				<p class='description'>
					" . __('Your description for payment form', 'wp-e-commerce') . "
				</p>
		</tr>
		<tr>
			<td>" . __('Private key', 'wp-e-commerce') . "</td>
			<td>
			    <textarea rows='10' cols='45' name='rbkmoney_payment_private_key'>" . trim(get_option('rbkmoney_payment_private_key')) . "</textarea>
				<p class='description'>
					" . __('The private key in the system RBKmoney', 'wp-e-commerce') . "
				</p>
		</tr>
		<tr>
			<td>" . __('Callback public key', 'wp-e-commerce') . "</td>
			<td>
				 <textarea rows='10' cols='45' name='rbkmoney_payment_callback_public_key'>" . trim(get_option('rbkmoney_payment_callback_public_key')) . "</textarea>
				<p class='description'>
					" . __('Callback public key', 'wp-e-commerce') . "
				</p>
		</tr>
		<tr>
			<td>" . __('Notification URL', 'wp-e-commerce') . "</td>
			<td>
				<input type='text' size='60' value='" . home_url('/?rbkmoney_payment_callback') . "' name='rbkmoney_payment_notification_url' readonly />
				<p class='description'>
					" . __('This address is to be inserted in a private office RBKmoney', 'wp-e-commerce') . "
				</p>
		</tr>";

    return $output;
}

add_action('init', 'nzshpcrt_rbkmoney_payment_callback');
add_action('init', 'nzshpcrt_rbkmoney_payment_results');

?>
