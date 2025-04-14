<?php

namespace SteadLane\Cloudflare;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SteadLane\Cloudflare\Purge;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;

class CloudFlareElementalExtension extends Extension
{

    public function onAfterPublish()
    {
        if (!Permission::check('CF_PURGE_PAGE')) {
            Security::permissionFailure();
        }

        $page = $this->owner->getPage();

        if (!($page instanceof SiteTree)) {
            return;
        }

        Purge::singleton()->quick('page', $page->ID);
    }
}
