<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * Released under the GNU General Public License.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet 
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenpflichtig/lizenz
 */

namespace Novalnet\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Novalnet\Services\PaymentService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;

/**
 * Class PaymentController
 *
 * @package Novalnet\Controllers
 */
class PaymentController extends Controller
{ 
	/**
	 * @var Request
	 */
	private $request;

	/**
	 * @var Response
	 */
	private $response;

	/**
	 * @var PaymentHelper
	 */
	private $paymentHelper;

	/**
	 * @var SessionStorageService
	 */
	private $sessionStorage;

	/**
	 * @var basket
	 */
	private $basketRepository;

	/**
     * @var AddressRepositoryContract
     */
    private $addressRepository;
	
	/**
	 * @var PaymentService
	 */
	private $paymentService;

	/**
	 * @var Twig
	 */
	private $twig;
	
	/**
	 * @var ConfigRepository
	 */
	private $config;

	/**
	 * PaymentController constructor.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param ConfigRepository $config
	 * @param PaymentHelper $paymentHelper
	 * @param AddressRepositoryContract $addressRepository
	 * @param FrontendSessionStorageFactoryContract $sessionStorage
	 * @param BasketRepositoryContract $basketRepository
	 * @param PaymentService $paymentService
	 * @param Twig $twig
	 */
	public function __construct(  Request $request,
								  Response $response,
								  ConfigRepository $config,
								  PaymentHelper $paymentHelper,
				   				  AddressRepositoryContract $addressRepository,
								  FrontendSessionStorageFactoryContract $sessionStorage,
								  BasketRepositoryContract $basketRepository,			  
				    			  PaymentService $paymentService,
								  Twig $twig
								)
	{

		$this->request         = $request;
		$this->response        = $response;
		$this->paymentHelper   = $paymentHelper;
		$this->sessionStorage  = $sessionStorage;
		$this->addressRepository = $addressRepository;
		$this->basketRepository  = $basketRepository;
		$this->paymentService  = $paymentService;
		$this->twig            = $twig;
		$this->config          = $config;
	}

