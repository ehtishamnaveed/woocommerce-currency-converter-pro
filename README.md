# WooCommerce Currency Converter Pro

A professional WooCommerce multi-currency solution with automatic exchange rate updates, country flag support, and real-time price conversion throughout your store.

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Troubleshooting](#troubleshooting)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)

## Overview

**WooCommerce Currency Converter Pro** is a premium-grade currency conversion plugin for WooCommerce that automatically updates exchange rates and allows customers to switch currencies with intuitive country flag icons. The plugin supports multiple countries, applies live conversion across the entire store, and includes daily rate synchronization, comprehensive admin controls, and a shortcode-based currency selector for seamless integration.

## Features

### Core Functionality
- **Automatic Exchange Rate Updates**: Daily synchronization with open.er-api.com
- **Multi-Currency Support**: Convert prices to any supported currency in real-time
- **Country Flag Integration**: Visual currency selection with national flags
- **Store-Wide Conversion**: Applies to products, cart, checkout, and variations
- **Admin Control Panel**: Full management interface for currency settings

### Advanced Capabilities
- **WP-Cron Scheduling**: Automated background rate updates
- **Smart Caching**: Transient-based caching for optimal performance
- **Shortcode Support**: `[gcs_currency_selector]` for easy placement
- **Cookie-Based Sessions**: Remember customer currency preferences
- **REST Countries API Integration**: Comprehensive country and currency data

## Installation

### Method 1: Manual Installation
1. Download the plugin ZIP file
2. Navigate to **WordPress Admin → Plugins → Add New**
3. Click **Upload Plugin** and select the downloaded file
4. Click **Install Now** then **Activate**

### Method 2: FTP Installation
1. Extract the plugin ZIP file
2. Upload the `woocommerce-currency-converter-pro` folder to `/wp-content/plugins/`
3. Navigate to **WordPress Admin → Plugins**
4. Find "WooCommerce Currency Converter Pro" and click **Activate**

### Requirements
- WordPress 5.6 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## Configuration

### Initial Setup
1. After activation, navigate to **WordPress Admin → Currency Switcher**
2. The plugin automatically detects your WooCommerce base currency
3. Select available countries from the provided list
4. Configure update intervals (1-48 hours)
5. Save settings

### Country Selection
- Browse or search through 200+ countries
- View currency codes and flag availability
- Select multiple countries for customer conversion
- Preview selections before saving

### Rate Management
- Manual refresh option for immediate updates
- Automatic daily synchronization
- Rate preview for selected currencies
- Clear cache when needed

## Usage

### Shortcode Implementation
Add the currency selector anywhere in your site using:

```
[gcs_currency_selector]
```

**Recommended Placement:**
- Header area (near cart icon)
- Product pages
- Sidebar widgets
- Footer section
- Checkout/cart pages

### Customer Experience
1. Customer sees currency selector with flags
2. Selects preferred currency
3. All prices instantly convert
4. Selection saved via cookie for future visits
5. Cart/checkout amounts display in selected currency

## Troubleshooting

### Common Issues & Solutions

#### Prices Not Converting
1. **Clear cache**: Click "Refresh Rates Now" in admin
2. **Check WooCommerce**: Ensure WooCommerce is active
3. **Verify selection**: Confirm currency is selected in frontend
4. **Cookie issues**: Test in private/incognito mode

#### Currency Selector Not Displaying
1. **Shortcode check**: Ensure `[gcs_currency_selector]` is properly placed
2. **Theme conflict**: Switch to default theme temporarily
3. **JavaScript**: Check browser console for errors
4. **Caching plugins**: Clear all cache layers

#### Exchange Rates Not Updating
1. **API status**: Verify open.er-api.com is accessible
2. **WP-Cron**: Check if WordPress cron is functioning
3. **Server time**: Ensure server time is correct
4. **Manual refresh**: Use admin button to force update


## Changelog

### Version 1.7.0
- **Added**: Country flag support in selector
- **Added**: Admin preview for selected countries
- **Improved**: WP-Cron scheduling reliability
- **Fixed**: Variable product price range display
- **Enhanced**: Caching mechanism for better performance

### Version 1.6.0
- **Added**: REST Countries API integration
- **Added**: Search functionality in country selector
- **Improved**: Admin interface design
- **Fixed**: Currency symbol replacement issues

### Version 1.5.0
- Initial stable release
- Basic currency conversion
- Admin control panel
- Shortcode implementation

## Contributing

We welcome contributions! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit changes (`git commit -m 'Add AmazingFeature'`)
4. Push to branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request


## Credits

**Developed by**: Ehtisham Naveed  

---

