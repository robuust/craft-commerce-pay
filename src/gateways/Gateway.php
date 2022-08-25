<?php

namespace robuust\pay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\CurrencyException;
use craft\commerce\errors\OrderStatusException;
use craft\commerce\errors\TransactionException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\errors\ElementNotFoundException;
use craft\helpers\App;
use craft\web\Response;
use craft\web\View;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Issuer;
use Omnipay\Common\PaymentMethod;
use Omnipay\Paynl\Gateway as OmnipayGateway;
use Omnipay\Paynl\Message\Request\FetchIssuersRequest;
use Omnipay\Paynl\Message\Request\FetchTransactionRequest;
use Omnipay\Paynl\Message\Response\FetchIssuersResponse;
use Omnipay\Paynl\Message\Response\FetchPaymentMethodsResponse;
use robuust\pay\models\forms\PayOffsitePaymentForm;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * PAY gateway.
 *
 * @property bool        $apiToken
 * @property string|null $settingsHtml
 */
class Gateway extends OffsiteGateway
{
    /**
     * @var string|null
     */
    private ?string $_apiToken = null;

    /**
     * @var string|null
     */
    private ?string $_tokenCode = null;

    /**
     * @var string|null
     */
    private ?string $_serviceId = null;

    /**
     * {@inheritdoc}
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['apiToken'] = $this->getApiToken(false);
        $settings['tokenCode'] = $this->getTokenCode(false);
        $settings['serviceId'] = $this->getServiceId(false);

        return $settings;
    }

    /**
     * @param bool $parse
     *
     * @return string|null
     */
    public function getApiToken(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiToken) : $this->_apiToken;
    }

    /**
     * @param string|null $apiToken
     */
    public function setApiToken(?string $apiToken): void
    {
        $this->_apiToken = $apiToken;
    }

    /**
     * @param bool $parse
     *
     * @return string|null
     */
    public function getTokenCode(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_tokenCode) : $this->_tokenCode;
    }

    /**
     * @param string|null $tokenCode
     */
    public function setTokenCode(?string $tokenCode): void
    {
        $this->_tokenCode = $tokenCode;
    }

    /**
     * @param bool $parse
     *
     * @return string|null
     */
    public function getServiceId(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_serviceId) : $this->_serviceId;
    }

    /**
     * @param string|null $serviceId
     */
    public function setServiceId(?string $serviceId): void
    {
        $this->_serviceId = $serviceId;
    }

    /**
     * {@inheritdoc}
     */
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null): void
    {
        if ($paymentForm) {
            /** @var PayOffsitePaymentForm $paymentForm */
            if ($paymentForm->paymentMethod) {
                $request['paymentMethod'] = $paymentForm->paymentMethod;
            }

            if ($paymentForm->issuer) {
                $request['issuer'] = $paymentForm->issuer;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        if (!$this->supportsCompletePurchase()) {
            throw new NotSupportedException(Craft::t('commerce', 'Completing purchase is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $request['transactionReference'] = $transaction->reference;
        $completeRequest = $this->prepareCompletePurchaseRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    /**
     * {@inheritdoc}
     */
    public static function displayName(): string
    {
        return Craft::t('commerce-pay', 'PAY');
    }

    /**
     * {@inheritdoc}
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * @return Response
     *
     * @throws \Throwable
     * @throws CurrencyException
     * @throws OrderStatusException
     * @throws TransactionException
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function processWebHook(): Response
    {
        $response = Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “' . $transactionHash . '“ not found.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        // Check to see if a successful purchase child transaction already exist and skip out early if they do
        $successfulPurchaseChildTransaction = TransactionRecord::find()->where([
            'parentId' => $transaction->id,
            'status' => TransactionRecord::STATUS_SUCCESS,
            'type' => TransactionRecord::TYPE_PURCHASE,
        ])->count();

        if ($successfulPurchaseChildTransaction) {
            Craft::warning('Successful child transaction for “' . $transactionHash . '“ already exists.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $id = Craft::$app->getRequest()->getBodyParam('order_id');
        $gateway = $this->createGateway();
        /** @var FetchTransactionRequest $request */
        $request = $gateway->fetchTransaction(['transactionReference' => $id]);
        $res = $request->send();

        if (!$res->isSuccessful()) {
            Craft::warning('PAY request was unsuccessful.', 'commerce');
            $response->data = 'ok';

            return $response;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        if ($res->isPaid()) {
            $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
        } elseif ($res->isExpired()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } elseif ($res->isCancelled()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } elseif (isset($res->getData()['status']) && 'failed' === $res->getData()['status']) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else {
            $response->data = 'ok';

            return $response;
        }

        $childTransaction->response = $res->getData();
        $childTransaction->code = $res->getTransactionId();
        $childTransaction->reference = $res->getTransactionReference();
        $childTransaction->message = $res->getMessage();
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $response->data = 'ok';

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-pay/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new PayOffsitePaymentForm();
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentFormHtml(array $params): ?string
    {
        try {
            $defaults = [
                'gateway' => $this,
                'paymentForm' => $this->getPaymentFormModel(),
                'paymentMethods' => $this->fetchPaymentMethods(),
                'issuers' => $this->fetchIssuers(),
            ];
        } catch (\Throwable $exception) {
            // In case this is not allowed for the account
            return parent::getPaymentFormHtml($params);
        }

        $params = array_merge($defaults, $params);

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('commerce-pay/paymentForm', $params);

        $view->setTemplateMode($previousMode);

        return $html;
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['paymentType', 'compare', 'compareValue' => 'purchase'];

        return $rules;
    }

    /**
     * @param array $parameters
     *
     * @return PaymentMethod[]
     *
     * @throws InvalidRequestException
     */
    public function fetchPaymentMethods(array $parameters = [])
    {
        /** @var OmnipayGateway $gateway */
        $gateway = $this->createGateway();

        $paymentMethodsRequest = $gateway->fetchPaymentMethods($parameters);
        /** @var FetchPaymentMethodsResponse $response */
        $response = $paymentMethodsRequest->sendData($paymentMethodsRequest->getData());

        return $response->getPaymentMethods();
    }

    /**
     * @param array $parameters
     *
     * @return Issuer[]
     *
     * @throws InvalidRequestException
     */
    public function fetchIssuers(array $parameters = [])
    {
        /** @var OmnipayGateway $gateway */
        $gateway = $this->createGateway();
        /** @var FetchIssuersRequest $issuersRequest */
        $issuersRequest = $gateway->fetchIssuers($parameters);
        /** @var FetchIssuersResponse $data */
        $data = $issuersRequest->sendData($issuersRequest->getData());

        return $data->getIssuers();
    }

    /**
     * {@inheritdoc}
     */
    public function getTransactionHashFromWebhook(): ?string
    {
        return Craft::$app->getRequest()->getParam('commerceTransactionHash');
    }

    /**
     * {@inheritdoc}
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setApiToken($this->getApiToken());
        $gateway->setTokenCode($this->getTokenCode());
        $gateway->setServiceId($this->getServiceId());

        return $gateway;
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\' . OmnipayGateway::class;
    }
}