	/**
	 * Novalnet redirects to this page if the payment was executed successfully
	 *
	 */
	public function paymentResponse() {
		$responseData = $this->request->all();
		$isPaymentSuccess = isset($responseData['status']) && in_array($responseData['status'], ['90','100']);
		$notificationMessage = $this->paymentHelper->getNovalnetStatusText($responseData);
		if ($isPaymentSuccess) {
			$this->paymentService->pushNotification($notificationMessage, 'success', 100);
		} else {
			$this->paymentService->pushNotification($notificationMessage, 'error', 100);	
		}
		
		$responseData['test_mode'] = $this->paymentHelper->decodeData($responseData['test_mode'], $responseData['uniqid']);
		$responseData['amount']    = $this->paymentHelper->decodeData($responseData['amount'], $responseData['uniqid']) / 100;
		$paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
		$this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($paymentRequestData, $responseData));
		$this->paymentService->validateResponse();
		return $this->response->redirectTo('confirmation');
	}
        
	/**
	 * Process the Form payment
	 *
	 */
	public function processPayment()
	{
		$requestData = $this->request->all();
		$serverRequestData['data']['save_payment'] = $requestData['save_payment'];
		if (!empty ($serverRequestData['data']['save_payment'])) {
		   $save_payment_data = 'true';
		}
		if (empty ($requestData['nn_new_details'])) {
		  $payment_reference = 	'true';
		}
		$notificationMessage = $this->paymentHelper->getNovalnetStatusText($requestData);
		$basket = $this->basketRepository->load();	
		$billingAddressId = $basket->customerInvoiceAddressId;
		$address = $this->addressRepository->findAddressById($billingAddressId);
		
		$serverRequestData = $this->paymentService->getRequestParameters($this->basketRepository->load(), $requestData['paymentKey'], $save_payment_data, $payment_reference);
		if (!empty($requestData['ref_tid']) && $requestData['nn_new_details'] == '0') {
		   $serverRequestData['data']['payment_ref'] = $requestData['ref_tid'];
		}
		
		$this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
		$guarantee_payments = [ 'NOVALNET_SEPA', 'NOVALNET_INVOICE' ];        
		if($requestData['paymentKey'] == 'NOVALNET_CC' || ($requestData['paymentKey'] == 'NOVALNET_PAYPAL' && !empty($requestData['nn_new_details'])) ) {
			
			if ($requestData['paymentKey'] == 'NOVALNET_CC' && !empty ($requestData['nn_new_details'])) {
			$serverRequestData['data']['pan_hash'] = $requestData['nn_pan_hash'];
			$serverRequestData['data']['unique_id'] = $requestData['nn_unique_id'];
			}
			
			if($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' || ($requestData['paymentKey'] == 'NOVALNET_PAYPAL' && !empty($requestData['nn_new_details'])))
			{
				$this->sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
				$this->sessionStorage->getPlugin()->setValue('nnPaymentUrl',$serverRequestData['url']);
				$this->paymentService->pushNotification($notificationMessage, 'success', 100);
				return $this->response->redirectTo('place-order');
			}
		}
		
		// Handles Guarantee and Normal Payment
		else if( in_array( $requestData['paymentKey'], $guarantee_payments ) ) 
		{	
			// Mandatory Params For Novalnet SEPA
			if ( $requestData['paymentKey'] == 'NOVALNET_SEPA' && empty ($serverRequestData['data']['payment_ref'])) {
					$serverRequestData['data']['bank_account_holder'] = $requestData['nn_sepa_cardholder'];
					$serverRequestData['data']['iban'] = $requestData['nn_sepa_iban'];					
			}            
			
			$guranteeStatus = $this->paymentService->getGuaranteeStatus($this->basketRepository->load(), $requestData['paymentKey']);                        
			
			if('guarantee' == $guranteeStatus)
			{		   
				$birthday     = ( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) ? $requestData['nn_sepa_birthday'] : $requestData['nn_invoice_birthday'];
				$force_status = ( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) ? 'Novalnet.novalnet_sepa_payment_guarantee_force_active' : 'Novalnet.novalnet_invoice_payment_guarantee_force_active';				
				
				// Proceed as Normal Payment if condition for birthdate doesn't meet as well as force is enable    
				if ($this->config->get( $force_status ) == 'true' && empty($address->companyName) && (empty($birthday) || time() < strtotime('+18 years', strtotime($birthday)))) {	
					if( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
					$serverRequestData['data']['payment_type'] = 'DIRECT_DEBIT_SEPA';
					$serverRequestData['data']['key']          = '37';
					} else {
					$serverRequestData['data']['payment_type'] = 'INVOICE_START';
					$serverRequestData['data']['key']          = '27';
					}
					
				}
				else if( empty( $birthday ) && empty($address->companyName))
				{	
					$notificationMessage = $this->paymentHelper->getTranslatedText('doberror');
					$this->paymentService->pushNotification($notificationMessage, 'error', 100);
					return $this->response->redirectTo('checkout');
				}
				else if( time() < strtotime('+18 years', strtotime($birthday)) && empty($address->companyName))
				{
					$notificationMessage = $this->paymentHelper->getTranslatedText('dobinvalid');
					$this->paymentService->pushNotification($notificationMessage, 'error', 100);
					return $this->response->redirectTo('checkout');
				} 
				else
				{
					
					// Guarantee Params Formation 
					if (empty($address->companyName)) {
						$serverRequestData['data']['birth_date']   = $birthday;	
					}
					if( $requestData['paymentKey'] == 'NOVALNET_SEPA' ) {
					$serverRequestData['data']['payment_type'] = 'GUARANTEED_DIRECT_DEBIT_SEPA';
					$serverRequestData['data']['key']          = '40';
					} else {						
					$serverRequestData['data']['payment_type'] = 'GUARANTEED_INVOICE';
					$serverRequestData['data']['key']          = '41';							
					}
				}
			}
		}

		$response = $this->paymentHelper->executeCurl($serverRequestData['data'], $serverRequestData['url']);
		$responseData = $this->paymentHelper->convertStringToArray($response['response'], '&');
		$notificationMessage = $this->paymentHelper->getNovalnetStatusText($responseData);
		$responseData['payment_id'] = (!empty($responseData['payment_id'])) ? $responseData['payment_id'] : $responseData['key'];
		$isPaymentSuccess = isset($responseData['status']) && $responseData['status'] == '100';
		
		if($isPaymentSuccess)
		{			
			if(isset($serverRequestData['data']['pan_hash']))
			{
				unset($serverRequestData['data']['pan_hash']);
			}

			$this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($serverRequestData['data'], $responseData));
			
			$this->paymentService->pushNotification($notificationMessage, 'success', 100);
			// Redirect to the success page.
			return $this->response->redirectTo('place-order');
		} else {
			$this->paymentService->pushNotification($notificationMessage, 'error', 100);
			// Redirects to the checkout page.
			return $this->response->redirectTo('checkout');
		}
	}

	/**
	 * Process the redirect payment
	 *
	 */
	public function redirectPayment()
	{
		$paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
		$paymentRequestData['order_no'] = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
		$paymentUrl = $this->sessionStorage->getPlugin()->getValue('nnPaymentUrl');

		return $this->twig->render('Novalnet::NovalnetPaymentRedirectForm', [
								   'formData'     => $paymentRequestData,
								   'nnPaymentUrl' => $paymentUrl
								   ]);
	}
}
