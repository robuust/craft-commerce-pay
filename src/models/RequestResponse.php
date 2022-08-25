<?php

namespace robuust\pay\models;

use Craft;
use craft\commerce\omnipay\base\RequestResponse as BaseRequestResponse;
use Omnipay\Paynl\Message\Response\CompletePurchaseResponse;

class RequestResponse extends BaseRequestResponse
{
    /**
     * {@inheritdoc}
     */
    public function getMessage(): string
    {
        $data = $this->response->getData();

        if (is_array($data) && !empty($data['status'])) {
            switch ($data['status']) {
                case 'canceled':
                    return Craft::t('commerce-pay', 'The payment was canceled.');
                case 'failed':
                    return Craft::t('commerce-pay', 'The payment failed.');
            }
        }

        return (string) $this->response->getMessage();
    }

    /**
     * {@inheritdoc}
     */
    public function isProcessing(): bool
    {
        $data = $this->response->getData();
        // @TODO Temporary solution ahead of either a PR to `omnipay-pay` or a gateway rewrite
        if ($this->response instanceof CompletePurchaseResponse && isset($data['method'], $data['status']) && $data['method'] === 'banktransfer' && $this->response->isOpen()) {
            return true;
        }

        return parent::isProcessing();
    }

    /**
     * {@inheritdoc}
     */
    public function isRedirect(): bool
    {
        $data = $this->response->getData();
        // @TODO Temporary solution ahead of either a PR to `omnipay-pay` or a gateway rewrite
        if ($this->response instanceof CompletePurchaseResponse && isset($data['method']) && $data['method'] === 'banktransfer' && $this->isProcessing()) {
            return false;
        }

        return parent::isRedirect();
    }
}
