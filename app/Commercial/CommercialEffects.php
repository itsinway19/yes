<?php

namespace App\Commercial;

final class CommercialEffects
{
    public const ADMINISTRATOR = 'SA01CommercialGrantAdministrator';

    public const OPERATIONAL = [
        'ReadCommercialMarketRegistry', 'ConfigureCommercialMarket',
        'EnableCommercialMarket', 'DisableCommercialMarket',
        'RecordOrVerifyApprovedMarketReference', 'RegisterCommercialScope',
        'RecordBeneficiaryBinding', 'AssignCommercialMarket',
        'ReadCommercialContextAdvertiser', 'ReadCommercialContextInternal',
        'ReadCommercialRegistrationReceipt', 'ReadCommercialAuditDeliveryState',
        'RetryOrRecoverExistingAuditDelivery',
    ];
}
