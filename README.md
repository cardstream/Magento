# Cardstream Payment Gateway for Magento 2

**Compatibility**

**Compatible with Magento 2.3 and Magento 2.4**

Supports both Hosted and Direct integrations

## Installation

**Step 1:**

If you are upgrading this module, please make sure to disable the module first with `bin/magento module:disable Cardstream_PaymentGateway`. Afterwards, make sure to delete the `app/code/Cardstream` directory that may interfere with the new version. Make sure to delete the `Cardstream_PaymentGateway` row from the `setup_module` table in the database so that any database tables required can get created.

**Step 2:**
Copy the contents of `httpdocs` to your Magento root directory. If you are asked if you want to replace any existing files, click Yes.

**Step 3:**
Enable the new module using the command `bin/magento module:enable Cardstream_PaymentGateway`

**Step 4:**
Upgrade and re-compile magento so that the system will install the module and create all necessary arrangements for the module. This command can be helpful...

```
bin/magento setup:upgrade && bin/magento setup:db-schema:upgrade && bin/magento setup:di:compile && chmod 775 -R ./var
```

**Step 5:**
Login to the Admin area of Magento. Click on System > Cache Management. Click on the button labelled ‘Flush Magento Cache’, located at the top right of the page.

**Step 6:**
Click on Stores > Configuration then click on Payment Methods under the Sales heading on the left-hand side of the page. All installed payment methods will be displayed.

**Step 7:**
Click on Cardstream Gateway to expand the configuration options that you will need to fill out before you can use the module. Here you can also select the hosted or direct integration type. Debugging should be turned off during production.

**Step 8:**
Head over to the store's settings and select advanced and then system. Once on this page; change the caching type to 'Varnished'.

## FAQ

**The processing page `/paymentgateway/order/process` shows an error page (Page Not Found)**

**Did you upgrade and re-compile Magento?** _Otherwise, have you changed either the order controller filename, the directory the order controller was in, or the name of that directory? Do you have the route setup in the `/etc/frontend/routes.xml` under the route attributes; `id` of `cardstream` and `frontName` as `cardstream`. Does that same route contain the module element with a `name` attribute of `Cardstream_PaymentGateway`? If you answered no to any of these questions. Please set up the appropriate arrangements based on the questions asked and try again after an upgrade & recompile command. Ask support if the error continues._

**I get the following error - router requires an id but one isn't set**

Go to `etc/frontend/routes.xml` and make sure the router element uses an `id` attribute with the value `standard`

**I get the following error - "Module version difference schema version higher/lower than in a database"**

Make sure to delete the `Cardstream_PaymentGateway` row from the setup_module database so that any database tables required can get created during the upgrade/db-schema process upon installation

**I get incorrect signature during checkout**

Is a signature set up in your configuration both in Magento and the MMS? Make sure this only contains alphabetical and numeric characters without any spaces, full-stops, etc.

**I cannot see the Cardstream Gateway in the backend (admin area)**

Please try running the following commands:

```
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

**The amount, address and name appear to cache upon a checkout**
Are you using the latest version of this module which fixes this issue?
