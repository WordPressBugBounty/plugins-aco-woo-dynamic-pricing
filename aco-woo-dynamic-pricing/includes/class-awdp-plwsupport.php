<?php

/*
* @@ Badge Plugin Support 
* @@ Last updated version 4.3.7
*/

class AWDP_plwSupport
{

    public function plw_check ( $selectedRule, $productID )
    {

        return call_user_func_array ( 
            array ( new AWDP_Discount(), 'check_in_rule' ), 
            array ( $selectedRule, $productID ) 
        );

    }

}