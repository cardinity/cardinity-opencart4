<?php
namespace Opencart\Catalog\Controller\Extension\OcCardinityPayment\Payment;

class CardinityPayment extends \Opencart\System\Engine\Controller
{

    /**
     * Build Hosted payment request form
     *
     * @return view
     */
    private function buildHostedPaymentForm()
    {

 		$totals = [];
		$taxes = $this->cart->getTaxes();
		$total = 0;

		$this->load->model('checkout/cart');

		if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
			($this->model_checkout_cart->getTotals)($totals, $taxes, $total);
		}

        $this->log->write($this->config->get("config_currency"));
        
        $amount = number_format($total, 2, '.', '');
        $country = strtoupper(substr($this->config->get('config_language'), 3, 2)); 
        $language = strtoupper(substr($this->config->get('config_language'), 0, 2));
        $currency = $this->session->data['currency'];

        $formattedOrderId = $this->session->data['order_id'];
        if ($formattedOrderId < 100) {
            $formattedOrderId = str_pad($formattedOrderId, 3, '0', STR_PAD_LEFT);
        } else {
            $formattedOrderId = $orderId;
        }

        $description = $this->session->data['order_id'];
        $order_id = $formattedOrderId;
        $return_url = $this->url->link('extension/oc_cardinity_payment/payment/cardinity_payment&event=hosted_callback', '', true);
        $cancel_url = $this->url->link('extension/oc_cardinity_payment/payment/cardinity_payment&event=hosted_callback', '', true);

        $project_id = $this->config->get('payment_cardinity_payment_project_key_0'); 
        $project_secret = $this->config->get('payment_cardinity_payment_project_secret_0'); 

        $attributes = [
            "amount" => $amount,
            "currency" => $currency,
            "country" => $country,
            "language" => $language,
            "order_id" => $order_id,
            "description" => $description,
            "project_id" => $project_id,
            "cancel_url" => $cancel_url,
            "return_url" => $return_url,
        ];

        ksort($attributes);
        $message = '';
        foreach ($attributes as $key => $value) {
            $message .= $key . $value;
        }

        $signature = hash_hmac('sha256', $message, $project_secret);

        $data = $attributes;
        $data['signature'] = $signature;
        $data['logged'] = $this->customer->isLogged();
        $data['action_url'] = 'https://checkout.cardinity.com';

