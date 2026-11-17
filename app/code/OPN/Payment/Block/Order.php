<?php
/**
 * Copyright © 2024 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace OPN\Payment\Block;

class Order extends \Magento\Framework\View\Element\Template {
    
    private $data;

    public function getOrderId(){
        if( !empty($this->data['order']) )
            return $this->data['order']->getIncrementId();
        return null;
    }
    
    public function setOrderData($data){
        $this->data = $data;
    }

    public function getInfo(){
        $info = [];
        if( !empty($this->data['payment']) )
            $info = $this->data['payment']->getAdditionalInformation();    

        if( empty($info['message']) )
            $info['message'] = $this->data['error'];

        return $info;
    }

    public function getHelper(){
        return $this->data['helper'];
    }

    public function getDetails(){
        $details =[];

        if( !empty($this->data['order']) ){
            $order = $this->data['order'];

            $billing = $order->getBillingAddress();

            $total = (float)$order->getGrandTotal(); 

            $details = [
                'order_id' => $order->getIncrementId(),
                'total' => $total,
                'payment_firstname' => ucfirst($billing->getFirstName() ?: ''),
                'payment_lastname' => ucfirst($billing->getLastname() ?: '' ),
                'email' => $billing->getEmail() ?: '',
                'telephone' => $billing->getTelephone() ?: '',
                'payment_address_1' => $billing->getData('street') ?: '',
                'payment_postcode' => $billing->getPostcode() ?: '',
            ];
        }

        return $details;
    }

    public function getOrderData(){
        return $this->data;
    }
}
