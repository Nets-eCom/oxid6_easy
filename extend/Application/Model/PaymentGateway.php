<?php

namespace Es\NetsEasy\extend\Application\Model;

use Es\NetsEasy\Application\Helper\Api;
use Es\NetsEasy\Application\Helper\DebugLog;
use Es\NetsEasy\Application\Helper\Language;
use Es\NetsEasy\Application\Model\Api\Payment\PaymentRetrieve;
use Es\NetsEasy\Application\Model\Api\Payment\ReferenceInformationUpdate;
use Es\NetsEasy\Application\Model\ResourceModel\NetsTransactions;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\Order;
use Es\NetsEasy\Application\Helper\Payment;

/**
 * Class defines execution of nets payment.
 */
class PaymentGateway extends PaymentGateway_parent
{
    /**
     * Function to execute Nets payment.
     *
     * @param  int $dAmount
     * @param  object $oOrder
     * @return bool
     */
    public function executePayment($dAmount, &$oOrder)
    {
        DebugLog::getInstance()->log("check if this payment method is valid in nets : " .$oOrder->oxorder__oxpaymenttype->value );
        
        if (!Payment::getInstance()->isNetsPayment($oOrder->oxorder__oxpaymenttype->value)) {
            return parent::executePayment($dAmount, $oOrder);
        }
        DebugLog::getInstance()->log("payment method check pass : ".$oOrder->oxorder__oxpaymenttype->value);
        return $this->handleNetsPayment($dAmount, $oOrder);
    }

    /**
     * Handles nets payment mechanics
     *
     * @param  double $dAmount
     * @param  Order $oOrder
     * @return bool
     */
    protected function handleNetsPayment($dAmount, $oOrder)
    {
        $oOrder->netsSetOrderNumber();

        try {
            if (Api::getInstance()->isHostedMode()) {
                $this->netsHandleHostedMode($dAmount, $oOrder);
            } elseif (Api::getInstance()->isEmbeddedMode()) {
                $this->netsHandleEmbeddedMode($dAmount, $oOrder);
            }
        } catch(\Exception $exc) {
            $this->_iLastErrorNo = $exc->getCode();
            $this->_sLastError = $exc->getMessage();
            return false;
        }
        return true;
    }

    /**
     * Handles nets payment for hosted mode
     *
     * @param  double $dAmount
     * @param  Order $oOrder
     * @return void
     */
    protected function netsHandleHostedMode($dAmount, $oOrder)
    {
        $pay_method = Registry::getSession()->getVariable('paymentid');
        $aResponse = $oOrder->netsCreateNewNetsPayment($pay_method);

        Registry::getSession()->setVariable('netsCustomerIsRedirected', true);
        Registry::getUtils()->redirect($aResponse["hostedPaymentPageUrl"]."&language=".Language::getInstance()->netsGetLocaleCode());
    }


    /**
     * Handles nets payment for embedded mode
     *
     * @param  double $dAmount
     * @param  Order $oOrder
     * @return void 
     */
    protected function netsHandleEmbeddedMode($dAmount, $oOrder)
    {
        DebugLog::getInstance()->log("netsHandleEmbeddedMode called ".json_encode($oOrder->getId()));
        $sPaymentId = Registry::getSession()->getVariable("nets_embedded_payment_id");
        $oNetsTransaction = oxNew(NetsTransactions::class);
        $oNetsTransaction->updateTransactionWithOrderInfo($sPaymentId, $oOrder->getId(), $oOrder->oxorder__oxordernr->value);

        $oOrder->netsSetTransactionId($sPaymentId);

        $oPaymentInfo = oxNew(PaymentRetrieve::class);
        $aResponse = $oPaymentInfo->sendRequest($sPaymentId);

        DebugLog::getInstance()->log("payment api status NetsOrder, response checkout url ".$aResponse['payment']['checkout']['url']);

        if (isset($aResponse['payment']['charges'])) {
            foreach ($aResponse['payment']['charges'] as $ky => $val) {
                foreach ($val['orderItems'] as $key => $value) {
                    if (isset($val['chargeId'])) {
                        $oNetsTransaction = oxNew(NetsTransactions::class);
                        $oNetsTransaction->createCharge($sPaymentId, $val['chargeId'], $value['reference'], $value['quantity'], $value['quantity']);
                    }
                }
            }
        }

        $aRefUpdateData = [
            'reference' => $oOrder->oxorder__oxordernr->value,
            'checkoutUrl' => $aResponse['payment']['checkout']['url']
        ];
        $oReferenceInformationUpdate = oxNew(ReferenceInformationUpdate::class);
        $oReferenceInformationUpdate->sendRequest($sPaymentId, $aRefUpdateData);
    }
}
