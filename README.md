# Admin SMS Order Notification (Free) Plugin for Shopware 6

This admin plugin sends SMS notifications to administrators when new orders are placed using the Twilio API. (Free)

## Features

- 📱 SMS notifications via Twilio API
- ⚙️ Admin configuration panel
- 🎯 Triggers on `checkout.order.placed` event
- 📝 Customizable SMS templates
- 🔧 Uses Shopware's SystemConfigService
- 📊 Proper logging and error handling

## Installation

### Manual Installation

1.  **Download/Clone the Plugin:**
    Place the `AdminSmsOrderNotificationFree` folder into the `custom/plugins/` directory of your Shopware 6 installation.

2.  **Install and activate the plugin via command line:**

### Installation via Composer

1.  **Add the repository to your Shopware `composer.json`:**
    If you are installing from a local path, add the following to the `repositories` section of your main `composer.json` file in the Shopware root directory.

    ```json
    "repositories": [
        {
            "type": "path",
            "url": "custom/plugins/AdminSmsOrderNotificationFree"
        }
    ]
    ```

2.  **Require the plugin:**
    Run the following command from your Shopware root directory:
    ```bash
    composer require vhdev-ua/admin-sms-order-notification-free
    ```

3.  **Install and activate the plugin:**
    ```bash
    bin/console plugin:refresh
    bin/console plugin:install --activate AdminSmsOrderNotificationFree
    bin/console cache:clear
    ```

## Configuration

1. Go to **Settings > System > Plugins** in your Shopware admin panel
2. Find "Admin SMS Order Notification (Free)" and click **Configure**
3. Fill in the required settings:
   - **Twilio Account SID**: Your Twilio Account SID
   - **Twilio Auth Token**: Your Twilio Auth Token
   - **Twilio From Number**: Your Twilio phone number (e.g., +1234567890)
   - **Administrator Phone Numbers**: Comma-separated list of admin phone numbers
   - **SMS Template**: Customize the notification message
   - **Enable SMS Notifications**: Toggle to enable/disable notifications

## SMS Template Variables

You can use the following variables in your SMS template:

- `{orderNumber}` - The order number
- `{amountTotal}` - The total order amount
- `{customerName}` - The customer's full name
- `{currency}` - The currency symbol of the order (e.g., $, €, £)

**Example template:**
```
New order #{orderNumber} placed with total amount {amountTotal} UAH by {customerName}.
```

## Requirements

- Shopware 6.4.0 or higher
- Twilio SDK 6.0 or higher
- Valid Twilio account with SMS capabilities

## File Structure

```
AdminSmsOrderNotificationFree/
├── composer.json
├── README.md
└── src/
    ├── AdminSmsOrderNotificationFree.php
    ├── Resources/
    │   └── config/
    │       ├── config.xml
    │       └── services.xml
    ├── Service/
    │   └── SmsService.php
    └── Subscriber/
        └── OrderPlacedSubscriber.php
```

## Troubleshooting

- Check the Shopware logs for any error messages
- Ensure your Twilio credentials are correct
- Verify that phone numbers are in international format (e.g., +1234567890)
- Make sure the plugin is activated and configured properly

## License

MIT License
