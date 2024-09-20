<?php

namespace robuust\pay\models\forms;

use craft\commerce\models\payments\BasePaymentForm;

class PayOffsitePaymentForm extends BasePaymentForm
{
    /**
     * @var string|null
     */
    public ?string $paymentMethod = null;
}
