<?php

namespace vaersaagod\cloudflaremate\web\twig\variables;

use Craft;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\View;

class CloudflareMateVariable
{

    /**
     * Returns a cache-safe CSRF input
     *
     * @return string
     */
    public function csrfInput(): string
    {
        $url = UrlHelper::actionUrl('_cloudflaremate/csrf');
        $js = <<<JS
            try {
                document.addEventListener("DOMContentLoaded", async function () {
                    const nodes = document.querySelectorAll('.cloudflaremate-csrf');
                    if (!nodes.length) {
                        return;
                    }
                    const response = await fetch('$url');
                    const text = await response.text();
                    document.querySelectorAll('.cloudflaremate-csrf').forEach(node => {
                        const fragment = document.createRange().createContextualFragment(text);
                        node.replaceWith(fragment);
                    });
                });    
            } catch (error) {
                console.error(error);
            };
        JS;
        Craft::$app->getView()->registerJs($js, View::POS_END);
        return Html::tag('span', '', [
            'class' => 'cloudflaremate-csrf',
            'style' => 'display:none;',
        ]);
    }

}
