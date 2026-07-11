# FOSSBilling Registrar Connect

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

A FOSSBilling registrar module for working with multiple domain registrars through one consistent API.

## Registrar Support

| Registrar | Status | Motes |
|----------|----------|----------|
| **Name.com** | beta | |
| OpenSRS | alpha | |

## Installation

1. Use our **[Module Customizer Tool](https://namingo.org/foss-module/)** to generate a fine-tuned registrar module specifically for your registrar, then extract the **generated archive** into `/tmp`.

2. From the extracted archive in `/tmp`, move the `namingo/` directory into your FOSSBilling/Namingo Registrar installation directory (for example `/var/www/fossbilling` or `/var/www`, depending on where it is installed). Move the main module file `Connect.php` into `[FOSSBilling_path]/library/Registrar/Adapter`.

If this is not your first registrar module, you may skip copying the `namingo/` directory, as it is shared between modules.

3. Within FOSSBilling, go to **System -> Domain Registration -> New Domain Registrar** and activate the new domain registrar.

4. Go to the **Registrars** tab in the admin panel and select your registrar module. Enter your registrar connection details.

5. Add a new Top Level Domain (TLD) using your module from the "**New Top Level Domain**" tab. Make sure to configure all necessary details, such as pricing, within this tab.

### Mandatory FOSSBilling Core Changes (FOSSBilling 0.8.3)

If you are using **FOSSBilling 0.8.3**, the following temporary core changes are required due to bugs in FOSSBilling.

#### 1. Prevent Duplicate Domain Registration

Edit `[FOSSBilling_path]/modules/Order/Service.php` and at the very beginning of the `activateOrder()` method, add:

```php
$order = $this->di['db']->load('ClientOrder', $order->id);

if ($order->status === \Model_ClientOrder::STATUS_ACTIVE) {
    return true;
}
```

This workaround prevents duplicate order activation, which may otherwise result in a second domain registration attempt during checkout.

#### 2. Registrar Adapter Autoload Fix

Edit `[FOSSBilling_path]/modules/Servicedomain/Service.php` and find:

```php
$class = sprintf('Registrar_Adapter_%s', $model->registrar);
```

Immediately after it, add:

```php
$file = Path::join(PATH_LIBRARY, 'Registrar', 'Adapter', "{$model->registrar}.php");

require_once $file;
```

This workaround is only required for **FOSSBilling 0.8.3** and will no longer be needed once **FOSSBilling 0.8.4** is released.

## Upgrade

- Before upgrading, note your current module settings.
- Download the updated module and repeat the installation steps to replace the existing files.
- After upgrading, verify that your module settings are still correct.

## Troubleshooting

1. **Multiple modules conflict**
   - FOSSBilling does not allow multiple registrar modules that share the same internal function names.
   - If you are connecting to more than one registry, you must generate each module using the Module Customizer Tool to ensure unique module names.
   - Do not manually duplicate or rename module files, as this may cause function collisions or unexpected behavior.

### Need More Help?

If the steps above do not resolve your issue, check the FOSSBilling logs and enable **Error Reporting** in the admin panel to display detailed error messages.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/fossbilling-registrar-connect/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Support This Project

If you find FOSSBilling Registrar Connect useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

FOSSBilling Registrar Connect is licensed under the MIT License.