        return $this->load->view('extension/oc_cardinity_payment/payment/cardinity_hosted_payment', $data);
    }

    /**
     * Callback URL from hosted payment
     *
     * @return view
     */
    private function handleHostedPaymentResponse()
    {

        $this->load->language('extension/oc_cardinity_payment/payment/cardinity_payment');
        $this->load->model('extension/oc_cardinity_payment/payment/cardinity_payment');
        $this->load->model('checkout/order');

        $json['redirect'] = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);

        
        if (!isset($this->session->data['order_id'])) {
            //order not found            
            $json['error']['warning'] = $this->language->get('error_order');
        } else {
            //order found            
            // Verify response is not tampered
            $message = '';
            ksort($_POST);

            foreach ($_POST as $key => $value) {
                if ($key == 'signature') {
                    continue;
                }
                $message .= $key . $value;
            }

            $signature = hash_hmac('sha256', $message, '1pl6zupeeby1plu9ru5w6pbgow2zzyurrc1s3s2x7dwtupch8f');

            if ($signature == $_POST['signature']) {
                //signature matched
                if ($_POST['status']??'' == 'approved') {
                    //payment accepted
                    $this->model_checkout_order->addHistory($this->session->data['order_id'], $this->config->get('payment_cardinity_payment_approved_status_id'), '', true);
                    $json['redirect'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);

                } else {
                    //payment failed
                    $this->model_checkout_order->addHistory($this->session->data['order_id'], $this->config->get('payment_cardinity_payment_failed_status_id'), '', true);                    
                }

            } else {
                //bad signature
                $json['error']['warning'] = $this->language->get('error_hosted_signature');
                $this->model->extension_oc_cardinity_payment_payment_cardinity_payment->log("Error hosted signature did not match");
            }

        }

        return $this->response->redirect($json['redirect']);
    }

    public function index(): string
    {
        /**
         * Bypass router, as we cannot pass '|' in hosted payment callback url.
         */
        if ( isset($_GET['event']) ?? '' === 'hosted_callback') {
            return $this->handleHostedPaymentResponse();
        }

        return $this->buildHostedPaymentForm();

        /*
    $this->load->language('extension/oc_cardinity_payment/payment/cardinity_payment');

    $data['logged'] = $this->customer->isLogged();

    $data['months'] = [];

    foreach (range(1, 12) as $month) {
    $data['months'][] = date('m', mktime(0, 0, 0, $month));
    }

    $data['years'] = [];

    foreach (range(date('Y'), date('Y', strtotime('+10 year'))) as $year) {
    $data['years'][] = $year;
    }

    $data['language'] = $this->config->get('config_language');

    return $this->load->view('extension/oc_cardinity_payment/payment/cardinity_payment', $data);*/
    }

    public function confirm(): void
    {
        $this->load->language('extension/oc_cardinity_payment/payment/cardinity_payment');

        $json = [];

        $keys = [
            'card_name',
            'card_number',
            'card_expire_month',
            'card_expire_year',
            'card_cvv',
            'store',
        ];

        foreach ($keys as $key) {
            if (!isset($this->request->post[$key])) {
                $this->request->post[$key] = '';
            }
        }

        if (!isset($this->session->data['order_id'])) {
            $json['error']['warning'] = $this->language->get('error_order');
        }

        if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method'] != 'cardinity_payment') {
            $json['error']['warning'] = $this->language->get('error_payment_method');
        }

        if (!$this->request->post['card_name']) {
            $json['error']['card_name'] = $this->language->get('error_card_name');
        }

        if (!preg_match('/[0-9\s]{8,19}/', $this->request->post['card_number'])) {
            $json['error']['card_number'] = $this->language->get('error_card_number');
        }

        if ($this->request->post['card_expire_year'] && $this->request->post['card_expire_month']) {
            if (strtotime((int) $this->request->post['card_expire_year'] . '-' . $this->request->post['card_expire_month'] . '-01') < time()) {
                $json['error']['card_expire'] = $this->language->get('error_card_expired');
            }
        } else {
            $json['error']['card_expire'] = $this->language->get('error_card_expire');
        }

        if (strlen($this->request->post['card_cvv']) != 3) {
            $json['error']['card_cvv'] = $this->language->get('error_card_cvv');
        }

        if (!$json) {
            // Set Credit Card response
            if ($this->config->get('payment_cardinity_payment_response')) {
                // Card storage
                if ($this->customer->isLogged() && $this->request->post['store']) {
                    $this->load->model('account/payment_method');

                    $payment_method_data = [
                        'name' => '**** **** **** ' . substr($this->request->post['card_number'], -4),
                        'image' => 'visa.png',
                        'type' => 'visa',
                        'extension' => 'opencart',
                        'code' => 'cardinity_payment',
                        'token' => md5(rand()),
                        'date_expire' => $this->request->post['card_expire_year'] . '-' . $this->request->post['card_expire_month'] . '-01',
                        'default' => !$this->model_account_payment_method->getTotalPaymentMethods() ? true : false,
                    ];

                    $this->model_account_payment_method->addPaymentMethod($payment_method_data);
                }

                $this->load->model('checkout/order');

                $this->model_checkout_order->addHistory($this->session->data['order_id'], $this->config->get('payment_cardinity_payment_approved_status_id'), '', true);

                $json['redirect'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);
            } else {
                $this->load->model('checkout/order');

                $this->model_checkout_order->addHistory($this->session->data['order_id'], $this->config->get('payment_cardinity_payment_failed_status_id'), '', true);

                $json['redirect'] = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
