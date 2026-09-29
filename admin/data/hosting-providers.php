<?php
/**
 * Hosting providers data for Allstars plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define hosting providers
function allstars_get_hosting_providers() {
    return array(
        'cloudron' => array(
            'name' => 'Cloudron',
            'description' => 'Self-hosted platform that makes it easy to run web applications like WordPress on your server.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.cloudron.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.cloudron.io/pricing.html'
                )
            )
        ),
        'hostinger' => array(
            'name' => 'Hostinger',
            'description' => 'Affordable WordPress hosting with good performance and user-friendly management tools.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.hostinger.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.hostinger.com/wordpress-hosting'
                )
            )
        ),
        'hetzner' => array(
            'name' => 'Hetzner Cloud',
            'description' => 'High-performance cloud servers with excellent price-to-performance ratio for self-managed WordPress hosting.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.hetzner.com/cloud/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.hetzner.com/cloud#pricing'
                )
            )
        ),
        'simplehost' => array(
            'name' => 'SimpleHost',
            'description' => 'Streamlined WordPress hosting with a focus on simplicity and performance.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://simplehost.so/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://simplehost.so/#pricing'
                )
            )
        ),
        'cloudflare' => array(
            'name' => 'Cloudflare',
            'description' => 'Global cloud platform that provides CDN, security, and performance optimization services.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.cloudflare.com/', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- product page link, not a loaded asset.
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.cloudflare.com/plans/' // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- product page link, not a loaded asset.
                )
            )
        ),
        'spaceship' => array(
            'name' => 'Spaceship',
            'description' => 'Modern hosting platform with advanced features for WordPress sites.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.spaceship.com/',
                    'primary' => true
                )
            )
        ),
        '101domain' => array(
            'name' => '101Domain',
            'description' => 'Domain registration and management service with support for hundreds of TLDs.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.101domain.com/',
                    'primary' => true
                )
            )
        ),
        'namecheap' => array(
            'name' => 'Namecheap',
            'description' => 'Domain registrar and web hosting provider with competitive pricing and good support.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://www.namecheap.com/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://www.namecheap.com/hosting/shared/'
                )
            )
        ),
        'updownio' => array(
            'name' => 'Updown.io',
            'description' => 'Simple and affordable website monitoring service with uptime checks and performance metrics.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://updown.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://updown.io/pricing'
                )
            )
        ),
        'coolify' => array(
            'name' => 'Coolify',
            'description' => 'Self-hostable Heroku & Netlify alternative for developers. Deploy your apps, databases, and services with ease.',
            'button_group' => array(
                array(
                    'text' => 'Home Page',
                    'url' => 'https://coolify.io/',
                    'primary' => true
                ),
                array(
                    'text' => 'Pricing',
                    'url' => 'https://coolify.io/pricing/'
                )
            )
        )
    );
}
