<?php
namespace OPN\Payment\Model\Payment;

use Magento\Vault\Model\CreditCardTokenFactory;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Vault\Model\PaymentTokenManagement;

class PaymentCards{
  
    protected $paymentTokenFactory;
    protected $paymentTokenRepository;
    protected $tokenManagement;
    protected $json;

    public function __construct(
        CreditCardTokenFactory $paymentTokenFactory,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        PaymentTokenManagement $tokenManagement,
        Json $json
    ) {
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->tokenManagement = $tokenManagement;
        $this->json = $json;
    }

    public function getAll($customerId){

        $tokens = [];
        $_tokens = $this->tokenManagement->getVisibleAvailableTokens($customerId);
        foreach($_tokens as $token){
            $token = $this->validateToken($token);
            if( !empty($token) )
                $tokens[] = $token;
        }

        return $tokens;
    }

    public function get($tokenId,$customerId){
        $token = $this->tokenManagement->getByPublicHash($tokenId, $customerId);
        return !empty($token) ? $this->validateToken($token) : null;
    }

    private function validateToken($_token){
        $token = null;

        if( !empty($_token['is_active']) && !empty($_token['is_visible']) && $_token['payment_method_code'] == 'opn' )
        {
        
            $_gtData = explode("|",$_token['gateway_token']);
            $_details = json_decode($_token['details'] ?? '',true);

            if( empty($_details['maskedCC']) || empty($_gtData[2]) )
                return null;
            
            $card = $_details['maskedCC'];

            $token = [
                'entity_id' => $_token['public_hash'],
                'id' => $_token['entity_id'],
                'customer_id' => $_token['customer_id'],
                'card_number' => substr($card,0,4)." **** **** ".substr($card, -4),
                'card_type' => $_details['type'],
                'card_expiry' => $_details['expirationDate'],
                'wallet_id' => $_gtData[0] ?? '',
                'card_id' => $_gtData[1] ?? '',
                'card_token' => $_gtData[2] ?? '',
                'created_at' => $_token['created_at'],
            ];
        }
            
        return $token;
    }

    public function save($customerId, $gatewayToken, $cardType, $maskedCC, $expiryYear, $expiryMonth)
    {

        $paymentToken = $this->paymentTokenFactory->create();

        $paymentToken->setExpiresAt(date("Y-m-d H:i:s",strtotime("+5 year")));
        $paymentToken->setGatewayToken($gatewayToken);
        $paymentToken->setTokenDetails($this->json->serialize([
            'type'              => $cardType,
            'maskedCC'          => $maskedCC,
            'expirationDate'    => sprintf('%02d',$expiryMonth).'/'.substr($expiryYear,-2), 
        ]));

        $paymentToken->setIsActive(true);
        $paymentToken->setIsVisible(true);
        $paymentToken->setPaymentMethodCode('opn');
        $paymentToken->setType(\Magento\Vault\Api\Data\PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
        
        $paymentToken->setCustomerId($customerId);
        $paymentToken->setPublicHash(bin2hex(random_bytes(32)));

        return $this->paymentTokenRepository->save($paymentToken);
    }
}