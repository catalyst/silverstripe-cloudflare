<?php

namespace SteadLane\Cloudflare;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Permission;
use SteadLane\Cloudflare\Purge;
use SilverStripe\CMS\Model\SiteTree;

class CloudFlareElementalExtension extends DataExtension
{
    public function onAfterPublish()
    {
        return $this->purgePage();
    }

    public function onAfterUnpublish()
    {
        return $this->purgePage();
    }

    protected function purgePage()
    {
        if (CloudFlare::singleton()->hasCFCredentials() && Permission::check('CF_PURGE_PAGE')) {
            $page = $this->owner->getPage();

            if (!($page instanceof SiteTree)) {
                return;
            }

            Purge::singleton()->quick('page', $page->ID);
        }
    }
}
