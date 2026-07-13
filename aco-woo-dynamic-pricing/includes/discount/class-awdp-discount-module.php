<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base for AWDP discount modules — shared owner reference.
 */
abstract class AWDP_Discount_Module
{

    /** @var AWDP_Discount */
    protected $owner;

    public function __construct(AWDP_Discount $owner)
    {
        $this->owner = $owner;
    }

}
