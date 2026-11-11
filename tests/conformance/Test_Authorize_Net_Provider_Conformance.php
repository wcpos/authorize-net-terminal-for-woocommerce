<?php

namespace WCPOS\WooCommercePOS\AuthorizeNetTerminal\Tests\Conformance;

use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;

require_once __DIR__ . '/Authorize_Net_Conformance_Fixture.php';

class Test_Authorize_Net_Provider_Conformance extends Provider_Conformance_Test_Case
{
    protected function fixture(): Conformance_Fixture
    {
        return new Authorize_Net_Conformance_Fixture();
    }
}
