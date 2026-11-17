<?php
namespace OPN\Payment\Block\Customer;

use Magento\Framework\View\Element\Template;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Block\AbstractCardRenderer;

class CardRenderer extends AbstractCardRenderer{

    public function canRender(PaymentTokenInterface $token) {
        return $token->getPaymentMethodCode() === 'opn';
    }

    public function getNumberLast4Digits(){   
        $token = $this->getTokenDetails();
        $card = $this->getTokenDetails()['maskedCC'];
        return substr($card,0,4)." **** **** ".substr($card, -4);
    }

    public function getExpDate(){
        return $this->getTokenDetails()['expirationDate'];
    }

    public function getType(){
        return $this->getTokenDetails()['type'];
    }

    public function getIconUrl(){
        return '';
    }

    public function getIconHeight(){
        return 'auto';
    }

    public function getIconWidth(){
        return 'auto';
    }
}
