<?php namespace Payfast\Payfast\Controller\Notify;
/**
 * Copyright (c) 2008 PayFast (Pty) Ltd
 * You (being anyone who is not PayFast (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
 */

use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Payfast\Payfast\Model\Config AS PayFastConfig;
use Magento\Framework\App\CsrfAwareActionInterface;


class Index extends \Payfast\Payfast\Controller\AbstractPayfast implements CsrfAwareActionInterface
{
    private $storeId;


    /**
     * indexAction
     *
     * Instantiate ITN model and pass ITN request to it
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );

        // Variable Initialization
        $pfError = false;
        $pfErrMsg = '';
        $pfData = array();
        $serverMode = $this->getConfigData('server');
        $pfParamString = '';

        $pfHost = $this->paymentMethod->getPayfastHost( $serverMode );

        pflog( ' PayFast ITN call received' );

        pflog( 'Server = '. $pfHost );

        //// Notify PayFast that information has been received
        if( !$pfError )
        {
            header( 'HTTP/1.0 200 OK' );
            flush();
        }

        //// Get data sent by PayFast
        if( !$pfError )
        {
            // Posted variables from ITN
            $pfData = pfGetData();

            if ( empty( $pfData ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        //// Verify security signature
        if( !$pfError )
        {
            pflog( 'Verify security signature' );

            // If signature different, log for debugging
            if ( !pfValidSignature( $pfData, $pfParamString, $this->getConfigData( 'passphrase' ), $this->getConfigData( 'server' ) ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
            }
        }

        //// Verify source IP (If not in debug mode)
        if( !$pfError && !defined( 'PF_DEBUG' ) )
        {
            pflog( 'Verify source IP' );

            if( !pfValidIP( $_SERVER['REMOTE_ADDR'] , $serverMode ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
            }
        }

        //// Get internal order and verify it hasn't already been processed
        if( !$pfError )
        {
            pflog( "Check order hasn't been processed" );

            // Load order
    		$orderId = $pfData['m_payment_id'];

            $this->_order = $this->orderFactory->create()->loadByIncrementId($orderId);

            pflog( 'order status is : '. $this->_order->getStatus());

            // Check order is in "pending payment" state
            if( $this->_order->getState() !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_ORDER_PROCESSED;
            }
        }

        //// Verify data received
        if( ! $pfError )
        {
            pflog( 'Verify data received' );

            if( ! pfValidData( $pfHost, $pfParamString ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        //// Check status and update order
        if( !$pfError )
        {
            pflog( 'Check status and update order' );

            // Successful
            if( $pfData['payment_status'] == "COMPLETE" )
            {
                $this->setPaymentAdditionalInformation($pfData);
                // Save invoice
                $this->saveInvoice();

            }
        }

        // If an error occurred
        if( $pfError )
        {
            pflog( 'Error occurred: '. $pfErrMsg );
            $this->_logger->critical($pre. "Error occured : ". $pfErrMsg );
        }
    }

    /**
	 * saveInvoice
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     */
	protected function saveInvoice()
    {
        pflog(__METHOD__.' : bof');

        try {

            $invoice = $this->_order->prepareInvoice();
            $invoice->setBaseGrandTotal($this->_order->getBaseGrandTotal());
            $invoice->register()->capture();

            /** @var \Magento\Framework\DB\Transaction $transaction */
            $transaction = $this->transactionFactory->create();
            $transaction
                ->addObject($invoice->getOrder());
            $transaction->save();

            $this->orderResourceModel->save($this->_order);


            if ($this->_config->getValue(PayFastConfig::KEY_SEND_CONFIRMATION_EMAIL)) {
                pflog( 'before sending order email, canSendNewEmailFlag is ' . boolval($this->_order->getCanSendNewEmailFlag()));
                $this->orderSender->send($this->_order);

                pflog('after sending order email');
            }

            if ($this->_config->getValue(PayFastConfig::KEY_SEND_INVOICE_EMAIL)) {

                pflog( 'before sending invoice email is ' . boolval($this->_order->getCanSendNewEmailFlag()));
                foreach ($this->_order->getInvoiceCollection() as $invoice) {
                    pflog('sending invoice #'. $invoice->getId() );
                    if ($invoice->getId()) {
                        $this->invoiceSender->send($invoice);
                    }
                }

                pflog( 'after sending ' . boolval($invoice->getIncrementId()));
            }

        } catch (LocalizedException $e) {
            pflog(__METHOD__ . ' localizedException caught and will be re thrown. ');
            pflog(__METHOD__ . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            pflog(__METHOD__ . 'Exception caught and will be re thrown.');
            pflog(__METHOD__ . $e->getMessage());
            throw $e;
        }

        pflog(__METHOD__.' : eof');
    }

    /**
     * @param $pfData
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    private function setPaymentAdditionalInformation($pfData)
    {
        pflog(__METHOD__.' : bof');
        pflog('Order complete');

        try {
            // Update order additional payment information
            /** @var  \Magento\Sales\Model\Order\Payment $payment */
            $payment = $this->_order->getPayment();
            $payment->setAdditionalInformation("payment_status", $pfData['payment_status']);
            $payment->setAdditionalInformation("m_payment_id", $pfData['m_payment_id']);
            $payment->setAdditionalInformation("pf_payment_id", $pfData['pf_payment_id']);
            $payment->setAdditionalInformation("email_address", $pfData['email_address']);
            $payment->setAdditionalInformation("amount_fee", $pfData['amount_fee']);
            $payment->registerCaptureNotification($pfData['amount_gross'], true);

            $this->_order->setPayment($payment);

        } catch (LocalizedException $e) {
            pflog(__METHOD__ . ' localizedException caught and will be re thrown. ');
            pflog(__METHOD__ . $e->getMessage());
            throw $e;
        }

        pflog(__METHOD__.' : eof');
    }

    /**
     * Create exception in case CSRF validation failed.
     * Return null if default exception will suffice.
     *
     * @param RequestInterface $request
     *
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException( RequestInterface $request ): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Perform custom request validation.
     * Return null if default validation is needed.
     *
     * @param RequestInterface $request
     *
     * @return bool|null
     */
    public function validateForCsrf( RequestInterface $request ): ?bool
    {
        return true;

    }
}
