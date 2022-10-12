<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="PAY for Craft Commerce icon"></p>

<h1 align="center">PAY for Craft Commerce</h1>

This plugin provides an [official](https://docs.pay.nl/plugins#hostedsolution_craftcommerce) [PAY](https://www.pay.nl/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

This plugin requires Craft 4.0 and Craft Commerce 4.0 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “PAY for Craft Commerce”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require robuust/craft-commerce-pay

# tell Craft to install the plugin
./craft install/plugin commerce-pay
```

## Setup

To add a PAY payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “PAY”.

> **Tip:** The API token, Token code and Service ID settings can be set to environment variables. See [Environmental Configuration](https://docs.craftcms.com/v3/config/environments.html) in the Craft docs to learn more about that.
