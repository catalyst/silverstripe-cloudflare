# silverstripe-cloudflare

[![Build Status](https://travis-ci.org/steadlane/silverstripe-cloudflare.svg?branch=master)](https://travis-ci.org/steadlane/silverstripe-cloudflare) [![Latest Stable Version](https://poser.pugx.org/steadlane/silverstripe-cloudflare/v/stable)](https://packagist.org/packages/steadlane/silverstripe-cloudflare) [![Total Downloads](https://poser.pugx.org/steadlane/silverstripe-cloudflare/downloads)](https://packagist.org/packages/steadlane/silverstripe-cloudflare) [![License](https://poser.pugx.org/steadlane/silverstripe-cloudflare/license)](https://packagist.org/packages/steadlane/silverstripe-cloudflare) [![Monthly Downloads](https://poser.pugx.org/steadlane/silverstripe-cloudflare/d/monthly)](https://packagist.org/packages/steadlane/silverstripe-cloudflare) [![Code Climate](https://codeclimate.com/github/steadlane/silverstripe-cloudflare/badges/gpa.svg)](https://codeclimate.com/github/steadlane/silverstripe-cloudflare)

# Introduction

The intention of this module is to relieve the double-handling required when updating any of your pages within the CMS of SilverStripe while being behind Cloudflare. When a page is _Published_ or _Unpublished_ a call will be made to the relevant Cloudflare endpoint to clear the cache of the URL/Page you just published/unpublished.

This allows you to see your changes instantly in the preview window without having to worry about logging into the Cloud Flare dashboard to purge the cache yourself.

Cloudflare allows you to have multiple domains registered under a single account. This module is versatile in the sense that it will automatically detect which Zone ID is to be used alongside the domain that this module is installed on. Therefore beyond the two configuration settings required below there is no additional setup required. You can "plug and play" this module in as many locations as you want which means you don't have to worry about tracking down the relevant Zone ID (you can only get it via the API).

**Remember**: Always keep your API authentication details secure. If you are concerned with your credentials being on someone else's machine; have them set up their own Cloudflare account.

**Note**: The detected Zone ID will always be shown in the SilverStripe Administration panel whilst viewing the "Cloudflare" menu item. To bypass this, set CLOUDFLARE_ZONE_ID as an environment variable, obtained from the Cloudflare dashboard

## Why is this module forked?

This module is a fork of the original [SteadLane module](https://github.com/steadlane/silverstripe-cloudflare), which was written for Silverstripe 3 and featured on the [Silverstripe blog](https://github.com/steadlane/silverstripe-cloudflare).  The parent repository was upgraded to Silverstripe 4, but necessary changes needed to make the module work for Silverstripe 5 are not being reviewed or accepted.  This has led to [numerous forks](https://github.com/arkhi-digital/silverstripe-cloudflare/forks?include=active&page=1&period=&sort_by=stargazer_counts) being maintained by community members, which makes it difficult to track and check out with Composer.  In addition, the Cloudflare API itself has evolved somewhat, leading to a fairly significant rewrite.

To keep the project alive, ________ has agreed to host the module on their public repository, to support their own client work and enable others to continue providing contributions.

## Features

- Dynamic Zone ID Detection.  You can bypass this by setting CLOUDFLARE_ZONE_ID as an environment variable, obtained from the dashboard
- Intelligent Purging
    - If you modify the title or URL of any page: All cache for the pages on that server name will be purged.
    - If you modify the contents of any page: Only the cache for that page will be purged. This can be done automatically on publish
    - If you modify any page that has a parent, the page you modified and all of it's parents will be purged too.
- Manual Purging
    - The administration area for this module allows you to either purge all css files, all javascript files, all image files or ... all pages on a specific website.

Unlike the original module, this version deliberately avoids the purge_everything action. It is more appropriate to use Cloudflare's Dashboard if this functionality is required.
    
## Installation

This module only supports installation via Composer:

```
composer require catalyst.net.nz/silverstripe-cloudflare
```

Run `/dev/build` afterwards and `?flush=1` for good measure for SilverStripe to become aware of this module

## Configuration

You're going to need an API key issued by Cloudflare for your zone.  

1. Login to Cloudflare.  Go to Profile > API Tokens > Create Token
2. You need to issue a token called "Cache Purges" with "Zone.Zone, Zone.Cache Purge" permissions.  Limit this to one specific zone if possible. 
3. Define environment variables in `.env`:

```
CLOUDFLARE_AUTH_KEY="ABCDEFGHIJKLMNOPQRSTUVWXYZ"

#Optional:
CLOUDFLARE_ZONE_ID="... obtained from dashboard ..."
CLOUDFLARE_SERVER_NAME=" ... explicitly defined hostname ... "
```

You can explicitly define the `CLOUDFLARE_ZONE_ID` variable if your API key does not allow you to read Zone information. When setting `CLOUDFLARE_SERVER_NAME`, you are telling the module to only issue purge commands for this specific hostname. This must match any caching or rewrite rules defined in your zone. For example, if your production domain name is "www.catalyst.net.nz", you should set "www.catalyst.net.nz" instead of just "catalyst.net.nz"

## Cache Rules
It is recommended that you add the below to your Cloudflare Cache Rules as `no-cache`

| Rule             	| Comments                                                                                                                                                	|
|------------------	|---------------------------------------------------------------------------------------------------------------------------------------------------------	|
| `example.com.au/*stage=Stage*` 	| It is outside the scope of this module to handle cache purging for drafts. Drafts should never need to be cached as they're not usable on the front end 	|
| `example.com.au/Security/*`   	| Prevents caching of the login page etc                                                                                                                  	|
| `example.com.au/admin/*`      	| Prevents caching of the Administrator Panel                                                                                                             	|
| `example.com.au/dev/*`      	| Prevents caching of the development tools                                                                                                             	|

![Bypass Cache Example](http://i.imgur.com/s37SJX4.png)

## Contributing

If you feel you can improve this module in any way, shape or form please do not hesitate to submit a PR for review.

## Troubleshooting and FAQ

Q. **The SS Cloudflare administrator section is blank!**
A. If the Cloudflare administration panel isn't loading correctly, a quick `?flush=1` will resolve this issue.

Q. **The SS Cloudflare footer always says "Zone ID: UNABLE TO DETECT".**
A. This module dynamically retrieves your Zone ID by using the domain you have accessed the website with, unless you have configured it to bypass this. Ensure this domain is correctly registered under your Cloudflare account. If the issue persists, please open a ticket in our issue tracker and provide as much information you can.


## Bugs / Issues

To report a bug or an issue please use our [issue tracker](https://github.com/steadlane/silverstripe-cloudflare/issues).

## License

This module is distributed under the [BSD-3 Clause](https://github.com/steadlane/silverstripe-cloudflare/blob/master/LICENSE) license.
