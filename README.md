Disclaimer: Please note that we no longer support older versions of SDKs and
Modules. We recommend that the latest versions are used. Please note version
3.1.11 contains a rebuild of the core module infrastructure, please ensure you
take relevant measures when updating to ensure compatibility with your 
integrations.

# Payment Network Gateway

Payment Network Gateway is a Magento payment plugin for processing card 
payments, Apple Pay, Google Pay, and hosted payment forms.

Current plugin version: **3.1.11**

## Requirements

- Magento 2.4.9+
- PHP 8.4+

## Supported Payment Methods

| Method | Features |
|---|---|
| **Card** | Hosted Fields, tokenisation (saved cards), 3D Secure, refunds |
| **Apple Pay** | Express checkout, refunds |
| **Google Pay** | Express checkout, 3D Secure, refunds |
| **Hosted** | Hosted Payment Form (v1, modal, custom URL), refunds |

Please ensure you review your Merchant configuration with your Payment Gateway Provider for the ability to process Apple Pay and Google Pay. 
The configuration requirements and availability of these payment methods may vary based on Express checkout or Hosted Form checkout being used, as well as your specific Merchant use case.

## Installation

Installation
Step 1:

If you are upgrading this module, please make sure to disable the module first with bin/magento module:disable PaymentNetwork_PaymentGateway. Afterwards, make sure to delete the app/code/OPN directory that may interfere with the new version. Make sure to delete the PaymentNetwork_PaymentGateway row from the setup_module table in the database so that any database tables required can get created.

Step 2: Copy the contents of the app folder to your Magento root directory. If you are asked if you want to replace any existing files, click Yes.

Step 3: Enable the new module using the command bin/magento module:enable PaymentNetwork_PaymentGateway

Step 4: Upgrade and re-compile magento so that the system will install the module and create all necessary arrangements for the module. This command can be helpful...

bin/magento setup:upgrade && bin/magento setup:db-schema:upgrade && bin/magento setup:di:compile && chmod 775 -R ./var
Step 5: Login to the Admin area of Magento. Click on System > Cache Management. Click on the button labelled ‘Flush Magento Cache’, located at the top right of the page.

Step 6: Click on Stores > Configuration then click on Payment Methods under the Sales heading on the left-hand side of the page. All installed payment methods will be displayed.

Step 7: Click on Payment Network Gateway to expand the configuration options that you will need to fill out before you can use the module. Here you can also select the hosted or direct integration type. Debugging should be turned off during production.

Step 8: Head over to the store's settings and select advanced and then system. Once on this page; change the caching type to 'Varnished'.
## Configuration

After activation, configure the plugin in two places:

### 1) Gateway Settings

Go to **Magento -> Stores -> Configuration -> Sales -> Payment Methods -> Open Payment Network -> Gateway Settings**.

| Setting | Description |
|---|---|
| Enable Plugin | Enables or disables the plugin globally |
| Merchant ID | Your gateway merchant ID |
| Merchant Secret | Your gateway merchant signature key |
| Country Code | ISO 3166-1 numeric country code of the merchant (for example, `826` for the United Kingdom) |
| Gateway URL | Gateway URL (for example, `https://gateway.example.com`) |
| Debug Enabled | Enables logging to Magento logs |

Logs are available in the Magento log directory **var/log/open_payment_network.log**.

## Updating

1. Back up your store files and database.
2. Deactivate and remove the old plugin version.
3. Install the new version using one of the installation methods above.
4. Re-check module and payment method settings after activation.

## Disclaimer

Sample code, SDKs and modules have been created as reference material only.
Modules are developed and tested against vanilla base platform installs only.
Any further module compatibility would need to be tested by the 
user/merchant/developer. Version support is as shown within the associated 
VERSION section. All sample code, SDKs and modules offer foundation transaction 
functionality for merchant and developers to use as a guide and/or to adapt, 
enhance or otherwise build upon. For the avoidance of doubt, this means that 
some desired functionality may not be useable or exist. All sample code, SDKs 
or modules that are used will require complete full end to end testing by the 
user/merchant/developer. Further to this, use of any sample code, SDKs or 
modules, Open Payment Network bears no responsibility for; nor extends any 
warranty in regard to; nor accepts any liability arising due to any changes 
or errors in functionality that may result. Developers, merchants or other 
users of any sample code, SDKs or modules accept these conditions de facto 
upon usage.
