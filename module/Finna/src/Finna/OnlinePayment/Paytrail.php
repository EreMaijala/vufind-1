<?php
/**
 * Paytrail payment handler
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2014-2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  OnlinePayment
 * @author   Leszek Manicki <leszek.z.manicki@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 * @link     http://docs.paytrail.com/ Paytrail API documentation
 */
namespace Finna\OnlinePayment;

require_once 'Paytrail_Module_Rest.php';

/**
 * Paytrail payment handler module.
 *
 * @category VuFind
 * @package  OnlinePayment
 * @author   Leszek Manicki <leszek.z.manicki@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 * @link     http://docs.paytrail.com/ Paytrail API documentation
 */
class Paytrail extends BaseHandler
{
    const PAYMENT_SUCCESS = 'success';
    const PAYMENT_FAILURE = 'failure';
    const PAYMENT_NOTIFY = 'notify';

    /**
     * Return payment response parameters.
     *
     * @param Zend\Http\Request $request Request
     *
     * @return array
     */
    public function getPaymentResponseParams($request)
    {
        $params = array_merge(
            $request->getQuery()->toArray(), $request->getPost()->toArray()
        );

        $required = [
            'ORDER_NUMBER', 'TIMESTAMP', 'RETURN_AUTHCODE'
        ];

        foreach ($required as $name) {
            if (!isset($params[$name])) {
                $this->logger->err(
                    "Paytrail: missing parameter $name in payment response: "
                    . var_export($params, true)
                );
                return false;
            }
        }

        $params['transaction'] = $params['ORDER_NUMBER'];

        return $params;
    }

    /**
     * Start transaction.
     *
     * @param string             $finesUrl       Return URL to MyResearch/Fines
     * @param string             $ajaxUrl        Base URL for AJAX-actions
     * @param \Finna\Db\Row\User $user           User
     * @param array              $patron         Patron information
     * @param string             $driver         Patron MultiBackend ILS source
     * @param int                $amount         Amount
     * (excluding transaction fee)
     * @param int                $transactionFee Transaction fee
     * @param array              $fines          Fines data
     * @param strin              $currency       Currency
     * @param string             $statusParam    Payment status URL parameter
     *
     * @return false on error, otherwise redirects to payment handler.
     */
    public function startPayment(
        $finesUrl, $ajaxUrl, $user, $patron, $driver, $amount, $transactionFee,
        $fines, $currency, $statusParam
    ) {
        $patronId = $patron['cat_username'];
        $orderNumber = $this->generateTransactionId($patronId);

        $successUrl
            = "{$finesUrl}?driver={$driver}"
            . "&{$statusParam}=" . self::PAYMENT_SUCCESS;

        $failUrl
            = "{$finesUrl}?driver={$driver}"
            . "&{$statusParam}=" . self::PAYMENT_FAILURE;

        $notifyUrl
            = "{$ajaxUrl}/onlinePaymentNotify?driver={$driver}"
            . "&{$statusParam}=" . self::PAYMENT_NOTIFY;

        $urlset
            = new Paytrail_Module_Rest_Urlset($successUrl, $failUrl, $notifyUrl, '');

        if (!isset($this->config->productCode)
            && !isset($this->config->transactionFeeProductCode)
            && !isset($this->config->productCodeMappings)
        ) {
            $totAmount = ($amount + $transactionFee) / 100.00;
            $payment = new Paytrail_Module_Rest_Payment_S1(
                $orderNumber, $urlset, $totAmount
            );
        } else {
            $contact = new Paytrail_Module_Rest_Contact(
                $user->firstname, $user->lastname, $user->email,
                !empty($patron['address1']) ? $patron['address1'] : 'N/A',
                !empty($patron['zip']) ? $patron['zip'] : 'N/A',
                !empty($patron['city']) ? $patron['city'] : 'N/A',
                'FI' //TODO: need mapping from $patron['country']?
            );
            $payment = new Paytrail_Module_Rest_Payment_E1(
                $orderNumber, $urlset, $contact
            );

            $productCode = !empty($this->config->productCode)
                ? $this->config->productCode : '';
            $productCodeMappings = [];
            if (!empty($this->config->productCodeMappings)) {
                foreach (explode(':', $this->config->productCodeMappings) as $item) {
                    $parts = explode('=', $item, 2);
                    if (count($parts) != 2) {
                        continue;
                    }
                    $productCodeMappings[trim($parts[0])] = trim($parts[1]);
                }
            }

            foreach ($fines as $fine) {
                $fineType = isset($fine['fine']) ? $fine['fine'] : '';

                if (isset($productCodeMappings[$fineType])) {
                    $code = $productCodeMappings[$fineType];
                } elseif ($productCode) {
                    $code = $productCode;
                } else {
                    $code = $fineType;
                }
                $code = substr($code, 0, 16);

                $fineDesc = '';
                if (!empty($fineType)) {
                    $fineDesc
                        = $this->translator->translate("fine_status_$fineType");
                    if ("fine_status_$fineType" === $fineDesc) {
                        $fineDesc = $this->translator->translate("status_$fineType");
                        if ("status_$fineType" === $fineDesc) {
                            $fineDesc = $fineType;
                        }
                    }
                }
                if (!empty($fine['title'])) {
                    $fineDesc .= ' ('
                        . substr($fine['title'], 0, 255 - 4 - strlen($fineDesc))
                    . ')';
                }
                $payment->addProduct(
                    $fineDesc ?: '', $code, 1, $fine['balance'] / 100.00, 0, 0
                );
            }
            if ($transactionFee) {
                $code = isset($this->config->transactionFeeProductCode)
                    ? $this->config->transactionFeeProductCode : $productCode;
                $payment->addProduct(
                    'Palvelumaksu / Serviceavgift / Transaction fee', $code, 1,
                    $transactionFee / 100.00, 0, 0,
                    Paytrail_Module_Rest_Product::TYPE_HANDLING
                );
            }
        }

        $locale = $this->translator->getLocale();
        $localeParts = explode('-', $locale);
        if ('sv' === $localeParts[0]) {
            $payment->setLocale('sv_SE');
        } elseif ('en' === $localeParts[0]) {
            $payment->setLocale('en_US');
        }

        if (!empty($this->config->paymentDescription)) {
            $payment->setDescription($this->config->paymentDescription);
        }

        if (!$module = $this->initPaytrail()) {
            $this->logger->err('Paytrail: error starting payment processing.');
            return false;
        }

        try {
            $result = $module->processPayment($payment);
        } catch (Paytrail_Exception $e) {
            $err = 'Paytrail: error starting payment processing: '
                . $e->getMessage();
            $this->logger->err($err);
            return false;
        }

        $success = $this->createTransaction(
            $orderNumber,
            $driver,
            $user->id,
            $patronId,
            $amount,
            $transactionFee,
            $currency,
            $fines
        );
        if (!$success) {
            return false;
        }

        $this->redirectToPayment($result->getUrl());
    }

