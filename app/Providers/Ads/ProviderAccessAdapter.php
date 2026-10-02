<?php

namespace App\Providers\Ads;

/** Provider-native mechanics only. Implementations cannot derive Builderad authority from credentials. */
interface ProviderAccessAdapter
{
    public function provider(): Provider;

    public function acquire(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $authorization,
        #[\SensitiveParameter] string $nativeContext): ProviderAccessMaterial;

    public function renew(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $current,
        #[\SensitiveParameter] string $nativeContext): ProviderAccessMaterial;

    /** Native hierarchy and role interpretation belong to the provider, including paging. */
    public function discover(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        #[\SensitiveParameter] string $nativeContext): ProviderAccountDiscovery;

    /** Selection must be reverified against native account access, not inferred from a displayed list. */
    public function verifySelection(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        #[\SensitiveParameter] string $nativeSelection): ProviderAccessMaterial;
}
