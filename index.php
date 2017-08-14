<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
Plugin Name: WooCommerce GoPay Payment Gateway
Plugin URI: 
Description: GoPay Payment gateway for woocommerce
Version: 1.0
Author: Pavel Witassek
Author URI: 
*/
require_once(dirname(__FILE__) . "/api/country_code.php");
require_once(dirname(__FILE__) . "/api/gopay_helper.php");
require_once(dirname(__FILE__) .'/api/gopay_soap.php');
define('LANG', substr(get_bloginfo('language'),0,2));

/*
 * URL skriptu vytvarejiciho platbu na GoPay
 */
require_once(dirname(__FILE__) . "/api/gopay_config.php");
$option = get_option('woocommerce_gopay_settings');
if($option['test']=='yes'){
	GopayConfig::init(GopayConfig::TEST);
}else{
	GopayConfig::init(GopayConfig::PROD);
}
add_action('plugins_loaded', 'woocommerce_gateway_gopay_init', 0);
function woocommerce_gateway_gopay_init(){
  if(!class_exists('WC_Payment_Gateway')) return;
 
  class WC_Gateway_GoPay extends WC_Payment_Gateway{ 
   
    public function __construct(){
      $this -> state = GopayHelper::CREATED;
      $this -> id = 'gopay';
      $this -> medthod_title = 'GoPay';
      $this -> has_fields = false;
 
      $this -> init_form_fields();
      $this -> init_settings();
 
      $this -> title = $this -> settings['title'];
      $this -> description = $this -> settings['description'];
      $this -> goid = $this -> settings['goid'];
      $this -> secure_key = $this -> settings['secure_key'];
      $this -> test = $this -> settings['secure_key'];
      define('CALLBACK_URL', get_site_url().'?wc-api=WC_Gateway_GoPay&gopay=callback');

      add_action('woocommerce_api_wc_gateway_gopay', array($this, 'check_gopay_response'));
     	if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options' ) );
             } else {
                add_action( 'woocommerce_update_options_payment_gateways', array($this, 'process_admin_options' ) );
            }
      add_action('woocommerce_receipt_gopay', array($this, 'receipt_page'));
      add_action('woocommerce_thankyou_gopay', array($this, 'thankyou_page'));
	}
	
    function init_form_fields(){
 
       $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Povolit/Zakázat', 'gateway'),
                    'type' => 'checkbox',
                    'label' => __('Povolit GoPay platební modul.', 'gateway'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Titulek:', 'gateway'),
                    'type'=> 'text',
                    'description' => __('Název platební metody, který uvidí uživatel během výběru platební metody.', 'gateway'),
                    'default' => __('GoPay', 'gateway')),
                'description' => array(
                    'title' => __('Popis:', 'gateway'),
                    'type' => 'textarea',
                    'description' => __('Tento popis uživatel uvidí při výběru platební metody.', 'gateway'),
                    'default' => __('Plaťte přes GoPay nebo můžete platit kreditní kartou, pokud nemáte GoPay účet.', 'gateway')),
                'goid' => array(
                    'title' => __('GoID', 'gateway'),
                    'type' => 'text',
                    'description' => __('GoID e-shopu, které vám bylo přiděleno','gateway')),
                'secure_key' => array(
                    'title' => __('Secure key', 'gateway'),
                    'type' => 'text',
                    'description' =>  __('Secure key e-shopu, které vám bylo přiděleno.', 'gateway')),
                'test' => array(
                    'title' => __('Test mód', 'gateway'),
                    'type' => 'checkbox',
                    'description' =>  __('Aktivace/deaktivace testovacího prostředí GoPay.', 'gateway'),
                    'default' => 'yes'),
                'notify' => array(
                    'title' => __('Notifikace', 'gateway'),
                    'type' => 'text',
                    'default' => get_site_url().'?wc-api=WC_Gateway_GoPay&gopay=notify',
                    'description' =>  __('URL pro zpracovávání notifikací. NEMĚNIT! ('.get_site_url().'/?wc-api=WC_Gateway_GoPay&gopay=notify)', 'gateway'))
            );
    }

    public function admin_options(){
        echo '<h3>'.__('GoPay platební brána', 'gateway').'</h3>';
        echo '<p>'.__('GoPay je nejpopulárnější platební brána pro online platby v České republice.').'</p>';
        echo '<table class="form-table">';
        // Generate the HTML For the settings form.
        $this -> generate_settings_html();
        echo '</table>';
 
    }
	
	function setState($state) {
		$this->state = $state;
	}
	
	function processPayment() {
		self::setState(GopayHelper::PAID);
		
	}

	/*
	 * Funkce simulujici zruseni objednavky - zmena stavu na "zrusena", zaslani emailu zakaznikovi, atd.
	 */
	function cancelPayment() {
		self::setState(GopayHelper::CANCELED);
		
	}

	/*
	 * Funkce simulujici vyprseni doby platnosti objednavky - zmena stavu na "vyprsela", zaslani emailu zakaznikovi, atd.
	 */
	function timeoutPayment() {
		self::setState(GopayHelper::TIMEOUTED);
		
	}

	/*
	 * Funkce simulujici autorizaci objednavky - zmena stavu na "autorizovana", zaslani emailu zakaznikovi, atd.
	 */
	function autorizePayment() {
		self::setState(GopayHelper::AUTHORIZED);
		
	}

	/*
	 * Funkce simulujici vraceni platby - zmena stavu na "vracena", zaslani emailu zakaznikovi, atd.
	 */
	function refundPayment() {
		self::setState(GopayHelper::REFUNDED);
		
	}
	

    /**
     * Receipt Page
     **/
    function receipt_page($order){
        $this->generate_gopay_payment($order,'');
    }
    
 	public function generate_gopay_payment($order_id,$defaultPaymentChannel){ 
        global $woocommerce;
   
		$order = new WC_Order($order_id);
		$paymentChannels = array();
		$p1 = null;
		$p2 = null;
		$p3 = null;
		$p4 = null;
		
		try {

			$paymentSessionId = GopaySoap::createPayment((float)$this->goid,
												$order->billing_first_name.' '.$order->billing_last_name,
										 		(float)$order->get_total()*100,
												get_woocommerce_currency(),
												$order->id,
												CALLBACK_URL,
												CALLBACK_URL,
												$paymentChannels,
												$defaultPaymentChannel,
												$this->secure_key,
												$order->billing_first_name,
												$order->billing_last_name,
												$order->billing_city,
												$order->billing_address_1,
												$order->billing_zip,
												'CZE',
												$order->billing_email,
												$order->billing_phone,
												$p1,
												$p2,
												$p3,
												$p4,
												LANG);
		
		} catch (Exception $e) {
			/*
			 *  Osetreni chyby v pripade chybneho zalozeni platby
			 */
			$order->update_status('Failed','Chyba při zakládání platby.');
			header("Location: " . $order->get_checkout_order_received_url() . "&sessionState=" . GopayHelper::FAILED);
			exit;
		}

		/*
		 * Platba na strane GoPay uspesne vytvorena
		 * Ulozeni paymentSessionId k objednavce. Slouzi pro komunikaci s GoPay
		 */
		$this->paymentSessionId = $paymentSessionId;
		update_post_meta( $order->id, '_paymentSessionId', esc_attr($paymentSessionId));
		$encryptedSignature = GopayHelper::encrypt(
				GopayHelper::hash(
					GopayHelper::concatPaymentSession((float)$this->goid,
													(float)$paymentSessionId, 
													$this->secure_key)
					), $this->secure_key);			
	
		/*
		 * Presmerovani na platebni branu GoPay s predvybranou platebni metodou($defaultPaymentChannel)
		 */
		$order->update_status('Pending','Zákazník vytvořil platbu na GoPay. ID platby: '.$paymentSessionId);
		header("Location: " . GopayConfig::fullIntegrationURL() . "?sessionInfo.targetGoId=" . $this->goid . "&sessionInfo.paymentSessionId=" . $paymentSessionId . "&sessionInfo.encryptedSignature=" . $encryptedSignature);
		exit;

		
    }
    
    
  	/**
     * Process the payment and return the result
     **/
    function process_payment($order_id){
		$order = new WC_Order($order_id);
        return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ));
    }
 
    /**
     * Check for valid gopay server callback
     **/
    function check_gopay_response(){
  		global $woocommerce;
  		require_once(dirname(__FILE__) .'/callback.php');
    }
 
 	function thankyou_page() {
  		global $woocommerce;
		if (! isset($_GET["sessionSubState"])){$_GET["sessionSubState"] = null;}
		if($_GET["sessionState"]==GopayHelper::PAYMENT_METHOD_CHOSEN){
			echo'<ul class="woocommerce-info"><li>'.GopayHelper::getResultMessage($_GET["sessionState"], $_GET["sessionSubState"]).'</li></ul>';
		}elseif($_GET["sessionState"]==GopayHelper::REFUNDED OR $_GET["sessionState"]==GopayHelper::AUTHORIZED OR $_GET["sessionState"]==GopayHelper::PAID){
			echo'<ul class="woocommerce-message"><li>'.GopayHelper::getResultMessage($_GET["sessionState"], $_GET["sessionSubState"]).'</li></ul>';
		}else{
			echo'<ul class="woocommerce-error"><li>'.GopayHelper::getResultMessage($_GET["sessionState"], $_GET["sessionSubState"]).'</li></ul>';
 		}
 	}
      
    }
   /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_gateway_gopay_gateway($methods) {
        $methods[] = 'WC_Gateway_GoPay';
        return $methods;
    }
 
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_gopay_gateway' );
    
    add_filter( 'woocommerce_checkout_fields' , 'custom_override_checkout_fields' );
	function custom_override_checkout_fields( $fields ) {
	     $fields['billing']['paymentSessionId'] = array(
	     	'type'		=> 'hidden',
    	    'label'     => __('paymentSessionId', 'woocommerce'),
		    'placeholder'   => _x('paymentSessionId', 'placeholder', 'woocommerce'),
		    'required'  => false,
		    'clear'     => true
		 );
	     return $fields;
	}

}
?>