    /**
     * Process the response from payment service.
     *
     * @param Zend\Http\Request $request Request
     *
     * @return string error message (not translated)
     *   or associative array with keys:
     *     'markFeesAsPaid' (boolean) true if payment was successful and fees
     *     should be registered as paid.
     *     'transactionId' (string) Transaction ID.
     *     'amount' (int) Amount to be registered (does not include transaction fee).
     */
    public function processResponse($request)
    {
        $params = $this->getPaymentResponseParams($request);
        $status = $params['payment'];
        $orderNum = $params['transaction'];
        $timestamp = $params['TIMESTAMP'];

        list($success, $data) = $this->getStartedTransaction($orderNum);
        if (!$success) {
            return $data;
        }

        $t = $data;

        $amount = $t->amount;
        if ($status == self::PAYMENT_SUCCESS || $status == self::PAYMENT_NOTIFY) {
            if (!$module = $this->initPaytrail()) {
                return 'online_payment_failed';
            }
            $success = $module->confirmPayment(
                $params["ORDER_NUMBER"],
                $params["TIMESTAMP"],
                $params["PAID"],
                $params["METHOD"],
                $params["RETURN_AUTHCODE"]
            );
            if (!$success) {
                $this->logger->err(
                    'Paytrail: error processing response: invalid checksum'
                );
                $this->logger->err("   " . var_export($params, true));
                $this->setTransactionFailed($orderNum, 'invalid checksum');
                return 'online_payment_failed';
            }
            $this->setTransactionPaid($orderNum, $timestamp);

            return [
                'markFeesAsPaid' => true,
                'transactionId' => $orderNum,
                'amount' => $amount
            ];
        } elseif ($status == self::PAYMENT_FAILURE) {
            $this->setTransactionCancelled($orderNum);
            return 'online_payment_canceled';
        } else {
            $this->setTransactionUnknownResponse($orderNum, $status);
            return 'online_payment_failed';
        }
    }

    /**
     * Init Paytrail module with configured merchantId, secret and URL.
     *
     * @return Paytrail_Module_Rest module.
     */
    protected function initPaytrail()
    {
        foreach (['merchantId', 'secret', 'url'] as $req) {
            if (!isset($this->config[$req])) {
                $this->logger->err("Paytrail: missing parameter $req");
                return false;
            }
        }
        $module = new Paytrail_Module_Rest(
            $this->config['merchantId'],
            $this->config['secret'],
            $this->config['url']
        );
        $module->setHttpService($this->http);
        $module->setLogger($this->logger);
        return $module;
    }
}