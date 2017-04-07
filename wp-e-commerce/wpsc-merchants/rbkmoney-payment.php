<?php
/**
 * Common settings
 */
$nzshpcrt_gateways[$num]['name'] = __('RBKmoney', 'wp-e-commerce');
$nzshpcrt_gateways[$num]['internalname'] = 'rbkmoney';
$nzshpcrt_gateways[$num]['function'] = 'gateway_rbkmoney_payment';
$nzshpcrt_gateways[$num]['form'] = "form_rbkmoney_payment";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_rbkmoney_payment";
$nzshpcrt_gateways[$num]['payment_type'] = "rbkmoney";
$nzshpcrt_gateways[$num]['display_name'] = __('RBKmoney', 'wp-e-commerce');
$nzshpcrt_gateways[$num]['image'] = WPSC_URL . '/images/rbkmoney_payment.png';


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

    $form_company_name = '';
    if(!empty(trim(get_option('rbkmoney_payment_form_company_name')))) {
        $form_company_name = 'data-name="' . trim(get_option('rbkmoney_payment_form_company_name')).'"';
    }

    $form_path_logo = '';
    if(!empty(trim(get_option('rbkmoney_payment_form_company_name')))) {
        $form_path_logo = 'data-logo="' . trim(get_option('rbkmoney_payment_form_path_logo')).'"';
    }

    $amount = number_format($purchase_log[0]['totalprice'], 2, '.', '');
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

    $output = '<html><body><script src="https://checkout.rbk.money/payframe/payframe.js" class="rbkmoney-checkout"
            data-invoice-id="' . $invoice_id . '"
            data-invoice-access-token="' . $invoice_access_token . '"
            data-endpoint-success="' . home_url('/?rbkmoney_payment_results') . '"
            data-endpoint-failed="' . home_url('/?rbkmoney_payment_results') . '"
            data-amount="' . $amount . '"
            data-currency="' . $currency . '"
            ' . $form_company_name . '
            ' . $form_path_logo . '
    >
    </script></body></html>';

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

        if(empty($_SERVER[RBKmoneyPayment::SIGNATURE])) {
            http_response_code(RBKmoneyPayment::HTTP_CODE_BAD_REQUEST);
            exit();
        }

        $content = file_get_contents('php://input');

        $required_fields = ['invoice_id', 'payment_id', 'amount', 'currency', 'created_at', 'metadata', 'status', 'session_id'];
        $data = json_decode($content, TRUE);
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                http_response_code(RBKmoneyPayment::HTTP_CODE_BAD_REQUEST);
                exit();
            }
        }
        if (empty($data[RBKmoneyPayment::METADATA][RBKmoneyPayment::ORDER_ID])) {
            http_response_code(RBKmoneyPayment::HTTP_CODE_BAD_REQUEST);
            exit();
        }
        if (!$signature = base64_decode($_SERVER[RBKmoneyPayment::SIGNATURE], TRUE)) {
            http_response_code(RBKmoneyPayment::HTTP_CODE_BAD_REQUEST);
            exit();
        }

        if (!RBKmoneyPaymentVerification::verification_signature($content, $signature, trim(get_option('rbkmoney_payment_callback_public_key')))) {
            http_response_code(RBKmoneyPayment::HTTP_CODE_BAD_REQUEST);
            exit();
        }

        $purchase_log_sql = $wpdb->prepare( "SELECT * FROM `".WPSC_TABLE_PURCHASE_LOGS."` WHERE `id`= %s AND `sessionid`= %s LIMIT 1",
            $data[RBKmoneyPayment::METADATA][RBKmoneyPayment::ORDER_ID],
            $data[RBKmoneyPayment::METADATA][RBKmoneyPayment::SESSION_ID]
        );
        $purchase_log = $wpdb->get_results($purchase_log_sql,ARRAY_A);
        if(empty($purchase_log)) {
            http_response_code(RBKmoneyPayment::HTTP_CODE_BAD_REQUEST);
            exit();
        }

        $all_statuses = array(
            '1' => 'pending',
            '2'	=> 'completed',
            '3' => 'ok',
            '4' => 'processed',
            '5'	=> 'closed',
            '6' => 'rejected',
        );

        if($data[RBKmoneyPayment::STATUS] == 'paid') {
            $details = array(
                'processed'  => array_search('ok', $all_statuses),
                'transactid' => $data[RBKmoneyPayment::INVOICE_ID],
                'date'       => time(),
            );
            $session_id = $data[RBKmoneyPayment::METADATA][RBKmoneyPayment::SESSION_ID];
            wpsc_update_purchase_log_details( $session_id, $details, 'sessionid' );
            transaction_results($session_id, false, $data[RBKmoneyPayment::INVOICE_ID]);
        }
    }
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
        'rbkmoney_payment_form_company_name',
        'rbkmoney_payment_logs',
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

    $selected = (get_option('rbkmoney_payment_logs') == 'on') ? 'checked="checked"' : '';
    $output .= "<tr>
			<td>" . __('Enable logs:', 'wp-e-commerce') . "</td>
			<td>
			    <input type='hidden' name='rbkmoney_payment_logs' value='off' />
				<label for='rbkmoney_payment_logs'>
				 <input type='checkbox' name='rbkmoney_payment_logs' id='rbkmoney_payment_logs' value='on' " . $selected . "' />
				</label>
				<p class='description'>
					" . __('Enable logs?', 'wp-e-commerce') . "
				</p>
		</tr>
		</tr>
		   <tr>
           <td colspan='2'>
           	" . sprintf(__('For more help configuring RBKmoney, read our documentation <a href="%s">here</a>', 'wp-e-commerce'), esc_url('https://rbkmoney.github.io/docs/')) . "
           </td>
       </tr>";

    return $output;
}

add_action('init', 'nzshpcrt_rbkmoney_payment_callback');
add_action('init', 'nzshpcrt_rbkmoney_payment_results');

?>
