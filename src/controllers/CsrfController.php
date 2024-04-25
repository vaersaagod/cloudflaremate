<?php

namespace vaersaagod\cloudflaremate\controllers;

use craft\helpers\Html;
use craft\web\Controller;

/**
 * Csrf controller
 */
class CsrfController extends Controller
{
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = true;

    /**
     * @return string
     */
    public function actionIndex(): string
    {
        return Html::csrfInput();
    }
}
