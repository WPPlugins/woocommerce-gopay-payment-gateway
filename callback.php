<?php

if($_GET['gopay']=='callback'){
	/**
	 * Provede overeni zaplacenosti objednavky po zpetnem presmerovani z platebni brany
	 * 
	 */
		
	/*
	 * Parametry obsazene v redirectu po potvrzeni / zruseni platby, predavane od GoPay e-shopu
	 */
	$returnedPaymentSessionId = $_GET['paymentSessionId'];
	$returnedGoId = $_GET['targetGoId'];
	$returnedOrderNumber = $_GET['orderNumber'];
	$returnedEncryptedSignature = $_GET['encryptedSignature'];

	/*
	 * Nacist data objednavky dle prichoziho paymentSessionId, zde z testovacich duvodu vse primo v testovaci tride Order
	 * Upravte dle ulozeni vasich objednavek
	 */
	$order = new WC_Order($returnedOrderNumber);
	$paymentSessionId = get_post_meta($order->id, '_paymentSessionId', true);
	if($paymentSessionId != $returnedPaymentSessionId){
		header('Location: ' .$this->get_return_url( $order ) . "&sessionState=" . GopayHelper::FAILED);
		exit;		
	}
	/*
	 * Kontrola validity parametru v redirectu, opatreni proti podvrzeni potvrzeni / zruseni platby
	 */
	try {
		GopayHelper::checkPaymentIdentity(
					(float)$returnedGoId,
					(float)$returnedPaymentSessionId,
					null,
					$returnedOrderNumber,
					$returnedEncryptedSignature,
					(float)$this->goid,
					$order->id,
					$this->secure_key);

		/*
		 * Kontrola zaplacenosti objednavky na serveru GoPay
		 */
		$result = GopaySoap::isPaymentDone(
					(float)$returnedPaymentSessionId,
					(float)$this->goid,
					$order->id,
					(int)$order->get_total()*100,
					get_woocommerce_currency(),
					$order->billing_first_name.' '.$order->billing_last_name,
					$this->secure_key);
	
		if ($result["sessionState"] == GopayHelper::PAID) {
			/*
			 * Zpracovat pouze objednavku, ktera jeste nebyla zaplacena 
			 */
			if ($order->status != 'processing' AND $order->status != 'completed') {
				/*
				 *  Zpracovani objednavky  ! UPRAVTE !
				 */
				$this->processPayment();
				$order->add_order_note('GoPay přijal úspěšně platbu. ID platby: '.$returnedPaymentSessionId,0);
				$order->payment_complete();
			}
		} else if ( $result["sessionState"] == GopayHelper::CANCELED) {
			/* Platba byla zrusena objednavajicim */
			$this->cancelPayment();
			$order->update_status('Failed','Platba byla zrušena. ID platby: '.$returnedPaymentSessionId);
	
		} else if ( $result["sessionState"] == GopayHelper::TIMEOUTED) {
			/* Platnost platby vyprsela  */
			$this->timeoutPayment();
			$order->update_status('Failed','Platnost platby vypršela. ID platby: '.$returnedPaymentSessionId);
	
		} else if ( $result["sessionState"] == GopayHelper::REFUNDED) {
			/* Platba byla vracena - refundovana */
			$order->update_status('Refunded','Platba byla refundována. ID platby: '.$returnedPaymentSessionId);
			$this->refundePayment();
		
		} else if ( $result["sessionState"] == GopayHelper::AUTHORIZED) {
			/* Platba byla autorizovana, ceka se na dokonceni  */
			$this->autorizePayment();
			$order->update_status('On-hold','Platba byla autorizována, čeká se na dokončení. ID platby: '.$returnedPaymentSessionId);
		
		} else {
			$order->update_status('Failed','Chyba ve stavu platby. ID platby: '.$returnedPaymentSessionId);
			header('Location: ' . $this->get_return_url( $order ) . "&sessionState=" . GopayHelper::FAILED);
			exit(0);
		}
		header('Location: ' . $this->get_return_url( $order ) . "&sessionState=" . $result["sessionState"] . "&sessionSubState=" . $result["sessionSubState"]);
		exit;

	} catch (Exception $e) {
		/*
		 * Nevalidni informace z redirectu
		 */
		$order->update_status('Failed',$returnedPaymentSessionId);
		header('Location: ' . $this->get_return_url( $order ) . "&sessionState=" . GopayHelper::FAILED);
		exit;
	}
	
}elseif($_GET['gopay']=='notify'){
	/*
	 * Parametry obsazene v notifikaci platby, predavane od GoPay e-shopu
	 */
	$returnedPaymentSessionId = $_GET['paymentSessionId'];
	$returnedParentPaymentSessionId = $_GET['parentPaymentSessionId'];
	$returnedGoId = $_GET['targetGoId'];
	$returnedOrderNumber = $_GET['orderNumber'];
	$returnedEncryptedSignature = $_GET['encryptedSignature'];

	/*
	 * Nacist data objednavky dle prichoziho paymentSessionId, 
	 * zde z testovacich duvodu vse primo v testovaci tride Order
	 * Upravte dle ulozeni vasich objednavek
	 */
	$order =  new WC_Order($returnedOrderNumber);
	if (empty($returnedParentPaymentSessionId)) {
		$paymentSessionId = get_post_meta($order->id, '_paymentSessionId', true);
		if($paymentSessionId != $returnedPaymentSessionId){
			header("HTTP/1.1 500 Internal Server Error");
			exit(0);
		}	
	} else {
		// notifikace o rekurentni platbe
		
		
		//$returnedParentPaymentSessionId if??
	
	}

	/*
	 * Kontrola validity parametru v http notifikaci, opatreni proti podvrzeni potvrzeni platby (notifikace)
	 */
	try {
	
		GopayHelper::checkPaymentIdentity(
					(float)$returnedGoId,
					(float)$returnedPaymentSessionId,
					(float)$returnedParentPaymentSessionId,
					$returnedOrderNumber,
					$returnedEncryptedSignature,
					(float)$this->goid,
					$order->id,
					$this->secure_key);

		/*
		 * Kontrola zaplacenosti objednavky na strane GoPay
		 */
		$result = GopaySoap::isPaymentDone(
										(float)$returnedPaymentSessionId,
										(float)$this->goid,
										$order->id,
										(int)$order->get_total()*100,
										get_woocommerce_currency(),
										$order->billing_first_name.' '.$order->billing_last_name,
										$this->secure_key);
	
		if ($result["sessionState"] == GopayHelper::PAID) {
			/*
			 * Zpracovat pouze objednavku, ktera jeste nebyla zaplacena 
			 */
			if (empty($returnedParentPaymentSessionId)) {
				// notifikace o bezne platbe
	
				if ($order->status != 'processing' AND $order->status != 'completed') {
	
					/*
					 *  Zpracovani objednavky  ! UPRAVTE !
					 */
					$this->processPayment();
					$order->update_status('Processing',$returnedPaymentSessionId);
				}
	
			} else {
				// notifikace o rekurentni platbe
	
				/*
				 * Je potreba kontrolovat, jestli jiz toto paymentSessionId neni zaplaceno, aby pri 
				 * opakovane notifikaci nedoslo k duplicitnimu zaznamu o zaplaceni 
				 * a nasledne zaznamenat $returnedPaymentSessionId pro kontroly u dalsich opakovanych plateb
				 */
				//if ($order->isPaidRecurrentPayment($returnedPaymentSessionId) != true) {
	
					/*
					 *  pridani paymentSessionId do seznamu uhrazenych opakovanych plateb
					 */
					//$order->addPaidRecurrentPayment($returnedPaymentSessionId);
				//}	

			}
		
		} else if ( $result["sessionState"] == GopayHelper::CANCELED) {
			/* Platba byla zrusena objednavajicim */
			$this->cancelPayment();
			$order->update_status('Failed',$returnedPaymentSessionId);
	
		} else if ( $result["sessionState"] == GopayHelper::TIMEOUTED) {
			/* Platnost platby vyprsela  */
			$this->timeoutPayment();
			$order->update_status('Failed',$returnedPaymentSessionId);
	
		} else if ( $result["sessionState"] == GopayHelper::REFUNDED) {
			/* Platba byla vracena - refundovana */
			$order->update_status('Refunded',$returnedPaymentSessionId);
			$this->refundePayment();
		
		} else if ( $result["sessionState"] == GopayHelper::AUTHORIZED) {
			/* Platba byla autorizovana, ceka se na dokonceni  */
			$this->autorizePayment();
		
		} else {
			$order->update_status('Failed',$returnedPaymentSessionId);
			header("HTTP/1.1 500 Internal Server Error");
			exit(0);
		
		}
	
	} catch (Exception $e) {
		/*
		 * Nevalidni informace z http notifikaci - prevdepodobne pokus o podvodne zaslani notifikace
		 */
		$order->update_status('Failed',$returnedPaymentSessionId);
		header("HTTP/1.1 500 Internal Server Error");
		exit(0);
	}
}else{
	header("HTTP/1.1 500 Internal Server Error");
	exit(0);
}
?>