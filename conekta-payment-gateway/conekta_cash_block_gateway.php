<?php
/*
 * Title   : Conekta Payment extension for WooCommerce
 * Author  : Franklin Carrero
 * Url     : https://wordpress.org/plugins/conekta-payment-gateway/
*/


require_once(__DIR__ . '/vendor/autoload.php');

use Conekta\Api\OrdersApi;
use Conekta\ApiException;
use \Conekta\Configuration;
use Conekta\Model\OrderRequest;
use Conekta\Model\CustomerShippingContacts;
use Conekta\Model\EventTypes;

class WC_Conekta_Cash_Gateway extends WC_Conekta_Plugin
{
    protected $GATEWAY_NAME = "WC_Conekta_Cash_Gateway";
    protected $order = null;
    protected $currencies = array('MXN', 'USD');

    public $id;
    public $method_title;
    public $has_fields;
    public $title;
    public $description;
    public $api_key;
    public $webhook_url;
    public $instructions;
    public $locale = 'es';
    public $i18n = [];

    /**
     * @throws ApiException|Exception
     */
    public function __construct()
    {
        $this->id = 'conekta_cash';
        $this->method_title = __('Conekta Efectivo', 'Conekta Cash');
        $this->has_fields = true;
        $this->ckpg_init_form_fields();
        $this->init_settings();
        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];
        $this->icon        = $this->settings['alternate_imageurl'] ?
                                                $this->settings['alternate_imageurl'] :
                                                WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__))
                                                . '/images/cash.png';
        $this->api_key = $this->settings['api_key'];
        $this->webhook_url = $this->settings['webhook_url'];
        $this->instructions = $this->settings['instructions'];
        $this->i18n = include plugin_dir_path(__FILE__) . "includes/i18n/{$this->locale}.php";


        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'ckpg_thankyou_page'));
        add_action('woocommerce_api_wc_conekta_cash', [$this, 'check_for_webhook']);
        add_action('woocommerce_email_before_order_table',array($this, 'ckpg_email_instructions'));
        add_action('woocommerce_email_before_order_table',array($this, 'ckpg_email_reference'));
        if (!$this->ckpg_validate_currency()) {
            $this->enabled = false;
        }

        if (empty($this->api_key)) {
            $this->enabled = false;
        }
        if (!empty($this->api_key)) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'configure_webhook'));
        }
    }

    /**
     * @throws Exception
     */
    public function configure_webhook()
    {
        $this->create_webhook($this->settings['api_key'], $this->settings['webhook_url']);
    }
    /**
     * @throws ApiException
     */
    public function check_for_webhook()
    {
        if (!isset($_SERVER['REQUEST_METHOD'])
            || ('POST' !== $_SERVER['REQUEST_METHOD'])
            || !isset($_GET['wc-api'])
            || ('wc_conekta_cash' !== $_GET['wc-api'])
        ) {
            return;
        }

        $body = @file_get_contents('php://input');
        $event = json_decode($body, true);

        switch ($event['type']) {
            case EventTypes::WEBHOOK_PING:
                self::handleWebhookPing();
                break;

            case EventTypes::ORDER_PAID:
                self::check_if_payment_payment_method_webhook($this->GATEWAY_NAME, $event);
                self::handleOrderPaid($this->get_api_instance($this->settings['api_key'], $this->version), $event);
                break;

            case EventTypes::ORDER_EXPIRED:
            case EventTypes::ORDER_CANCELED:
                self::check_if_payment_payment_method_webhook($this->GATEWAY_NAME, $event);
                self::handleOrderExpiredOrCanceled($this->get_api_instance($this->settings['api_key'], $this->version), $event);
                break;
            default:
                break;
        }
    }


    private function get_product_type_renderers(): array {
        return [
            'cash_in' => [$this, 'render_cash_in'],
            'pespay_cash_in' => [$this, 'render_cash_in'],
            'oxxo' => [$this, 'render_oxxo'],
            'bbva_cash_in' => [$this, 'render_bbva'],
        ];
    }

    function render_ckpg_header($product_type) {
        $text = $this->i18n['header'][$product_type] ?? $this->i18n['header']['default'];
        echo "<div class=\"conekta-reference-title\">$text</div>";
    }
    
    function render_ckpg_instructions($instructions, $product_type, $reference, $agreement) {
        echo '<div class="conekta-instructions"><ol>';
        foreach ($instructions as $line) {
            echo "<li>$line</li>";
        }
        echo '</ol>';
        echo '</div>';
    }
    
    function render_ckpg_logos($logos, $product_type) {
        echo '<div class="conekta-logo-row">';
        foreach ($logos as $logo) {
            echo '<img src="' . esc_url($logo) . '" alt="Logo" class="' . $product_type .'-logo">';
        }
        echo '</div>';
    }
    
    function render_ckpg_barcode($barcode_url) {
        if (!empty($barcode_url)) {
            echo '<div class="conekta-barcode"><img src="' . esc_url($barcode_url) . '" alt="Código de barras"></div>';
        }
    }
    
    function render_ckpg_reference($reference) {
        echo '<div class="conekta-agreement-text">' . esc_html($reference) . '</div>';
    }
    
    /**
     * Output for the order received page.
     * @param string $order_id
     * @throws ApiException
     */
    function ckpg_thankyou_page($order_id)
{
    $order = new WC_Order($order_id);
    $conekta_order_id = get_post_meta($order->get_id(), 'conekta-order-id', true);
    if (empty($conekta_order_id)) return;

    $conekta_order = $this->get_api_instance($this->settings['api_key'], $this->version)->getorderbyid($conekta_order_id);
    $assets = include plugin_dir_path(__FILE__) . 'includes/blocks/payment-instructions.php';
    $logos_map = $assets['logos'];
    $renderers = $this->get_product_type_renderers();

    echo '<div class="conekta-charges-container">';
    foreach ($conekta_order->getCharges()->getData() as $charge) {
        $payment_method = $charge->getPaymentMethod();
        $method_type = $payment_method->getObject();
        $product_type = method_exists($payment_method, 'getProductType') ? $payment_method->getProductType() : '';

        if ($method_type === 'cash_payment' && !empty($product_type)) {
            $reference = $payment_method->getReference();
            $barcode_url = $payment_method->getBarcodeUrl();
            $agreement = method_exists($payment_method, 'getAgreement') ? $payment_method->getAgreement() : null;
            $logos = $logos_map[$product_type] ?? [];
            $instructions = $this->i18n['instructions'][$product_type] ?? [];

            echo '<div class="conekta-box">';
            $this->render_ckpg_header($product_type);
            $this->render_ckpg_logos($logos, $product_type);
            $render_function = $renderers[$product_type] ?? $renderers["cash_in"];

            if (isset($render_function) && is_callable($render_function)) {
                call_user_func($render_function, [
                    'product_type' => $product_type,
                    'reference' => $reference,
                    'barcode_url' => $barcode_url,
                    'agreement' => $agreement,
                    'logos' => $logos,
                    'instructions' => $instructions,
                ]);
            }

            echo '<button class="conekta-copy-button" onclick="navigator.clipboard.writeText(\'' . esc_js($reference) . '\')">' . $this->i18n['button_copy'] . '</button>';
            $this->render_ckpg_instructions($instructions, $product_type, $reference, $agreement);
            echo '</div>';
        }
    }
    echo '</div>';
}

    function render_bbva($data) {
        echo '<p class="conekta-commission">' . $this->i18n['commission_note']['bbva_cash_in'] . '</p>';
        echo '<p class="conekta-agreement-text conekta-small-text">' . $this->i18n['agreement'] . ' ' . esc_html($data['agreement']) . '</p>';
        echo '<p class="conekta-agreement-text conekta-small-text">' . $this->i18n['reference'] . ' ' . esc_html($data['reference']) . '</p>';
    }

    function render_cash_in($data) {
        echo '<p class="conekta-conekta-link">' . $this->i18n['extra_note_cash_in'] . '</p>';
        echo '<p class="conekta-commission">' . $this->i18n['commission_note']['others'] . '</p>';
        $this->render_ckpg_barcode($data['barcode_url']);
        $this->render_ckpg_reference($data['reference']);
    }

    function render_oxxo($data) {
        $this->render_ckpg_barcode($data['barcode_url']);
        $this->render_ckpg_reference($data['reference']);
    }

    public function ckpg_init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'type' => 'checkbox',
                'title' => __('Habilitar/Deshabilitar', 'woothemes'),
                'label' => __('Habilitar Conekta', 'woothemes'),
                'default' => 'yes'
            ),
            'title' => array(
                'type' => 'text',
                'title' => __('Título', 'woothemes'),
                'description' => __('', 'woothemes'),
                'default' => __('Efectivo', 'woothemes'),
                'required' => true
            ),
            'description' => array(
                'type' => 'text',
                'title' => __('Descripción', 'woothemes'),
                'description' => __('', 'woothemes'),
                'default' => __('Paga con efectivo en más de 10 mil puntos de venta', 'woothemes'),
                'required' => true
            ),
            'api_key' => array(
                'type' => 'password',
                'title' => __('Conekta API key', 'woothemes'),
                'description' => __('API Key Producción (Tokens/Llaves Privadas)', 'woothemes'),
                'required' => true
            ),
            'order_expiration' => array(
                'type' => 'number',
                'title' => __('Vencimiento de las órdenes de pago (Días)', 'woothemes'),
                'description' => __('La cantidad de dīas configuradas en esta opción, corresponde al tiempo en el que la orden estará activa para ser pagada por el cliente desde el momento de su creación.', 'woothemes'),
                'default' => __(1),
                'custom_attributes' => array(
                    'min' => 1,
                    'max' => 30,
                    'step' => 1
                ),
            ),
            'webhook_url' => array(
                'type' => 'text',
                'title' => __('URL webhook', 'woothemes'),
                'description' => __('URL webhook)', 'woothemes'),
                'default' => __(get_site_url() . '/?wc-api=wc_conekta_cash'),
                'required' => true
            ),
            'alternate_imageurl' => array(
                'type'        => 'text',
                'title'       => __('Imagen alternativa para mostrar en el momento del pago, utilice una URL completa y envíela a través de https', 'woothemes'),
                'default'     => __('', 'woothemes')
            ),
            'instructions' => array(
                'title' => __( 'Instructions', 'woocommerce' ),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce'),
                'default' =>__('Por favor realiza el pago en la tienda más cercano utilizando la referencia que se encuentra a continuación.', 'woocommerce'),
                'desc_tip' => true,
            )
        );

    }

   /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function ckpg_email_instructions( $order, $sent_to_admin = false, $plain_text = false ) {
        if (get_post_meta( $order->get_id(), '_payment_method', true ) === $this->id){
            $instructions = $this->settings['instructions'];
            if ( $instructions && 'on-hold' === $order->get_status() ) {
                echo wpautop( wptexturize( esc_html($instructions ) ) ). PHP_EOL;
            }
        }
    }
     /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     */

     function ckpg_email_reference($order) {
        if (get_post_meta( $order->get_id(), 'conekta-referencia', true ) != null)
            {
                echo '<p style="font-size: 30px"><strong>'.__('Referencia').':</strong> ' . esc_html(get_post_meta( $order->get_id(), 'conekta-referencia', true )). '</p>';
                echo '<p>Se cobrará una comisión adicional al momento de realizar el pago.</p>';
                echo '<p>INSTRUCCIONES:'. esc_html($this->settings['instructions']) .'</p>';
            }
    }
    public function ckpg_admin_options()
    {
        include_once('templates/cash_admin.php');
    }

    public function payment_fields()
    {
        include_once('templates/cash.php');
    }

    protected function ckpg_mark_as_failed_payment($order)
    {
        $order->add_order_note(
            sprintf(
                "%s conekta Payment Failed",
                $this->GATEWAY_NAME,
            )
        );
    }

    /**
     * @throws Exception
     */
    public function process_payment($order_id)
    {
        global $woocommerce;
        $order = new WC_Order($order_id);
        $data = ckpg_get_request_data($order);
        $items = $order->get_items();
        $taxes = $order->get_taxes();
        $fees = $order->get_fees();
        $fees_formatted = ckpg_build_get_fees($fees);
        $discounts_data = $fees_formatted['discounts'];
        $fees_data = $fees_formatted['fees'];
        $tax_lines = ckpg_build_tax_lines($taxes);
        $tax_lines = array_merge($tax_lines, $fees_data);
        $discount_lines = ckpg_build_discount_lines($data);
        $discount_lines = array_merge($discount_lines, $discounts_data);
        $line_items = ckpg_build_line_items($items, parent::ckpg_get_version());
        $shipping_lines = ckpg_build_shipping_lines($data);
        $shipping_contact = ckpg_build_shipping_contact($data);
        $customer_info = ckpg_build_customer_info($data);
        $order_metadata = ckpg_build_order_metadata($data + array(
                'plugin_conekta_version' => $this->version,
                'woocommerce_version' => $woocommerce->version,
                'payment_method' => $this->GATEWAY_NAME,
            )
        );
        $rq = new OrderRequest([
            'currency' => $data['currency'],
            'charges' => [
                [
                    'payment_method' => [
                        'type' => 'cash',
                        'expires_at' => get_expired_at($this->settings['order_expiration']),
                    ],
                    'reference_id' => strval($order->get_id()),
                ]
            ],
            'shipping_lines' => $shipping_lines,
            'discount_lines' => $discount_lines,
            'tax_lines' => $tax_lines,
            'customer_info' => $customer_info,
            'line_items' => $line_items,
            'metadata' => $order_metadata
        ]);
        if (!empty($shipping_contact)) {
            $rq->setShippingContact(new CustomerShippingContacts($shipping_contact));
        }
        try {
            $orderCreated = $this->get_api_instance($this->settings['api_key'], $this->version)->createOrder($rq);
            $order->update_status('on-hold', __('Awaiting the conekta cash payment', 'woocommerce'));
            self::update_conekta_order_meta( $order, $orderCreated->getId(), 'conekta-order-id');
            self::update_conekta_order_meta( $order, $orderCreated->getCharges()->getData()[0]->getPaymentMethod()->getReference(), 'conekta-referencia');

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } catch (Exception $e) {
            $description = $e->getMessage();
            wc_add_notice(__('Error: ', 'woothemes') . $description);
            $this->ckpg_mark_as_failed_payment($order);
            WC()->session->reload_checkout = true;
        }

    }

    /**
     * Checks if woocommerce has enabled available currencies for plugin
     *
     * @access public
     * @return bool
     */
    public function ckpg_validate_currency(): bool
    {
        return in_array(get_woocommerce_currency(), $this->currencies);
    }
}

function ckpg_conekta_cash_add_gateway($methods)
{
    $methods[] = 'WC_Conekta_Cash_Gateway';
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'ckpg_conekta_cash_add_gateway');

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'ckpg-checkout-style',
        plugin_dir_url(__FILE__) . 'assets/styles.css',
        [],
        '1.0'
    );
});

add_action('woocommerce_blocks_loaded', 'woocommerce_gateway_conekta_cash_woocommerce_block_support');
function woocommerce_gateway_conekta_cash_woocommerce_block_support()
{
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once 'includes/blocks/class-wc-conekta-cash-payments-blocks.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Gateway_Conekta_Cash_Blocks_Support());
            }
        );
    }
}